<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AgentProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionLedgerService;
use App\Services\CollectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/WithdrawalFixtures.php';
require_once __DIR__.'/CollectionFixtures.php';
require_once __DIR__.'/CashExecutionFixtures.php';
require_once __DIR__.'/FeeFixtures.php';

/**
 * One recorded cash receipt for a fresh Agent and Customer. The projection is not rebuilt.
 *
 * @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: LedgerPostingGroup, 4: ThriftPlan, 5: string}
 */
function ledgerGapReceipt(int $slotAmountKobo = 200000, string $amountNgn = '2000.00', int $days = 1, int $startOffsetDays = 0): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture($days, $startOffsetDays, slotAmountKobo: $slotAmountKobo);
    $receipt = ledgerGapRecordReceipt($agent, $customer, $assignment, $plan, $today, $amountNgn);
    LedgerAccount::query()->where('code', 'unapplied_funds_ngn')->update(['mapping_status' => 'mapped']);

    return [$agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id), $plan, $today];
}

/** Record one more cash receipt on the received date (a date earlier than today needs the late reason supplied here). */
function ledgerGapRecordReceipt(User $agent, CustomerProfile $customer, $assignment, $plan, string $receivedDate, string $amountNgn, string $lateReason = ''): CollectionReceipt
{
    $collection = app(CollectionService::class);
    $customer->refresh();
    $assignment->refresh();
    $plan->refresh();
    $payload = collectionPayload($customer, $assignment, $plan, $receivedDate, $amountNgn);
    $payload['late_reason'] = $lateReason;
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];

    return $collection->record($agent, $customer, $payload);
}

/**
 * A second, independent active Agent with one currently assigned Customer and no financial history. The collection and
 * withdrawal fixtures share one fee-rule identity, so a second receipt owner cannot be built with them in the same test.
 *
 * @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment}
 */
function ledgerGapSecondCustomer(): array
{
    $agent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agentProfile->id,
        'assigned_by_user_id' => $agent->id, 'status' => CustomerAssignmentStatus::Current]);

    return [$agent, $customer, $assignment];
}

/** A group the projection cannot own, which makes every rebuild fail closed. */
function ledgerGapUnsupportedGroup(User $actor, ?int $customerId = null): LedgerPostingGroup
{
    return LedgerPostingGroup::create(['posting_reference' => 'TEST-GAP-'.Str::uuid(), 'idempotency_key' => 'gap-'.Str::uuid(),
        'payload_hash' => str_repeat('e', 64), 'source_type' => 'unsupported_event', 'source_id' => '1', 'event_type' => 'unsupported_event',
        'currency' => 'NGN', 'actor_user_id' => $actor->id, 'customer_profile_id' => $customerId, 'occurred_at' => now(), 'committed_at' => now()]);
}

/** @return array<string, int> */
function ledgerGapFreshSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp];
}

/** @param list<AdminPermission> $permissions */
function ledgerGapAdmin(array $permissions = []): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    if ($permissions !== []) {
        $admin->givePermissionTo(array_map(static fn (AdminPermission $permission): string => $permission->value, $permissions));
    }

    return $admin->fresh();
}

/**
 * Insert projection rows directly at the active version so that the read side can be exercised with many rows.
 *
 * @return list<string> the transaction references, oldest first
 */
function ledgerGapProjectionRows(CustomerProfile $customer, int $count, string $occurredOn, string $commitBase = '2026-10-01 08:00:00', int $secondsApart = 0): array
{
    $state = DB::table('ledger_projection_state')->where('id', 1)->first();
    $references = [];
    $offset = DB::table('ledger_transaction_references')->count();
    for ($index = 1; $index <= $count; $index++) {
        $reference = 'TXN-'.str_replace('-', '', $occurredOn).'-'.str_pad((string) (900000 + $offset + $index), 6, '0', STR_PAD_LEFT);
        $referenceId = DB::table('ledger_transaction_references')->insertGetId(['root_type' => 'gap_fixture', 'root_id' => (string) $index.'-'.Str::uuid(),
            'transaction_reference' => $reference, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_transaction_projections')->insert(['ledger_transaction_reference_id' => $referenceId,
            'projection_version' => (int) $state->active_version, 'customer_profile_id' => $customer->id, 'type' => 'contribution', 'status' => 'posted',
            'occurred_on' => $occurredOn, 'committed_at' => date('Y-m-d H:i:s', strtotime($commitBase) + ($index - 1) * $secondsApart),
            'timezone' => 'Africa/Lagos', 'currency' => 'NGN', 'gross_amount_kobo' => 100, 'savings_effect_kobo' => 100, 'fee_amount_kobo' => 0,
            'posting_group_count' => 1, 'source_max_group_id' => (int) $state->ledger_group_watermark, 'source_hash' => str_repeat('c', 64),
            'created_at' => now(), 'updated_at' => now()]);
        $references[] = $reference;
    }

    return $references;
}

