<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The single balanced posting boundary for a definitively paid withdrawal, shared by every payout rail.
 * G = P + F + D: liability is debited once by G; the rail's payout account, fee income and the deduction destination are credited.
 */
class WithdrawalPostingService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function post(
        WithdrawalRequest $withdrawal,
        LedgerAccount $payoutAccount,
        string $eventType,
        string $postingReference,
        string $idempotencyKey,
        string $payloadHash,
        CarbonInterface $occurredAt,
        int $executorUserId,
        string $correlationId,
        array $metadata,
        User $actor,
    ): LedgerPostingGroup {
        $liability = $this->account(LedgerAccountCode::CustomerSavingsLiability, LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit);
        $fee = $withdrawal->fee_amount_kobo > 0 ? $this->account(LedgerAccountCode::FeeIncome, LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit) : null;
        $deduction = $withdrawal->deduction_amount_kobo > 0
            ? $this->account(LedgerAccountCode::OtherDeductionDestination, LedgerAccountClass::OtherDeductionDestination, LedgerEntrySide::Credit) : null;
        if ($withdrawal->gross_amount_kobo !== $withdrawal->net_amount_kobo + $withdrawal->fee_amount_kobo + $withdrawal->deduction_amount_kobo) {
            throw new ConflictHttpException('The withdrawal components do not equal the gross debit.');
        }
        $timezone = BusinessProfile::current()->timezone;
        app(FinancialPeriodService::class)->assertOpen($occurredAt->setTimezone($timezone)->toDateString(), $timezone, true);
        $obligation = $this->withdrawalFee($withdrawal, $actor);
        $group = LedgerPostingGroup::create([
            'posting_reference' => $postingReference, 'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash, 'source_type' => 'withdrawal', 'source_id' => (string) $withdrawal->id,
            'event_type' => $eventType, 'currency' => 'NGN', 'actor_user_id' => $executorUserId, 'approver_user_id' => $withdrawal->reviewed_by_user_id,
            'customer_profile_id' => $withdrawal->customer_profile_id, 'thrift_plan_id' => $withdrawal->thrift_plan_id,
            'occurred_at' => $occurredAt, 'occurred_on' => $occurredAt->setTimezone($timezone)->toDateString(),
            'business_timezone' => $timezone, 'schema_version' => 1, 'correlation_id' => $correlationId,
            'committed_at' => now(), 'metadata' => $metadata,
        ]);
        $lines = [[$liability, LedgerEntrySide::Debit, $withdrawal->gross_amount_kobo], [$payoutAccount, LedgerEntrySide::Credit, $withdrawal->net_amount_kobo]];
        if ($fee !== null) {
            $lines[] = [$fee, LedgerEntrySide::Credit, $withdrawal->fee_amount_kobo];
        }
        if ($deduction !== null) {
            $lines[] = [$deduction, LedgerEntrySide::Credit, $withdrawal->deduction_amount_kobo];
        }
        foreach ($lines as $index => [$account, $side, $amount]) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                'ledger_account_id' => $account->id, 'side' => $side, 'amount_kobo' => $amount,
                'customer_profile_id' => $withdrawal->customer_profile_id,
                'thrift_plan_id' => $account->id === $liability->id ? $withdrawal->thrift_plan_id : null,
                'fee_obligation_id' => $account->id === $fee?->id ? $obligation?->id : null]);
        }
        if ($obligation !== null) {
            FeeObligationEntry::create(['fee_obligation_id' => $obligation->id, 'entry_type' => FeeObligationEntryType::Settlement,
                'amount_kobo' => $withdrawal->fee_amount_kobo, 'currency' => 'NGN', 'source_type' => 'withdrawal',
                'source_id' => (string) $withdrawal->id, 'idempotency_key' => 'withdrawal-fee-'.$withdrawal->id,
                'actor_user_id' => $executorUserId, 'customer_description' => $obligation->customer_description,
                'ledger_posting_reference' => $group->posting_reference]);
        }

        return $group;
    }

    public function account(LedgerAccountCode $code, LedgerAccountClass $class, LedgerEntrySide $side): LedgerAccount
    {
        $account = LedgerAccount::query()->where('code', $code->value)->lockForUpdate()->firstOrFail();
        if ($account->currency !== 'NGN' || $account->mapping_status !== 'mapped' || $account->account_class !== $class
            || $account->normal_balance !== $side || $account->version < 1) {
            throw new ConflictHttpException('The required payout accounting map is unavailable.');
        }

        return $account;
    }

    private function withdrawalFee(WithdrawalRequest $withdrawal, User $actor): ?FeeObligation
    {
        if ($withdrawal->fee_amount_kobo === 0) {
            return null;
        }
        $snapshot = FeeSnapshot::query()->findOrFail($withdrawal->fee_snapshot_id);
        if ($snapshot->timing === FeeRuleTiming::CycleCompletion) {
            $obligation = $snapshot->obligation;
            if ($obligation === null || $obligation->outstandingAmountKobo() !== $withdrawal->fee_amount_kobo) {
                throw new ConflictHttpException('The completion fee assessment changed.');
            }

            return $obligation;
        }
        $attributes = $snapshot->only(['customer_profile_id', 'fee_rule_id', 'fee_rule_version', 'name', 'kind', 'model', 'timing', 'basis', 'settlement_source', 'currency', 'basis_points', 'customer_description']);
        $basis = $snapshot->model === FeeRuleModel::OneDay ? $snapshot->basis_amount_kobo : $withdrawal->gross_amount_kobo;
        $paymentSnapshot = FeeSnapshot::create([...$attributes, 'source_type' => 'withdrawal', 'source_id' => (string) $withdrawal->id,
            'basis_amount_kobo' => $basis, 'amount_kobo' => $withdrawal->fee_amount_kobo, 'acknowledged_at' => $snapshot->acknowledged_at]);

        return app(FeeObligationService::class)->assessSnapshot($paymentSnapshot, $actor);
    }
}
