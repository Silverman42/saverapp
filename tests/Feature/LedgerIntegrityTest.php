<?php

use App\Enums\AdminPermission;
use App\Jobs\ProjectAuditEvent;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\ReversalService;
use App\Services\StatementPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';

/** @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: LedgerPostingGroup} */
function ledgerReceiptFixture(): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1, slotAmountKobo: 200000);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', 'unapplied_funds_ngn')->update(['mapping_status' => 'mapped']);

    return [$agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id)];
}

function unsupportedLedgerGroup(User $actor, ?int $customerId = null): void
{
    LedgerPostingGroup::create(['posting_reference' => 'TEST-UNSUPPORTED-'.Str::uuid(), 'idempotency_key' => 'unsupported-'.Str::uuid(),
        'payload_hash' => str_repeat('e', 64), 'source_type' => 'unsupported_event', 'source_id' => '1', 'event_type' => 'unsupported_event',
        'currency' => 'NGN', 'actor_user_id' => $actor->id, 'customer_profile_id' => $customerId, 'occurred_at' => now(), 'committed_at' => now()]);
}

test('a failed command rebuild keeps its durable incident and fails closed', function (): void {
    $actor = User::factory()->agent()->create();
    unsupportedLedgerGroup($actor);

    $this->artisan('ledger:rebuild-transactions')->assertFailed();

    expect(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(1)
        ->and(DB::table('ledger_projection_state')->value('status'))->toBe('unavailable');
});

test('a lagging ledger keeps verified history readable but blocks balances', function (): void {
    [$agent, $customer, , $original] = ledgerReceiptFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reads = app(LedgerTransactionReadService::class);
    $reference = $reads->search($agent, [])['data'][0]['reference'];
    expect($reads->balance($agent, $customer)['status'])->toBe('ready');

    unsupportedLedgerGroup($agent);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    $result = $reads->search($agent, []);
    expect($reads->state()['status'])->toBe('stale')->and($result['status'])->toBe('ready')->and($result['stale'])->toBeTrue()
        ->and($result['data'])->toHaveCount(1)->and($reads->detail($agent, $reference)['state']['status'])->toBe('stale')
        ->and($reads->balance($agent, $customer)['status'])->toBe('unavailable');
    $this->actingAs($agent)->get(route('transactions.index'))->assertOk();
});

test('the scheduled catch-up rebuilds only when the projection is behind', function (): void {
    [$agent, $customer] = ledgerReceiptFixture();
    $this->artisan('ledger:rebuild-transactions --if-stale')->assertSuccessful();
    $version = DB::table('ledger_projection_state')->value('active_version');

    $this->artisan('ledger:rebuild-transactions --if-stale')->expectsOutput('The transaction projection is current.')->assertSuccessful();
    expect(DB::table('ledger_projection_state')->value('active_version'))->toBe($version);
});

test('an incident is recovered by a clean rebuild but resolved only by an authorized person', function (): void {
    $actor = User::factory()->agent()->create();
    unsupportedLedgerGroup($actor);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);
    $reference = DB::table('ledger_integrity_incidents')->value('incident_reference');
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $payload = ['note' => 'Investigated the unsupported group and removed its source.', 'confirmed' => true];

    $this->actingAs($admin)->withSession($session)->post(route('ledger.incidents.resolve', $reference), $payload)->assertStatus(409);
    LedgerPostingGroup::query()->delete();
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_integrity_incidents')->value('status'))->toBe('recovered');

    $plain = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($plain)->withSession($session)->post(route('ledger.incidents.resolve', $reference), $payload)->assertForbidden();
    $this->actingAs($admin)->post(route('ledger.incidents.resolve', $reference), ['note' => 'x'])->assertSessionHasErrors('confirmed');
    $this->actingAs($admin)->withSession($session)->get(route('transactions.index'))->assertInertia(fn ($page) => $page->has('incidents', 1)->where('can_resolve_incidents', true));
    $this->post(route('ledger.incidents.resolve', $reference), $payload)->assertRedirect();
    $this->post(route('ledger.incidents.resolve', $reference), $payload)->assertRedirect();

    $row = DB::table('ledger_integrity_incidents')->first();
    expect($row->status)->toBe('resolved')->and($row->resolved_by_user_id)->toBe($admin->id)->and($row->resolution_note)->toBe($payload['note'])
        ->and(DB::table('canonical_audit_events')->where('event_type', 'ledger.integrity_incident_resolved')->count())->toBe(1);
});

test('a retired account refuses new postings while its history stays readable', function (): void {
    [$agent, $customer, $assignment, $original] = ledgerReceiptFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $groups = LedgerPostingGroup::query()->count();
    LedgerAccount::query()->where('code', 'agent_receivable_ngn')->update(['retired_at' => now()->subMinute()]);

    $account = LedgerAccount::query()->where('code', 'agent_receivable_ngn')->firstOrFail();
    expect(fn () => DB::transaction(function () use ($account, $agent): void {
        $group = LedgerPostingGroup::create(['posting_reference' => 'TEST-RETIRED-'.Str::uuid(), 'idempotency_key' => 'retired-'.Str::uuid(),
            'payload_hash' => str_repeat('f', 64), 'source_type' => 'test_retired', 'source_id' => '1', 'event_type' => 'test_retired',
            'currency' => 'NGN', 'actor_user_id' => $agent->id, 'occurred_at' => now(), 'committed_at' => now()]);
        LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => 1, 'ledger_account_id' => $account->id, 'side' => 'debit', 'amount_kobo' => 1]);
    }))->toThrow(ConflictHttpException::class, 'retired');

    expect(LedgerPostingGroup::query()->count())->toBe($groups)
        ->and(app(LedgerTransactionReadService::class)->search($agent, [])['data'])->toHaveCount(1)
        ->and(app(LedgerTransactionProjectionService::class)->rebuild()['groups'])->toBe($groups);
});

