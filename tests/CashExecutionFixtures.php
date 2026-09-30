<?php

use App\Enums\AdminPermission;
use App\Models\CashExecution;
use App\Models\FinancialPeriod;
use App\Models\User;
use App\Services\CollectionLedgerService;
use App\Services\LedgerTransactionProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/WithdrawalFixtures.php';

function cashPaymentFixture(bool $funded = true): array
{
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CashExecute, AdminPermission::WithdrawalsReview]);
    $withdrawal->update(['state' => 'approved', 'reviewed_by_user_id' => $admin->id, 'approved_at' => now()]);
    FinancialPeriod::factory()->create();
    if ($funded) {
        $receipt = DB::table('collection_receipts')->where('customer_profile_id', $customer->id)->first();
        $remittance = DB::table('cash_remittances')->insertGetId([
            'handoff_reference' => 'REM-'.Str::uuid(), 'receiving_location' => 'Business till', 'source_attestation' => 'Counted handoff', 'collection_batch_id' => $receipt->collection_batch_id,
            'agent_profile_id' => $receipt->recording_agent_profile_id, 'confirmed_by_user_id' => $admin->id,
            'amount_kobo' => 100000, 'handoff_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::transaction(function () use ($remittance, $receipt, $admin): void {
            $group = app(CollectionLedgerService::class)->postCashRemittance($remittance, $receipt->recording_agent_profile_id, 100000, $admin);
            DB::table('cash_remittances')->where('id', $remittance)->update(['ledger_posting_group_id' => $group->id]);
        });
    }
    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$admin, $customer, $plan, $withdrawal];
}

function cashSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

function startCashFixture(object $test, User $admin, object $withdrawal): array
{
    $payload = ['execution_reference' => (string) Str::uuid(), 'version' => $withdrawal->version,
        'evidence' => 'Business till; verified Customer personally present.', 'confirmed' => true];
    $test->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.cash.start', $withdrawal), $payload)->assertRedirect();

    return [CashExecution::query()->sole(), $payload];
}
