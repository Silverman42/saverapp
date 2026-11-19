<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Compensates an erroneous plan→wallet transfer (Module 18 §10): the wallet gives back P, fee and deduction are restored,
 * and plan savings regain G. The wallet must still hold P; a spent wallet keeps the correction blocked rather than going negative.
 */
class WalletTransferReversalOwner implements ReversalOwnerContract
{
    public function preview(LedgerPostingGroup $original, CustomerProfile $customer, bool $forUpdate): array
    {
        $withdrawal = WithdrawalRequest::query()->where('id', $original->source_id)->where('customer_profile_id', $customer->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($withdrawal->method !== 'wallet' || $withdrawal->isWalletSource() || $withdrawal->state !== 'posted'
            || $withdrawal->wallet_posting_group_id !== $original->id) {
            throw new ConflictHttpException('The original wallet transfer is unavailable.');
        }
        $plan = ThriftPlan::query()->whereKey($withdrawal->thrift_plan_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if (! in_array($plan->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused, ThriftPlanStatus::Completed], true)
            || $plan->closure_pending_at !== null) {
            throw new ConflictHttpException('The source plan is closing or closed. Correct it through the closed-plan correction path.');
        }
        try {
            $wallet = app(WalletBalanceService::class)->position($customer, $forUpdate);
        } catch (RuntimeException $exception) {
            throw new ConflictHttpException('The Customer wallet is unavailable.', $exception);
        }
        if ($wallet['available_kobo'] < $withdrawal->net_amount_kobo) {
            throw new ConflictHttpException('The wallet no longer holds the transferred amount. Resolve dependent payouts or contributions first.');
        }
        $original->load('entries.account');
        $expected = [LedgerAccountCode::CustomerSavingsLiability->value => ['debit', $withdrawal->gross_amount_kobo],
            LedgerAccountCode::CustomerWalletLiability->value => ['credit', $withdrawal->net_amount_kobo]];
        if ($withdrawal->fee_amount_kobo > 0) {
            $expected[LedgerAccountCode::FeeIncome->value] = ['credit', $withdrawal->fee_amount_kobo];
        }
        if ($withdrawal->deduction_amount_kobo > 0) {
            $expected[LedgerAccountCode::OtherDeductionDestination->value] = ['credit', $withdrawal->deduction_amount_kobo];
        }
        if ($original->entries->count() !== count($expected)) {
            throw new ConflictHttpException('The full original transfer bundle is unavailable.');
        }
        foreach ($original->entries as $entry) {
            if (($expected[$entry->account->code->value] ?? null) !== [$entry->side->value, $entry->amount_kobo]) {
                throw new ConflictHttpException('The original transfer does not reconcile to gross, wallet credit and fee.');
            }
        }
        $feeEntry = $original->entries->firstWhere('fee_obligation_id', '!=', null);
        $obligation = $feeEntry === null ? null : FeeObligation::query()->whereKey($feeEntry->fee_obligation_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($obligation !== null) {
            $concessions = app(FeeConcessionPosition::class)->read($obligation, forUpdate: $forUpdate);
            if ($concessions['savings_kobo'] !== 0 || $concessions['external_kobo'] !== 0
                || $obligation->settledAmountKobo() < $withdrawal->fee_amount_kobo) {
                throw new ConflictHttpException('The transfer fee has a concession or correction dependency.');
            }
            if (app(FinancialCashPosition::class)->undrawnEarningsKobo(forUpdate: $forUpdate) < $withdrawal->fee_amount_kobo) {
                throw new ConflictHttpException('Drawn or encumbered fee earnings require recovery before compensation.');
            }
        }
        $summary = ['withdrawal_request_id' => $withdrawal->id, 'plan_id' => $plan->id, 'fee_obligation_id' => $obligation?->id,
            'fee_kobo' => $withdrawal->fee_amount_kobo, 'wallet_credit_kobo' => $withdrawal->net_amount_kobo];
        $dependencies = [
            ['kind' => 'wallet', 'classification' => 'compensable', 'available_kobo' => $wallet['available_kobo'], 'amount_kobo' => $withdrawal->net_amount_kobo],
            ['kind' => 'plan', 'classification' => 'compensable', 'id' => $plan->id, 'version' => $plan->version, 'status' => $plan->status->value],
        ];

        return ['gross_kobo' => $withdrawal->gross_amount_kobo, 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$original->payload_hash, $summary, $dependencies, $withdrawal->version,
                $obligation?->entries()->pluck('id')->all(), app(ReversalWatermark::class)->forCustomer($original->customer_profile_id)], JSON_THROW_ON_ERROR))];
    }

    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup
    {
        $original = $request->originalPostingGroup->load('entries.account');
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $group = LedgerPostingGroup::create(['posting_reference' => 'REV-'.Str::uuid(), 'idempotency_key' => 'wallet-transfer-compensation-'.$request->id,
            'payload_hash' => $preview['fingerprint'], 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'event_type' => 'plan_to_wallet_transfer_compensation', 'currency' => 'NGN', 'actor_user_id' => $reviewer->id, 'approver_user_id' => $reviewer->id,
            'customer_profile_id' => $original->customer_profile_id, 'thrift_plan_id' => $original->thrift_plan_id,
            'occurred_at' => now(), 'occurred_on' => $date, 'business_timezone' => $business->timezone, 'schema_version' => 1,
            'committed_at' => now(), 'metadata' => ['original_posting_group_id' => $original->id,
                'withdrawal_request_id' => $preview['summary']['withdrawal_request_id']]]);
        foreach ($original->entries as $index => $line) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1, 'ledger_account_id' => $line->ledger_account_id,
                'side' => $line->side === LedgerEntrySide::Debit ? LedgerEntrySide::Credit : LedgerEntrySide::Debit,
                'amount_kobo' => $line->amount_kobo, 'customer_profile_id' => $line->customer_profile_id,
                'thrift_plan_id' => $line->thrift_plan_id, 'fee_obligation_id' => $line->fee_obligation_id]);
        }
        $obligationId = $preview['summary']['fee_obligation_id'];
        if ($obligationId !== null) {
            $obligation = FeeObligation::query()->whereKey($obligationId)->firstOrFail();
            $types = $obligation->source_type === 'withdrawal'
                ? [FeeObligationEntryType::SettlementReversal, FeeObligationEntryType::AssessmentCorrection] : [FeeObligationEntryType::SettlementReversal];
            foreach ($types as $type) {
                FeeObligationEntry::create(['fee_obligation_id' => $obligationId, 'entry_type' => $type,
                    'amount_kobo' => $preview['summary']['fee_kobo'], 'currency' => 'NGN', 'source_type' => 'reversal_request',
                    'source_id' => (string) $request->id, 'idempotency_key' => 'wallet-transfer-reversal-'.$request->id.'-'.$type->value,
                    'actor_user_id' => $reviewer->id, 'customer_description' => $request->customer_explanation,
                    'ledger_posting_reference' => $group->posting_reference]);
            }
        }
        $customer = CustomerProfile::query()->whereKey($original->customer_profile_id)->firstOrFail();
        app(WalletBalanceService::class)->position($customer, true);

        return $group;
    }
}
