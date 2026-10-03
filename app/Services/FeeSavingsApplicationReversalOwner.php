<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use ValueError;

class FeeSavingsApplicationReversalOwner implements ReversalOwnerContract
{
    public function preview(LedgerPostingGroup $original, CustomerProfile $customer, bool $forUpdate): array
    {
        $verified = app(FeeSavingsApplicationService::class)->assertPosted($original->source_id, $forUpdate);
        if ($verified->id !== $original->id || $original->customer_profile_id !== $customer->id) {
            throw new ConflictHttpException('The original savings fee payment is unavailable.');
        }
        $application = DB::table('fee_savings_applications')->where('operation_reference', $original->source_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        $plan = ThriftPlan::query()->whereKey($application->thrift_plan_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if (! $plan->status->isOpen()) {
            throw new ConflictHttpException('Terminal cycle compensation requires its lifecycle owner.');
        }
        $fee = FeeObligation::query()->whereKey($application->fee_obligation_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $fee->setRelation('entries', $fee->entries()->when($forUpdate, fn ($query) => $query->lockForUpdate())->get());
        $amount = (int) $application->amount_kobo;
        if ($fee->settledAmountKobo() < $amount || $fee->entries->contains(fn (FeeObligationEntry $entry): bool => in_array($entry->entry_type, [FeeObligationEntryType::SavingsRefund, FeeObligationEntryType::ExternalRefundEntitlement], true))) {
            throw new ConflictHttpException('The full original payment is not retained or requires its concession owner.');
        }
        $accounts = LedgerAccount::query()->whereIn('code', [LedgerAccountCode::FeeIncome->value,
            LedgerAccountCode::CustomerSavingsLiability->value, LedgerAccountCode::BusinessDistributions->value])
            ->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->get()->keyBy(fn (LedgerAccount $account): string => $account->code->value);
        if ($accounts->count() !== 3) {
            throw new ConflictHttpException('The fee compensation accounting owner is unavailable.');
        }
        foreach ($accounts as $account) {
            [$class, $side] = match ($account->code) {
                LedgerAccountCode::FeeIncome => [LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit],
                LedgerAccountCode::CustomerSavingsLiability => [LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit],
                LedgerAccountCode::BusinessDistributions => [LedgerAccountClass::BusinessDistributions, LedgerEntrySide::Debit],
                default => throw new ConflictHttpException('Unsupported fee compensation account.'),
            };
            if ($account->mapping_status !== 'mapped' || $account->account_class !== $class || $account->normal_balance !== $side || $account->currency !== 'NGN') {
                throw new ConflictHttpException('The approved fee compensation accounting is unavailable.');
            }
        }
        $income = $this->balance($accounts->firstOrFail(fn (LedgerAccount $account): bool => $account->code === LedgerAccountCode::FeeIncome), $forUpdate);
        $drawn = $this->balance($accounts->firstOrFail(fn (LedgerAccount $account): bool => $account->code === LedgerAccountCode::BusinessDistributions), $forUpdate);
        $pending = 0;
        foreach (CashDisbursement::query()->where('kind', 'earnings_draw')->whereIn('status', ['processing', 'outcome_unknown'])
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() as $draw) {
            $pending = $this->add($pending, $draw->amount_kobo);
        }
        $undrawn = $income - $drawn - $pending;
        if ($undrawn < $amount) {
            throw new ConflictHttpException('The full original fee earnings are no longer available for compensation.');
        }
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        $period = app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, $forUpdate);
        $summary = ['application_reference' => $original->source_id, 'fee_obligation_id' => $fee->id,
            'thrift_plan_id' => $plan->id, 'amount_kobo' => $amount, 'disposition' => 'restore_savings_and_valid_unpaid_fee',
            'business_timezone' => $business->timezone, 'occurred_on' => $date, 'business_version' => $business->version,
            'period_id' => $period->id, 'period_version' => $period->version,
            'account_versions' => $accounts->mapWithKeys(fn (LedgerAccount $account): array => [$account->code->value => $account->version])->all()];
        $dependencies = [['kind' => 'retained_fee_payment', 'classification' => 'compensable', 'amount_kobo' => $amount],
            ['kind' => 'undrawn_fee_earnings', 'classification' => 'compensable', 'retained_kobo' => $undrawn]];

        return ['gross_kobo' => $amount, 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$original->payload_hash, $summary, $dependencies,
                $fee->entries->pluck('id')->all(), LedgerPostingGroup::query()->max('id')], JSON_THROW_ON_ERROR))];
    }

    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup
    {
        $original = app(FeeSavingsApplicationService::class)->assertPosted($preview['summary']['application_reference'], true);
        $group = LedgerPostingGroup::create(['posting_reference' => 'REV-'.Str::uuid(), 'idempotency_key' => 'fee-application-compensation-'.$request->id,
            'payload_hash' => $preview['fingerprint'], 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'event_type' => 'fee_application_compensation', 'currency' => 'NGN', 'actor_user_id' => $reviewer->id,
            'customer_profile_id' => $request->customer_profile_id, 'thrift_plan_id' => $original->thrift_plan_id,
            'occurred_at' => now(), 'occurred_on' => $preview['summary']['occurred_on'], 'business_timezone' => $preview['summary']['business_timezone'],
            'schema_version' => 1, 'committed_at' => now(), 'metadata' => ['original_posting_group_id' => $original->id,
                'application_reference' => $original->source_id, 'fee_obligation_id' => $preview['summary']['fee_obligation_id']]]);
        foreach ($original->entries as $line) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $line->line_number,
                'ledger_account_id' => $line->ledger_account_id, 'side' => $line->side === LedgerEntrySide::Debit ? LedgerEntrySide::Credit : LedgerEntrySide::Debit,
                'amount_kobo' => $line->amount_kobo, 'customer_profile_id' => $line->customer_profile_id,
                'thrift_plan_id' => $line->thrift_plan_id, 'fee_obligation_id' => $line->fee_obligation_id]);
        }
        FeeObligationEntry::create(['fee_obligation_id' => $preview['summary']['fee_obligation_id'], 'entry_type' => FeeObligationEntryType::SettlementReversal,
            'amount_kobo' => $preview['gross_kobo'], 'currency' => 'NGN', 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'idempotency_key' => 'fee-application-settlement-reversal-'.$request->id, 'actor_user_id' => $reviewer->id,
            'ledger_posting_reference' => $group->posting_reference, 'customer_description' => $request->customer_explanation]);

        return $group;
    }

    public function assertPosted(int $requestId, bool $current = false): LedgerPostingGroup
    {
        return $this->verifiedPostedSources([$requestId], $current)->get($requestId)
            ?? throw new ConflictHttpException('The retained fee payment compensation is unavailable.');
    }

    /** @param list<int> $requestIds
     * @return Collection<int, LedgerPostingGroup>
     */
    public function verifiedPostedSources(array $requestIds, bool $current = false): Collection
    {
        if ($current && DB::transactionLevel() === 0) {
            throw new LogicException('Current compensation proof requires an owning transaction.');
        }
        if ($requestIds === []) {
            return collect();
        }
        $requests = ReversalRequest::query()->with(['events' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock())])
            ->whereIn('id', $requestIds)->when($current, fn ($query) => $query->sharedLock())->get();
        $groups = LedgerPostingGroup::query()->with(['entries.account' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock()), 'entries' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock())])
            ->whereIn('id', $requests->flatMap(fn (ReversalRequest $request): array => [$request->original_posting_group_id, $request->compensation_posting_group_id]))
            ->when($current, fn ($query) => $query->sharedLock())->get()->keyBy('id');
        $originals = $groups->where('source_type', 'fee_savings_application');
        $applications = app(FeeSavingsApplicationService::class)->verifiedPostedSources(array_values($originals->map(fn (LedgerPostingGroup $group): string => $group->source_id)->all()), $current)->keyBy('id');
        $entries = FeeObligationEntry::query()->where('source_type', 'reversal_request')->whereIn('source_id', $requestIds)
            ->when($current, fn ($query) => $query->sharedLock())->get()->groupBy('source_id');
        $verified = collect();
        foreach ($requests as $request) {
            try {
                $original = $applications->get($request->original_posting_group_id);
                $group = $groups->get($request->compensation_posting_group_id);
                $effects = $entries->get((string) $request->id, collect());
                $effect = $effects->first();
                $feeId = $original?->entries->first()?->fee_obligation_id;
                $event = $request->events->firstWhere('event_type', 'approved_posted');
                if ($original === null || $group === null || $request->state !== 'approved_posted' || $request->reviewed_by_user_id === null
                    || $request->requested_by_user_id === $request->reviewed_by_user_id || $request->reviewed_at === null
                    || $request->currency !== 'NGN' || $request->live_original_posting_group_id !== null
                    || $request->posted_original_posting_group_id !== $original->id || $request->original_amount_kobo !== $original->entries->first()->amount_kobo
                    || $request->events->where('event_type', 'approved_posted')->where('actor_user_id', $request->reviewed_by_user_id)->count() !== 1
                    || ($event?->metadata['compensation_posting_group_id'] ?? null) !== $group->id
                    || ($event?->metadata['version'] ?? null) !== $request->version
                    || ($event?->metadata['owner_fingerprint'] ?? null) !== $group->payload_hash
                    || $group->getRawOriginal('committed_at') === null || $group->occurred_at === null || $group->occurred_on === null
                    || $group->source_type !== 'reversal_request' || $group->source_id !== (string) $request->id || $group->event_type !== 'fee_application_compensation'
                    || $group->idempotency_key !== 'fee-application-compensation-'.$request->id || $group->currency !== 'NGN'
                    || $group->actor_user_id !== $request->reviewed_by_user_id || $group->customer_profile_id !== $request->customer_profile_id
                    || $group->customer_profile_id !== $original->customer_profile_id || $group->thrift_plan_id !== $original->thrift_plan_id
                    || ($group->metadata['original_posting_group_id'] ?? null) !== $original->id
                    || ($group->metadata['application_reference'] ?? null) !== $original->source_id || ($group->metadata['fee_obligation_id'] ?? null) !== $feeId
                    || $effects->count() !== 1 || $effect === null || $effect->entry_type !== FeeObligationEntryType::SettlementReversal
                    || $effect->fee_obligation_id !== $feeId || $effect->amount_kobo !== $request->original_amount_kobo || $effect->currency !== 'NGN'
                    || $effect->actor_user_id !== $request->reviewed_by_user_id || $effect->ledger_posting_reference !== $group->posting_reference
                    || $effect->idempotency_key !== 'fee-application-settlement-reversal-'.$request->id || $group->entries->count() !== 2) {
                    throw new ConflictHttpException('The exact fee compensation source does not reconcile.');
                }
                foreach ($original->entries as $line) {
                    $counter = $group->entries->firstWhere('line_number', $line->line_number);
                    if ($counter === null || $counter->ledger_account_id !== $line->ledger_account_id || $counter->side === $line->side
                        || $counter->amount_kobo !== $line->amount_kobo || $counter->customer_profile_id !== $line->customer_profile_id
                        || $counter->thrift_plan_id !== $line->thrift_plan_id || $counter->fee_obligation_id !== $line->fee_obligation_id
                        || $counter->agent_profile_id !== null) {
                        throw new ConflictHttpException('The exact fee compensation journal does not reconcile.');
                    }
                }
                $verified->put($request->id, $group);
            } catch (RuntimeException|ValueError) {
                continue;
            }
        }

        return $verified;
    }

    private function balance(LedgerAccount $account, bool $current): int
    {
        $balance = 0;
        foreach (LedgerEntry::query()->where('ledger_account_id', $account->id)->when($current, fn ($query) => $query->lockForUpdate())->get() as $line) {
            $balance = $this->add($balance, $line->side === $account->normal_balance ? $line->amount_kobo : -$line->amount_kobo);
        }
        if ($balance < 0) {
            throw new ConflictHttpException('The fee earnings position is unavailable.');
        }

        return $balance;
    }

    private function add(int $total, int $amount): int
    {
        if (($amount > 0 && $total > PHP_INT_MAX - $amount) || ($amount < 0 && $total < PHP_INT_MIN - $amount)) {
            throw new ConflictHttpException('The fee earnings position exceeds the supported range.');
        }

        return $total + $amount;
    }
}
