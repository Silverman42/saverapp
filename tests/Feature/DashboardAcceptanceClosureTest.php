<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'Africa/Lagos'));
});

/** @return array{0: User, 1: CustomerProfile, 2: mixed, 3: mixed, 4: string, 5: CollectionReceipt} */
function dshClosurePostedFixture(): array
{
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3, slotAmountKobo: 300000);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$agent, $customer->refresh(), $assignment, $plan, $today, $receipt];
}

/** @param array<string, mixed> $section */
function dshClosureMetric(array $section, string $code): mixed
{
    return collect($section['metrics'])->firstWhere('code', $code)['value'] ?? null;
}

test('DSH-AC-014: each Customer status keeps exact own history while only Active work can record collections', function (CustomerStatus $status, bool $inActiveCount): void {
    [$agent, $customer] = dshClosurePostedFixture();
    $customer->update(['operational_status' => $status]);

    $this->actingAs($customer->user)->get(route('customer.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.role', 'customer')
        ->where('dashboard.sections.savings.status', 'Current')
        ->where('dashboard.sections.activity.total', 1)
        ->where('dashboard.sections.activity.rows.0.amount', '₦2,000.00'));
    $customerSections = $this->get(route('customer.dashboard'))->viewData('page')['props']['dashboard']['sections'];
    expect(dshClosureMetric($customerSections['savings'], 'customer_liability'))->toBe(200000)
        ->and(dshClosureMetric($customerSections['savings'], 'available_savings'))->toBe(200000);

    $agentSections = $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk()->viewData('page')['props']['dashboard']['sections'];
    $work = collect($agentSections['schedule']['rows'])->firstWhere('customer_id', $customer->customer_id);
    expect(dshClosureMetric($agentSections['savings'], 'customer_liability'))->toBe(200000);
    if ($inActiveCount) {
        expect($work['can_record_cash'])->toBe($status === CustomerStatus::Active);
    } else {
        expect($work)->toBeNull();
    }

    $admin = User::factory()->admin()->create();
    $adminSections = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['dashboard']['sections'];
    expect(dshClosureMetric($adminSections['savings'], 'customer_liability'))->toBe(200000);
})->with([
    'active' => [CustomerStatus::Active, true],
    'inactive' => [CustomerStatus::Inactive, true],
    'restricted' => [CustomerStatus::Restricted, true],
    'archived' => [CustomerStatus::Archived, false],
]);

test('DSH-AC-014: an Invited Customer has no dashboard session while Agent and Admin portfolios still count them', function (): void {
    [$agent, $customer] = dshClosurePostedFixture();
    $customer->user->update(['account_state' => AccountState::Invited]);

    $this->actingAs($customer->user->fresh())->get(route('customer.dashboard'))->assertRedirect(route('login'));

    $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.metrics.0.value', 200000));
});

test('DSH-AC-014: an unknown savings source is unavailable for every Customer status rather than zero', function (CustomerStatus $status): void {
    [, $customer] = dshClosurePostedFixture();
    $customer->update(['operational_status' => $status]);
    DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable']);

    $this->actingAs($customer->user)->get(route('customer.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.status', 'Unavailable')
        ->where('dashboard.sections.savings.metrics', [])
        ->where('dashboard.sections.activity.status', 'Unavailable')
        ->where('dashboard.sections.portfolio.status', 'Current'));
})->with([CustomerStatus::Active, CustomerStatus::Inactive, CustomerStatus::Restricted, CustomerStatus::Archived]);

