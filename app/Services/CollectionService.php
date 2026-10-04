<?php

namespace App\Services;

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\CustomerActivity;
use App\Enums\FeeLedgerPostingType;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionAllocation;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\ContributionSlot;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeSnapshot;
use App\Models\ManualCharge;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanTermsRevision;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\FeePercentageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use ValueError;

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
        private CollectionReceiptMethod $methods,
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
    public function preview(User $actor, CustomerProfile $customer, array $data, ?ReversalRequest $replacement = null): array
    {
        Gate::forUser($actor)->authorize('recordCollection', $customer);
        $this->settings->ensureFeature('collections');
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
        $position = $this->balances->position($customer, DB::transactionLevel() > 0);

        $savings = filled($data['savings_ngn'] ?? null) && ! in_array($data['savings_ngn'], ['0', '0.0', '0.00'], true)
            ? $this->amountToKobo((string) $data['savings_ngn']) : 0;
        $feeItems = [];
        $feeTotal = 0;
        foreach ($data['fees'] ?? [] as $item) {
            $obligation = FeeObligation::query()->whereKey((int) $item['obligation_id'])
                ->where('customer_profile_id', $customer->id)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first()
                ?? throw new NotFoundHttpException('Record unavailable.');
            $obligation->setRelation('entries', $obligation->entries()
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get());
            if ($obligation->customer_profile_id !== $customer->id || $obligation->currency !== 'NGN') {
                throw ValidationException::withMessages(['fees' => ['The selected fee belongs to another Customer or currency.']]);
            }
            $outstanding = $this->selectedFeeOutstanding($obligation, $customer, DB::transactionLevel() > 0);
            $amount = $this->amountToKobo((string) $item['amount_ngn']);
            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['fees' => ['A fee component exceeds its current outstanding amount.']]);
            }
            $feeTotal = $this->checkedAdd($feeTotal, $amount);
            $feeItems[] = ['obligation_id' => $obligation->id, 'amount_kobo' => $amount,
                'outstanding_kobo' => $outstanding,
                'fee_snapshot_id' => $obligation->fee_snapshot_id,
                'latest_entry_id' => $obligation->relationLoaded('entries') ? $obligation->entries->max('id') : $obligation->entries()->max('id')];
        }
        if (count(array_unique(array_column($feeItems, 'obligation_id'))) !== count($feeItems)) {
            throw ValidationException::withMessages(['fees' => ['List each fee obligation once.']]);
        }
        $total = $this->checkedAdd($savings, $feeTotal);
        if ($total < $limits['receipt_minimum_kobo'] || $total > $limits['receipt_maximum_kobo']) {
            throw ValidationException::withMessages(['savings_ngn' => ['Receipt tender must be positive and within the supported cap.']]);
        }

        $plan = null;
        $planFeeFingerprint = null;
        $receivedAtUtc = null;
        $planReceivedDate = null;
        $planTimezone = null;
        $allocations = [];
        $slotOptions = [];
        if ($savings > 0) {
            $this->activityGate->allows($customer->operational_status, CustomerActivity::RecordContribution)
                || throw ValidationException::withMessages(['customer' => ['This Customer cannot receive a savings contribution.']]);
            $plan = ThriftPlan::query()->where('plan_id', $data['plan_id'] ?? '')
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->firstOrFail();
            if ($plan->customer_profile_id !== $customer->id || $plan->status !== ThriftPlanStatus::Active) {
                throw ValidationException::withMessages(['plan_id' => ['Choose an active plan for this Customer.']]);
            }
            $terms = $this->verifiedPlanFeeTerms($plan, DB::transactionLevel() > 0);
            $snapshot = $terms->feeSnapshot;
            $planTimezone = $terms->timezone;
            $planFeeFingerprint = AuditProjection::digest(['terms_id' => $terms->id, 'revision' => $terms->revision,
                'snapshot_id' => $snapshot->id, 'rule_id' => $snapshot->fee_rule_id, 'rule_version' => $snapshot->fee_rule_version,
                'source_type' => $snapshot->source_type, 'source_id' => $snapshot->source_id,
                'amount_kobo' => $snapshot->amount_kobo, 'basis_amount_kobo' => $snapshot->basis_amount_kobo]);
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
            $fundedBySlot = DB::table('collection_allocations')->whereNotIn('collection_allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
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
            $projectedFunding = $fundedBySlot->all();
            foreach ($allocations as $allocation) {
                $projectedFunding[$allocation['slot_id']] = (int) ($projectedFunding[$allocation['slot_id']] ?? 0) + $allocation['amount_kobo'];
            }
            $fullyFunded = $slots->isNotEmpty() && $slots->every(fn (ContributionSlot $slot): bool => (int) ($projectedFunding[$slot->id] ?? 0) === $slot->expected_amount_kobo);
            $this->assertAutomaticFeeApplicationAccounts($snapshot, $savings, (int) array_sum($projectedFunding),
                $fullyFunded, $position['available_kobo'], $feeItems);
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
        $method = $this->methods->preview($actor, $customer, $data, $total, $received->toDateString(), $business->timezone, $replacement);
        $custody = $replacement === null ? LedgerAccountCode::from($method['custody_account_code']) : LedgerAccountCode::UnappliedFunds;
        if ($savings > 0) {
            $this->ledger->assertSavingsAccounts($custody, DB::transactionLevel() > 0);
        }
        if ($feeItems !== []) {
            $this->feeLedger->assertCollectionFeeAccounts($custody, DB::transactionLevel() > 0);
        }
        $fingerprint = hash('sha256', json_encode([
            $actor->id, $customer->id, $customer->version, $assignment->id, $assignment->version,
            $plan?->id, $plan?->version, $plan?->current_terms_revision, $planFeeFingerprint,
            $business->version, $business->timezone, $received->toDateString(),
            $receivedAtUtc?->toIso8601String(), $planTimezone, $planReceivedDate,
            $savings, $feeItems, $allocations, trim((string) ($data['late_reason'] ?? '')),
            trim((string) ($data['notes'] ?? '')), $method,
        ], JSON_THROW_ON_ERROR));

        return [
            'method_context' => $method,
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
    public function record(User $actor, CustomerProfile $customer, array $data, ?ReversalRequest $replacement = null): CollectionReceipt
    {
        $submittedHash = hash('sha256', json_encode([$actor->id, $customer->id, $data], JSON_THROW_ON_ERROR));

        $attempt = fn (): CollectionReceipt => app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $data, $submittedHash, $replacement): CollectionReceipt {
            $existing = CollectionReceipt::query()->where('attempt_reference', $data['attempt_reference'])->first();
            if ($existing !== null) {
                $existing = CollectionReceipt::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
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
            $preview = $this->preview($actor, $lockedCustomer->load('currentAssignment'), $data, $replacement);
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
                    || $slot->expected_amount_kobo - (int) CollectionAllocation::query()->whereNotIn('id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))->where('contribution_slot_id', $slot->id)->sum('amount_kobo') < $item['amount_kobo']) {
                    throw new ConflictHttpException('Slot capacity changed before receipt posting.');
                }
            }

            $assignment = $lockedCustomer->currentAssignment;
            $originalReceipt = $replacement === null ? null : app(CollectionReplacementService::class)->lockSource($replacement, $lockedCustomer, $preview['tender_kobo']);
            $method = $preview['method_context'];
            $custodianId = $originalReceipt === null ? $method['original_agent_profile_id'] : $originalReceipt->recording_agent_profile_id;
            $batch = $originalReceipt === null ? $this->currentBatch($custodianId, $preview['received_date'], $preview['timezone'], $method) : $originalReceipt->batch;
            $reference = 'TXN-'.str_replace('-', '', $preview['received_date']).'-'.$this->references->generate('ledger_transaction');
            $receipt = CollectionReceipt::create([
                ...array_diff_key($method, ['original_agent_profile_id' => true]),
                'replacement_reversal_id' => $replacement?->id, 'receipt_reference' => $reference, 'attempt_reference' => $data['attempt_reference'],
                'payload_hash' => $submittedHash, 'customer_profile_id' => $lockedCustomer->id,
                'thrift_plan_id' => $plan?->id, 'recording_agent_profile_id' => $custodianId,
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
                    $custodianId, $preview['savings_kobo'], $actor);
                $receipt->update(['savings_posting_group_id' => $group->id]);
                $plan->activity_started_at ??= now();
                $plan->version++;
                $plan->save();
            }

            foreach ($preview['fee_items'] as $index => $item) {
                $obligation = FeeObligation::query()->whereKey($item['obligation_id'])
                    ->where('customer_profile_id', $lockedCustomer->id)->lockForUpdate()->first()
                    ?? throw new NotFoundHttpException('Record unavailable.');
                $obligation->setRelation('entries', $obligation->entries()->lockForUpdate()->get());
                if ($obligation->customer_profile_id !== $lockedCustomer->id || $item['amount_kobo'] > $obligation->outstandingAmountKobo()) {
                    throw new ConflictHttpException('Fee obligation changed before receipt posting.');
                }
                $custody = $replacement === null ? LedgerAccountCode::from($method['custody_account_code']) : LedgerAccountCode::UnappliedFunds;
                $group = $this->feeLedger->postFee(new LedgerPostingCommand(
                    $replacement === null ? FeeLedgerPostingType::ExternalFeeReceipt : FeeLedgerPostingType::UnappliedFeeApplication, 'collection-fee-'.$receipt->id.'-'.$index,
                    'collection_receipt', $receipt->id.'-'.$obligation->id, 'NGN', $actor, $lockedCustomer->id,
                    CarbonImmutable::now(), [
                        new LedgerPostingLine($custody, LedgerEntrySide::Debit,
                            $item['amount_kobo'], $lockedCustomer->id, $custody === LedgerAccountCode::AgentReceivable ? $custodianId : null, $obligation->id),
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
            $finalPosition = $this->balances->position($lockedCustomer, true);
            $this->transactions->projectReceipt($receipt->refresh());
            $receiptAudit = AuditEvent::record('collection.receipt_posted', CollectionReceipt::class, $receipt->id, $receipt->receipt_reference, [
                'customer_profile_id' => $lockedCustomer->id, 'plan_id' => $plan?->plan_id,
                'recording_agent_profile_id' => $custodianId,
                'received_date' => $preview['received_date'], 'tender_kobo' => $preview['tender_kobo'],
                'savings_kobo' => $preview['savings_kobo'], 'fee_kobo' => $preview['fees_kobo'],
                'assignment_id' => $receipt->assignment_id, 'business_version' => $receipt->business_version,
                'currency' => 'NGN', 'timezone' => $receipt->timezone, 'method' => $receipt->method,
                'custody_account_code' => $receipt->custody_account_code, 'method_version_id' => $receipt->collection_method_version_id,
                'payment_evidence_id' => $receipt->collection_payment_evidence_id, 'evidence_review_id' => $receipt->collection_evidence_review_id,
                'posting_group_id' => $receipt->savings_posting_group_id, 'batch_id' => $receipt->collection_batch_id,
            ], $actor,
                context: ['executor' => self::class, 'source_version' => 1,
                    'correlation_reference' => hash('sha256', $receipt->attempt_reference)]
            );
            if ($plan !== null) {
                $snapshot = $this->verifiedPlanFeeTerms($plan, true)->feeSnapshot;
                $fee = $snapshot->obligation()->first();
                if ($fee !== null) {
                    app(FeeOperationalIssues::class)->trigger($fee, $plan, $actor, $receiptAudit, [
                        'source_kind' => 'collection_receipt', 'source_id' => $receipt->id,
                        'operation_reference' => $receipt->attempt_reference,
                        'available_kobo' => $finalPosition['available_kobo'], 'timezone' => $receipt->timezone,
                    ]);
                }
            }
            $feeReceiptContext = app(CollectionFeeReceiptNotice::class)->capture($receipt, $receiptAudit);
            foreach (['database', 'mail'] as $channel) {
                $intentId = DB::table('collection_notification_intents')->insertGetId([
                    'notification_id' => (string) Str::uuid(), 'collection_receipt_id' => $receipt->id,
                    'recipient_user_id' => $lockedCustomer->user_id, 'channel' => $channel, 'status' => 'pending',
                    'audience_type' => 'subject_customer', 'customer_profile_id' => $lockedCustomer->id,
                    'context_ciphertext' => $feeReceiptContext,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($channel === 'database') {
                    app(NotificationPipeline::class)->capture('collection', $intentId, false);
                } else {
                    app(ManagementMailDelivery::class)->register('collection', $intentId);
                }
                DB::afterCommit(static function () use ($intentId): void {
                    app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverCollectionNotificationIntent::dispatch($intentId)->afterCommit());
                });
            }

            return $receipt;
        }, attempts: 3);

        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            return $attempt();
        }
    }

    /** @param array<string, mixed> $method */
    private function currentBatch(int $agentId, string $date, string $timezone, array $method): CollectionBatch
    {
        $identity = $method['collection_method_version_id'] === null ? 'cash' : 'method-'.$method['collection_method_version_id'];
        $latest = CollectionBatch::query()->where('agent_profile_id', $agentId)
            ->where('method_identity', $identity)
            ->where('received_date', $date)->where('timezone', $timezone)
            ->orderByDesc('revision')->lockForUpdate()->first();
        if ($latest?->status === 'open') {
            return $latest;
        }

        return CollectionBatch::create([
            'method_identity' => $identity, 'collection_method_version_id' => $method['collection_method_version_id'],
            'custody_account_code' => $method['custody_account_code'],
            'agent_profile_id' => $agentId, 'received_date' => $date, 'timezone' => $timezone,
            'business_version' => BusinessProfile::current()->version,
            'revision' => ($latest === null ? 0 : $latest->revision) + 1,
            'predecessor_batch_id' => $latest === null ? null : $latest->id,
            'status' => 'open', 'version' => 1,
        ]);
    }

    public function updatePlanCompletionAndFee(ThriftPlan $plan, User $actor, CustomerProfile $customer, CollectionReceipt $receipt): void
    {
        $revision = $this->verifiedPlanFeeTerms($plan, true);
        $snapshot = $revision->feeSnapshot;
        $existingObligation = $snapshot->obligation()->lockForUpdate()->first();
        $assessedBefore = 0;
        if ($existingObligation !== null) {
            $existingObligation->setRelation('entries', $existingObligation->entries()->lockForUpdate()->get());
            $assessedBefore = $existingObligation->assessedAmountKobo();
        }
        if ($snapshot->timing === FeeRuleTiming::FirstContribution) {
            $this->fees->assessSnapshot($snapshot, $actor);
        }
        $slots = $plan->slots()->whereNotNull('active_ordinal')->get();
        $fundedBySlot = DB::table('collection_allocations')->whereNotIn('collection_allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
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
        if ($fullyFunded && $plan->status !== ThriftPlanStatus::Completed) {
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
        app(PlanFeeCorrectionService::class)->synchronize($plan, $actor, $receipt->attempt_reference);
        $obligation = $snapshot->obligation()->first();
        if ($obligation !== null && $snapshot->settlement_source === FeeSettlementSource::SavingsApplication) {
            $obligation->setRelation('entries', $obligation->entries()->lockForUpdate()->get());
            $outstanding = $obligation->outstandingAmountKobo();
            if ($outstanding > 0 && $obligation->assessedAmountKobo() > $assessedBefore
                && $this->balances->position($customer, true)['available_kobo'] >= $outstanding) {
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

    /** @param list<array{obligation_id: int, amount_kobo: int, outstanding_kobo: int, fee_snapshot_id: int, latest_entry_id: mixed}> $feeItems */
    private function assertAutomaticFeeApplicationAccounts(FeeSnapshot $snapshot, int $savings, int $principal, bool $fullyFunded, int $available, array $feeItems): void
    {
        if ($snapshot->settlement_source !== FeeSettlementSource::SavingsApplication || $snapshot->isZero()
            || ! ($snapshot->timing === FeeRuleTiming::FirstContribution
                || ($snapshot->timing === FeeRuleTiming::CycleCompletion && $fullyFunded))) {
            return;
        }
        $forUpdate = DB::transactionLevel() > 0;
        $obligation = $snapshot->obligation()->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($obligation !== null) {
            $obligation->setRelation('entries', $obligation->entries()->when($forUpdate, fn ($query) => $query->lockForUpdate())->get());
        }
        $assessedBefore = $obligation?->assessedAmountKobo() ?? 0;
        $waived = $obligation?->waivedAmountKobo() ?? 0;
        $target = max($waived, match ($snapshot->model) {
            FeeRuleModel::NoFee => 0,
            FeeRuleModel::Percentage => FeePercentageCalculator::calculate($principal, $snapshot->basis_points ?? 0),
            default => $snapshot->amount_kobo,
        });
        $settled = $obligation?->settledAmountKobo() ?? 0;
        foreach ($feeItems as $item) {
            if ($item['obligation_id'] === $obligation?->id) {
                $settled = $this->checkedAdd($settled, $item['amount_kobo']);
            }
        }
        $outstanding = max(0, $target - $waived - $settled);
        if ($target > $assessedBefore && $outstanding > 0 && $this->checkedAdd($available, $savings) >= $outstanding) {
            $this->feeLedger->assertSavingsFeeApplicationAccounts($forUpdate);
        }
    }

    private function verifiedPlanFeeTerms(ThriftPlan $plan, bool $forUpdate): PlanTermsRevision
    {
        $terms = $plan->termsRevisions()->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $current = $terms->firstWhere('revision', $plan->current_terms_revision);
        $snapshot = $current === null ? null : FeeSnapshot::query()->whereKey($current->fee_snapshot_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        $boundPlan = clone $plan;
        $boundPlan->setRelation('termsRevisions', $terms);
        if ($current === null || $snapshot === null || ! app(PlanFeeSnapshotBinding::class)->isValid($boundPlan, $current, $snapshot, true)) {
            throw new ServiceUnavailableHttpException(null, 'The cycle fee agreement is unavailable.');
        }
        $snapshot->setRelation('feeRule', $snapshot->feeRule()
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first());
        try {
            $this->fees->assertSnapshotMatchesRuleQuote($snapshot, true);
        } catch (RuntimeException|ValueError $exception) {
            throw new ServiceUnavailableHttpException(null, 'The cycle fee agreement is unavailable.', $exception);
        }
        $current->setRelation('feeSnapshot', $snapshot);

        return $current;
    }

    private function selectedFeeOutstanding(FeeObligation $obligation, CustomerProfile $customer, bool $forUpdate): int
    {
        $snapshot = FeeSnapshot::query()->whereKey($obligation->fee_snapshot_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($snapshot === null || $snapshot->customer_profile_id !== $customer->id
            || $snapshot->getRawOriginal('kind') !== $obligation->kind
            || $snapshot->currency !== $obligation->currency || $snapshot->amount_kobo !== $obligation->amount_kobo
            || $snapshot->source_type === '' || $snapshot->source_id === ''
            || $snapshot->source_type !== $obligation->source_type || $snapshot->source_id !== $obligation->source_id) {
            throw new ServiceUnavailableHttpException(null, 'The selected fee agreement is unavailable.');
        }
        if ($snapshot->getRawOriginal('kind') === 'registration'
            && ($snapshot->source_type !== 'registration'
                || ! in_array($snapshot->source_id, [(string) $customer->id, $customer->customer_id], true))) {
            throw new ServiceUnavailableHttpException(null, 'The selected fee agreement is unavailable.');
        }
        if ($snapshot->getRawOriginal('kind') === 'manual') {
            $this->assertSelectedManualFeeOwner($obligation, $snapshot, $customer, $forUpdate);
        }
        $withdrawalFeeSource = $snapshot->getRawOriginal('kind') === 'plan' && $snapshot->source_type === 'withdrawal'
            && $snapshot->getRawOriginal('timing') === 'withdrawal';
        if ($snapshot->getRawOriginal('kind') === 'plan' && ! $withdrawalFeeSource) {
            $this->assertSelectedPlanFeeOwner($snapshot, $customer, $forUpdate);
        }
        $snapshot->setRelation('feeRule', $snapshot->feeRule()
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first());
        try {
            $this->fees->assertSnapshotMatchesRuleQuote($snapshot, true);

            $outstanding = $obligation->outstandingAmountKobo();
            if ($withdrawalFeeSource && $outstanding !== 0) {
                throw new ServiceUnavailableHttpException(null, 'The selected external fee owner is unavailable.');
            }

            return $outstanding;
        } catch (RuntimeException|ValueError $exception) {
            throw new ServiceUnavailableHttpException(null, 'The selected fee agreement is unavailable.', $exception);
        }
    }

    private function assertSelectedManualFeeOwner(FeeObligation $obligation, FeeSnapshot $snapshot, CustomerProfile $customer, bool $forUpdate): void
    {
        $charge = ManualCharge::query()->where('operation_reference', $snapshot->source_id)
            ->where('customer_profile_id', $customer->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($snapshot->source_type !== 'manual_charge' || $charge === null
            || (string) $charge->getRawOriginal('fee_obligation_id') !== (string) $obligation->id
            || filter_var($charge->getRawOriginal('amount_kobo'), FILTER_VALIDATE_INT) !== $obligation->amount_kobo) {
            throw new ServiceUnavailableHttpException(null, 'The selected manual fee owner is unavailable.');
        }
        $plan = ThriftPlan::query()->whereKey($charge->getRawOriginal('thrift_plan_id'))
            ->where('customer_profile_id', $customer->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        $category = ChargeCategoryVersion::query()->whereKey($charge->getRawOriginal('charge_category_version_id'))
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($plan === null || $category === null || $category->getRawOriginal('kind') !== 'manual_fee'
            || filter_var($category->getRawOriginal('amount_kobo'), FILTER_VALIDATE_INT) !== $obligation->amount_kobo
            || (string) $category->getRawOriginal('fee_rule_id') !== (string) $snapshot->fee_rule_id) {
            throw new ServiceUnavailableHttpException(null, 'The selected manual fee owner is unavailable.');
        }
    }

    private function assertSelectedPlanFeeOwner(FeeSnapshot $snapshot, CustomerProfile $customer, bool $forUpdate): void
    {
        $origins = PlanTermsRevision::query()->where('fee_snapshot_id', $snapshot->id)
            ->with(['plan' => fn ($query) => $query->where('customer_profile_id', $customer->id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())])
            ->orderBy('revision')->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $origin = $origins->first();
        if ($origin === null || $origin->plan === null || $origins->pluck('thrift_plan_id')->unique()->count() !== 1) {
            throw new ServiceUnavailableHttpException(null, 'The selected cycle fee owner is unavailable.');
        }
        $owner = clone $origin->plan;
        $owner->setRelation('termsRevisions', $origins);
        if (! app(PlanFeeSnapshotBinding::class)->isValid($owner, $origin, $snapshot, true)) {
            throw new ServiceUnavailableHttpException(null, 'The selected cycle fee owner is unavailable.');
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
