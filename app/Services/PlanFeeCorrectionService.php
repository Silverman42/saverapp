<?php

namespace App\Services;

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\FeeLedgerPostingType;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeRefund;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\FeePercentageCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PlanFeeCorrectionService
{
    /** @return array<string, mixed> */
    public function preview(ThriftPlan $plan, ?CollectionReceipt $excluding = null, bool $terminating = false): array
    {
        $snapshot = $plan->currentTermsRevision()?->feeSnapshot;
        if ($snapshot === null) {
            throw new ConflictHttpException('Original fee terms are unavailable.');
        }
        $allocations = DB::table('collection_allocations')->join('contribution_slots', 'contribution_slots.id', '=', 'collection_allocations.contribution_slot_id')
            ->where('contribution_slots.thrift_plan_id', $plan->id)
            ->whereNotIn('collection_allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
            ->when($excluding !== null, fn ($query) => $query->where('collection_receipt_id', '!=', $excluding->id));
        $principal = (int) $allocations->sum('collection_allocations.amount_kobo');
        $fullyFunded = $plan->slots()->whereNotNull('active_ordinal')->get()->every(fn ($slot): bool => (int) (clone $allocations)->where('contribution_slot_id', $slot->id)->sum('collection_allocations.amount_kobo') === $slot->expected_amount_kobo);
        $obligation = $snapshot->obligation()->first();
        $waived = $obligation === null ? 0 : $obligation->waivedAmountKobo();
        $due = $principal > 0 && ($snapshot->timing === FeeRuleTiming::FirstContribution || $fullyFunded || $terminating
            || $plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')->exists());
        $target = $due ? match ($snapshot->model) {
            FeeRuleModel::NoFee => 0,
            FeeRuleModel::Percentage => FeePercentageCalculator::calculate($principal, $snapshot->basis_points ?? 0),
            default => $snapshot->amount_kobo,
        } : 0;
        if ($snapshot->timing === FeeRuleTiming::Withdrawal) {
            $target = $obligation?->assessedAmountKobo() ?? 0;
        }
        // A consumed waiver remains authoritative across cancellation and recompletion.
        $target = max($waived, $target);
        if ($obligation === null) {
            return ['kind' => 'plan_fee', 'classification' => 'compensable', 'fee_obligation_id' => null,
                'snapshot_id' => $snapshot->id, 'target_kobo' => $target, 'refund_kobo' => 0,
                'savings_refund_kobo' => 0, 'external_refund_kobo' => 0, 'principal_kobo' => $principal, 'history_ids' => []];
        }
        $position = app(FeeConcessionPosition::class);
        $concessions = $position->read($obligation);
        $retained = $position->retainedSources($obligation);
        $savingsSettled = $retained['savings_kobo'] + $concessions['savings_kobo'];
        if ($savingsSettled < 0 || $savingsSettled > $obligation->settledAmountKobo()) {
            throw new ConflictHttpException('Original savings fee application provenance is unavailable.');
        }
        if ($concessions['savings_kobo'] > $savingsSettled
            || $concessions['external_kobo'] > $obligation->settledAmountKobo() - $savingsSettled) {
            throw new ConflictHttpException('Fee concessions exceed their retained settlement source.');
        }
        $receiptFee = $excluding === null ? 0 : (int) DB::table('collection_fee_components')
            ->where('collection_receipt_id', $excluding->id)->where('fee_obligation_id', $obligation->id)->sum('amount_kobo');
        $receiptConcession = max(0, $receiptFee - $retained['external_kobo']);
        if ($receiptFee > $obligation->settledAmountKobo() - $savingsSettled || $receiptConcession > $concessions['external_kobo']) {
            throw new ConflictHttpException('Receipt-owned fee settlement exceeds its authoritative external source.');
        }
        $concessions['external_kobo'] -= $receiptConcession;
        $refund = max(0, $obligation->settledAmountKobo() - $receiptFee + $waived - $target);
        $consumedSavings = min($refund, $concessions['savings_kobo']);
        $consumedExternal = min($refund - $consumedSavings, $concessions['external_kobo']);
        $additionalRefund = $refund - $consumedSavings - $consumedExternal;
        $savingsRefund = min($additionalRefund, $savingsSettled - $concessions['savings_kobo']);

        return ['kind' => 'plan_fee', 'classification' => 'compensable', 'fee_obligation_id' => $obligation->id,
            'snapshot_id' => $snapshot->id, 'target_kobo' => $target, 'refund_kobo' => $refund,
            'savings_refund_kobo' => $savingsRefund, 'external_refund_kobo' => $additionalRefund - $savingsRefund,
            'consumed_savings_concession_kobo' => $consumedSavings, 'consumed_external_concession_kobo' => $consumedExternal,
            'excluded_receipt_fee_kobo' => $receiptFee, 'excluded_receipt_concession_kobo' => $receiptConcession,
            'principal_kobo' => $principal, 'history_ids' => $obligation->entries()->pluck('id')->all()];
    }

    /** @param array<string, mixed> $effect */
    public function compensate(array $effect, LedgerPostingGroup $group, User $actor): void
    {
        if ($effect['fee_obligation_id'] === null) {
            return;
        }
        $obligation = FeeObligation::query()->whereKey($effect['fee_obligation_id'])->lockForUpdate()->firstOrFail();
        $refund = $effect['savings_refund_kobo'];
        if ($refund > 0) {
            $accounts = LedgerAccount::query()->whereIn('code', [LedgerAccountCode::FeeIncome, LedgerAccountCode::CustomerSavingsLiability])->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($account): string => $account->code->value);
            foreach ([LedgerAccountCode::FeeIncome, LedgerAccountCode::CustomerSavingsLiability] as $code) {
                if ($accounts[$code->value]->mapping_status !== 'mapped' || $accounts[$code->value]->currency !== 'NGN') {
                    throw new ConflictHttpException('Fee compensation mapping is unavailable.');
                }
            }
            $number = (int) $group->entries()->max('line_number');
            foreach ([[LedgerAccountCode::FeeIncome, LedgerEntrySide::Debit], [LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Credit]] as [$code, $side]) {
                LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => ++$number, 'ledger_account_id' => $accounts[$code->value]->id,
                    'side' => $side, 'amount_kobo' => $refund, 'customer_profile_id' => $obligation->customer_profile_id,
                    'thrift_plan_id' => $group->thrift_plan_id, 'fee_obligation_id' => $obligation->id]);
            }
        }
        if ($effect['external_refund_kobo'] > 0) {
            $reference = (string) Str::uuid();
            $amount = $effect['external_refund_kobo'];
            $refundGroup = app(LedgerPostingService::class)->postFee(new LedgerPostingCommand(FeeLedgerPostingType::ExternalRefundEntitlement,
                'corrected-plan-refund-'.$group->id, 'external_refund_entitlement', $reference, 'NGN', $actor, $obligation->customer_profile_id, now()->toImmutable(), [
                    new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Debit, $amount, $obligation->customer_profile_id, null, $obligation->id),
                    new LedgerPostingLine(LedgerAccountCode::RefundPayable, LedgerEntrySide::Credit, $amount, $obligation->customer_profile_id, null, $obligation->id),
                ], 'External cycle fee correction: original unpaid entitlement.'));
            $refund = FeeRefund::create(['refund_reference' => $reference, 'payload_hash' => $refundGroup->payload_hash,
                'customer_profile_id' => $obligation->customer_profile_id, 'fee_obligation_id' => $obligation->id,
                'actor_user_id' => $actor->id, 'amount_kobo' => $amount, 'kind' => 'external',
                'compensation_posting_group_id' => $group->id, 'reason' => 'Reviewed plan fee correction linked to compensation '.$group->posting_reference, 'ledger_posting_group_id' => $refundGroup->id]);
            AuditEvent::record('fee.refund_authorized', FeeRefund::class, $refund->id, $reference,
                ['customer_profile_id' => $obligation->customer_profile_id, 'amount_kobo' => $amount,
                    'source_type' => 'external', 'source_id' => $reference, 'reason' => $refund->reason], $actor,
                context: ['executor' => self::class, 'correlation_reference' => $reference, 'required_permission' => 'reversals.review']);
            app(FinancialCashNotice::class)->queue($actor, $refund, 'refund_authorized');
            app(LedgerTransactionProjectionService::class)->projectRefund($refund);
        }
        if ($effect['refund_kobo'] > 0) {
            $this->entry($obligation, $group, $actor, FeeObligationEntryType::SettlementReversal, $effect['refund_kobo']);
        }
        $delta = $effect['target_kobo'] - $obligation->assessedAmountKobo();
        if ($delta !== 0) {
            $this->entry($obligation, $group, $actor, $delta > 0 ? FeeObligationEntryType::AssessmentCorrectionIncrease : FeeObligationEntryType::AssessmentCorrection, abs($delta));
        }
        $obligation->refresh()->outstandingAmountKobo();
    }

    public function synchronize(ThriftPlan $plan, User $actor, string $reference, bool $terminating = false): void
    {
        $effect = $this->preview($plan, terminating: $terminating);
        if ($effect['fee_obligation_id'] === null) {
            return;
        }
        $obligation = FeeObligation::query()->whereKey($effect['fee_obligation_id'])->lockForUpdate()->firstOrFail();
        if ($effect['refund_kobo'] > 0) {
            throw new ConflictHttpException('Paid fee correction must be reviewed before preparing settlement.');
        }
        $delta = $effect['target_kobo'] - $obligation->assessedAmountKobo();
        if ($delta === 0) {
            return;
        }
        FeeObligationEntry::create(['fee_obligation_id' => $obligation->id,
            'entry_type' => $delta > 0 ? FeeObligationEntryType::AssessmentCorrectionIncrease : FeeObligationEntryType::AssessmentCorrection,
            'amount_kobo' => abs($delta), 'currency' => 'NGN', 'source_type' => 'plan_fee_trigger', 'source_id' => $reference,
            'idempotency_key' => 'plan-trigger-'.$plan->id.'-'.$reference, 'actor_user_id' => $actor->id,
            'customer_description' => 'Agreed cycle fee trigger updated.']);
    }

    private function entry(FeeObligation $obligation, LedgerPostingGroup $group, User $actor, FeeObligationEntryType $type, int $amount): void
    {
        FeeObligationEntry::create(['fee_obligation_id' => $obligation->id, 'entry_type' => $type, 'amount_kobo' => $amount, 'currency' => 'NGN',
            'source_type' => 'plan_fee_correction', 'source_id' => (string) $group->id, 'idempotency_key' => 'plan-fee-'.$group->id.'-'.$type->value,
            'actor_user_id' => $actor->id, 'customer_description' => 'Linked plan fee correction.', 'ledger_posting_reference' => $group->posting_reference]);
    }
}