test('a full reversal turns the original Reversed and links both ways without editing it', function (): void {
    [$agent, $customer, $assignment, $original] = ledgerReceiptFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $request = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $customer->version, 'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'Controlled by the original Agent.', 'customer_explanation' => 'The original is corrected.',
        'evidence_text' => 'Verified.', 'confirmed' => true]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($admin, $request);
    $before = $original->entries()->orderBy('id')->get()->map->getAttributes()->all();
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];

    $this->actingAs($admin)->withSession($session)->post(route('reversals.approve', $request), ['attempt_reference' => (string) Str::uuid(),
        'version' => $request->version, 'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Reviewed.', 'confirmed' => true])->assertRedirect();

    $reads = app(LedgerTransactionReadService::class);
    $rows = collect($reads->search($agent, [])['data'])->keyBy('type');
    $originalDetail = $reads->detail($agent, $rows['contribution']['reference']);
    $reversalDetail = $reads->detail($agent, $rows['reversal']['reference']);
    expect($rows['contribution']['status'])->toBe('reversed')->and($rows['reversal']['status'])->toBe('posted')
        ->and($originalDetail['compensation_reference'])->toBe($rows['reversal']['reference'])
        ->and($reversalDetail['original_reference'])->toBe($rows['contribution']['reference'])
        ->and($reversalDetail['actors']['reviewed_by'])->toBe($admin->name)
        ->and($original->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(collect($reads->search($agent, [])['data'])->firstWhere('type', 'contribution')['status'])->toBe('reversed')
        ->and(ReversalRequest::query()->sole()->state)->toBe('approved_posted');
    $customerView = $reads->detail($customer->user, $rows['reversal']['reference']);
    expect($customerView['actors'])->toBe([]);
});

test('the statement shows per-type totals and unpaid fees separately and renders the same figures in the PDF template', function (): void {
    [$agent, $customer] = ledgerReceiptFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $timezone = BusinessProfile::current()->timezone;
    $preview = app(StatementPreviewService::class)->preview($agent, $customer, now($timezone)->startOfMonth()->toDateString(), now($timezone)->toDateString(), $timezone);

    expect($preview['status'])->toBe('ready')->and($preview['type_totals'])->toHaveCount(1)->and($preview['type_totals'][0]['type'])->toBe('contribution')
        ->and(array_sum(array_column($preview['type_totals'], 'savings_effect_kobo')))->toBe($preview['activity_kobo'])
        ->and($preview['unpaid_fees_kobo'])->toBe(0)->and($preview['closing_kobo'])->toBe(200000);
    $html = view('financial-document', ['artifact' => (object) ['kind' => 'statement', 'artifact_reference' => 'ART-TEST'],
        'manifest' => ['business_name' => 'Test Business', 'captured_at' => 'now', 'snapshot_hash' => 'hash', 'supersedes_reference' => null],
        'snapshot' => [...$preview, 'customer_name' => 'Test', 'customer_id' => $customer->customer_id]])->render();
    expect($html)->toContain('Closing NGN 2000.00')->and($html)->toContain('contribution')->and($html)->toContain('available savings NGN')
        ->and($html)->toContain('unpaid fees NGN 0.00')->and($html)->toContain('reserved for pending withdrawals NGN 0.00');
});

test('a reservation outage withholds only the availability figure and never shows zero', function (): void {
    [$agent, $customer] = ledgerReceiptFixture();
    $plan = $customer->thriftPlans()->firstOrFail();
    app(LedgerTransactionProjectionService::class)->rebuild();
    DB::table('withdrawal_reservations')->insert(['customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id,
        'owner_reference' => 'WDL-DAMAGED', 'gross_amount_kobo' => 900000000, 'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $timezone = BusinessProfile::current()->timezone;

    $preview = app(StatementPreviewService::class)->preview($agent, $customer, now($timezone)->startOfMonth()->toDateString(), now($timezone)->toDateString(), $timezone);

    expect($preview['status'])->toBe('ready')->and($preview['current_available_kobo'])->toBeNull()->and($preview['current_reserved_kobo'])->toBeNull()
        ->and($preview['closing_kobo'])->toBe(200000);
});

test('the interactive statement preview is a read that takes no row locks', function (): void {
    [$agent, $customer] = ledgerReceiptFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    Queue::fake([ProjectAuditEvent::class]);

    $this->actingAs($agent)->get(route('customers.statements.preview', $customer->customer_id))->assertOk();

    $locks = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'for update'));
    expect($locks->reject(fn (string $sql): bool => str_contains($sql, 'audit_projection_state'))->all())->toBe([]);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('a denied transaction read is audited without revealing whether the record exists', function (): void {
    [$agent, $customer] = ledgerReceiptFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reference = app(LedgerTransactionReadService::class)->search($agent, [])['data'][0]['reference'];
    $stranger = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    AgentProfile::factory()->active()->create(['user_id' => $stranger->id]);

    $this->actingAs($stranger)->get(route('transactions.show', $reference))->assertNotFound();
    $this->get(route('transactions.show', 'TXN-19990101-000001'))->assertNotFound();

    expect(DB::table('canonical_audit_events')->where('event_type', 'ledger.transaction_viewed')->count())->toBe(2);
});
