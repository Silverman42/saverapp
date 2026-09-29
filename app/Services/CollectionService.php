<?php

namespace App\Services;

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\CustomerActivity;
use App\Enums\FeeLedgerPostingType;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionAllocation;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\ContributionSlot;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\PlanLifecycleEvent;
use App\Models\ThriftPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionService
{
    public function __construct(
        private BusinessSettings $settings,
        private CustomerActionAuthorizationGuard $authorizationGuard,
        private CustomerActivityGate $activityGate,
        private CollectionLedgerService $ledger,
        private CollectionReadService $balances,
        private FeeObligationService $fees,
        private LedgerPostingService $feeLedger,
        private PublicIdGenerator $references,
        private LedgerTransactionProjectionService $transactions,
        private FinancialPeriodService $periods,
        private CollectionReceivedTime $receivedTimes,
    ) {}

    public function amountToKobo(string $amount): int
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/', $amount)) {
            throw ValidationException::withMessages(['amount' => ['Enter an exact NGN amount with at most two decimal places.']]);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $kobo = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($kobo < 1 || $kobo > 999_999_999_999) {
            throw ValidationException::withMessages(['amount' => ['Amount exceeds the supported receipt limit.']]);
        }

        return $kobo;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, CustomerProfile $customer, array $data): array
    {
        Gate::forUser($actor)->authorize('recordCollection', $customer);
        $this->settings->ensureFeature('collections');
        $this->settings->ensureFeature('collection_cash');
        $configuration = $this->settings->collectionLimits();
        $limits = $configuration['values'];
        $business = BusinessProfile::current();
        $today = CarbonImmutable::now($business->timezone)->startOfDay();
        $received = CarbonImmutable::createFromFormat('!Y-m-d', $data['received_date'], $business->timezone);
        if ($received === null || $received->gt($today) || $received->lt($today->subDays($limits['late_lookback_days']))) {
            throw ValidationException::withMessages(['received_date' => ['Choose today or a received date within the configured late lookback.']]);
        }
        if ($received->lt($today) && blank($data['late_reason'] ?? null)) {
            throw ValidationException::withMessages(['late_reason' => ['Explain why this payment is being recorded late.']]);
        }
        $this->periods->assertOpen($received->toDateString(), $business->timezone);

        $savings = filled($data['savings_ngn'] ?? null) && $data['savings_ngn'] !== '0'
            ? $this->amountToKobo((string) $data['savings_ngn']) : 0;
        $feeItems = [];
        $feeTotal = 0;
        foreach ($data['fees'] ?? [] as $item) {
            $obligation = FeeObligation::query()->whereKey((int) $item['obligation_id'])->firstOrFail();
            if ($obligation->customer_profile_id !== $customer->id || $obligation->currency !== 'NGN') {
                throw ValidationException::withMessages(['fees' => ['The selected fee belongs to another Customer or currency.']]);
            }
            $amount = $this->amountToKobo((string) $item['amount_ngn']);
            if ($amount > $obligation->outstandingAmountKobo()) {
                throw ValidationException::withMessages(['fees' => ['A fee component exceeds its current outstanding amount.']]);
            }
            $feeTotal = $this->checkedAdd($feeTotal, $amount);
            $feeItems[] = ['obligation_id' => $obligation->id, 'amount_kobo' => $amount,
                'outstanding_kobo' => $obligation->outstandingAmountKobo(),
                'fee_snapshot_id' => $obligation->fee_snapshot_id,
                'latest_entry_id' => $obligation->entries()->max('id')];
        }
        if (count(array_unique(array_column($feeItems, 'obligation_id'))) !== count($feeItems)) {
            throw ValidationException::withMessages(['fees' => ['List each fee obligation once.']]);
        }
        $total = $this->checkedAdd($savings, $feeTotal);
        if ($total < $limits['receipt_minimum_kobo'] || $total > $limits['receipt_maximum_kobo']) {
            throw ValidationException::withMessages(['savings_ngn' => ['Receipt tender must be positive and within the supported cap.']]);
        }

        $plan = null;
        $receivedAtUtc = null;
        $planReceivedDate = null;
        $planTimezone = null;
        $allocations = [];
        $slotOptions = [];
        if ($savings > 0) {
            $this->activityGate->allows($customer->operational_status, CustomerActivity::RecordContribution)
                || throw ValidationException::withMessages(['customer' => ['This Customer cannot receive a savings contribution.']]);
            $plan = ThriftPlan::query()->where('plan_id', $data['plan_id'] ?? '')->firstOrFail();
            if ($plan->customer_profile_id !== $customer->id || $plan->status !== ThriftPlanStatus::Active) {
                throw ValidationException::withMessages(['plan_id' => ['Choose an active plan for this Customer.']]);
            }
            $planTimezone = $plan->currentTermsRevision()?->timezone;
            if ($planTimezone === null) {
                throw new ConflictHttpException('Plan timezone is unavailable.');
            }
            if ($planTimezone !== $business->timezone) {
                if (blank($data['received_local_time'] ?? null) || blank($data['received_utc_offset'] ?? null)) {
                    throw ValidationException::withMessages(['received_local_time' => ['Enter the actual received time and choose its UTC offset for this plan.']]);
                }
                $receivedAtUtc = $this->receivedTimes->resolve($received->toDateString(),
                    $data['received_local_time'], $business->timezone, $data['received_utc_offset']);
                if ($receivedAtUtc->isFuture()) {
                    throw ValidationException::withMessages(['received_local_time' => ['The received time cannot be in the future.']]);
                }
                $planReceivedDate = $receivedAtUtc->setTimezone($planTimezone)->toDateString();
            } else {
                $planReceivedDate = $received->toDateString();
            }
            $slots = $plan->slots()->whereNotNull('active_ordinal')->orderBy('active_ordinal')->get();
            $fundedBySlot = DB::table('collection_allocations')
                ->whereIn('contribution_slot_id', $slots->pluck('id'))
                ->selectRaw('contribution_slot_id, SUM(amount_kobo) as funded_kobo')
                ->groupBy('contribution_slot_id')->pluck('funded_kobo', 'contribution_slot_id');
            $requested = [];
            foreach ($data['allocations'] ?? [] as $requestedAllocation) {
                $slotId = (int) $requestedAllocation['slot_id'];
                if (isset($requested[$slotId])) {
                    throw ValidationException::withMessages(['allocations' => ['List each slot only once.']]);
                }
                $requested[$slotId] = $requestedAllocation;
            }
            $remaining = $savings;
            foreach ($slots as $slot) {
                $funded = (int) ($fundedBySlot[$slot->id] ?? 0);
                $capacity = $slot->expected_amount_kobo - $funded;
                if ($capacity < 0) {
                    throw new ConflictHttpException('Slot funding integrity is unavailable.');
                }
                if ($capacity > 0) {
                    $slotOptions[] = ['slot_id' => $slot->id, 'due_date' => $slot->due_date,
                        'capacity_kobo' => $capacity];
                }
                $amount = $requested === []
                    ? min($remaining, $capacity)
                    : (isset($requested[$slot->id]) ? $this->amountToKobo((string) $requested[$slot->id]['amount_ngn']) : 0);
                if ($amount > $capacity) {
                    throw ValidationException::withMessages(['allocations' => ['An allocation exceeds the current slot capacity.']]);
                }
                if ($amount > 0) {
                    $allocations[] = ['slot_id' => $slot->id, 'due_date' => $slot->due_date,
                        'amount_kobo' => $amount, 'capacity_kobo' => $capacity];
                    $remaining -= $amount;
                }
            }
            if ($remaining !== 0 || ($requested !== [] && count($allocations) !== count($requested))) {
                throw ValidationException::withMessages(['allocations' => ['Allocate every savings kobo to an eligible slot.']]);
            }
        } elseif (filled($data['plan_id'] ?? null) || ! $this->activityGate->allows($customer->operational_status, CustomerActivity::ApplyAgreedFee)) {
            throw ValidationException::withMessages(['plan_id' => ['Fee-only collection cannot include a plan or this Customer status.']]);
        }
        if ($receivedAtUtc === null && (filled($data['received_local_time'] ?? null) || filled($data['received_utc_offset'] ?? null))) {
            throw ValidationException::withMessages(['received_local_time' => ['An exact received time is only used for a cross-timezone savings plan.']]);
        }

        $assignment = $customer->currentAssignment;
        if ($assignment === null) {
            throw new ConflictHttpException('Customer assignment is unavailable.');
        }
        $fingerprint = hash('sha256', json_encode([
            $actor->id, $customer->id, $customer->version, $assignment->id, $assignment->version,
            $plan?->id, $plan?->version, $plan?->current_terms_revision,
            $business->version, $business->timezone, $received->toDateString(),
            $receivedAtUtc?->toIso8601String(), $planTimezone, $planReceivedDate,
            $savings, $feeItems, $allocations, trim((string) ($data['late_reason'] ?? '')),
            trim((string) ($data['notes'] ?? '')),
        ], JSON_THROW_ON_ERROR));

        return [
            'customer_id' => $customer->customer_id, 'customer_version' => $customer->version,
            'assignment_version' => $assignment->version, 'plan_id' => $plan?->plan_id,
            'plan_version' => $plan?->version, 'received_date' => $received->toDateString(),
            'timezone' => $business->timezone, 'business_version' => $business->version,
            'received_at_utc' => $receivedAtUtc?->toIso8601String(),
            'plan_timezone' => $planTimezone, 'plan_received_date' => $planReceivedDate,
            'savings_kobo' => $savings, 'fees_kobo' => $feeTotal, 'tender_kobo' => $total,
            'fee_items' => $feeItems, 'allocations' => $allocations, 'slot_options' => $slotOptions,
            'preview_fingerprint' => $fingerprint,
        ];
    }

    /** @param array<string, mixed> $data */
    public function record(User $actor, CustomerProfile $customer, array $data): CollectionReceipt
    {
        $submittedHash = hash('sha256', json_encode([$actor->id, $customer->id, $data], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $data, $submittedHash): CollectionReceipt {
            $existing = CollectionReceipt::query()->where('attempt_reference', $data['attempt_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->recorded_by_user_id !== $actor->id || ! hash_equals($existing->payload_hash, $submittedHash)) {
                    throw new ConflictHttpException('This receipt attempt belongs to a different request.');
                }
                Gate::forUser($actor)->authorize('view', $existing->customerProfile);

                return $existing;
            }

            $context = $this->authorizationGuard->lockAndAuthorize(
                $actor, $customer->id, 'recordCollection',
                (int) $data['customer_version'], (int) $data['assignment_version'],
            );
            $lockedCustomer = $context->customerProfile;
            $preview = $this->preview($actor, $lockedCustomer->load('currentAssignment'), $data);
            $this->periods->assertOpen($preview['received_date'], $preview['timezone'], true);
            if (! hash_equals($preview['preview_fingerprint'], $data['preview_fingerprint'])
                || $preview['business_version'] !== (int) $data['business_version']
                || ($preview['plan_version'] ?? null) !== (isset($data['plan_version']) ? (int) $data['plan_version'] : null)) {
                throw new ConflictHttpException('Collection details changed. Review the current preview before recording money.');
            }
            $activity = $preview['savings_kobo'] > 0 ? CustomerActivity::RecordContribution : CustomerActivity::ApplyAgreedFee;
            $this->activityGate->assertAllowed($lockedCustomer, $activity);

            $plan = $preview['plan_id'] === null ? null : ThriftPlan::query()->where('plan_id', $preview['plan_id'])->lockForUpdate()->firstOrFail();
            if ($plan !== null && ($plan->status !== ThriftPlanStatus::Active || $plan->version !== $preview['plan_version'])) {
                throw new ConflictHttpException('Plan changed before receipt posting.');
            }
            foreach ($preview['allocations'] as $item) {
                $slot = ContributionSlot::query()->whereKey($item['slot_id'])->lockForUpdate()->firstOrFail();
                if ($slot->thrift_plan_id !== $plan?->id || $slot->active_ordinal === null
                    || $slot->expected_amount_kobo - (int) CollectionAllocation::query()->where('contribution_slot_id', $slot->id)->sum('amount_kobo') < $item['amount_kobo']) {
                    throw new ConflictHttpException('Slot capacity changed before receipt posting.');
                }
            }

            $assignment = $lockedCustomer->currentAssignment;
            $batch = $this->currentBatch($assignment->agent_profile_id, $preview['received_date'], $preview['timezone']);
            $reference = 'TXN-'.str_replace('-', '', $preview['received_date']).'-'.$this->references->generate('ledger_transaction');
            $receipt = CollectionReceipt::create([
                'receipt_reference' => $reference, 'attempt_reference' => $data['attempt_reference'],
                'payload_hash' => $submittedHash, 'customer_profile_id' => $lockedCustomer->id,
                'thrift_plan_id' => $plan?->id, 'recording_agent_profile_id' => $assignment->agent_profile_id,
                'assignment_id' => $assignment->id, 'collection_batch_id' => $batch->id,
                'recorded_by_user_id' => $actor->id, 'received_date' => $preview['received_date'],
                'timezone' => $preview['timezone'], 'business_version' => $preview['business_version'],
                'received_at_utc' => $preview['received_at_utc'],
                'tender_amount_kobo' => $preview['tender_kobo'], 'savings_amount_kobo' => $preview['savings_kobo'],
                'fee_amount_kobo' => $preview['fees_kobo'], 'late_reason' => trim((string) ($data['late_reason'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'recorded_at' => now(),
            ]);
            foreach ($preview['allocations'] as $item) {
                CollectionAllocation::create([
                    'collection_receipt_id' => $receipt->id, 'contribution_slot_id' => $item['slot_id'],
                    'amount_kobo' => $item['amount_kobo'],
                    'is_advance' => $item['due_date'] > $preview['plan_received_date'],
                ]);
            }
            if ($preview['savings_kobo'] > 0) {
                $group = $this->ledger->postCashSavings($receipt->id, $lockedCustomer->id,
                    $assignment->agent_profile_id, $preview['savings_kobo'], $actor);
                $receipt->update(['savings_posting_group_id' => $group->id]);
                $plan->activity_started_at ??= now();
                $plan->version++;
                $plan->save();
            }

            foreach ($preview['fee_items'] as $index => $item) {
                $obligation = FeeObligation::query()->whereKey($item['obligation_id'])->lockForUpdate()->firstOrFail();
                if ($obligation->customer_profile_id !== $lockedCustomer->id || $item['amount_kobo'] > $obligation->outstandingAmountKobo()) {
                    throw new ConflictHttpException('Fee obligation changed before receipt posting.');
                }
                $group = $this->feeLedger->postFee(new LedgerPostingCommand(
                    FeeLedgerPostingType::ExternalFeeReceipt, 'collection-fee-'.$receipt->id.'-'.$index,
                    'collection_receipt', $receipt->id.'-'.$obligation->id, 'NGN', $actor, $lockedCustomer->id,
                    CarbonImmutable::now(), [
                        new LedgerPostingLine(LedgerAccountCode::AgentReceivable, LedgerEntrySide::Debit,
                            $item['amount_kobo'], $lockedCustomer->id, $assignment->agent_profile_id, $obligation->id),
                        new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit,
                            $item['amount_kobo'], $lockedCustomer->id, null, $obligation->id),
                    ], $obligation->customer_description,
                ));
                DB::table('collection_fee_components')->insert([
                    'collection_receipt_id' => $receipt->id, 'fee_obligation_id' => $obligation->id,
                    'ledger_posting_group_id' => $group->id, 'amount_kobo' => $item['amount_kobo'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($plan !== null) {
                $this->updatePlanCompletionAndFee($plan, $actor, $lockedCustomer, $receipt);
            }
            $this->balances->position($lockedCustomer, true);
            $this->transactions->projectReceipt($receipt->refresh());
            AuditEvent::record('collection.receipt_posted', CollectionReceipt::class, $receipt->id, $receipt->receipt_reference, [
                'customer_profile_id' => $lockedCustomer->id, 'plan_id' => $plan?->plan_id,
                'recording_agent_profile_id' => $assignment->agent_profile_id,
                'received_date' => $preview['received_date'], 'tender_kobo' => $preview['tender_kobo'],
                'savings_kobo' => $preview['savings_kobo'], 'fee_kobo' => $preview['fees_kobo'],
            ], $actor,
                context: ['executor' => self::class]
            );
            $intentId = DB::table('collection_notification_intents')->insertGetId([
                'notification_id' => (string) Str::uuid(), 'collection_receipt_id' => $receipt->id,
                'recipient_user_id' => $lockedCustomer->user_id, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(NotificationPipeline::class)->capture('collection', $intentId, false);
            DB::afterCommit(static function () use ($intentId): void {
                app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverCollectionNotificationIntent::dispatch($intentId)->afterCommit());
            });

            return $receipt;
        }, attempts: 3);
    }

    private function currentBatch(int $agentId, string $date, string $timezone): CollectionBatch
    {
        $latest = CollectionBatch::query()->where('agent_profile_id', $agentId)
            ->where('received_date', $date)->where('timezone', $timezone)
            ->orderByDesc('revision')->lockForUpdate()->first();
        if ($latest?->status === 'open') {
            return $latest;
        }

        return CollectionBatch::create([
            'agent_profile_id' => $agentId, 'received_date' => $date, 'timezone' => $timezone,
            'business_version' => BusinessProfile::current()->version,
            'revision' => ($latest === null ? 0 : $latest->revision) + 1,
            'predecessor_batch_id' => $latest === null ? null : $latest->id,
            'status' => 'open', 'version' => 1,
        ]);
    }

    private function updatePlanCompletionAndFee(ThriftPlan $plan, User $actor, CustomerProfile $customer, CollectionReceipt $receipt): void
    {
        $revision = $plan->currentTermsRevision();
        if ($revision === null) {
            throw new ConflictHttpException('Plan terms are unavailable.');
        }
        $snapshot = $revision->feeSnapshot;
        if ($snapshot->timing === FeeRuleTiming::FirstContribution) {
            $this->fees->assessSnapshot($snapshot, $actor);
        }
        $slots = $plan->slots()->whereNotNull('active_ordinal')->get();
        $fundedBySlot = DB::table('collection_allocations')
            ->whereIn('contribution_slot_id', $slots->pluck('id'))
            ->selectRaw('contribution_slot_id, SUM(amount_kobo) as funded_kobo')
            ->groupBy('contribution_slot_id')->pluck('funded_kobo', 'contribution_slot_id');
        $fullyFunded = $slots->isNotEmpty() && $slots->every(function (ContributionSlot $slot) use ($fundedBySlot): bool {
            $funded = (int) ($fundedBySlot[$slot->id] ?? 0);
            if ($funded > $slot->expected_amount_kobo) {
                throw new ConflictHttpException('Slot funding integrity is unavailable.');
            }

            return $funded === $slot->expected_amount_kobo;
        });
        if ($fullyFunded) {
            $previous = $plan->status;
            $plan->status = ThriftPlanStatus::Completed;
            $plan->version++;
            $plan->save();
            if ($snapshot->timing === FeeRuleTiming::CycleCompletion) {
                $this->fees->assessSnapshot($snapshot, $actor);
            }
            PlanLifecycleEvent::create([
                'thrift_plan_id' => $plan->id, 'event_type' => 'completed',
                'from_status' => $previous, 'to_status' => ThriftPlanStatus::Completed,
                'actor_user_id' => $actor->id, 'assignment_version' => $customer->currentAssignment?->version,
                'plan_version' => $plan->version, 'reason' => null,
                'customer_explanation' => 'All scheduled contribution slots are funded.',
                'payload' => ['receipt_reference' => $receipt->receipt_reference], 'effective_at' => now(),
            ]);
        }
        $obligation = $snapshot->obligation;
        if ($obligation !== null && $snapshot->settlement_source === FeeSettlementSource::SavingsApplication) {
            $outstanding = $obligation->outstandingAmountKobo();
            if ($outstanding > 0 && $this->balances->position($customer, true)['available_kobo'] >= $outstanding) {
                $this->feeLedger->postFee(new LedgerPostingCommand(
                    FeeLedgerPostingType::SavingsFeeApplication, 'collection-apply-'.$receipt->id,
                    'fee_application', (string) $receipt->id, 'NGN', $actor, $customer->id,
                    CarbonImmutable::now(), [
                        new LedgerPostingLine(LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit,
                            $outstanding, $customer->id, null, $obligation->id),
                        new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit,
                            $outstanding, $customer->id, null, $obligation->id),
                    ], $obligation->customer_description,
                ));
            }
        }
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw ValidationException::withMessages(['amount' => ['Receipt total exceeds the supported range.']]);
        }

        return $left + $right;
    }
}
