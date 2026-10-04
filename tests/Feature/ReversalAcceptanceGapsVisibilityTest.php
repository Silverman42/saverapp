<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\ReversalEvidenceFile;
use App\Models\ReversalNotificationIntent;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\LedgerTransactionProjectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';

/** @return array<string, mixed> */
function revGapProps(TestResponse $response): array
{
    return AssertableInertia::fromTestResponse($response)->toArray()['props'];
}

/** @return list<string> the reversal ids listed on the returned index page */
function revGapListedIds(TestResponse $response): array
{
    return array_column(revGapProps($response)['requests']['data'], 'id');
}

function revGapEvidenceScanner(): void
{
    config()->set(['collections.evidence_scanner_binary' => '/opt/clamav/bin/clamdscan', 'collections.evidence_scanner_version' => 'test-signatures-1']);
    Storage::fake('collection_evidence');
    Process::fake(['*clamdscan*' => Process::result()]);
}

test('REV-AC-028: the index lists only the requests inside the viewer scope and counts follow the filter', function (): void {
    [$agentA, $customerA1, $assignmentA1] = revGapAgentWithCustomer();
    $customerA2 = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $customerA2->id,
        'agent_profile_id' => $assignmentA1->agent_profile_id, 'assigned_by_user_id' => $agentA->id,
        'status' => CustomerAssignmentStatus::Current]);
    [$agentD, $customerD, $assignmentD] = revGapAgentWithCustomer();
    [$former, $customerHanded, $assignmentHanded] = revGapAgentWithCustomer();
    $a1 = revGapRawRequest($agentA, $customerA1, $assignmentA1);
    $a2 = revGapRawRequest($agentA, $customerA2, $assignmentA1, 'rejected');
    $d1 = revGapRawRequest($agentD, $customerD, $assignmentD);
    $handed = revGapRawRequest($former, $customerHanded, $assignmentHanded);
    [$successor] = revGapReassign($customerHanded);
    $all = [$a1, $a2, $d1, $handed];

    $ids = fn (User $viewer, array $query = []): array => revGapListedIds($this->actingAs($viewer)->get(route('reversals.index', $query))->assertOk());
    $sorted = fn (array $requests): array => collect($requests)->pluck('reversal_id')->sort()->values()->all();

    expect(collect($ids($agentA))->sort()->values()->all())->toBe($sorted([$a1, $a2]))
        ->and($ids($agentD))->toBe([$d1->reversal_id])
        ->and($ids($successor))->toBe([$handed->reversal_id])
        ->and($ids($former))->toBe([])
        ->and($ids($customerA1->user))->toBe([$a1->reversal_id])
        ->and($ids($customerA2->user))->toBe([$a2->reversal_id])
        ->and(collect($ids(revGapAdmin()))->sort()->values()->all())->toBe($sorted($all))
        ->and($ids($agentA, ['state' => 'pending_review']))->toBe([$a1->reversal_id])
        ->and($ids($agentA, ['state' => 'rejected']))->toBe([$a2->reversal_id])
        ->and($ids(revGapAdmin(), ['state' => 'pending_review']))->toHaveCount(3);
    expect(revGapProps($this->actingAs($agentA)->get(route('reversals.index')))['requests']['total'])->toBe(2)
        ->and(revGapProps($this->actingAs(revGapAdmin())->get(route('reversals.index', ['state' => 'rejected'])))['requests']['total'])->toBe(1)
        ->and(revGapProps($this->actingAs(CustomerProfile::factory()->create()->user)->get(route('reversals.index')))['requests']['total'])->toBe(0);
});

