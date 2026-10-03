<?php

namespace App\Services;

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\AdminPermission;
use App\Enums\CustomerActivity;
use App\Enums\FeeLedgerPostingType;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ValueError;

class FeeSavingsApplicationService
{
    public function available(): bool
    {
        return (bool) config('fees.savings_applications_enabled') && Schema::hasTable('fee_savings_applications')
            && Schema::hasTable('fee_application_notification_intents');
    }

    /** @return list<array{plan_id: string, name: string, status: string}> */
    public function sources(User $actor, int $obligationId): array
    {
        return DB::transaction(function () use ($actor, $obligationId): array {
            $this->authorize($actor);
            $this->assertEnabled();
            $fee = FeeObligation::query()->findOrFail($obligationId);
            $customer = CustomerProfile::query()->whereKey($fee->customer_profile_id)->lockForUpdate()->firstOrFail();
            app(CustomerActivityGate::class)->assertAllowed($customer, CustomerActivity::ApplyAgreedFee);

            return array_values(ThriftPlan::query()->where('customer_profile_id', $customer->id)->whereIn('status', ['active', 'paused', 'completed'])
                ->with('termsRevisions')->orderBy('id')->get()->map(fn (ThriftPlan $plan): array => [
                    'plan_id' => $plan->plan_id, 'name' => $plan->currentTermsRevision()->name, 'status' => $plan->status->displayName(),
                ])->all());
        });
    }

    /** @return array{status: string, posting_reference: string} */
    public function status(User $actor, int $obligationId, string $reference): array
    {
        return DB::transaction(function () use ($actor, $obligationId, $reference): array {
            $admin = $this->authorize($actor);
            if (! Schema::hasTable('fee_savings_applications')) {
                throw new NotFoundHttpException('Record unavailable.');
            }
            $application = DB::table('fee_savings_applications')->where('operation_reference', $reference)
                ->where('fee_obligation_id', $obligationId)->where('actor_user_id', $admin->id)->lockForUpdate()->first();
            if ($application === null) {
                throw new NotFoundHttpException('Record unavailable.');
            }
            $group = $this->assertPosted($reference, true);

            return ['status' => 'posted', 'posting_reference' => $group->posting_reference];
        });
    }

    public function assertPosted(string $reference, bool $current = false): LedgerPostingGroup
    {
        $group = $this->verifiedPostedSources([$reference], $current)->get($reference);
        if ($group === null) {
            throw new ConflictHttpException('The retained savings fee application source is unavailable.');
        }

        return $group;
    }