test('DSH-AC-016: an Admin without granular grants reads summaries but no protected task, incident or collection action', function (): void {
    [$agent, $customer, $assignment, $plan, $today, $receipt] = dshClosurePostedFixture();
    dshClosureReviewWork($agent, $customer, $assignment, $receipt);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $sections = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['dashboard']['sections'];

    expect(dshClosureMetric($sections['requests'], 'pending_withdrawal_reviews'))->toBe(1)
        ->and(dshClosureMetric($sections['requests'], 'pending_reversal_reviews'))->toBe(1)
        ->and($sections['requests']['rows'])->toBe([])
        ->and($sections)->not->toHaveKey('incidents')
        ->and(json_encode($sections, JSON_THROW_ON_ERROR))->not->toContain('Private evidence')->not->toContain('Private reason');
    $this->get(route('customers.collections.create', $customer))->assertNotFound();
    $this->post(route('customers.collections.store', $customer), [...collectionPayload($customer, $assignment, $plan, $today, '2000.00'), 'preview_fingerprint' => str_repeat('a', 64)])->assertNotFound();
    $this->get(route('reversals.show', 'REV-DSH-CLOSE-1'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reversal.evidence_text', null)->where('reversal.internal_reason', null));
    $this->get(route('customers.plans.create', $customer))->assertNotFound();
    $transition = ['attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version, 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason' => 'Admin plan attempt', 'customer_explanation' => 'Plan change attempt.'];
    expect($this->post(route('plans.pause', $plan), $transition)->status())->toBeIn([403, 404])
        ->and($this->post(route('plans.cancel', $plan), [...$transition, 'attempt_reference' => (string) Str::uuid()])->status())->toBeIn([403, 404]);
    expect($plan->fresh()->status->value)->toBe('active');
    $this->assertDatabaseCount('collection_receipts', 1);
});

test('DSH-AC-016: each independent review grant reveals only its own owner route', function (AdminPermission $permission, string $visibleType, bool $incidents): void {
    [$agent, $customer, $assignment, , , $receipt] = dshClosurePostedFixture();
    [$withdrawal, $reversal] = dshClosureReviewWork($agent, $customer, $assignment, $receipt);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo($permission->value);

    $sections = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['dashboard']['sections'];
    $rows = collect($sections['requests']['rows']);

    expect($rows->pluck('type')->unique()->values()->all())->toBe($visibleType === '' ? [] : [$visibleType])
        ->and(array_key_exists('incidents', $sections))->toBe($incidents);
    if ($visibleType === 'withdrawal') {
        expect($rows->sole()['href'])->toBe(route('withdrawals.show', $withdrawal->withdrawal_id));
        $this->get(route('reversals.show', $reversal->reversal_id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reversal.evidence_text', null)->where('reversal.internal_reason', null));
    }
    if ($visibleType === 'reversal') {
        expect($rows->sole()['href'])->toBe(route('reversals.show', $reversal->reversal_id));
        $this->get(route('reversals.show', $reversal->reversal_id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reversal.evidence_text', 'Private evidence'));
    }
})->with([
    'withdrawal review' => [AdminPermission::WithdrawalsReview, 'withdrawal', true],
    'reversal review' => [AdminPermission::ReversalsReview, 'reversal', false],
    'reconciliation' => [AdminPermission::ReconciliationManage, '', true],
]);

/** @return array{0: WithdrawalRequest, 1: ReversalRequest} */
function dshClosureReviewWork(User $agent, CustomerProfile $customer, mixed $assignment, CollectionReceipt $receipt): array
{
    $plan = ThriftPlan::query()->where('customer_profile_id', $customer->id)->sole();
    $withdrawal = WithdrawalRequest::create([
        'withdrawal_id' => 'WDL-DSH-CLOSE-1', 'customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id,
        'initiating_agent_profile_id' => $agent->agentProfile->id, 'assignment_id' => $assignment->id,
        'submitted_by_user_id' => $agent->id, 'fee_snapshot_id' => $plan->currentTermsRevision()->fee_snapshot_id,
        'type' => 'partial', 'state' => 'pending_review', 'held' => true, 'gross_amount_kobo' => 30000, 'fee_amount_kobo' => 0,
        'net_amount_kobo' => 30000, 'currency' => 'NGN', 'method' => 'cash', 'destination_reference' => 'PRIVATE-BANK',
        'destination_mask' => 'Private destination', 'reason' => 'Private reason', 'internal_notes' => 'Private evidence',
        'customer_version' => 1, 'assignment_version' => 1, 'plan_version' => 1, 'business_version' => 1,
        'method_version' => 1, 'version' => 1, 'submitted_at' => now(), 'deadline_at' => now()->addDays(7),
    ]);
    $group = $receipt->savings_posting_group_id;
    $reversal = ReversalRequest::create([
        'reversal_id' => 'REV-DSH-CLOSE-1', 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $group, 'live_original_posting_group_id' => $group,
        'requested_by_user_id' => $agent->id, 'initiating_agent_profile_id' => $agent->agentProfile->id,
        'assignment_id' => $assignment->id, 'state' => 'pending_review', 'version' => 1,
        'reason_category' => 'duplicate_posting', 'internal_reason' => 'Private reason',
        'customer_explanation' => 'A receipt is being reviewed.', 'evidence_text' => 'Private evidence',
        'dependency_fingerprint' => str_repeat('a', 64), 'dependency_snapshot' => [],
        'original_amount_kobo' => 200000, 'currency' => 'NGN',
    ]);

    return [$withdrawal, $reversal];
}
