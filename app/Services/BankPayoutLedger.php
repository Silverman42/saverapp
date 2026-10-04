<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\BusinessProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\WithdrawalRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Custody-only postings for a bank payout after the withdrawal itself posted. Neither movement touches Customer liability.
 */
class BankPayoutLedger
{
    public function __construct(private WithdrawalPostingService $accounts) {}

    /** Provider settlement: the transferred amount leaves business bank funding and clears the payout payable. */
    public function recordSettlement(BankPayoutAttempt $attempt, WithdrawalRequest $withdrawal, CarbonInterface $settledAt): LedgerPostingGroup
    {
        $clearing = $this->accounts->account(LedgerAccountCode::PayoutClearing, LedgerAccountClass::PayoutClearing, LedgerEntrySide::Credit);
        $bank = $this->accounts->account(LedgerAccountCode::BusinessBank, LedgerAccountClass::Asset, LedgerEntrySide::Debit);

        return $this->post($attempt, $withdrawal, 'bank_payout_settlement', 'bank_payout_attempt', (string) $attempt->id,
            'bank-settlement-'.$attempt->attempt_reference, $attempt->start_payload_hash, $settledAt, [[$clearing, LedgerEntrySide::Debit], [$bank, LedgerEntrySide::Credit]],
            $attempt->amount_kobo, ['attempt_reference' => $attempt->attempt_reference, 'provider_reference' => $attempt->provider_reference]);
    }

    /**
     * Provider return after the withdrawal posted. The money is back in business custody pending an independently approved compensation:
     * it returns to the payout payable if it never settled, otherwise to business bank funding.
     */
    public function recordReturn(BankPayoutReturn $return, BankPayoutAttempt $attempt, WithdrawalRequest $withdrawal, CarbonInterface $returnedAt): LedgerPostingGroup
    {
        $settled = $attempt->settlement_posting_group_id !== null;
        $source = $settled
            ? $this->accounts->account(LedgerAccountCode::BusinessBank, LedgerAccountClass::Asset, LedgerEntrySide::Debit)
            : $this->accounts->account(LedgerAccountCode::PayoutClearing, LedgerAccountClass::PayoutClearing, LedgerEntrySide::Credit);
        $recovery = $this->accounts->account(LedgerAccountCode::CashRecoveryClearing, LedgerAccountClass::CashRecoveryClearing, LedgerEntrySide::Credit);

        return $this->post($attempt, $withdrawal, 'bank_payout_return', 'bank_payout_return', (string) $return->id,
            'bank-return-'.$return->return_reference, $return->payload_hash, $returnedAt, [[$source, LedgerEntrySide::Debit], [$recovery, LedgerEntrySide::Credit]],
            $return->amount_kobo, ['attempt_reference' => $attempt->attempt_reference, 'return_reference' => $return->return_reference, 'settled' => $settled]);
    }

    /**
     * @param  list<array{0: LedgerAccount, 1: LedgerEntrySide}>  $lines
     * @param  array<string, mixed>  $metadata
     */
    private function post(BankPayoutAttempt $attempt, WithdrawalRequest $withdrawal, string $eventType, string $sourceType, string $sourceId, string $idempotencyKey,
        string $payloadHash, CarbonInterface $occurredAt, array $lines, int $amount, array $metadata): LedgerPostingGroup
    {
        $timezone = BusinessProfile::current()->timezone;
        app(FinancialPeriodService::class)->assertOpen($occurredAt->setTimezone($timezone)->toDateString(), $timezone, true);
        $group = LedgerPostingGroup::create(['posting_reference' => Str::upper(Str::before($eventType, '_')).'-'.Str::uuid(),
            'idempotency_key' => $idempotencyKey, 'payload_hash' => $payloadHash, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'event_type' => $eventType, 'currency' => 'NGN', 'actor_user_id' => $attempt->executor_user_id, 'approver_user_id' => $withdrawal->reviewed_by_user_id,
            'customer_profile_id' => $withdrawal->customer_profile_id, 'thrift_plan_id' => $withdrawal->thrift_plan_id,
            'occurred_at' => $occurredAt, 'occurred_on' => $occurredAt->setTimezone($timezone)->toDateString(),
            'business_timezone' => $timezone, 'schema_version' => 1, 'correlation_id' => $attempt->attempt_reference,
            'committed_at' => now(), 'metadata' => $metadata]);
        foreach ($lines as $index => [$account, $side]) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1, 'ledger_account_id' => $account->id,
                'side' => $side, 'amount_kobo' => $amount, 'customer_profile_id' => $withdrawal->customer_profile_id,
                'thrift_plan_id' => $withdrawal->thrift_plan_id]);
        }

        return $group;
    }
}