/**
 * Confirm one remittance for the receipt's batch, posting Dr Business cash and Cr Agent receivable.
 *
 * @return array{0: User, 1: int, 2: LedgerPostingGroup}
 */
function ledgerGapRemittance(int $amountKobo = 200000): array
{
    $receipt = DB::table('collection_receipts')->orderBy('id')->first();
    $admin = ledgerGapAdmin([AdminPermission::ReconciliationManage]);
    $remittanceId = DB::table('cash_remittances')->insertGetId([
        'handoff_reference' => 'REM-'.Str::uuid(), 'receiving_location' => 'Business till', 'source_attestation' => 'Counted handoff',
        'collection_batch_id' => $receipt->collection_batch_id, 'agent_profile_id' => $receipt->recording_agent_profile_id,
        'confirmed_by_user_id' => $admin->id, 'amount_kobo' => $amountKobo, 'handoff_date' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $group = DB::transaction(function () use ($remittanceId, $receipt, $amountKobo, $admin): LedgerPostingGroup {
        $group = app(CollectionLedgerService::class)->postCashRemittance($remittanceId, $receipt->recording_agent_profile_id, $amountKobo, $admin);
        DB::table('cash_remittances')->where('id', $remittanceId)->update(['ledger_posting_group_id' => $group->id]);

        return $group;
    });

    return [$admin, $remittanceId, $group];
}

/** A snapshot of every authoritative ledger table, used to prove a refused action changed nothing. */
function ledgerGapSnapshot(): array
{
    $rows = [];
    foreach (['ledger_posting_groups', 'ledger_entries', 'ledger_transaction_references', 'ledger_transaction_projections',
        'ledger_projection_state', 'ledger_integrity_incidents', 'withdrawal_reservations', 'fee_obligation_entries'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

/**
 * One posted cash contribution that also settles a 500.00 external fee on the same receipt.
 *
 * @return array{0: User, 1: CustomerProfile, 2: mixed, 3: LedgerPostingGroup}
 */
function ledgerGapMixedReceipt(): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(1, slotAmountKobo: 200000);
    $obligation = reportFeeObligation($agent, $customer, 50000);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);

    return [$agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id)];
}

/**
 * A funded cash withdrawal paid out and acknowledged by the Customer, so that it is a posted withdrawal group.
 *
 * @return array{0: User, 1: CustomerProfile, 2: object, 3: LedgerPostingGroup}
 */
function ledgerGapPostedWithdrawal(object $test): array
{
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    [$execution, $payload] = startCashFixture($test, $admin, $withdrawal);
    $test->post(route('withdrawals.cash.start', $withdrawal), $payload)->assertRedirect();
    $test->post(route('cash-executions.handoff', $execution), ['evidence' => 'Cash handed to the verified Customer.', 'confirmed' => true])->assertRedirect();
    $test->travel(5)->seconds();
    $test->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();

    return [$admin, $customer, $withdrawal->fresh(), LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole()];
}

/** A group written straight into the ledger for a Customer, used where only the subsidiary balance matters. */
function ledgerGapLiability(CustomerProfile $customer, int $kobo, int $agentProfileId): LedgerPostingGroup
{
    $group = LedgerPostingGroup::create(['posting_reference' => 'COL-GAP-'.Str::uuid(), 'idempotency_key' => 'gap-liability-'.Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'source_type' => 'gap_fixture', 'source_id' => (string) Str::uuid(), 'event_type' => 'cash_contribution',
        'currency' => 'NGN', 'customer_profile_id' => $customer->id, 'occurred_at' => now(), 'occurred_on' => now()->toDateString(),
        'business_timezone' => 'Africa/Lagos', 'schema_version' => 1, 'committed_at' => now()]);
    foreach ([[LedgerAccountCode::AgentReceivable, LedgerEntrySide::Debit, $agentProfileId], [LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Credit, null]] as $index => [$code, $side, $agent]) {
        LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
            'ledger_account_id' => LedgerAccount::query()->where('code', $code->value)->value('id'), 'side' => $side, 'amount_kobo' => $kobo,
            'customer_profile_id' => $code === LedgerAccountCode::CustomerSavingsLiability ? $customer->id : null, 'agent_profile_id' => $agent]);
    }

    return $group;
}

/** @return array<string, mixed> the projection rows of the active version without identifiers or timestamps */
function ledgerGapProjectionContent(): array
{
    $version = (int) DB::table('ledger_projection_state')->value('active_version');

    return DB::table('ledger_transaction_projections as p')->join('ledger_transaction_references as r', 'r.id', '=', 'p.ledger_transaction_reference_id')
        ->where('p.projection_version', $version)->orderBy('r.transaction_reference')
        ->get(['r.transaction_reference', 'p.type', 'p.status', 'p.occurred_on', 'p.committed_at', 'p.gross_amount_kobo', 'p.savings_effect_kobo',
            'p.fee_amount_kobo', 'p.posting_group_count', 'p.source_hash', 'p.source_max_group_id'])->map(fn (object $row): array => (array) $row)->all();
}
