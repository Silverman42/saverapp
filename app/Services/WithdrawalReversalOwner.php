<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WithdrawalReversalOwner implements ReversalOwnerContract
{
    /**
     * @param  Collection<int, CashRecovery>  $returns
     * @return array<string, mixed>
     */
    public function assertConsumedReturns(CashExecution $execution, Collection $returns, bool $forUpdate): array
    {
        $withdrawal = WithdrawalRequest::query()->whereKey($execution->withdrawal_request_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        $original = LedgerPostingGroup::query()->whereKey($execution->ledger_posting_group_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        $requests = ReversalRequest::query()->where('original_posting_group_id', $execution->ledger_posting_group_id)
            ->whereNotNull('compensation_posting_group_id')->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $request = $requests->first();
        if ($withdrawal === null || $original === null || $requests->count() !== 1 || $request === null
            || $original->source_type !== 'withdrawal' || $original->source_id !== (string) $withdrawal->id
            || $original->customer_profile_id !== $withdrawal->customer_profile_id || $original->thrift_plan_id !== $withdrawal->thrift_plan_id
            || $request->state !== 'approved_posted' || $request->customer_profile_id !== $withdrawal->customer_profile_id
            || $request->posted_original_posting_group_id !== $original->id || $request->live_original_posting_group_id !== null
            || $request->currency !== $original->currency || $request->reviewed_at === null || $request->reviewed_by_user_id === null
            || $request->reviewed_by_user_id === $request->requested_by_user_id) {
            throw new ConflictHttpException('Consumed payout returns require an independently approved compensation.');
        }
        $group = LedgerPostingGroup::query()->whereKey($request->compensation_posting_group_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        $events = $request->events()->where('event_type', 'approved_posted')->where('actor_user_id', $request->reviewed_by_user_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $event = $events->first();
        $snapshot = $request->getAttribute('dependency_snapshot');
        $summary = is_array($snapshot) ? ($snapshot['summary'] ?? null) : null;
        if ($group === null || $group->event_type !== 'withdrawal_compensation' || $group->thrift_plan_id !== $withdrawal->thrift_plan_id
            || $group->actor_user_id !== $request->reviewed_by_user_id || ($group->metadata['original_posting_group_id'] ?? null) !== $original->id
            || $events->count() !== 1 || $event === null || ($event->metadata['compensation_posting_group_id'] ?? null) !== $group->id
            || ($event->metadata['owner_fingerprint'] ?? null) !== $group->payload_hash
            || ! is_array($summary) || ($summary['withdrawal_request_id'] ?? null) !== $withdrawal->id
            || ($summary['cash_recovery_ids'] ?? null) !== $returns->modelKeys()
            || $returns->isEmpty() || $returns->sum('amount_kobo') !== $withdrawal->net_amount_kobo
            || ! $returns->contains('ledger_posting_group_id', $group->id)) {
            throw new ConflictHttpException('The consumed return compensation source is unavailable.');
        }
        app(ReversalService::class)->assertCompensation($request, $group, $forUpdate);
        foreach ($returns as $returned) {
            if ($returned->status !== 'consumed' || $returned->consumed_at === null) {
                throw new ConflictHttpException('The linked payout returns have not been consumed.');
            }
            app(CashRecoveryLedger::class)->assertReturnPosting($execution, $returned, $forUpdate);
        }

        return ['request_id' => $request->id, 'version' => $request->version, 'state' => $request->state,
            'reviewer_user_id' => $request->reviewed_by_user_id, 'reviewed_at' => $request->reviewed_at,
            'event_id' => $event->id, 'group_id' => $group->id, 'payload_hash' => $group->payload_hash];
    }

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
        $concession = $obligation === null ? 0 : app(FeeConcessionPosition::class)->read($obligation, forUpdate: $forUpdate)['savings_kobo'];
        if ($obligation !== null && ($obligation->settledAmountKobo() !== $withdrawal->fee_amount_kobo
            || app(FeeConcessionPosition::class)->read($obligation, forUpdate: $forUpdate)['external_kobo'] !== 0
            || $concession > $withdrawal->fee_amount_kobo)) {
            throw new ConflictHttpException('The original payout fee and concession sources do not reconcile.');
        }
        $retainedFee = $withdrawal->fee_amount_kobo - $concession;
        if ($retainedFee > 0 && app(FinancialCashPosition::class)->undrawnEarningsKobo(forUpdate: $forUpdate) < $retainedFee) {
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
            'cash_mapping_version' => $cash->version, 'fee_obligation_id' => $obligation?->id, 'fee_kobo' => $withdrawal->fee_amount_kobo,
            'consumed_savings_concession_kobo' => $concession, 'retained_fee_kobo' => $retainedFee];
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
            'committed_at' => now(), 'metadata' => ['original_posting_group_id' => $original->id, 'cash_recovery_id' => $recovery->id,
                'fee_concession_effect' => ['fee_obligation_id' => $preview['summary']['fee_obligation_id'],
                    'consumed_savings_concession_kobo' => $preview['summary']['consumed_savings_concession_kobo'],
                    'consumed_external_concession_kobo' => 0]]]);
        $clearing = LedgerAccount::query()->where('code', LedgerAccountCode::CashRecoveryClearing)->lockForUpdate()->sole();
        if ($clearing->mapping_status !== 'mapped') {
            throw new ConflictHttpException('Recovery clearing is unavailable.');
        }
        foreach ($original->entries as $index => $line) {
            $amount = $line->amount_kobo;
            if (in_array($line->account->code, [LedgerAccountCode::CustomerSavingsLiability, LedgerAccountCode::FeeIncome], true)) {
                $amount -= $preview['summary']['consumed_savings_concession_kobo'];
            }
            if ($amount === 0) {
                continue;
            }
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                'ledger_account_id' => $line->account->code === LedgerAccountCode::BusinessCash ? $clearing->id : $line->ledger_account_id,
                'side' => $line->side === LedgerEntrySide::Debit ? LedgerEntrySide::Credit : LedgerEntrySide::Debit,
                'amount_kobo' => $amount, 'customer_profile_id' => $line->customer_profile_id,
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
