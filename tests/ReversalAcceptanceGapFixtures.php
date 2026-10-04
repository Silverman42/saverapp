<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\ReversalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/WithdrawalFixtures.php';
require_once __DIR__.'/CollectionFixtures.php';

/**
 * A recorded 2000.00 receipt whose savings posting group is an eligible correction original.
 *
 * @return array{agent: User, customer: CustomerProfile, assignment: CustomerAssignment, plan: mixed, original: LedgerPostingGroup, receipt: mixed}
 */
function revGapReceipt(int $days = 1): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture($days, slotAmountKobo: 200000);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->update(['mapping_status' => 'mapped']);

    return ['agent' => $agent, 'customer' => $customer->refresh(), 'assignment' => $assignment, 'plan' => $plan,
        'original' => LedgerPostingGroup::query()->findOrFail($receipt->savings_posting_group_id), 'receipt' => $receipt];
}

/** @return array<string, mixed> */
function revGapQuote(object $test, User $agent, LedgerPostingGroup $original): array
{
    return $test->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
}

/**
 * @param  array<string, mixed>  $quote
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function revGapSubmission(array $quote, array $overrides = []): array
{
    return [...[
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'Actual tender remains controlled by the original Agent.',
        'customer_explanation' => 'The erroneous original is corrected with its required linked entries.',
        'evidence_text' => 'Original amount and agreed terms verified.', 'confirmed' => true,
    ], ...$overrides];
}

/** Submits one valid correction request as the Agent and returns the stored request. */
function revGapSubmit(object $test, User $agent, LedgerPostingGroup $original, array $overrides = []): ReversalRequest
{
    $quote = revGapQuote($test, $agent, $original);
    $test->actingAs($agent)->post(route('reversals.store', $original->posting_reference), revGapSubmission($quote, $overrides))
        ->assertRedirect()->assertSessionHasNoErrors();

    return ReversalRequest::query()->where('original_posting_group_id', $original->id)->latest('id')->firstOrFail();
}

function revGapAdmin(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);

    return $admin;
}

/** @return array<string, int> */
function revGapFreshSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp];
}

/** @param array<string, mixed> $overrides */
function revGapDecision(object $test, User $admin, ReversalRequest $request, string $action, array $overrides = []): TestResponse
{
    $data = ['attempt_reference' => (string) Str::uuid(), 'version' => $request->fresh()->version,
        'decision_reason' => 'Reviewed original custody evidence.', 'confirmed' => true];
    if ($action === 'approve') {
        $data['preview_fingerprint'] = app(ReversalService::class)->reviewPreview($admin, $request->fresh())['preview_fingerprint'];
    }

    return $test->actingAs($admin)->withSession(revGapFreshSession())->postJson(route('reversals.'.$action, $request), [...$data, ...$overrides]);
}

/** Moves the Customer to the given operational status without touching other state. */
function revGapSetCustomerStatus(CustomerProfile $customer, string $status): void
{
    DB::table('customer_profiles')->where('id', $customer->id)->update(['operational_status' => $status]);
}

/** Hands the Customer over to a new active Agent through the real reassignment service. */
function revGapReassign(CustomerProfile $customer): array
{
    $successor = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $successor->id]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CustomersReassign, AdminPermission::ReversalsReview]);
    test()->actingAs($admin)->withSession(revGapFreshSession());
    $handover = app(CustomerReassignmentService::class);
    $preview = $handover->preview($admin, $customer, $profile->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'target_agent_id' => $profile->id, 'preview_token' => $preview['preview_token'], 'confirmed' => true,
        'reason' => 'Transfer receipt follow-up responsibility.', 'customer_explanation' => 'Your service contact changed.']);

    return [$successor, $profile, $admin];
}

/** A pending/terminal request with its own original posting group, bypassing owner contracts. */
function revGapRawRequest(User $agent, CustomerProfile $customer, CustomerAssignment $assignment, string $state = 'pending_review'): ReversalRequest
{
    $original = LedgerPostingGroup::create([
        'posting_reference' => 'COL-GAP-'.Str::upper(Str::random(12)), 'idempotency_key' => 'rev-gap-'.Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'source_type' => 'collection_receipt', 'source_id' => (string) (900000 + LedgerPostingGroup::query()->count()),
        'event_type' => 'cash_savings', 'currency' => 'NGN', 'actor_user_id' => $agent->id,
        'customer_profile_id' => $customer->id, 'occurred_at' => now(), 'committed_at' => now(),
    ]);
    $terminal = $state !== 'pending_review';

    return ReversalRequest::create([
        'reversal_id' => (string) Str::uuid(), 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $original->id, 'live_original_posting_group_id' => $terminal ? null : $original->id,
        'requested_by_user_id' => $agent->id, 'initiating_agent_profile_id' => $assignment->agent_profile_id,
        'assignment_id' => $assignment->id, 'state' => $state, 'version' => 1,
        'reason_category' => 'duplicate_posting', 'internal_reason' => 'Duplicate receipt investigation',
        'customer_explanation' => 'We are reviewing a receipt correction.', 'evidence_text' => 'Cash register shows one tender.',
        'dependency_fingerprint' => str_repeat('b', 64), 'dependency_snapshot' => ['summary' => [], 'dependencies' => []],
        'original_amount_kobo' => 200000, 'currency' => 'NGN',
    ]);
}

/** @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment} */
function revGapAgentWithCustomer(): array
{
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id,
        'agent_profile_id' => $profile->id, 'assigned_by_user_id' => $agent->id, 'status' => CustomerAssignmentStatus::Current]);

    return [$agent, $customer, $assignment];
}

/** @return array<string, int> row counts of every table a rejected request must leave untouched */
function revGapEffectCounts(): array
{
    return collect(['reversal_requests', 'reversal_attempts', 'reversal_events', 'reversal_notification_intents',
        'ledger_posting_groups', 'ledger_entries', 'financial_workflow_supplements', 'canonical_audit_events'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
}
