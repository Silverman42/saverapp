<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WithdrawalReversalOwner implements ReversalOwnerContract
{
    public function preview(LedgerPostingGroup $original, CustomerProfile $customer, bool $forUpdate): array
    {
        $withdrawal = WithdrawalRequest::query()->where('id', $original->source_id)->where('customer_profile_id', $customer->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $execution = CashExecution::query()->where('ledger_posting_group_id', $original->id)->where('status', 'posted')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        $return = CashRecovery::query()->where('cash_execution_id', $execution->id)->where('event_type', 'return')->where('status', 'confirmed')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $returns = $return;
        $return = $returns->first();
        if ($return === null || $return->confirmed_at === null || $returns->sum('amount_kobo') !== $withdrawal->net_amount_kobo || $returns->contains(fn ($item): bool => $item->confirmed_at === null || $item->customer_acknowledgement === null)
            || $return->recipient_user_id !== $execution->recipient_user_id || $return->customer_acknowledgement === null) {
            throw new ConflictHttpException('A proven full return is required; partial or uncertain recovery cannot restore savings.');
        }
        app(CashRecoveryLedger::class)->assertConfirmedReturns($execution, $returns);
        $original->load('entries.account');
        $cash = LedgerAccount::query()->where('code', LedgerAccountCode::BusinessCash->value)->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        if ($cash->mapping_status !== 'mapped' || $cash->currency !== 'NGN' || $cash->normal_balance !== LedgerEntrySide::Debit || $cash->account_class !== LedgerAccountClass::Asset) {
            throw new ConflictHttpException('The verified returned-cash destination is unavailable.');
        }
        $feeEntry = $original->entries->firstWhere('fee_obligation_id', '!=', null);
        $obligation = $feeEntry === null ? null : FeeObligation::query()->whereKey($feeEntry->fee_obligation_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($obligation !== null && ($obligation->settledAmountKobo() !== $withdrawal->fee_amount_kobo
            || $obligation->entries()->whereIn('entry_type', [FeeObligationEntryType::SavingsRefund, FeeObligationEntryType::ExternalRefundEntitlement])->exists())) {
            throw new ConflictHttpException('A dependent fee refund or adjustment must be resolved before full payout compensation.');
        }
        if ($withdrawal->fee_amount_kobo > 0 && CashDisbursement::query()->where('kind', 'earnings_draw')->whereIn('status', ['processing', 'outcome_unknown', 'posted'])->exists()
            && app(FinancialCashPosition::class)->read()['undrawn_earnings_kobo'] < $withdrawal->fee_amount_kobo) {
            throw new ConflictHttpException('Drawn or encumbered fee earnings require recovery before compensation.');
        }
        $expected = [LedgerAccountCode::CustomerSavingsLiability->value => ['debit', $withdrawal->gross_amount_kobo],
            LedgerAccountCode::BusinessCash->value => ['credit', $withdrawal->net_amount_kobo]];
        if ($withdrawal->fee_amount_kobo > 0) {
            $expected[LedgerAccountCode::FeeIncome->value] = ['credit', $withdrawal->fee_amount_kobo];
        }
        if ($original->entries->count() !== count($expected) || $original->entries->pluck('ledger_account_id')->unique()->count() !== count($expected)) {
            throw new ConflictHttpException('The full original payout bundle is unavailable.');
        }
        foreach ($original->entries as $entry) {
            if (($expected[$entry->account->code->value] ?? null) !== [$entry->side->value, $entry->amount_kobo]) {
                throw new ConflictHttpException('The original payout does not reconcile to gross, net and fee.');
            }
        }
        $summary = ['withdrawal_request_id' => $withdrawal->id, 'cash_recovery_id' => $return->id, 'cash_recovery_ids' => $returns->pluck('id')->all(), 'cash_account_id' => $cash->id,
            'cash_mapping_version' => $cash->version, 'fee_obligation_id' => $obligation?->id, 'fee_kobo' => $withdrawal->fee_amount_kobo];
        $dependencies = [['kind' => 'cash_return', 'classification' => 'compensable', 'reference' => $return->recovery_reference,
            'amount_kobo' => $return->amount_kobo, 'custodian_user_id' => $return->custodian_user_id]];

        return ['gross_kobo' => $withdrawal->gross_amount_kobo, 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$original->payload_hash, $summary, $dependencies, $withdrawal->version,
                $obligation?->entries()->pluck('id')->all(), LedgerPostingGroup::query()->max('id')], JSON_THROW_ON_ERROR))];
    }

    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup
    {
        $original = $request->originalPostingGroup->load('entries.account');
        $recovery = CashRecovery::query()->where('id', $preview['summary']['cash_recovery_id'])->firstOrFail();
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $group = LedgerPostingGroup::create(['posting_reference' => 'REV-'.Str::uuid(), 'idempotency_key' => 'payout-compensation-'.$request->id,
            'payload_hash' => $preview['fingerprint'], 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'event_type' => 'withdrawal_compensation', 'currency' => 'NGN', 'actor_user_id' => $reviewer->id,
            'customer_profile_id' => $original->customer_profile_id, 'thrift_plan_id' => $original->thrift_plan_id,
            'occurred_at' => now(), 'occurred_on' => $date, 'business_timezone' => $business->timezone, 'schema_version' => 1,
            'committed_at' => now(), 'metadata' => ['original_posting_group_id' => $original->id, 'cash_recovery_id' => $recovery->id]]);
        $clearing = LedgerAccount::query()->where('code', LedgerAccountCode::CashRecoveryClearing)->lockForUpdate()->sole();
        if ($clearing->mapping_status !== 'mapped') {
            throw new ConflictHttpException('Recovery clearing is unavailable.');
        }
        foreach ($original->entries as $index => $line) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                'ledger_account_id' => $line->account->code === LedgerAccountCode::BusinessCash ? $clearing->id : $line->ledger_account_id,
                'side' => $line->side === LedgerEntrySide::Debit ? LedgerEntrySide::Credit : LedgerEntrySide::Debit,
                'amount_kobo' => $line->amount_kobo, 'customer_profile_id' => $line->customer_profile_id,
                'thrift_plan_id' => $line->thrift_plan_id, 'fee_obligation_id' => $line->fee_obligation_id]);
        }
        $obligationId = $preview['summary']['fee_obligation_id'];
        if ($obligationId !== null) {
            $obligation = FeeObligation::query()->where('id', $obligationId)->firstOrFail();
            $types = $obligation->source_type === 'withdrawal'
                ? [FeeObligationEntryType::SettlementReversal, FeeObligationEntryType::AssessmentCorrection] : [FeeObligationEntryType::SettlementReversal];
            foreach ($types as $type) {
                FeeObligationEntry::create(['fee_obligation_id' => $obligationId, 'entry_type' => $type,
                    'amount_kobo' => $preview['summary']['fee_kobo'], 'currency' => 'NGN', 'source_type' => 'reversal_request',
                    'source_id' => (string) $request->id, 'idempotency_key' => 'payout-reversal-'.$request->id.'-'.$type->value,
                    'actor_user_id' => $reviewer->id, 'customer_description' => $request->customer_explanation,
                    'ledger_posting_reference' => $group->posting_reference]);
            }
        }
        $recovery->update(['ledger_posting_group_id' => $group->id]);
        foreach (CashRecovery::query()->whereIn('id', $preview['summary']['cash_recovery_ids'])->get() as $returned) {
            $returned->update(['status' => 'consumed', 'consumed_at' => now()]);
        }

        return $group;
    }
}