test('REV-AC-028: pagination stays inside the viewer scope across pages', function (): void {
    [$agent, $customer, $assignment] = revGapAgentWithCustomer();
    [$other, $otherCustomer, $otherAssignment] = revGapAgentWithCustomer();
    $own = collect(range(1, 26))->map(fn (): string => revGapRawRequest($agent, $customer, $assignment, 'rejected')->reversal_id);
    $foreign = collect(range(1, 3))->map(fn (): string => revGapRawRequest($other, $otherCustomer, $otherAssignment, 'rejected')->reversal_id);

    $first = $this->actingAs($agent)->get(route('reversals.index'))->assertOk();
    $second = $this->get(route('reversals.index', ['page' => 2]))->assertOk();

    expect(revGapProps($first)['requests']['total'])->toBe(26)->and(revGapListedIds($first))->toHaveCount(25)
        ->and(revGapListedIds($second))->toHaveCount(1)
        ->and(array_merge(revGapListedIds($first), revGapListedIds($second)))->toHaveCount(26)
        ->and(array_diff(array_merge(revGapListedIds($first), revGapListedIds($second)), $own->all()))->toBe([])
        ->and(array_intersect(array_merge(revGapListedIds($first), revGapListedIds($second)), $foreign->all()))->toBe([])
        ->and(revGapProps($this->actingAs($customer->user)->get(route('reversals.index', ['page' => 2])))['requests']['total'])->toBe(26)
        ->and(revGapProps($this->actingAs(revGapAdmin())->get(route('reversals.index')))['requests']['total'])->toBe(29);
});

test('REV-AC-029: Customer-facing views, statement and notices omit internal evidence and claim no physical return', function (): void {
    revGapEvidenceScanner();
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $quote = revGapQuote($this, $agent, $original);
    $submission = revGapSubmission($quote, ['internal_reason' => 'INTERNAL-REASON-MARKER cash stays with the Agent.',
        'evidence_text' => 'EVIDENCE-TEXT-MARKER teller slip 88421.', 'customer_explanation' => 'The receipt was recorded against the wrong allocation.']);
    $this->post(route('reversals.store', $original->posting_reference), [...$submission, 'files' => [UploadedFile::fake()->image('proof.png')]])->assertRedirect();
    $request = ReversalRequest::query()->sole();
    $file = ReversalEvidenceFile::query()->sole();
    $private = ['INTERNAL-REASON-MARKER', 'EVIDENCE-TEXT-MARKER', '88421', 'proof.png', $file->storage_path, $file->checksum];
    revGapDecision($this, revGapAdmin(), $request, 'approve')->assertRedirect();
    $reference = DB::table('ledger_transaction_references')->where('root_type', 'reversal_request')->where('root_id', (string) $request->id)->value('transaction_reference');

    $page = $this->actingAs($customer->user)->get(route('reversals.show', $request))->assertOk();
    $props = revGapProps($page);
    expect($props['reversal']['internal_reason'])->toBeNull()->and($props['reversal']['evidence_text'])->toBeNull()
        ->and($props['reversal']['dependency_snapshot'])->toBeNull()
        ->and($props['reversal']['customer_explanation'])->toBe('The receipt was recorded against the wrong allocation.')
        ->and($props['evidence_files'])->toBe([])->and($props['can_add_evidence'])->toBeFalse()
        ->and($props['can_approve'])->toBeFalse()->and($props['can_cancel'])->toBeFalse();

    $views = [$page->getContent(),
        $this->get(route('transactions.show', $reference))->assertOk()->getContent(),
        $this->get(route('transactions.index'))->assertOk()->getContent(),
        $this->get(route('customers.statements.preview', $customer->customer_id))->assertOk()->getContent(),
        $this->get(route('notifications.index'))->assertOk()->getContent()];
    foreach ($views as $content) {
        foreach ($private as $secret) {
            expect($content)->not->toContain($secret);
        }
    }
    $detail = revGapProps($this->get(route('transactions.show', $reference)))['transaction'];
    expect($detail['type'])->toBe('reversal')->and($detail['actors'])->toBe([])->and($detail['original_reference'])->not->toBeNull();
    $lines = revGapProps($this->get(route('customers.statements.preview', $customer->customer_id)))['preview']['lines'];
    expect(collect($lines)->pluck('type')->all())->toContain('contribution', 'reversal')
        ->and(collect($lines)->sum('savings_effect_kobo'))->toBe(0);

    $customerNotices = ReversalNotificationIntent::query()->where('audience_type', 'subject_customer')->get();
    expect($customerNotices)->not->toBeEmpty();
    foreach ($customerNotices as $notice) {
        expect(json_encode($notice->payload))->not->toMatch('/returned|refund|paid back|cash back|handed back/i');
        foreach ($private as $secret) {
            expect(json_encode($notice->payload))->not->toContain($secret);
        }
    }
});

