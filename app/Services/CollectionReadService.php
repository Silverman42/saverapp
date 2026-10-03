<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\FeeLedgerPostingType;
use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Models\AgentProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CollectionReadService
{
    public function hasPendingCorrectionForBatches(QueryBuilder $batchIds): bool
    {
        $receipts = DB::table('collection_receipts')->whereIn('collection_batch_id', $batchIds->clone()->select('id'));
        $savingsGroups = (clone $receipts)->select('savings_posting_group_id');
        $feeGroups = DB::table('collection_fee_components')
            ->whereIn('collection_receipt_id', (clone $receipts)->select('id'))
            ->select('ledger_posting_group_id');

        return DB::table('reversal_requests')->where('state', 'pending_review')
            ->where(static function (QueryBuilder $query) use ($savingsGroups, $feeGroups): void {
                $query->whereIn('original_posting_group_id', $savingsGroups)
                    ->orWhereIn('original_posting_group_id', $feeGroups);
            })->lockForUpdate()->first(['id']) !== null;
    }

    public function agentOffboardingStatus(AgentProfile $agent, bool $forUpdate = false): string
    {
        if (app(CollectionPaymentEvidenceService::class)->unresolved()->where('evidence.recording_agent_profile_id', $agent->id)->exists()) {
            return 'blocked';
        }
        $mapping = DB::table('ledger_accounts')->where('code', LedgerAccountCode::AgentReceivable->value)
            ->where('mapping_status', 'mapped')->where('currency', 'NGN')->where('normal_balance', 'debit')->first();
        if ($mapping === null) {
            return 'unavailable';
        }
        $batches = CollectionBatch::query()->where('agent_profile_id', $agent->id)->orderBy('id');
        if ($forUpdate) {
            $batches->lockForUpdate();
        }
        foreach ($batches->get() as $batch) {
            if ($batch->status !== 'reconciled') {
                return in_array($batch->status, ['open', 'ready_for_review', 'in_review', 'exception'], true) ? 'blocked' : 'unavailable';
            }
            $review = DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->latest('id')->first();
            try {
                $position = app(CollectionBatchPosition::class)->read($batch);
                app(CollectionExceptionResolution::class)->assertResolvedCases($batch);
            } catch (ConflictHttpException|ServiceUnavailableHttpException) {
                return 'unavailable';
            }
            if ($review === null || $review->outcome !== 'reconciled' || (int) $review->outstanding_kobo !== 0
                || DB::table('collection_exceptions')->where('collection_batch_id', $batch->id)->where('status', '!=', 'resolved')->exists()
                || $position['outstanding_kobo'] !== 0) {
                return 'unavailable';
            }
        }
        $balance = DB::table('ledger_entries')->where('ledger_account_id', $mapping->id)->where('agent_profile_id', $agent->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END), 0) AS balance")->first();
        $amount = filter_var($balance->balance, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0) {
            return 'unavailable';
        }

        return $amount === 0 ? 'passed' : 'blocked';
    }

    /** @param Builder<CustomerProfile> $customers */
    public function scopedLiability(Builder $customers): int
    {
        $account = DB::table('ledger_accounts')->where('code', LedgerAccountCode::CustomerSavingsLiability->value)
            ->where('mapping_status', 'mapped')->where('currency', 'NGN')->where('normal_balance', 'credit')->first();
        if ($account === null) {
            throw new RuntimeException('Savings account mapping is unavailable.');
        }
        if ($this->invalidFeeSavingsCustomers((clone $customers)->toBase(), (int) $account->id) !== []) {
            throw new RuntimeException('Customer fee savings source integrity is unavailable.');
        }
        $total = 0;
        $rows = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
            ->whereIn('customer_profile_id', (clone $customers)->select('id'))
            ->selectRaw("customer_profile_id, SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END) AS liability")
            ->groupBy('customer_profile_id')->cursor();
        foreach ($rows as $row) {
            $liability = filter_var($row->liability, FILTER_VALIDATE_INT);
            if ($liability === false || $liability < 0) {
                throw new RuntimeException('Customer savings integrity is unavailable.');
            }
            $total = $this->checkedAdd($total, $liability);
        }

        return $total;
    }

    /**
     * Bulk read of the same subsidiary balances used by position(), inside the caller's snapshot.
     *
     * @param  Builder<CustomerProfile>  $customers
     * @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int}
     */
    public function scopedPosition(Builder $customers): array
    {
        $account = DB::table('ledger_accounts')->where('code', LedgerAccountCode::CustomerSavingsLiability->value)
            ->where('mapping_status', 'mapped')->where('currency', 'NGN')->where('normal_balance', 'credit')->first();
        if ($account === null) {
            throw new RuntimeException('Savings account mapping is unavailable.');
        }
        if ($this->invalidFeeSavingsCustomers((clone $customers)->toBase(), (int) $account->id) !== []) {
            throw new RuntimeException('Customer fee savings source integrity is unavailable.');
        }
        $liabilities = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
            ->selectRaw("customer_profile_id, SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END) AS liability")
            ->groupBy('customer_profile_id');
        $reservations = DB::table('withdrawal_reservations')->where('status', 'live')
            ->selectRaw('customer_profile_id, SUM(gross_amount_kobo) AS reserved')->groupBy('customer_profile_id');
        $rows = (clone $customers)->toBase()
            ->leftJoinSub($liabilities, 'liabilities', 'liabilities.customer_profile_id', '=', 'customer_profiles.id')
            ->leftJoinSub($reservations, 'reservations', 'reservations.customer_profile_id', '=', 'customer_profiles.id')
            ->selectRaw('COALESCE(liability, 0) AS liability, COALESCE(reserved, 0) AS reserved')->cursor();
        $total = 0;
        $reservedTotal = 0;
        foreach ($rows as $row) {
            $liability = filter_var($row->liability, FILTER_VALIDATE_INT);
            $reserved = filter_var($row->reserved, FILTER_VALIDATE_INT);
            if ($liability === false || $reserved === false || $liability < 0 || $reserved < 0 || $reserved > $liability) {
                throw new RuntimeException('Customer savings or reservation integrity is unavailable.');
            }
            $total = $this->checkedAdd($total, $liability);
            $reservedTotal = $this->checkedAdd($reservedTotal, $reserved);
        }

        return ['liability_kobo' => $total, 'reservations_kobo' => $reservedTotal, 'available_kobo' => $total - $reservedTotal];
    }

    /** @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int} */
    public function position(CustomerProfile $customer, bool $forUpdate = false): array
    {
        if ($forUpdate) {
            if (DB::transactionLevel() === 0) {
                throw new RuntimeException('An authoritative savings position requires a transaction.');
            }
            CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        }
        $accountQuery = DB::table('ledger_accounts')->where('code', LedgerAccountCode::CustomerSavingsLiability->value);
        if ($forUpdate) {
            $accountQuery->lockForUpdate();
        }
        $account = $accountQuery->first();
        if ($account === null || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
            || $account->account_class !== 'customer_savings_liability' || $account->normal_balance !== 'credit') {
            throw new ServiceUnavailableHttpException(null, 'Savings account mapping is unavailable.');
        }
        if ($this->invalidFeeSavingsCustomers(CustomerProfile::query()->whereKey($customer->id)->toBase(), (int) $account->id, $forUpdate) !== []) {
            throw new RuntimeException('Customer fee savings source integrity is unavailable.');
        }
        $entries = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_entries.customer_profile_id', $customer->id)
            ->where('ledger_accounts.code', LedgerAccountCode::CustomerSavingsLiability->value)
            ->select('ledger_entries.side', 'ledger_entries.amount_kobo');
        $reservations = DB::table('withdrawal_reservations')->where('customer_profile_id', $customer->id)
            ->where('status', 'live')->select('gross_amount_kobo');
        if ($forUpdate) {
            $entries->lockForUpdate();
            $reservations->lockForUpdate();
        }

        return $this->positionFromRows($entries->get(), $reservations->get());
    }

    /**
     * Read inside the caller's snapshot; financial commands continue using position().
     *
     * @param  list<int>  $customerIds
     * @return array<int, array{liability_kobo: int, reservations_kobo: int, available_kobo: int}|null>
     */
    public function positions(array $customerIds): array
    {
        if (count($customerIds) > 100 || count(array_unique($customerIds)) !== count($customerIds)) {
            throw new \InvalidArgumentException('Read at most 100 distinct Customer savings positions.');
        }
        if ($customerIds === []) {
            return [];
        }
        $account = DB::table('ledger_accounts')->where('code', LedgerAccountCode::CustomerSavingsLiability->value)->first();
        if ($account === null || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
            || $account->account_class !== 'customer_savings_liability' || $account->normal_balance !== 'credit') {
            throw new RuntimeException('Savings account mapping is unavailable.');
        }
        $entries = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
            ->whereIn('customer_profile_id', $customerIds)->select('customer_profile_id', 'side', 'amount_kobo')->orderBy('id')->get()
            ->groupBy('customer_profile_id');
        $reservations = DB::table('withdrawal_reservations')->whereIn('customer_profile_id', $customerIds)
            ->where('status', 'live')->select('customer_profile_id', 'gross_amount_kobo')->orderBy('id')->get()
            ->groupBy('customer_profile_id');
        $invalidCustomers = $this->invalidFeeSavingsCustomers(CustomerProfile::query()->whereKey($customerIds)->toBase(), (int) $account->id);
        $positions = [];
        foreach ($customerIds as $customerId) {
            try {
                if (in_array($customerId, $invalidCustomers, true)) {
                    throw new RuntimeException('Customer fee savings source integrity is unavailable.');
                }
                $positions[$customerId] = $this->positionFromRows($entries->get($customerId, collect()), $reservations->get($customerId, collect()));
            } catch (RuntimeException) {
                $positions[$customerId] = null;
            }
        }

        return $positions;
    }

    /** @return list<int> */
    private function invalidFeeSavingsCustomers(QueryBuilder $customers, int $liabilityAccountId, bool $current = false): array
    {
        $rows = DB::table('ledger_posting_groups as groups')
            ->leftJoin('reversal_requests as reversals', 'reversals.compensation_posting_group_id', '=', 'groups.id')
            ->leftJoin('ledger_posting_groups as originals', 'originals.id', '=', 'reversals.original_posting_group_id')
            ->leftJoin('ledger_entries as lines', function (JoinClause $join) use ($liabilityAccountId): void {
                $join->on('lines.ledger_posting_group_id', '=', 'groups.id')->where('lines.ledger_account_id', $liabilityAccountId);
            })
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->where('groups.source_type', 'fee_savings_application')
                ->orWhere('groups.event_type', 'fee_application_compensation')->orWhere('originals.source_type', 'fee_savings_application'))
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->whereIn('groups.customer_profile_id', (clone $customers)->select('id'))
                ->orWhereIn('lines.customer_profile_id', (clone $customers)->select('id')))
            ->select('groups.id', 'groups.source_type', 'groups.source_id', 'groups.customer_profile_id',
                'reversals.id as reversal_id', 'lines.customer_profile_id as entry_customer_id')
            ->orderBy('groups.id')->when($current, fn (QueryBuilder $query): QueryBuilder => $query->lockForUpdate())->get();
        $references = [];
        $requestIds = [];
        foreach ($rows as $row) {
            if ($row->source_type === 'fee_savings_application') {
                if (is_string($row->source_id)) {
                    $references[] = $row->source_id;
                }
            } else {
                $requestId = filter_var($row->reversal_id ?? $row->source_id, FILTER_VALIDATE_INT);
                if ($requestId !== false && $requestId > 0) {
                    $requestIds[] = $requestId;
                }
            }
        }
        $payments = app(FeeSavingsApplicationService::class)->verifiedPostedSources(array_values(array_unique($references)), $current);
        $compensations = app(FeeSavingsApplicationReversalOwner::class)->verifiedPostedSources(array_values(array_unique($requestIds)), $current);
        $invalid = [];
        foreach ($rows as $row) {
            if ($row->source_type === 'fee_savings_application') {
                $group = $payments->get($row->source_id);
            } else {
                $requestId = filter_var($row->reversal_id ?? $row->source_id, FILTER_VALIDATE_INT);
                $group = $requestId !== false && $requestId > 0 ? $compensations->get($requestId) : null;
            }
            if ($group === null || $group->id !== (int) $row->id || $group->customer_profile_id !== (int) $row->customer_profile_id
                || $group->customer_profile_id !== (int) $row->entry_customer_id) {
                foreach ([$row->customer_profile_id, $row->entry_customer_id] as $customerId) {
                    $identity = filter_var($customerId, FILTER_VALIDATE_INT);
                    if ($identity !== false && $identity > 0) {
                        $invalid[$identity] = true;
                    }
                }
            }
        }

        return array_keys($invalid);
    }

    /**
     * @param  Collection<int, stdClass>  $entries
     * @param  Collection<int, stdClass>  $reservations
     * @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int}
     */
    private function positionFromRows(Collection $entries, Collection $reservations): array
    {
        $liability = 0;
        foreach ($entries as $entry) {
            $amount = filter_var($entry->amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1 || ! in_array($entry->side, ['credit', 'debit'], true)) {
                throw new ServiceUnavailableHttpException(null, 'Savings entry integrity is unavailable.');
            }
            $liability = $entry->side === 'credit' ? $this->checkedAdd($liability, $amount) : $liability - $amount;
        }
        $reserved = 0;
        foreach ($reservations as $reservation) {
            $amount = filter_var($reservation->gross_amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1) {
                throw new ServiceUnavailableHttpException(null, 'Reservation amount integrity is unavailable.');
            }
            $reserved = $this->checkedAdd($reserved, $amount);
        }
        if ($liability < 0 || $reserved > $liability) {
            throw new ServiceUnavailableHttpException(null, 'Customer savings or reservation integrity is unavailable.');
        }

        return ['liability_kobo' => $liability, 'reservations_kobo' => $reserved, 'available_kobo' => $liability - $reserved];
    }

    /** @return array<string, mixed> */
    public function card(ThriftPlan $plan): array
    {
        return $this->fundingCard($plan) + ['position' => $this->position($plan->customerProfile)];
    }

    /** @return array<string, mixed> */
    public function fundingCard(ThriftPlan $plan): array
    {
        $revision = $plan->currentTermsRevision();
        if ($revision === null) {
            throw new RuntimeException('Plan terms are unavailable.');
        }
        $today = CarbonImmutable::now($revision->timezone)->toDateString();
        $blockedIntervals = [];
        $pauseDate = null;
        $planHistory = $plan->lifecycleEvents()->get();
        $firstPlanEvent = $planHistory->first();
        $unknownPlanBefore = $firstPlanEvent?->from_status === ThriftPlanStatus::Paused
            ? $firstPlanEvent->effective_at->setTimezone($revision->timezone)->toDateString() : null;
        $unknownIntervals = [];
        $previousPlanEvent = null;
        foreach ($planHistory as $event) {
            $eventDate = $event->effective_at->setTimezone($revision->timezone)->toDateString();
            if ($previousPlanEvent !== null && $event->from_status !== null
                && $previousPlanEvent->to_status !== $event->from_status) {
                $unknownIntervals[] = [$previousPlanEvent->effective_at->setTimezone($revision->timezone)->toDateString(), $eventDate];
            }
            $previousPlanEvent = $event;
            if ($event->event_type === 'pause' || ($event->to_status === ThriftPlanStatus::Paused
                && $event->from_status !== ThriftPlanStatus::Paused)) {
                $pauseDate = $eventDate;
            } elseif (($event->event_type === 'resume' || ($event->from_status === ThriftPlanStatus::Paused
                && $event->to_status === ThriftPlanStatus::Active)) && $pauseDate !== null) {
                $blockedIntervals[] = [$pauseDate, $eventDate];
                $pauseDate = null;
            }
        }
        if ($pauseDate !== null) {
            $blockedIntervals[] = [$pauseDate, null];
        }
        $unknownPlanAfter = $previousPlanEvent !== null && $previousPlanEvent->to_status !== null
            && $previousPlanEvent->to_status !== $plan->status
            ? $previousPlanEvent->effective_at->setTimezone($revision->timezone)->toDateString() : null;
        $customerHistory = $this->customerParticipationHistory($plan, $revision->timezone);
        $blockedIntervals = array_merge($blockedIntervals, $customerHistory['intervals']);
        $unknownIntervals = array_merge($unknownIntervals, $customerHistory['unknown_intervals']);
        $serviceHistory = $this->agentServiceHistory($plan, $revision->timezone);
        $serviceIntervals = $serviceHistory['intervals'];
        $unknownIntervals = array_merge($unknownIntervals, $serviceHistory['unknown_intervals']);
        $slots = $plan->slots()->whereNotNull('active_ordinal')->orderBy('active_ordinal')->get();
        $totals = DB::table('collection_allocations')->whereNotIn('collection_allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
            ->whereIn('contribution_slot_id', $slots->pluck('id'))
            ->selectRaw('contribution_slot_id, SUM(amount_kobo) as funded_kobo, MAX(is_advance) as has_advance')
            ->groupBy('contribution_slot_id')->get()->keyBy('contribution_slot_id');
        $annotations = DB::table('collection_annotations')
            ->whereIn('contribution_slot_id', $slots->pluck('id'))
            ->orderByDesc('version')->get()->unique('contribution_slot_id')->keyBy('contribution_slot_id');
        $fundedTotal = 0;
        $paidCount = 0;
        $rows = [];
        foreach ($slots as $slot) {
            $funded = (int) ($totals[$slot->id]->funded_kobo ?? 0);
            if ($funded > $slot->expected_amount_kobo) {
                throw new RuntimeException('Slot funding integrity is unavailable.');
            }
            $fundedTotal = $this->checkedAdd($fundedTotal, $funded);
            $paidCount += (int) ($funded === $slot->expected_amount_kobo);
            $annotation = $annotations[$slot->id] ?? null;
            $blocked = false;
            foreach ($blockedIntervals as [$start, $end]) {
                if ($slot->due_date >= $start && ($end === null || $slot->due_date < $end)) {
                    $blocked = true;
                    break;
                }
            }
            $classificationUnavailable = ($unknownPlanBefore !== null && $slot->due_date < $unknownPlanBefore)
                || ($customerHistory['unknown_before'] !== null && $slot->due_date < $customerHistory['unknown_before'])
                || ($unknownPlanAfter !== null && $slot->due_date >= $unknownPlanAfter)
                || ($customerHistory['unknown_after'] !== null && $slot->due_date >= $customerHistory['unknown_after']);
            foreach ($unknownIntervals as [$start, $end]) {
                if ($slot->due_date >= $start && ($end === null || $slot->due_date < $end)) {
                    $classificationUnavailable = true;
                    break;
                }
            }
            $serviceInterrupted = false;
            foreach ($serviceIntervals as [$start, $end]) {
                if ($slot->due_date >= $start && ($end === null || $slot->due_date < $end)) {
                    $serviceInterrupted = true;
                    break;
                }
            }
            $status = match (true) {
                $funded === $slot->expected_amount_kobo => 'paid',
                $classificationUnavailable => 'unavailable',
                $serviceInterrupted && ! $blocked => 'service-interrupted',
                $funded > 0 => 'partial',
                $blocked => 'blocked',
                $annotation !== null && $annotation->kind === 'skipped' => 'skipped',
                $annotation !== null && $annotation->kind === 'missed' => 'missed',
                $slot->due_date < $today => 'missed',
                default => 'pending',
            };
            $rows[] = [
                'id' => $slot->id, 'ordinal' => $slot->active_ordinal, 'due_date' => $slot->due_date,
                'target_kobo' => $slot->expected_amount_kobo, 'funded_kobo' => $funded,
                'remaining_kobo' => $slot->expected_amount_kobo - $funded,
                'status' => $status, 'advance' => (bool) ($totals[$slot->id]->has_advance ?? false),
                'blocked' => $blocked,
                'annotation_reason' => $annotation === null ? null : $annotation->reason,
                'annotation_version' => $annotation === null ? 0 : $annotation->version,
            ];
        }

        return [
            'plan_id' => $plan->plan_id, 'status' => $plan->status->value, 'timezone' => $revision->timezone,
            'target_kobo' => $revision->expected_gross_kobo, 'funded_kobo' => $fundedTotal,
            'paid_slots' => $paidCount, 'slot_count' => $slots->count(), 'slots' => $rows,
        ];
    }

    /** @return array{intervals: list<array{0: string, 1: string|null}>, unknown_before: string|null, unknown_after: string|null, unknown_intervals: list<array{0: string, 1: string}>} */
    private function customerParticipationHistory(ThriftPlan $plan, string $timezone): array
    {
        $intervals = [];
        $blockedDate = null;
        $history = CustomerStatusHistory::query()->where('customer_profile_id', $plan->customer_profile_id)
            ->orderBy('created_at')->orderBy('id')->get();
        $firstEvent = $history->first();
        $unknownBefore = $firstEvent !== null && $firstEvent->from_status !== null && $firstEvent->from_status !== CustomerStatus::Active
            ? $firstEvent->created_at->toImmutable()->setTimezone($timezone)->toDateString() : null;
        $unknownIntervals = [];
        $previousEvent = null;
        foreach ($history as $event) {
            $date = $event->created_at->toImmutable()->setTimezone($timezone)->toDateString();
            if ($previousEvent !== null && $event->from_status !== null && $previousEvent->to_status !== $event->from_status) {
                $unknownIntervals[] = [$previousEvent->created_at->toImmutable()->setTimezone($timezone)->toDateString(), $date];
            }
            $previousEvent = $event;
            if ($event->to_status !== CustomerStatus::Active && $blockedDate === null) {
                $blockedDate = $date;
            } elseif ($event->to_status === CustomerStatus::Active && $blockedDate !== null) {
                $intervals[] = [$blockedDate, $date];
                $blockedDate = null;
            }
        }
        if ($blockedDate !== null) {
            $intervals[] = [$blockedDate, null];
        }

        $currentStatus = DB::table('customer_profiles')->where('id', $plan->customer_profile_id)->value('operational_status');
        $unknownAfter = $previousEvent !== null && $previousEvent->to_status->value !== $currentStatus
            ? $previousEvent->created_at->toImmutable()->setTimezone($timezone)->toDateString() : null;

        return ['intervals' => $intervals, 'unknown_before' => $unknownBefore, 'unknown_after' => $unknownAfter, 'unknown_intervals' => $unknownIntervals];
    }

    /** @return array{intervals: list<array{0: string, 1: string|null}>, unknown_intervals: list<array{0: string, 1: string|null}>} */
    private function agentServiceHistory(ThriftPlan $plan, string $timezone): array
    {
        $assignments = $plan->customerProfile->assignments()->orderBy('effective_at')->orderBy('id')->get();
        $history = DB::table('agent_status_histories')->whereIn('agent_profile_id', $assignments->pluck('agent_profile_id'))
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('agent_profile_id');
        $accountHistory = DB::table('agent_lifecycle_histories')->whereIn('agent_profile_id', $assignments->pluck('agent_profile_id'))
            ->select('agent_profile_id', 'created_at', 'id')->selectRaw('from_account_state as from_status, to_account_state as to_status')
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('agent_profile_id');
        $currentStates = DB::table('agent_profiles')->leftJoin('users', 'users.id', '=', 'agent_profiles.user_id')
            ->whereIn('agent_profiles.id', $assignments->pluck('agent_profile_id'))
            ->select('agent_profiles.id', 'agent_profiles.operational_status', 'users.account_state')->get()->keyBy('id');
        $intervals = [];
        $unknownIntervals = [];
        foreach ($assignments as $assignment) {
            $assignedDate = $assignment->effective_at->toImmutable()->setTimezone($timezone)->toDateString();
            $endedDate = $assignment->ended_at?->toImmutable()->setTimezone($timezone)->toDateString();
            $agentIntervals = [];
            $agentUnknownIntervals = [];
            foreach ([$history->get($assignment->agent_profile_id, collect()), $accountHistory->get($assignment->agent_profile_id, collect())] as $stream => $events) {
                $firstEvent = $events->first();
                if ($firstEvent !== null && $firstEvent->from_status !== null && $firstEvent->from_status !== 'active') {
                    $knownDate = CarbonImmutable::parse($firstEvent->created_at, 'UTC')->setTimezone($timezone)->toDateString();
                    $agentUnknownIntervals[] = [$assignedDate, $knownDate];
                }
                $inactiveDate = null;
                $previousEvent = null;
                foreach ($events as $event) {
                    $date = CarbonImmutable::parse($event->created_at, 'UTC')->setTimezone($timezone)->toDateString();
                    if ($previousEvent !== null && $event->from_status !== null && $previousEvent->to_status !== $event->from_status) {
                        $agentUnknownIntervals[] = [CarbonImmutable::parse($previousEvent->created_at, 'UTC')->setTimezone($timezone)->toDateString(), $date];
                    }
                    $previousEvent = $event;
                    if ($event->to_status !== 'active' && $inactiveDate === null) {
                        $inactiveDate = $date;
                    } elseif ($event->to_status === 'active' && $inactiveDate !== null) {
                        $agentIntervals[] = [$inactiveDate, $date];
                        $inactiveDate = null;
                    }
                }
                if ($inactiveDate !== null) {
                    $agentIntervals[] = [$inactiveDate, null];
                }
                $currentState = $stream === 0
                    ? ($currentStates->get($assignment->agent_profile_id)?->operational_status)
                    : ($currentStates->get($assignment->agent_profile_id)?->account_state);
                if ($previousEvent !== null && $previousEvent->to_status !== $currentState) {
                    $agentUnknownIntervals[] = [CarbonImmutable::parse($previousEvent->created_at, 'UTC')->setTimezone($timezone)->toDateString(), null];
                }
            }
            foreach ($agentUnknownIntervals as [$start, $end]) {
                $start = max($start, $assignedDate);
                if ($endedDate !== null) {
                    $end = $end === null ? $endedDate : min($end, $endedDate);
                }
                if ($end === null || $start < $end) {
                    $unknownIntervals[] = [$start, $end];
                }
            }
            foreach ($agentIntervals as [$start, $end]) {
                $start = max($start, $assignedDate);
                if ($endedDate !== null) {
                    $end = $end === null ? $endedDate : min($end, $endedDate);
                }
                if ($end === null || $start < $end) {
                    $intervals[] = [$start, $end];
                }
            }
        }

        return ['intervals' => $intervals, 'unknown_intervals' => $unknownIntervals];
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new RuntimeException('Financial total exceeds the supported integer range.');
        }

        return $left + $right;
    }

    public function archivalStatus(CustomerProfile $customer): string
    {
        if (app(CollectionPaymentEvidenceService::class)->unresolved()->where('evidence.customer_profile_id', $customer->id)->exists()) {
            return 'blocked';
        }
        $receipts = CollectionReceipt::query()->where('customer_profile_id', $customer->id)->get();
        foreach ($receipts as $receipt) {
            if ($receipt->tender_amount_kobo !== $receipt->savings_amount_kobo + $receipt->fee_amount_kobo) {
                return 'unavailable';
            }
            if ($receipt->savings_amount_kobo > 0) {
                $posting = DB::table('ledger_posting_groups')->where('id', $receipt->savings_posting_group_id)->first();
                if ($posting === null || $posting->source_type !== 'collection_receipt' || $posting->source_id !== (string) $receipt->id
                    || (int) $posting->customer_profile_id !== $customer->id
                    || (int) $receipt->allocations()->sum('amount_kobo') !== $receipt->savings_amount_kobo) {
                    return 'unavailable';
                }
                $postedSavings = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
                    ->where('ledger_posting_group_id', $posting->id)->where('side', 'credit')
                    ->where('ledger_accounts.code', LedgerAccountCode::CustomerSavingsLiability->value)->sum('amount_kobo');
                if ((int) $postedSavings !== $receipt->savings_amount_kobo) {
                    return 'unavailable';
                }
                foreach ($receipt->allocations()->get() as $allocation) {
                    if ($allocation->amount_kobo < 1 || ! DB::table('contribution_slots')->where('id', $allocation->contribution_slot_id)
                        ->where('thrift_plan_id', $receipt->thrift_plan_id)->exists()) {
                        return 'unavailable';
                    }
                }
            }
            $components = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->get();
            if ((int) $components->sum('amount_kobo') !== $receipt->fee_amount_kobo) {
                return 'unavailable';
            }
            foreach ($components as $component) {
                if (! DB::table('ledger_posting_groups')->where('id', $component->ledger_posting_group_id)
                    ->where('customer_profile_id', $customer->id)->where('source_type', 'collection_receipt')
                    ->where('source_id', $receipt->id.'-'.$component->fee_obligation_id)->exists()
                    || ! DB::table('fee_obligations')->where('id', $component->fee_obligation_id)->where('customer_profile_id', $customer->id)->exists()
                    || $component->amount_kobo < 1) {
                    return 'unavailable';
                }
            }
            $batch = $receipt->batch;
            if ($batch === null) {
                return 'unavailable';
            }
            if (DB::table('collection_exceptions')->where('collection_batch_id', $batch->id)->where('status', '!=', 'resolved')->exists()) {
                return 'unavailable';
            }
            if ($batch->status !== 'reconciled') {
                return in_array($batch->status, ['open', 'ready_for_review', 'in_review', 'exception'], true) ? 'blocked' : 'unavailable';
            }
            $review = DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->orderByDesc('id')->first();
            try {
                $position = app(CollectionBatchPosition::class)->read($batch);
                app(CollectionExceptionResolution::class)->assertResolvedCases($batch);
            } catch (ConflictHttpException|ServiceUnavailableHttpException) {
                return 'unavailable';
            }
            if ($review === null || $review->outcome !== 'reconciled' || (int) $review->outstanding_kobo !== 0
                || $position['outstanding_kobo'] !== 0) {
                return 'unavailable';
            }
        }

        return 'passed';
    }

    public function archivalSavingsStatus(CustomerProfile $customer, bool $forUpdate = false): string
    {
        foreach ([LedgerAccountCode::UnappliedFunds, LedgerAccountCode::RefundPayable] as $code) {
            $balance = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
                ->where('ledger_entries.customer_profile_id', $customer->id)->where('ledger_accounts.code', $code->value)
                ->selectRaw("COALESCE(SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -CAST(amount_kobo AS SIGNED) END), 0) AS balance")->value('balance');
            $integer = filter_var($balance, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0) {
                return 'unavailable';
            }
            if ($integer > 0) {
                return 'blocked';
            }
        }
        $position = $this->position($customer, $forUpdate);
        $groups = LedgerPostingGroup::query()->where('customer_profile_id', $customer->id)->with('entries.account')->get();
        foreach ($groups as $group) {
            if (! in_array($group->event_type, ['cash_contribution', 'noncash_contribution', 'cash_withdrawal', 'receipt_reclassification', 'withdrawal_compensation', 'deduction_compensation', 'fee_refund', ...array_column(FeeLedgerPostingType::cases(), 'value')], true)
                || $group->currency !== 'NGN' || $group->entries->count() < 2 || $group->getRawOriginal('committed_at') === null) {
                return 'unavailable';
            }
            $debits = 0;
            $credits = 0;
            foreach ($group->entries as $entry) {
                $account = $entry->account;
                if ($account === null || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
                    || $entry->amount_kobo < 1 || ! in_array($entry->getRawOriginal('side'), ['debit', 'credit'], true)
                    || ($entry->customer_profile_id !== null && $entry->customer_profile_id !== $customer->id)) {
                    return 'unavailable';
                }
                if ($entry->side->value === 'debit') {
                    $debits = $this->checkedAdd($debits, $entry->amount_kobo);
                } else {
                    $credits = $this->checkedAdd($credits, $entry->amount_kobo);
                }
            }
            if ($debits !== $credits) {
                return 'unavailable';
            }
        }
        if (DB::table('ledger_entries')->where('customer_profile_id', $customer->id)
            ->whereNotIn('ledger_posting_group_id', $groups->pluck('id'))->exists()) {
            return 'unavailable';
        }

        return $position['liability_kobo'] === 0 && $position['reservations_kobo'] === 0 ? 'passed' : 'blocked';
    }
}
