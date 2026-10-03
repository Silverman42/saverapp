<?php

namespace App\Services;

use App\Data\PlanFeeReadSnapshot;
use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerPostingGroup;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FeeConcessionPosition
{
    /** @return array{savings_kobo: int, external_kobo: int, consumed_savings_kobo: int, consumed_external_kobo: int} */
    public function read(FeeObligation $obligation, ?PlanFeeReadSnapshot $captured = null, bool $forUpdate = false): array
    {
        $this->assertCurrentRead($captured, $forUpdate);
        $amounts = ['savings_kobo' => 0, 'external_kobo' => 0, 'consumed_savings_kobo' => 0, 'consumed_external_kobo' => 0];
        $linkedRefunds = $captured === null ? FeeRefund::query()->where('fee_obligation_id', $obligation->id)
            ->whereNotNull('compensation_posting_group_id')->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->pluck('refund_reference')->all()
            : $captured->refunds->where('fee_obligation_id', $obligation->id)->whereNotNull('compensation_posting_group_id')->pluck('refund_reference')->all();
        foreach ($captured === null ? $obligation->entries()->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() : $obligation->entries as $entry) {
            $key = match ($entry->entry_type) {
                FeeObligationEntryType::SavingsRefund => 'savings_kobo',
                FeeObligationEntryType::ExternalRefundEntitlement => 'external_kobo',
                default => null,
            };
            if ($key !== null && ! in_array($entry->source_id, $linkedRefunds, true)) {
                $amounts[$key] = $this->add($amounts[$key], $entry->amount_kobo);
            }
            if ($entry->entry_type === FeeObligationEntryType::SettlementReversal && $entry->source_type === 'receipt_no_money') {
                $amounts['consumed_external_kobo'] = $this->add($amounts['consumed_external_kobo'],
                    app(CollectionNoMoneyCorrection::class)->consumedConcession($entry));
            }
            if ($entry->entry_type === FeeObligationEntryType::SettlementReversal
                && in_array($entry->source_type, ['plan_fee_correction', 'reversal_request', 'receipt_compensation'], true)) {
                $group = $this->sourceGroup($entry->ledger_posting_reference, $captured, forUpdate: $forUpdate);
                $effect = $group->metadata['plan_fee_effect'] ?? $group->metadata['fee_concession_effect'] ?? [];
                if ($entry->source_type === 'receipt_compensation') {
                    $effects = $group->metadata['external_fee_effects'] ?? [];
                    if (! is_array($effects)) {
                        throw new ConflictHttpException('Receipt concession correction provenance is unavailable.');
                    }
                    $effect = [];
                    foreach ($effects as $candidate) {
                        if (! is_array($candidate)) {
                            throw new ConflictHttpException('Receipt concession correction provenance is unavailable.');
                        }
                        if (($candidate['fee_obligation_id'] ?? null) === $obligation->id) {
                            if ($effect !== [] || ($candidate['amount_kobo'] ?? null) !== $entry->amount_kobo) {
                                throw new ConflictHttpException('Receipt concession correction amounts do not reconcile.');
                            }
                            $effect = $candidate;
                        }
                    }
                }
                if ($effect !== [] && ($effect['fee_obligation_id'] ?? null) !== $obligation->id) {
                    throw new ConflictHttpException('Fee concession correction provenance is unavailable.');
                }
                foreach (['savings', 'external'] as $kind) {
                    $consumed = $effect['consumed_'.$kind.'_concession_kobo'] ?? 0;
                    if (! is_int($consumed) || $consumed < 0 || $consumed > $entry->amount_kobo) {
                        throw new ConflictHttpException('Fee concession correction amount is invalid.');
                    }
                    $key = 'consumed_'.$kind.'_kobo';
                    $amounts[$key] = $this->add($amounts[$key], $consumed);
                }
                if (($effect['consumed_savings_concession_kobo'] ?? 0) + ($effect['consumed_external_concession_kobo'] ?? 0) > $entry->amount_kobo) {
                    throw new ConflictHttpException('Combined concessions exceed the corrected settlement.');
                }
            }
        }
        foreach (['savings', 'external'] as $kind) {
            $amounts[$kind.'_kobo'] -= $amounts['consumed_'.$kind.'_kobo'];
            if ($amounts[$kind.'_kobo'] < 0) {
                throw new ConflictHttpException('Fee corrections exceed the original concession.');
            }
        }

        return $amounts;
    }

    /** @return array{savings_kobo: int, external_kobo: int} */
    public function retainedSources(FeeObligation $obligation, ?PlanFeeReadSnapshot $captured = null, bool $forUpdate = false): array
    {
        $this->assertCurrentRead($captured, $forUpdate);
        $amounts = ['savings_kobo' => 0, 'external_kobo' => 0];
        foreach ($captured === null ? $obligation->entries()->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() : $obligation->entries as $entry) {
            if (! in_array($entry->entry_type, [FeeObligationEntryType::Settlement, FeeObligationEntryType::SettlementReversal], true)) {
                continue;
            }
            if ($entry->entry_type === FeeObligationEntryType::SettlementReversal && $entry->source_type === 'receipt_no_money') {
                $amounts['external_kobo'] -= app(CollectionNoMoneyCorrection::class)->consumedConcession($entry);

                continue;
            }
            $group = $this->sourceGroup($entry->ledger_posting_reference, $captured, true, $forUpdate);
            if ($entry->entry_type === FeeObligationEntryType::Settlement) {
                $credit = $group->entries->first(fn ($line): bool => $line->account->code === LedgerAccountCode::FeeIncome
                    && $line->side === LedgerEntrySide::Credit && $line->fee_obligation_id === $obligation->id);
                if ($group->customer_profile_id !== $obligation->customer_profile_id || $credit?->amount_kobo !== $entry->amount_kobo) {
                    throw new ConflictHttpException('The original paid-fee source is unavailable.');
                }
                $key = match ($group->event_type) {
                    'savings_fee_application', 'cash_withdrawal' => 'savings_kobo',
                    'external_fee_receipt', 'unapplied_fee_application' => 'external_kobo',
                    default => throw new ConflictHttpException('The fee settlement source is unsupported.'),
                };
                $amounts[$key] = $this->add($amounts[$key], $entry->amount_kobo);
            } elseif ($entry->source_type === 'plan_fee_correction') {
                $effect = $group->metadata['plan_fee_effect'] ?? [];
                $savings = $effect === [] ? (int) $group->entries->filter(fn ($line): bool => $line->account->code === LedgerAccountCode::CustomerSavingsLiability
                    && $line->side === LedgerEntrySide::Credit && $line->fee_obligation_id === $obligation->id)->sum('amount_kobo')
                    : ($effect['savings_refund_kobo'] ?? 0) + ($effect['consumed_savings_concession_kobo'] ?? 0);
                if (! is_int($savings) || $savings < 0 || $savings > $entry->amount_kobo) {
                    throw new ConflictHttpException('Fee compensation source amounts are invalid.');
                }
                $amounts['savings_kobo'] -= $savings;
                $amounts['external_kobo'] -= $entry->amount_kobo - $savings;
            } elseif ($entry->source_type === 'receipt_compensation') {
                $amounts['external_kobo'] -= $entry->amount_kobo;
            } elseif ($entry->source_type === 'reversal_request' && in_array($group->event_type, ['withdrawal_compensation', 'fee_application_compensation'], true)) {
                $amounts['savings_kobo'] -= $entry->amount_kobo;
            } else {
                throw new ConflictHttpException('The fee compensation source is unsupported.');
            }
        }
        $concessions = $this->read($obligation, $captured, $forUpdate);
        foreach (['savings', 'external'] as $kind) {
            $amounts[$kind.'_kobo'] -= $concessions[$kind.'_kobo'];
            if ($amounts[$kind.'_kobo'] < 0) {
                throw new ConflictHttpException('Fee relief exceeds its original settlement source.');
            }
        }

        return $amounts;
    }

    private function sourceGroup(?string $reference, ?PlanFeeReadSnapshot $captured, bool $withEntries = false, bool $forUpdate = false): LedgerPostingGroup
    {
        if ($captured === null) {
            $group = LedgerPostingGroup::query()->where('posting_reference', $reference)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                ->when($withEntries, fn ($query) => $query->with([
                    'entries' => fn ($entries) => $entries->orderBy('id')->when($forUpdate, fn ($rows) => $rows->lockForUpdate())
                        ->with(['account' => fn ($accounts) => $accounts->when($forUpdate, fn ($rows) => $rows->lockForUpdate())]),
                ]))->firstOrFail();
            if ($group->event_type === 'fee_application_compensation') {
                app(FeeSavingsApplicationReversalOwner::class)->assertPosted((int) $group->source_id, DB::transactionLevel() > 0);
            }

            return $group;
        }
        $group = $reference === null ? null : $captured->groups->firstWhere('posting_reference', $reference);
        if ($group === null) {
            throw new ConflictHttpException('The original fee source is unavailable.');
        }

        if ($group->event_type === 'fee_application_compensation'
            && $captured->feeCompensations->get((int) $group->source_id)?->id !== $group->id) {
            throw new ConflictHttpException('The retained fee compensation source is unavailable.');
        }

        return $group;
    }

    private function assertCurrentRead(?PlanFeeReadSnapshot $captured, bool $forUpdate): void
    {
        if ($forUpdate && (DB::transactionLevel() === 0 || $captured !== null)) {
            throw new RuntimeException('Current fee concession sources require their owning transaction and current history.');
        }
    }

    private function add(int $total, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $total) {
            throw new \OverflowException('Fee concession history exceeds the supported integer range.');
        }

        return $total + $amount;
    }
}