test('REV-AC-031: submission, evidence access and every decision are audited with masked fields', function (): void {
    revGapEvidenceScanner();
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = revGapAdmin();
    $secrets = ['AUDIT-INTERNAL-MARKER', 'AUDIT-EVIDENCE-MARKER', 'AUDIT-EXPLANATION-MARKER', 'AUDIT-DECISION-MARKER'];
    $overrides = ['internal_reason' => $secrets[0], 'evidence_text' => $secrets[1], 'customer_explanation' => $secrets[2]];

    $quote = revGapQuote($this, $agent, $original);
    $this->post(route('reversals.store', $original->posting_reference), [...revGapSubmission($quote, $overrides), 'files' => [UploadedFile::fake()->image('first.png')]])->assertRedirect();
    $first = ReversalRequest::query()->sole();
    $file = ReversalEvidenceFile::query()->sole();
    $url = $this->actingAs($admin)->getJson(route('reversals.evidence.link', [$first, $file->id]))->assertOk()->json('url');
    $this->get($url)->assertOk();
    revGapDecision($this, $admin, $first, 'reject', ['decision_reason' => $secrets[3]])->assertRedirect();

    $second = revGapSubmit($this, $agent, $original, $overrides);
    $this->actingAs($agent)->post(route('reversals.evidence.store', $second), ['files' => [UploadedFile::fake()->image('second.png')]])->assertRedirect();
    revGapDecision($this, $admin, $second, 'approve', ['decision_reason' => $secrets[3]])->assertRedirect();

    $events = DB::table('canonical_audit_events')->where('source_module', 'reversal')->orderBy('id')->get();
    $byType = $events->groupBy('event_type')->map->count()->all();
    expect($byType)->toBe(['reversal.submitted' => 2, 'reversal.evidence_added' => 2, 'reversal.evidence_downloaded' => 1,
        'reversal.rejected' => 1, 'reversal.approved_posted' => 1]);
    foreach ($events as $event) {
        expect($event->target_reference)->toBeIn([$first->reversal_id, $second->reversal_id]);
        foreach ([...$secrets, 'first.png', 'second.png', $file->storage_path, $file->checksum] as $secret) {
            expect($event->content)->not->toContain($secret);
        }
    }
    foreach (['reversal.rejected', 'reversal.approved_posted'] as $type) {
        $decision = $events->firstWhere('event_type', $type);
        expect($decision->actor_id)->toBe($admin->id)->and($decision->actor_type)->toBe('admin')
            ->and($decision->required_permission)->toBe('reversals.review')->and($decision->outcome)->toBe('Succeeded')
            ->and(json_decode($decision->content, true)['approver_id'])->toBe($admin->id);
    }
    expect($events->firstWhere('event_type', 'reversal.submitted')->actor_id)->toBe($agent->id)
        ->and($events->firstWhere('event_type', 'reversal.evidence_downloaded')->actor_id)->toBe($admin->id);
});

test('REV-AC-031: the audit workspace stays closed to Customers, Agents and Admins without the audit grant', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $eventId = (string) DB::table('canonical_audit_events')->where('event_type', 'reversal.submitted')
        ->where('target_reference', $request->reversal_id)->value('event_id');
    expect($eventId)->not->toBe('');
    $reviewer = revGapAdmin();
    $reviewer->givePermissionTo(AdminPermission::ReportsExport);

    foreach ([$customer->user, $agent, $reviewer] as $viewer) {
        $this->actingAs($viewer)->get(route('admin.audit.index'))->assertForbidden();
        $this->get(route('admin.audit.show', $eventId))->assertForbidden();
    }
});
