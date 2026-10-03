<?php

namespace App\Services;

use App\Data\PlanFeeReadSnapshot;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\CashExecution;
use App\Models\FeeObligation;
use App\Models\FeeSnapshot;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\WithdrawalRequest;
use App\Support\FeePercentageCalculator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PlanWithdrawalFeePosition
{
    public function isConsumed(ThriftPlan $plan, FeeSnapshot $agreement): bool
    {
        return $this->read($plan, $agreement)['consumed'];
    }

    /** @return array{consumed: bool, assessed_kobo: int, settled_kobo: int, waived_kobo: int, outstanding_kobo: int, restored: bool} */
    public function read(ThriftPlan $plan, FeeSnapshot $agreement, ?PlanFeeReadSnapshot $captured = null): array
    {
        try {
            $consumed = 0;
            $position = ['consumed' => false, 'assessed_kobo' => 0, 'settled_kobo' => 0,
                'waived_kobo' => 0, 'outstanding_kobo' => 0, 'restored' => false];
            $withdrawals = $captured === null ? WithdrawalRequest::query()->where('thrift_plan_id', $plan->id)->get()
                : $captured->withdrawals->where('thrift_plan_id', $plan->id);
            $sourceGroups = $captured === null ? LedgerPostingGroup::query()->where('source_type', 'withdrawal')
                ->whereIn('source_id', $withdrawals->pluck('id')->all())->with('entries.account', 'entries.feeObligation.feeSnapshot', 'entries.feeObligation.entries')->get()->toBase()
                : $captured->groups->where('source_type', 'withdrawal')->whereIn('source_id', $withdrawals->pluck('id'));
            $executions = $captured === null ? CashExecution::query()->whereIn('withdrawal_request_id', $withdrawals->pluck('id'))
                ->where('status', 'posted')->get() : $captured->executions;
            $groups = $sourceGroups->groupBy(fn (LedgerPostingGroup $group): string => $group->source_id);
            foreach ($withdrawals as $withdrawal) {
                $postings = $groups->get((string) $withdrawal->id);
                if ($postings === null) {
                    if ($withdrawal->state === 'posted') {
                        throw new RuntimeException('A posted withdrawal has no fee source evidence.');
                    }

                    continue;
                }
                if ($postings->count() !== 1) {
                    throw new RuntimeException('A payout has conflicting fee source evidence.');
                }
                $group = $postings->sole();
                $execution = $executions->where('withdrawal_request_id', $withdrawal->id)->where('ledger_posting_group_id', $group->id)->where('status', 'posted')->first();
                $feeLine = $group->entries->first(fn ($line): bool => $line->account->code === LedgerAccountCode::FeeIncome);
                if ($withdrawal->fee_amount_kobo === 0) {
                    if ($withdrawal->fee_snapshot_id !== $agreement->id || $feeLine !== null || $execution === null || $execution->acknowledged_at === null
                        || $execution->customer_acknowledgement === null || $execution->amount_kobo !== $withdrawal->net_amount_kobo
                        || $withdrawal->customer_profile_id !== $plan->customer_profile_id || $withdrawal->currency !== 'NGN'
                        || $withdrawal->gross_amount_kobo !== $withdrawal->net_amount_kobo
                        || $group->event_type !== 'cash_withdrawal' || $group->currency !== 'NGN'
                        || $group->customer_profile_id !== $plan->customer_profile_id || $group->thrift_plan_id !== $plan->id
                        || $group->entries->count() !== 2
                        || ! $group->entries->contains(fn ($line): bool => $line->account->code === LedgerAccountCode::CustomerSavingsLiability
                            && $line->side === LedgerEntrySide::Debit && $line->amount_kobo === $withdrawal->gross_amount_kobo
                            && $line->thrift_plan_id === $plan->id && $line->customer_profile_id === $plan->customer_profile_id)
                        || ! $group->entries->contains(fn ($line): bool => $line->account->code === LedgerAccountCode::BusinessCash
                            && $line->side === LedgerEntrySide::Credit && $line->amount_kobo === $withdrawal->net_amount_kobo)) {
                        throw new RuntimeException('A fee-free payout does not match its original journal.');
                    }

                    continue;
                }
                $obligation = $feeLine?->feeObligation;
                $snapshot = $obligation?->feeSnapshot;
                if ($execution === null || $execution->acknowledged_at === null || $execution->customer_acknowledgement === null
                    || $execution->amount_kobo !== $withdrawal->net_amount_kobo
                    || $withdrawal->fee_snapshot_id !== $agreement->id
                    || $withdrawal->customer_profile_id !== $plan->customer_profile_id || $withdrawal->currency !== 'NGN'
                    || $withdrawal->gross_amount_kobo !== $withdrawal->net_amount_kobo + $withdrawal->fee_amount_kobo
                    || $group->event_type !== 'cash_withdrawal' || $group->currency !== 'NGN'
                    || $group->customer_profile_id !== $plan->customer_profile_id || $group->thrift_plan_id !== $plan->id
                    || $feeLine === null || $feeLine->side !== LedgerEntrySide::Credit || $feeLine->amount_kobo !== $withdrawal->fee_amount_kobo
                    || $obligation === null || $snapshot === null || $obligation->customer_profile_id !== $plan->customer_profile_id
                    || $obligation->source_type !== 'withdrawal' || $obligation->source_id !== (string) $withdrawal->id
                    || $snapshot->source_type !== 'withdrawal' || $snapshot->source_id !== (string) $withdrawal->id
                    || $snapshot->customer_profile_id !== $plan->customer_profile_id || $snapshot->currency !== 'NGN'
                    || $snapshot->timing !== FeeRuleTiming::Withdrawal
                    || $snapshot->fee_rule_id !== $agreement->fee_rule_id || $snapshot->fee_rule_version !== $agreement->fee_rule_version
                    || $snapshot->kind !== $agreement->kind || $snapshot->model !== $agreement->model || $snapshot->basis !== $agreement->basis
                    || ($agreement->model === FeeRuleModel::OneDay && $snapshot->basis_amount_kobo !== $agreement->basis_amount_kobo)
                    || $withdrawal->fee_amount_kobo !== match ($agreement->model) {
                        FeeRuleModel::OneDay => $agreement->basis_amount_kobo,
                        FeeRuleModel::Percentage => FeePercentageCalculator::calculate($withdrawal->gross_amount_kobo, $agreement->basis_points ?? 0),
                        default => $agreement->amount_kobo,
                    }
                    || $snapshot->basis_points !== $agreement->basis_points
                    || ($agreement->model === FeeRuleModel::Percentage && $snapshot->basis_amount_kobo !== $withdrawal->gross_amount_kobo)
                    || $snapshot->settlement_source !== $agreement->settlement_source
                    || $snapshot->amount_kobo !== $withdrawal->fee_amount_kobo || $obligation->amount_kobo !== $withdrawal->fee_amount_kobo
                    || $group->entries->count() !== 3
                    || ! $group->entries->contains(fn ($line): bool => $line->account->code === LedgerAccountCode::CustomerSavingsLiability
                        && $line->side === LedgerEntrySide::Debit && $line->amount_kobo === $withdrawal->gross_amount_kobo
                        && $line->thrift_plan_id === $plan->id && $line->customer_profile_id === $plan->customer_profile_id)
                    || ! $group->entries->contains(fn ($line): bool => $line->account->code === LedgerAccountCode::BusinessCash
                        && $line->side === LedgerEntrySide::Credit && $line->amount_kobo === $withdrawal->net_amount_kobo)) {
                    throw new RuntimeException('The original cycle fee payout cannot be verified.');
                }
                $assessed = $obligation->assessedAmountKobo();
                $outstanding = $obligation->outstandingAmountKobo();
                $assessments = $obligation->entries->where('entry_type', FeeObligationEntryType::Assessment);
                $assessment = $assessments->first();
                $settlements = $obligation->entries->where('entry_type', FeeObligationEntryType::Settlement);
                $settlement = $settlements->first();
                if ($assessments->count() !== 1 || $assessment === null || $assessment->source_type !== 'withdrawal'
                    || $assessment->source_id !== (string) $withdrawal->id
                    || $settlements->count() !== 1 || $settlement === null || $settlement->source_type !== 'withdrawal'
                    || $settlement->source_id !== (string) $withdrawal->id || $settlement->ledger_posting_reference !== $group->posting_reference) {
                    throw new RuntimeException('The original cycle fee assessment and settlement sources do not reconcile.');
                }
                if ($assessed === 0) {
                    $this->assertRestored($obligation, $group, $withdrawal, $captured);
                    $position['restored'] = true;
                } elseif ($assessed !== $obligation->amount_kobo || $outstanding !== 0) {
                    throw new RuntimeException('The posted cycle fee obligation is inconsistent.');
                } else {
                    $consumed++;
                }
                $position['assessed_kobo'] = $this->checkedAdd($position['assessed_kobo'], $assessed);
                $position['settled_kobo'] = $this->checkedAdd($position['settled_kobo'], $obligation->settledAmountKobo());
                $position['waived_kobo'] = $this->checkedAdd($position['waived_kobo'], $obligation->waivedAmountKobo());
                $position['outstanding_kobo'] = $this->checkedAdd($position['outstanding_kobo'], $outstanding);
            }
            if ($consumed > 1 && $agreement->model !== FeeRuleModel::Percentage) {
                throw new RuntimeException('More than one effective once-per-cycle fee exists.');
            }

            return [...$position, 'consumed' => $consumed === 1 && $agreement->model !== FeeRuleModel::Percentage];
        } catch (RuntimeException $exception) {
            throw new ConflictHttpException('The once-per-cycle withdrawal fee history is unavailable.', $exception);
        }
    }

    private function checkedAdd(int $total, int $amount): int
    {
        if ($amount < 0 || $amount > PHP_INT_MAX - $total) {
            throw new RuntimeException('Cycle withdrawal fee totals exceed their supported range.');
        }

        return $total + $amount;
    }

    private function assertRestored(FeeObligation $obligation, LedgerPostingGroup $original, WithdrawalRequest $withdrawal, ?PlanFeeReadSnapshot $captured): void
    {
        $reductions = $obligation->entries->where('entry_type', FeeObligationEntryType::AssessmentCorrection);
        $correction = $reductions->first();
        if ($reductions->count() !== 1 || $correction === null || $correction->source_type !== 'reversal_request'
            || $correction->amount_kobo !== $obligation->amount_kobo || $obligation->settledAmountKobo() !== 0
            || $obligation->outstandingAmountKobo() !== 0) {
            throw new RuntimeException('Fee marker restoration has no complete linked correction.');
        }
        $reversal = $captured === null ? ReversalRequest::query()->where('id', $correction->source_id)->where('state', 'approved_posted')
            ->where('original_posting_group_id', $original->id)->where('posted_original_posting_group_id', $original->id)
            ->where('customer_profile_id', $withdrawal->customer_profile_id)->first()
            : $captured->reversals->where('id', $correction->source_id)->where('state', 'approved_posted')
                ->where('original_posting_group_id', $original->id)->where('posted_original_posting_group_id', $original->id)
                ->where('customer_profile_id', $withdrawal->customer_profile_id)->first();
        $compensation = $reversal === null ? null : ($captured === null ? LedgerPostingGroup::query()->find($reversal->compensation_posting_group_id)
            : $captured->groups->firstWhere('id', $reversal->compensation_posting_group_id));
        $settlements = $obligation->entries->where('entry_type', FeeObligationEntryType::SettlementReversal);
        $settlement = $settlements->first();
        if ($reversal === null || $compensation === null || $compensation->source_type !== 'reversal_request'
            || $compensation->source_id !== (string) $reversal->id || $compensation->event_type !== 'withdrawal_compensation'
            || $compensation->currency !== 'NGN' || $compensation->customer_profile_id !== $withdrawal->customer_profile_id
            || $compensation->thrift_plan_id !== $withdrawal->thrift_plan_id
            || ($compensation->metadata['original_posting_group_id'] ?? null) !== $original->id
            || $correction->ledger_posting_reference !== $compensation->posting_reference
            || $settlements->count() !== 1 || $settlement === null || $settlement->source_type !== 'reversal_request'
            || $settlement->source_id !== (string) $reversal->id || $settlement->amount_kobo !== $obligation->amount_kobo
            || $settlement->ledger_posting_reference !== $compensation->posting_reference) {
            throw new RuntimeException('Fee marker restoration requires approved payout compensation.');
        }
        $consumedConcession = $compensation->metadata['fee_concession_effect']['consumed_savings_concession_kobo'] ?? null;
        if (! is_int($consumedConcession) || $consumedConcession < 0 || $consumedConcession > $obligation->amount_kobo
            || ($compensation->metadata['fee_concession_effect']['fee_obligation_id'] ?? null) !== $obligation->id
            || $reversal->reviewed_at === null || $reversal->reviewed_by_user_id === null) {
            throw new RuntimeException('Fee marker restoration has invalid concession or review evidence.');
        }
        $retainedFee = $obligation->amount_kobo - $consumedConcession;
        $expected = [LedgerAccountCode::CustomerSavingsLiability->value => [LedgerEntrySide::Credit, $withdrawal->gross_amount_kobo - $consumedConcession],
            LedgerAccountCode::CashRecoveryClearing->value => [LedgerEntrySide::Debit, $withdrawal->net_amount_kobo]];
        if ($retainedFee > 0) {
            $expected[LedgerAccountCode::FeeIncome->value] = [LedgerEntrySide::Debit, $retainedFee];
        }
        $lines = $captured === null ? $compensation->entries()->with('account')->get() : $compensation->entries;
        if ($lines->count() !== count($expected) || $lines->pluck('ledger_account_id')->unique()->count() !== count($expected)) {
            throw new RuntimeException('Fee marker restoration has an incomplete compensation journal.');
        }
        foreach ($lines as $line) {
            if (($expected[$line->account->code->value] ?? null) !== [$line->side, $line->amount_kobo]
                || $line->customer_profile_id !== $withdrawal->customer_profile_id) {
                throw new RuntimeException('Fee marker restoration journal does not reconcile.');
            }
        }
    }
}