    /**
     * @param  list<string>  $references
     * @return Collection<string, LedgerPostingGroup>
     */
    public function verifiedPostedSources(array $references, bool $current = false): Collection
    {
        if ($current && DB::transactionLevel() === 0) {
            throw new LogicException('Current fee source verification requires an owning transaction.');
        }
        if ($references === []) {
            return collect();
        }
        $applications = DB::table('fee_savings_applications')->whereIn('operation_reference', $references)
            ->when($current, fn ($query) => $query->sharedLock())->get()->keyBy('operation_reference');
        $fees = FeeObligation::query()->with([
            'feeSnapshot' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock()),
            'entries' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock()),
        ])->whereIn('id', $applications->pluck('fee_obligation_id'))->when($current, fn ($query) => $query->sharedLock())->get()->keyBy('id');
        $groups = LedgerPostingGroup::query()->with([
            'entries' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock()),
            'entries.account' => fn ($query) => $query->when($current, fn ($query) => $query->sharedLock()),
        ])->where('source_type', 'fee_savings_application')->whereIn('source_id', $references)
            ->when($current, fn ($query) => $query->sharedLock())->get()->groupBy('source_id');
        $plans = ThriftPlan::query()->whereIn('id', $applications->pluck('thrift_plan_id'))
            ->when($current, fn ($query) => $query->sharedLock())->get()->keyBy('id');
        $settlements = FeeObligationEntry::query()->where('source_type', 'fee_savings_application')->whereIn('source_id', $references)
            ->when($current, fn ($query) => $query->sharedLock())->get()->groupBy('source_id');
        $verified = collect();
        foreach (array_unique($references) as $reference) {
            $application = $applications->get($reference);
            $fee = $application === null ? null : $fees->get($application->fee_obligation_id);
            $plan = $application === null ? null : $plans->get($application->thrift_plan_id);
            try {
                $verified->put($reference, $this->verifyCapturedSource($reference, $application, $fee,
                    $groups->get($reference, collect()), $settlements->get($reference, collect()), $plan));
            } catch (RuntimeException|ValueError) {
                continue;
            }
        }

        return $verified;
    }

    /**
     * @param  Collection<int, LedgerPostingGroup>  $groups
     * @param  Collection<int, FeeObligationEntry>  $settlements
     */
    private function verifyCapturedSource(string $reference, ?stdClass $application, ?FeeObligation $fee, Collection $groups, Collection $settlements, ?ThriftPlan $plan): LedgerPostingGroup
    {
        $group = $groups->first();
        if ($application === null || $groups->count() !== 1 || $group === null
            || ! Str::isUuid($reference) || (int) $application->amount_kobo < 1 || $application->currency !== 'NGN'
            || (int) $application->remaining_cycle_savings_kobo < 0 || (int) $application->remaining_available_kobo < 0
            || (int) $application->remaining_available_kobo > (int) $application->remaining_cycle_savings_kobo
            || (int) $application->remaining_fee_kobo !== 0
            || $group->event_type !== FeeLedgerPostingType::SavingsFeeApplication->value || $group->currency !== 'NGN'
            || $group->idempotency_key !== 'admin-fee-apply-'.$reference || $group->schema_version < 1
            || $group->occurred_on === null || $group->business_timezone === null
            || $group->actor_user_id !== (int) $application->actor_user_id
            || $group->customer_profile_id !== (int) $application->customer_profile_id
            || $group->thrift_plan_id !== (int) $application->thrift_plan_id
            || $plan === null || $plan->customer_profile_id !== (int) $application->customer_profile_id) {
            throw new ConflictHttpException('The retained savings fee application source is unavailable.');
        }
        $snapshot = $fee?->feeSnapshot;
        if ($fee === null || $snapshot === null || $fee->customer_profile_id !== (int) $application->customer_profile_id
            || $snapshot->customer_profile_id !== $fee->customer_profile_id || $snapshot->getRawOriginal('kind') !== $fee->kind
            || $snapshot->currency !== 'NGN' || $fee->currency !== 'NGN' || $snapshot->amount_kobo !== $fee->amount_kobo
            || $snapshot->source_type !== $fee->source_type || $snapshot->source_id !== $fee->source_id) {
            throw new ConflictHttpException('The retained fee assessment source is unavailable.');
        }
        if ($group->entries->count() !== 2 || $group->entries->where('side', LedgerEntrySide::Debit)->count() !== 1
            || $group->entries->where('side', LedgerEntrySide::Credit)->count() !== 1) {
            throw new ConflictHttpException('The retained savings fee journal does not balance.');
        }
        foreach ($group->entries as $line) {
            $savings = $line->side === LedgerEntrySide::Debit;
            if ($line->account->code !== ($savings ? LedgerAccountCode::CustomerSavingsLiability : LedgerAccountCode::FeeIncome)
                || $line->account->account_class !== ($savings ? LedgerAccountClass::CustomerSavingsLiability : LedgerAccountClass::FeeIncome)
                || $line->account->normal_balance !== LedgerEntrySide::Credit || $line->account->currency !== 'NGN'
                || $line->amount_kobo !== (int) $application->amount_kobo || $line->customer_profile_id !== $group->customer_profile_id
                || $line->agent_profile_id !== null || $line->fee_obligation_id !== $fee->id
                || $line->thrift_plan_id !== ($savings ? $group->thrift_plan_id : null)) {
                throw new ConflictHttpException('The retained savings fee journal has inconsistent dimensions.');
            }
        }
        $settlement = $settlements->first();
        if ($settlements->count() !== 1 || $settlement === null || $settlement->entry_type->value !== 'settlement'
            || $settlement->fee_obligation_id !== $fee->id || $settlement->amount_kobo !== (int) $application->amount_kobo
            || $settlement->currency !== 'NGN' || $settlement->ledger_posting_reference !== $group->posting_reference
            || $settlement->actor_user_id !== $group->actor_user_id || $settlement->customer_description !== $application->customer_description) {
            throw new ConflictHttpException('The retained fee settlement has no matching savings application.');
        }

        $beforePayment = clone $fee;
        $beforePayment->setRelation('entries', $fee->entries->filter(fn (FeeObligationEntry $entry): bool => $entry->id < $settlement->id));
        foreach ($beforePayment->entries as $entry) {
            if (! in_array($entry->entry_type, [FeeObligationEntryType::AssessmentCorrection, FeeObligationEntryType::AssessmentCorrectionIncrease], true)) {
                continue;
            }
            if (in_array($fee->kind, [FeeRuleKind::Manual->value, FeeRuleKind::Registration->value], true)
                && $entry->source_type !== 'admin_assessment_correction') {
                throw new ConflictHttpException('The corrected manual or registration assessment source is unavailable.');
            }
            if ($entry->source_type === 'admin_assessment_correction'
                && (! Str::isUuid($entry->source_id) || $entry->idempotency_key !== 'fee-admin-'.$entry->source_id
                    || $entry->actor_user_id === null || $entry->ledger_posting_reference !== null)) {
                throw new ConflictHttpException('The corrected assessment has no linked administrative evidence.');
            }
        }
        if ((int) $application->amount_kobo !== $beforePayment->outstandingAmountKobo()) {
            throw new ConflictHttpException('The savings fee application does not match its retained unpaid assessment history.');
        }

        return $group;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, int $obligationId, array $data): array
    {
        $this->assertEnabled();

        return DB::transaction(fn (): array => $this->quote($actor, $obligationId, $data));
    }

    /** @param array<string, mixed> $data */
    public function apply(User $actor, int $obligationId, array $data, Request $request): LedgerPostingGroup
    {
        if (! Str::isUuid($data['attempt_reference'] ?? '') || ($data['confirmed'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirmed' => ['Confirm the reviewed fee application with a valid attempt reference.']]);
        }
        $hash = $this->fingerprint([$actor->id, $obligationId, $data]);

        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $obligationId, $data, $request, $hash): LedgerPostingGroup {
            $admin = $this->authorize($actor);
            if (! app(FreshAuthenticationService::class)->isFresh($admin, $request)) {
                throw new ConflictHttpException('Fresh password and authenticator confirmation is required.');
            }
            if (! Schema::hasTable('fee_savings_applications')) {
                throw new ConflictHttpException('Reviewed savings fee application is unavailable.');
            }
            $attemptOwner = app(FeeActionAttemptService::class);
            $attempt = $attemptOwner->reserveForCommit($admin, $obligationId, 'apply_savings', $data['attempt_reference'], $data);
            $existing = DB::table('fee_savings_applications')->where('operation_reference', $data['attempt_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->actor_user_id !== $admin->id || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('Changed fee application conflicts with its original attempt.');
                }

                $group = $this->assertPosted($existing->operation_reference, true);
                $attemptOwner->markRecorded($attempt, 'fee_savings_application', $data['attempt_reference']);

                return $group;
            }
            $this->assertEnabled();
            try {
                $expires = CarbonImmutable::parse($data['quote_expires_at'] ?? '')->utc();
            } catch (\Exception) {
                throw ValidationException::withMessages(['quote_expires_at' => ['Review the current application again.']]);
            }
            if (! is_string($data['quote_expires_at'] ?? null) || $expires->lessThanOrEqualTo(now()) || $expires->greaterThan(now()->addMinutes(10))) {
                throw new ConflictHttpException('The fee application review expired. Review again.');
            }
            $quote = $this->quote($admin, $obligationId, $data, $expires);
            if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint'] ?? '')) {
                throw new ConflictHttpException('Fee or savings sources changed. Review the application again.');
            }
            DB::table('fee_savings_applications')->insert([
                'operation_reference' => $data['attempt_reference'], 'payload_hash' => $hash, 'preview_fingerprint' => $quote['preview_fingerprint'],
                'customer_profile_id' => $quote['customer_profile_id'], 'thrift_plan_id' => $quote['thrift_plan_id'],
                'fee_obligation_id' => $obligationId, 'actor_user_id' => $admin->id, 'amount_kobo' => $quote['amount_kobo'], 'currency' => 'NGN',
                'remaining_cycle_savings_kobo' => $quote['remaining_cycle_savings_kobo'],
                'remaining_available_kobo' => $quote['remaining_available_kobo'], 'remaining_fee_kobo' => $quote['remaining_fee_kobo'],
                'reason' => Crypt::encryptString(trim($data['reason'])), 'customer_description' => trim($data['customer_description']), 'created_at' => now(),
            ]);

            $group = app(LedgerPostingService::class)->postFee(new LedgerPostingCommand(
                FeeLedgerPostingType::SavingsFeeApplication, 'admin-fee-apply-'.$data['attempt_reference'], 'fee_savings_application', $data['attempt_reference'],
                'NGN', $admin, $quote['customer_profile_id'], CarbonImmutable::now(), [
                    new LedgerPostingLine(LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit, $quote['amount_kobo'], $quote['customer_profile_id'], null, $obligationId),
                    new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, $quote['amount_kobo'], $quote['customer_profile_id'], null, $obligationId),
                ], trim($data['customer_description']),
            ));
            app(LedgerTransactionProjectionService::class)->projectSavingsApplication($data['attempt_reference']);
            $this->captureNotices($group);
            $attemptOwner->markRecorded($attempt, 'fee_savings_application', $data['attempt_reference']);

            return $group;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function validatePreparation(User $actor, int $obligationId, array $data): void
    {
        $this->assertEnabled();
        $expires = CarbonImmutable::parse($data['quote_expires_at'])->utc();
        if ($expires->lessThanOrEqualTo(now()) || $expires->greaterThan(now()->addMinutes(10))) {
            throw new ConflictHttpException('The fee application review expired. Review again.');
        }
        $quote = $this->quote($actor, $obligationId, $data, $expires);
        if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint'])) {
            throw new ConflictHttpException('Fee or savings sources changed. Review the application again.');
        }
    }

    /** @param array<string, mixed> $data */
    public function assertAttemptPayload(User $actor, int $obligationId, string $reference, array $data): void
    {
        $application = DB::table('fee_savings_applications')->where('operation_reference', $reference)->sharedLock()->first();
        $original = ['plan_id' => $data['plan_id'], 'reason' => $data['reason'], 'customer_description' => $data['customer_description'],
            'attempt_reference' => $reference, 'confirmed' => true, 'preview_fingerprint' => $data['preview_fingerprint'], 'quote_expires_at' => $data['quote_expires_at']];
        $matchesOriginal = $application !== null && hash_equals($application->payload_hash, $this->fingerprint([$actor->id, $obligationId, $data]));
        $matchesHttp = $application !== null && hash_equals($application->payload_hash, $this->fingerprint([$actor->id, $obligationId, $original]));
        if (! $matchesOriginal && ! $matchesHttp) {
            throw new ConflictHttpException('Changed fee application conflicts with its original attempt.');
        }
    }

    private function captureNotices(LedgerPostingGroup $group): void
    {
        $application = DB::table('fee_savings_applications')->where('operation_reference', $group->source_id)->sole();
        $customer = CustomerProfile::query()->with('currentAssignment.agentProfile.user')->findOrFail($group->customer_profile_id);
        $recipients = [['user_id' => $customer->user_id, 'audience' => 'subject_customer', 'agent_id' => null]];
        $agent = $customer->currentAssignment?->agentProfile;
        if ($agent !== null && app(AgentEligibilityService::class)->canReadAssignedCustomers($agent->user)) {
            $recipients[] = ['user_id' => $agent->user_id, 'audience' => 'current_agent', 'agent_id' => $agent->id];
        }
        foreach ($recipients as $recipient) {
            $id = DB::table('fee_application_notification_intents')->insertGetId([
                'notification_id' => (string) Str::uuid(), 'fee_savings_application_id' => $application->id,
                'customer_profile_id' => $customer->id, 'thrift_plan_id' => $group->thrift_plan_id,
                'recipient_user_id' => $recipient['user_id'], 'agent_profile_id' => $recipient['agent_id'],
                'audience_type' => $recipient['audience'], 'channel' => 'database', 'payload' => '{}', 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(NotificationPipeline::class)->capture('fee_application', $id);
        }
        $mailId = DB::table('fee_application_notification_intents')->insertGetId([
            'notification_id' => (string) Str::uuid(), 'fee_savings_application_id' => $application->id,
            'customer_profile_id' => $customer->id, 'thrift_plan_id' => $group->thrift_plan_id,
            'recipient_user_id' => $customer->user_id, 'agent_profile_id' => null,
            'audience_type' => 'subject_customer', 'channel' => 'mail', 'payload' => '{}', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ManagementMailDelivery::class)->register('fee_application', $mailId);
        DB::afterCommit(static fn () => app(NotificationPipeline::class)->dispatchRecoverably(
            static fn () => DeliverFeeApplicationNotificationIntent::dispatch($mailId)->afterCommit()));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function quote(User $actor, int $obligationId, array $data, ?CarbonImmutable $expires = null): array
    {
        $admin = $this->authorize($actor);
        foreach (['reason', 'customer_description', 'plan_id'] as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '' || mb_strlen($data[$field]) > 500) {
                throw ValidationException::withMessages([$field => ['Provide the selected cycle, reason and Customer description.']]);
            }
        }
        $source = FeeObligation::query()->findOrFail($obligationId);
        $customer = CustomerProfile::query()->whereKey($source->customer_profile_id)->lockForUpdate()->firstOrFail();
        app(CustomerActivityGate::class)->assertAllowed($customer, CustomerActivity::ApplyAgreedFee);
        $plan = ThriftPlan::query()->where('plan_id', $data['plan_id'])->where('customer_profile_id', $customer->id)->lockForUpdate()->firstOrFail();
        if (! $plan->status->isOpen()) {
            throw new ConflictHttpException('The selected cycle is not open for fee application.');
        }
        $fee = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
        $fee->setRelation('entries', $fee->entries()->lockForUpdate()->get());
        $snapshot = $fee->feeSnapshot;
        try {
            $rule = $snapshot->feeRule;
            if ($rule === null) {
                throw new ConflictHttpException('The original fee pricing source is unavailable.');
            }
            foreach ([$snapshot, $rule] as $pricingSource) {
                foreach (['kind' => FeeRuleKind::class, 'model' => FeeRuleModel::class, 'timing' => FeeRuleTiming::class,
                    'basis' => FeeRuleBasis::class, 'settlement_source' => FeeSettlementSource::class] as $field => $enum) {
                    $value = $pricingSource->getRawOriginal($field);
                    if (! is_string($value)) {
                        throw new ConflictHttpException('The original fee pricing source is unavailable.');
                    }
                    $enum::from($value);
                }
            }
            if ($rule->version !== $snapshot->fee_rule_version || $rule->kind !== $snapshot->kind
                || $rule->model !== $snapshot->model || $rule->timing !== $snapshot->timing || $rule->basis !== $snapshot->basis
                || $rule->currency !== $snapshot->currency || $rule->basis_points !== $snapshot->basis_points
                || $rule->settlement_source !== $snapshot->settlement_source || $snapshot->amount_kobo !== $fee->amount_kobo
                || ($snapshot->model->value === 'fixed' && $rule->amount_kobo !== $snapshot->amount_kobo)) {
                throw new ConflictHttpException('The original fee pricing source is unavailable.');
            }
        } catch (ValueError) {
            throw new ConflictHttpException('The original fee pricing source is unavailable.');
        }

        if ($snapshot->customer_profile_id !== $customer->id || $snapshot->timing->value === 'withdrawal') {
            throw new ConflictHttpException('This fee cannot be applied through the selected savings workflow.');
        }
        if ($snapshot->kind->value !== $fee->kind || $snapshot->currency !== $fee->currency
            || $snapshot->source_type !== $fee->source_type || $snapshot->source_id !== $fee->source_id) {
            throw new ConflictHttpException('The original fee source is unavailable.');
        }
        if ($snapshot->kind === FeeRuleKind::Registration
            && ($snapshot->source_type !== 'registration' || ! in_array($snapshot->source_id, [(string) $customer->id, $customer->customer_id], true))) {
            throw new ConflictHttpException('The registration fee has no matching Customer source.');
        }
        if ($snapshot->kind === FeeRuleKind::Plan) {
            $terms = $plan->currentTermsRevision();
            if ($terms === null || ! app(PlanFeeSnapshotBinding::class)->isValid($plan, $terms, $snapshot)
                || $snapshot->settlement_source !== FeeSettlementSource::SavingsApplication) {
                throw new ConflictHttpException('The cycle fee has no agreed savings application source.');
            }
        }
        if ($snapshot->kind === FeeRuleKind::Manual) {
            $charge = ManualCharge::query()->where('operation_reference', $snapshot->source_id)->where('customer_profile_id', $customer->id)->lockForUpdate()->first();
            if ($snapshot->source_type !== 'manual_charge' || $charge === null || $charge->fee_obligation_id !== $fee->id
                || $charge->thrift_plan_id !== $plan->id) {
                throw new ConflictHttpException('The manual fee has no matching assessed owner.');
            }
        }
        $boundPlan = app(LedgerPostingService::class)->planForObligation($fee);
        if ($boundPlan !== null && $boundPlan !== $plan->id) {
            throw new ConflictHttpException('The fee belongs to another cycle.');
        }
        $amount = $fee->outstandingAmountKobo();
        if ($amount < 1) {
            throw new ConflictHttpException('There is no unpaid fee to apply.');
        }
        $position = app(WithdrawalBalanceService::class)->position($customer, $plan, true);
        if ($amount > min($position['available_kobo'], $position['cycle_available_kobo'])) {
            throw ValidationException::withMessages(['plan_id' => ['Available savings cannot settle the full unpaid fee without using reserved funds.']]);
        }
        $accounts = LedgerAccount::query()->whereIn('code', [LedgerAccountCode::CustomerSavingsLiability->value, LedgerAccountCode::FeeIncome->value])->orderBy('id')->lockForUpdate()->get();
        if ($accounts->count() !== 2) {
            throw new ConflictHttpException('Required fee application accounting is unavailable.');
        }
        foreach ($accounts as $account) {
            $classification = $account->code === LedgerAccountCode::FeeIncome ? LedgerAccountClass::FeeIncome : LedgerAccountClass::CustomerSavingsLiability;
            if ($account->mapping_status !== 'mapped' || $account->currency !== 'NGN' || $account->normal_balance !== LedgerEntrySide::Credit || $account->account_class !== $classification) {
                throw new ConflictHttpException('Required fee application accounting is unavailable.');
            }
        }
        $business = BusinessProfile::current();
        $period = app(FinancialPeriodService::class)->assertOpen(now($business->timezone)->toDateString(), $business->timezone, true);
        $quote = ['actor_user_id' => $admin->id, 'customer_profile_id' => $customer->id, 'customer_version' => $customer->version,
            'thrift_plan_id' => $plan->id, 'plan_id' => $plan->plan_id, 'plan_version' => $plan->version,
            'fee_obligation_id' => $fee->id, 'fee_snapshot_id' => $fee->fee_snapshot_id, 'latest_entry_id' => $fee->entries->max('id'),
            'business_version' => $business->version, 'business_timezone' => $business->timezone,
            'occurred_on' => now($business->timezone)->toDateString(), 'period_id' => $period->id, 'period_version' => $period->version,
            'quote_expires_at' => ($expires ?? CarbonImmutable::now()->addMinutes(10))->utc()->toIso8601String(),
            'account_versions' => $accounts->mapWithKeys(fn (LedgerAccount $account): array => [$account->code->value => $account->version])->all(), 'amount_kobo' => $amount, 'currency' => 'NGN', 'position' => $position,
            'remaining_cycle_savings_kobo' => $position['cycle_liability_kobo'] - $amount,
            'remaining_available_kobo' => $position['cycle_available_kobo'] - $amount,
            'remaining_fee_kobo' => 0, 'reason' => trim($data['reason']), 'customer_description' => trim($data['customer_description'])];
        $quote['preview_fingerprint'] = $this->fingerprint($quote);

        return $quote;
    }

    private function authorize(User $actor): User
    {
        $admin = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! app(AuthorizationService::class)->allows($admin, AdminPermission::FeesManage)) {
            throw new AuthorizationException('Current authority to manage fees is required.');
        }

        return $admin;
    }

    private function assertEnabled(): void
    {
        if (! $this->available()) {
            throw new ConflictHttpException('Reviewed savings fee application is unavailable.');
        }
    }

    /** @param array<mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
