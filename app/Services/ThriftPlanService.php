<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\CustomerActivity;
use App\Enums\CustomerStatus;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanNotificationIntent;
use App\Models\PlanOperationAttempt;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ThriftPlanService
{
    public function __construct(
        protected CustomerActionAuthorizationGuard $authorizationGuard,
        protected CustomerActivityGate $customerActivityGate,
        protected PublicIdGenerator $publicIdGenerator,
        protected FeeObligationService $feeObligationService,
    ) {}

    /**
     * Build a fresh server-side agreement preview.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, CustomerProfile $customer, array $data): array
    {
        Gate::forUser($actor)->authorize('managePlan', $customer);

        $customer->loadMissing(['user', 'currentAssignment.agentProfile.user']);
        app(BusinessSettings::class)->ensureFeature('plan_creation');
        $business = BusinessProfile::current();
        $this->assertBusinessTimezone($business->timezone);
        $this->assertCustomerCanCreate($customer);
        $this->assertNoOpenCycle($customer);
        $this->assertPreviewPredecessor($customer, $data['predecessor_plan_id'] ?? null);

        $assignment = $customer->currentAssignment;
        if ($assignment === null) {
            throw ValidationException::withMessages(['customer' => ['This Customer has no current Agent assignment.']]);
        }

        $rule = $this->currentPlanRule((int) ($data['fee_rule_id'] ?? 0));
        $terms = $this->normalizeTerms($data, $business->timezone);
        $expectedGrossKobo = $this->checkedMultiply($terms['contribution_amount_kobo'], $terms['contribution_days']);
        $previewBasisKobo = $this->feeBasisForPreview($rule, $terms['contribution_amount_kobo'], $expectedGrossKobo);
        $quote = $this->feeObligationService->quote($rule, $previewBasisKobo, 'plan_preview', $customer->customer_id);

        $slots = [];
        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $terms['start_date'], $business->timezone);
        for ($ordinal = 1; $ordinal <= $terms['contribution_days']; $ordinal++) {
            $slots[] = [
                'ordinal' => $ordinal,
                'due_date' => $startDate->addDays($ordinal - 1)->toDateString(),
                'formatted_amount' => MoneyFormatter::formatNaira($terms['contribution_amount_kobo']),
            ];
        }

        $fingerprintData = $this->canonicalPayload(
            $terms,
            $customer,
            $assignment->version,
            $business->version,
            $rule->id,
            $rule->version,
            filled($data['predecessor_plan_id'] ?? null) ? (string) $data['predecessor_plan_id'] : null,
        );

        return [
            'available' => true,
            'customer' => [
                'id' => $customer->customer_id,
                'name' => $customer->user?->name,
                'status' => $customer->operational_status->value,
                'assignment_version' => $assignment->version,
                'version' => $customer->version,
            ],
            'business' => [
                'timezone' => $business->timezone,
                'version' => $business->version,
            ],
            'terms' => [
                ...$terms,
                'currency' => 'NGN',
                'formatted_contribution_amount' => MoneyFormatter::formatNaira($terms['contribution_amount_kobo']),
                'expected_gross_kobo' => $expectedGrossKobo,
                'formatted_expected_gross' => MoneyFormatter::formatNaira($expectedGrossKobo),
                'scheduled_end_date' => $slots[array_key_last($slots)]['due_date'],
            ],
            'fee' => [
                'rule_id' => $rule->id,
                'rule_version' => $rule->version,
                'name' => $rule->name,
                'model' => $rule->model->value,
                'timing' => $rule->timing->value,
                'basis' => $rule->basis->value,
                'basis_points' => $rule->basis_points,
                'amount_kobo' => $quote->amountKobo,
                'estimate_available' => ! ($rule->model === FeeRuleModel::Percentage && $rule->timing === FeeRuleTiming::Withdrawal),
                'formatted_amount' => $this->formatFeeQuote($rule, $quote->amountKobo),
                'customer_description' => $rule->customer_description,
            ],
            'slots' => $slots,
            'open_cycle_available' => true,
            'preview_fingerprint' => hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Build a server-side preview for an existing plan revision.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function previewRevision(User $actor, ThriftPlan $plan, array $data): array
    {
        $customer = $plan->customerProfile()->with(['user', 'currentAssignment'])->firstOrFail();
        Gate::forUser($actor)->authorize('managePlan', $customer);
        $plan->loadMissing('termsRevisions.feeSnapshot');
        $currentRevision = $plan->currentTermsRevision();
        if ($currentRevision === null || ! in_array($plan->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true)) {
            throw new ConflictHttpException('Only active or paused plans with current terms can be amended.');
        }
        $this->assertBusinessTimezone($currentRevision->timezone);

        if (! $this->customerActivityGate->allows($customer->operational_status, CustomerActivity::AmendPlan)) {
            throw ValidationException::withMessages(['customer_status' => ['The Customer’s current status does not allow plan amendments.']]);
        }

        $business = BusinessProfile::current();

        $terms = $this->normalizeTerms($data, $currentRevision->timezone);
        $submittedRuleId = (int) ($data['fee_rule_id'] ?? 0);
        $rule = $submittedRuleId === $currentRevision->feeSnapshot->fee_rule_id
            ? FeeRule::query()->whereKey($submittedRuleId)->firstOrFail()
            : $this->currentPlanRule($submittedRuleId);
        if (isset($data['fee_rule_version']) && $rule->version !== (int) $data['fee_rule_version']) {
            throw new ConflictHttpException('The selected fee option changed. Review the current plan preview.');
        }

        $expectedGrossKobo = $this->checkedMultiply($terms['contribution_amount_kobo'], $terms['contribution_days']);
        $financialTermsChanged = $this->financialTermsChanged($terms, $currentRevision, $rule);
        $hasActivity = $this->hasCycleActivity($plan);
        if ($hasActivity && $financialTermsChanged) {
            throw new ConflictHttpException('Financial and schedule terms are locked after activity. Only the plan name and Customer-visible notes can change.');
        }
        if (! $hasActivity && $this->hasFeeObligations($plan)) {
            throw new ConflictHttpException('A fee obligation exists for this plan. Terms cannot be amended.');
        }

        $previewBasisKobo = $this->feeBasisForPreview($rule, $terms['contribution_amount_kobo'], $expectedGrossKobo);
        $quote = $this->feeObligationService->quote($rule, $previewBasisKobo, 'plan_preview', $plan->plan_id);
        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $terms['start_date'], $currentRevision->timezone);
        $slots = [];
        for ($ordinal = 1; $ordinal <= $terms['contribution_days']; $ordinal++) {
            $slots[] = [
                'ordinal' => $ordinal,
                'due_date' => $startDate->addDays($ordinal - 1)->toDateString(),
                'formatted_amount' => MoneyFormatter::formatNaira($terms['contribution_amount_kobo']),
            ];
        }

        $assignmentVersion = $customer->currentAssignment?->version;
        if ($assignmentVersion === null) {
            throw ValidationException::withMessages(['customer' => ['This Customer has no current Agent assignment.']]);
        }
        $fingerprint = $this->revisionPreviewFingerprint(
            $plan,
            $customer,
            $assignmentVersion,
            $business->version,
            $terms,
            $rule,
            $financialTermsChanged,
        );

        return [
            'available' => true,
            'plan_id' => $plan->plan_id,
            'plan_version' => $plan->version,
            'terms_revision' => $plan->current_terms_revision,
            'customer_version' => $customer->version,
            'assignment_version' => $assignmentVersion,
            'business_version' => $business->version,
            'terms' => [
                ...$terms,
                'currency' => 'NGN',
                'formatted_contribution_amount' => MoneyFormatter::formatNaira($terms['contribution_amount_kobo']),
                'expected_gross_kobo' => $expectedGrossKobo,
                'formatted_expected_gross' => MoneyFormatter::formatNaira($expectedGrossKobo),
                'scheduled_end_date' => $slots[array_key_last($slots)]['due_date'],
            ],
            'fee' => [
                'rule_id' => $rule->id,
                'rule_version' => $rule->version,
                'name' => $rule->name,
                'model' => $rule->model->value,
                'timing' => $rule->timing->value,
                'basis' => $rule->basis->value,
                'basis_points' => $rule->basis_points,
                'amount_kobo' => $quote->amountKobo,
                'estimate_available' => ! ($rule->model === FeeRuleModel::Percentage && $rule->timing === FeeRuleTiming::Withdrawal),
                'formatted_amount' => $this->formatFeeQuote($rule, $quote->amountKobo),
                'customer_description' => $rule->customer_description,
            ],
            'financial_terms_changed' => $financialTermsChanged,
            'financial_terms_locked' => $hasActivity,
            'slots' => $slots,
            'preview_fingerprint' => $fingerprint,
        ];
    }

    /**
     * Create a confirmed plan, terms, slots, fee snapshot, lifecycle record and operation result atomically.
     *
     * @param  array<string, mixed>  $data
     * @return array{plan: ThriftPlan, replayed: bool}
     */
    public function create(User $actor, CustomerProfile $customer, string $attemptReference, array $data): array
    {
        if (! Str::isUuid($attemptReference)) {
            throw ValidationException::withMessages(['attempt_reference' => ['A valid operation reference is required.']]);
        }

        $submittedFingerprint = (string) ($data['preview_fingerprint'] ?? '');

        return DB::transaction(function () use ($actor, $customer, $attemptReference, $data, $submittedFingerprint): array {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $customer->id,
                ability: 'managePlan',
            );

            $lockedCustomer = $context->customerProfile;
            $operationType = filled($data['predecessor_plan_id'] ?? null) ? 'plan_renew' : 'plan_create';
            $payloadFingerprint = $this->requestFingerprint($data, [
                'operation' => $operationType,
                'customer_profile_id' => $lockedCustomer->id,
                'preview_fingerprint' => $submittedFingerprint,
            ]);
            $attempt = $this->existingAttempt($attemptReference, $context->actor, $operationType, $payloadFingerprint);
            if ($attempt?->status === 'committed') {
                if ($attempt->thrift_plan_id === null || $attempt->customer_profile_id !== $lockedCustomer->id) {
                    throw new ConflictHttpException('The original plan operation has no accessible committed result.');
                }

                return [
                    'plan' => ThriftPlan::query()
                        ->where('customer_profile_id', $lockedCustomer->id)
                        ->with('customerProfile.user')
                        ->findOrFail($attempt->thrift_plan_id),
                    'replayed' => true,
                ];
            }

            $this->assertSubmittedVersions($context->customerProfile->version, (int) $data['customer_version'], 'Customer details changed. Review the current plan preview.');
            $currentAssignmentVersion = $context->currentAssignment?->version;
            if ($currentAssignmentVersion === null || $currentAssignmentVersion !== (int) $data['assignment_version']) {
                throw new ConflictHttpException('Customer assignment changed. Review the current plan preview.');
            }
            $this->customerActivityGate->assertAllowed($lockedCustomer, CustomerActivity::CreatePlan);
            app(BusinessSettings::class)->ensureFeature('plan_creation');
            $business = BusinessProfile::query()->lockForUpdate()->firstOrFail();
            $this->assertBusinessTimezone($business->timezone);

            if ($business->version !== (int) $data['business_version']) {
                throw new ConflictHttpException('Business configuration changed. Review the current plan preview.');
            }

            $terms = $this->normalizeTerms($data, $business->timezone);
            $rule = $this->currentPlanRule((int) $data['fee_rule_id'], true);
            if ($rule->version !== (int) $data['fee_rule_version']) {
                throw new ConflictHttpException('The selected fee option changed. Review the current plan preview.');
            }

            $this->assertCustomerCanCreate($lockedCustomer);
            $predecessor = $this->lockCancelledPredecessor($lockedCustomer, $data['predecessor_plan_id'] ?? null);

            $expectedGrossKobo = $this->checkedMultiply($terms['contribution_amount_kobo'], $terms['contribution_days']);
            $previewBasisKobo = $this->feeBasisForPreview($rule, $terms['contribution_amount_kobo'], $expectedGrossKobo);
            $quote = $this->feeObligationService->quote($rule, $previewBasisKobo, 'plan_preview', $lockedCustomer->customer_id);
            $canonical = $this->canonicalPayload(
                $terms,
                $lockedCustomer,
                $currentAssignmentVersion,
                $business->version,
                $rule->id,
                $rule->version,
                $predecessor?->plan_id,
            );
            $currentFingerprint = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
            if ($submittedFingerprint === '' || ! hash_equals($currentFingerprint, $submittedFingerprint)) {
                throw new ConflictHttpException('Plan details changed after preview. Review and confirm the current agreement.');
            }

            if (! (bool) ($data['customer_agreement_attested'] ?? false)) {
                throw ValidationException::withMessages(['customer_agreement_attested' => ['Confirm that the Customer agreed to the displayed plan terms.']]);
            }

            if (ThriftPlan::query()->where('customer_profile_id', $lockedCustomer->id)->whereNotNull('open_customer_profile_id')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['customer' => ['This Customer already has an open daily thrift plan.']]);
            }

            $attempt ??= PlanOperationAttempt::create([
                'attempt_reference' => $attemptReference,
                'user_id' => $context->actor->id,
                'business_id' => $business->business_id,
                'operation_type' => $operationType,
                'payload_fingerprint' => $payloadFingerprint,
                'status' => 'in_progress',
                'customer_profile_id' => $lockedCustomer->id,
            ]);

            $plan = ThriftPlan::create([
                'plan_id' => $this->publicIdGenerator->generateForPlan(),
                'customer_profile_id' => $lockedCustomer->id,
                'created_by_user_id' => $context->actor->id,
                'predecessor_plan_id' => $predecessor?->id,
                'open_customer_profile_id' => $lockedCustomer->id,
                'status' => ThriftPlanStatus::Active,
                'current_terms_revision' => 1,
                'version' => 1,
            ]);

            $snapshot = $this->createFeeSnapshot(
                customer: $lockedCustomer,
                plan: $plan,
                revision: 1,
                rule: $rule,
                quoteAmountKobo: $quote->amountKobo,
                basisKobo: $previewBasisKobo,
            );

            $revision = $this->createTermsRevision(
                plan: $plan,
                revision: 1,
                terms: $terms,
                expectedGrossKobo: $expectedGrossKobo,
                timezone: $business->timezone,
                businessVersion: $business->version,
                snapshot: $snapshot,
                actor: $context->actor,
                reason: null,
            );

            $this->createSlots($plan, $revision, $terms);
            $event = $this->recordLifecycleEvent(
                plan: $plan,
                attempt: $attempt,
                eventType: $predecessor === null ? 'created' : 'renewed',
                fromStatus: null,
                toStatus: ThriftPlanStatus::Active,
                actor: $context->actor,
                assignmentVersion: $context->currentAssignment?->version,
                reason: null,
                customerExplanation: null,
                payload: [
                    'terms_revision' => 1,
                    'fee_snapshot_id' => $snapshot->id,
                    'agreement_attested' => true,
                    'predecessor_plan_id' => $predecessor?->plan_id,
                ],
            );
            $this->createNotificationIntents($lockedCustomer, $plan, $event, $context->currentAgentProfile?->user_id);

            AuditEvent::record(
                eventType: $predecessor === null ? 'thrift_plan.created' : 'thrift_plan.renewed',
                targetType: ThriftPlan::class,
                targetId: $plan->id,
                targetReference: $plan->plan_id,
                payload: [
                    'customer_profile_id' => $lockedCustomer->id,
                    'terms_revision' => 1,
                    'fee_snapshot_id' => $snapshot->id,
                    'business_version' => $business->version,
                    'assignment_version' => $context->currentAssignment?->version,
                    'predecessor_plan_id' => $predecessor?->plan_id,
                    'agreement_attested' => true,
                ],
                actor: $context->actor,

                context: ['executor' => self::class]
            );

            $attempt->thrift_plan_id = $plan->id;
            $attempt->status = 'committed';
            $attempt->result_summary = [
                'plan_id' => $plan->plan_id,
                'status' => $plan->status->value,
                'terms_revision' => $revision->revision,
            ];
            $attempt->save();

            return ['plan' => $plan->load(['customerProfile.user', 'termsRevisions.feeSnapshot', 'slots']), 'replayed' => false];
        }, attempts: 3);
    }

    /**
     * Revise a plan while preserving prior terms and slot identities.
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(User $actor, ThriftPlan $plan, string $attemptReference, array $data): ThriftPlan
    {
        if (! Str::isUuid($attemptReference)) {
            throw ValidationException::withMessages(['attempt_reference' => ['A valid operation reference is required.']]);
        }

        return DB::transaction(function () use ($actor, $plan, $attemptReference, $data): ThriftPlan {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $plan->customer_profile_id,
                ability: 'managePlan',
            );
            $fingerprint = $this->requestFingerprint($data, ['operation' => 'plan_revise', 'plan_id' => $plan->plan_id]);
            $attempt = $this->existingAttempt($attemptReference, $context->actor, 'plan_revise', $fingerprint);
            if ($attempt?->status === 'committed') {
                if ($attempt->thrift_plan_id !== $plan->id) {
                    throw new ConflictHttpException('This operation reference belongs to a different plan.');
                }

                return $plan->fresh(['termsRevisions.feeSnapshot', 'slots', 'lifecycleEvents']);
            }

            $this->assertSubmittedVersions($context->customerProfile->version, (int) $data['customer_version'], 'Customer details changed. Reload the plan before saving your revision.');
            $currentAssignmentVersion = $context->currentAssignment?->version;
            if ($currentAssignmentVersion === null || $currentAssignmentVersion !== (int) $data['assignment_version']) {
                throw new ConflictHttpException('Customer assignment changed. Reload the plan before saving your revision.');
            }
            $planRecord = ThriftPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($planRecord->version !== (int) $data['plan_version'] || $planRecord->current_terms_revision !== (int) $data['terms_revision']) {
                throw new ConflictHttpException('This plan changed. Reload it before saving your revision.');
            }

            $currentRevision = $planRecord->termsRevisions()->where('revision', $planRecord->current_terms_revision)->firstOrFail();
            if (! in_array($planRecord->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true)) {
                throw new ConflictHttpException('Only active or paused plans can be amended.');
            }

            $this->customerActivityGate->assertAllowed($context->customerProfile, CustomerActivity::AmendPlan);
            $business = BusinessProfile::query()->lockForUpdate()->firstOrFail();
            if ($business->version !== (int) $data['business_version']) {
                throw new ConflictHttpException('Business configuration changed. Reload the plan before saving your revision.');
            }
            $this->assertBusinessTimezone($currentRevision->timezone);

            $terms = $this->normalizeTerms($data, $currentRevision->timezone);
            $hasActivity = $this->hasCycleActivity($planRecord);
            $submittedRuleId = (int) $data['fee_rule_id'];
            $rule = $submittedRuleId === $currentRevision->feeSnapshot->fee_rule_id
                ? FeeRule::query()->whereKey($submittedRuleId)->lockForUpdate()->firstOrFail()
                : $this->currentPlanRule($submittedRuleId, true);
            if ($rule->version !== (int) $data['fee_rule_version']) {
                throw new ConflictHttpException('The selected fee option changed. Review the current plan preview.');
            }

            $expectedGrossKobo = $this->checkedMultiply($terms['contribution_amount_kobo'], $terms['contribution_days']);
            $financialTermsChanged = $terms['contribution_amount_kobo'] !== $currentRevision->contribution_amount_kobo
                || $terms['start_date'] !== $currentRevision->start_date
                || $terms['contribution_days'] !== $currentRevision->contribution_days
                || $rule->id !== $currentRevision->feeSnapshot->fee_rule_id
                || $rule->version !== $currentRevision->feeSnapshot->fee_rule_version;

            if ($hasActivity && $financialTermsChanged) {
                throw new ConflictHttpException('Financial and schedule terms are locked after activity. Only the plan name and Customer-visible notes can change.');
            }

            if (! $hasActivity && $this->hasFeeObligations($planRecord)) {
                throw new ConflictHttpException('A fee obligation exists for this plan. Terms cannot be amended.');
            }
            if (! (bool) ($data['customer_agreement_attested'] ?? false)) {
                throw ValidationException::withMessages(['customer_agreement_attested' => ['Confirm that the Customer agreed to the revised plan terms.']]);
            }
            $previewFingerprint = $this->revisionPreviewFingerprint(
                $planRecord,
                $context->customerProfile,
                $currentAssignmentVersion,
                $business->version,
                $terms,
                $rule,
                $financialTermsChanged,
            );
            if (! hash_equals($previewFingerprint, (string) ($data['preview_fingerprint'] ?? ''))) {
                throw new ConflictHttpException('Plan details changed after preview. Review and confirm the current agreement.');
            }

            $attempt ??= PlanOperationAttempt::create([
                'attempt_reference' => $attemptReference,
                'user_id' => $context->actor->id,
                'business_id' => $business->business_id,
                'operation_type' => 'plan_revise',
                'payload_fingerprint' => $fingerprint,
                'status' => 'in_progress',
                'thrift_plan_id' => $planRecord->id,
                'customer_profile_id' => $planRecord->customer_profile_id,
            ]);

            $revisionNumber = $planRecord->current_terms_revision + 1;
            $previewBasisKobo = $currentRevision->feeSnapshot->basis_amount_kobo;
            $quoteAmountKobo = $currentRevision->feeSnapshot->amount_kobo;
            if ($financialTermsChanged) {
                $previewBasisKobo = $this->feeBasisForPreview($rule, $terms['contribution_amount_kobo'], $expectedGrossKobo);
                $quote = $this->feeObligationService->quote($rule, $previewBasisKobo, 'plan_preview', $planRecord->plan_id);
                $quoteAmountKobo = $quote->amountKobo;
            }
            $snapshot = $this->createFeeSnapshot(
                $context->customerProfile,
                $planRecord,
                $revisionNumber,
                $rule,
                $quoteAmountKobo,
                $previewBasisKobo,
            );

            $revision = $this->createTermsRevision(
                plan: $planRecord,
                revision: $revisionNumber,
                terms: $terms,
                expectedGrossKobo: $expectedGrossKobo,
                timezone: $currentRevision->timezone,
                businessVersion: $business->version,
                snapshot: $snapshot,
                actor: $context->actor,
                reason: trim((string) $data['reason']),
            );

            if (! $hasActivity && $financialTermsChanged) {
                $this->syncSlots($planRecord, $revision, $terms);
            }

            $fromVersion = $planRecord->version;
            $planRecord->current_terms_revision = $revisionNumber;
            $planRecord->version++;
            $planRecord->save();

            $event = $this->recordLifecycleEvent(
                $planRecord,
                $attempt,
                $hasActivity ? 'details_corrected' : 'terms_amended',
                $planRecord->status,
                $planRecord->status,
                $context->actor,
                $context->currentAssignment?->version,
                trim((string) $data['reason']),
                trim((string) $data['customer_explanation']),
                ['from_version' => $fromVersion, 'terms_revision' => $revisionNumber, 'financial_terms_changed' => $financialTermsChanged],
            );
            $this->createNotificationIntents($context->customerProfile, $planRecord, $event, $context->currentAgentProfile?->user_id);

            AuditEvent::record(
                eventType: $hasActivity ? 'thrift_plan.details_corrected' : 'thrift_plan.terms_amended',
                targetType: ThriftPlan::class,
                targetId: $planRecord->id,
                targetReference: $planRecord->plan_id,
                payload: [
                    'from_version' => $fromVersion,
                    'to_version' => $planRecord->version,
                    'terms_revision' => $revisionNumber,
                    'financial_terms_changed' => $financialTermsChanged,
                    'reason' => trim((string) $data['reason']),
                ],
                actor: $context->actor,

                context: ['executor' => self::class]
            );

            $attempt->status = 'committed';
            $attempt->result_summary = ['plan_id' => $planRecord->plan_id, 'terms_revision' => $revisionNumber];
            $attempt->save();

            return $planRecord->fresh(['termsRevisions.feeSnapshot', 'slots', 'lifecycleEvents']);
        }, attempts: 3);
    }

    /**
     * Apply an explicit pause, resume, or zero-activity cancellation.
     *
     * @param  array<string, mixed>  $data
     */
    public function transition(User $actor, ThriftPlan $plan, string $action, string $attemptReference, array $data): ThriftPlan
    {
        if (! in_array($action, ['pause', 'resume', 'cancel'], true)) {
            throw new \InvalidArgumentException('Unsupported plan transition.');
        }

        if (! Str::isUuid($attemptReference)) {
            throw ValidationException::withMessages(['attempt_reference' => ['A valid operation reference is required.']]);
        }

        return DB::transaction(function () use ($actor, $plan, $action, $attemptReference, $data): ThriftPlan {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $plan->customer_profile_id,
                ability: 'managePlan',
            );
            $operationType = 'plan_'.$action;
            $fingerprint = $this->requestFingerprint($data, [
                'operation' => $operationType,
                'plan_id' => $plan->plan_id,
            ]);
            $attempt = $this->existingAttempt($attemptReference, $context->actor, $operationType, $fingerprint);
            if ($attempt?->status === 'committed') {
                if ($attempt->thrift_plan_id !== $plan->id) {
                    throw new ConflictHttpException('This operation reference belongs to a different plan.');
                }

                return $plan->fresh(['termsRevisions.feeSnapshot', 'slots', 'lifecycleEvents']);
            }

            $this->assertSubmittedVersions($context->customerProfile->version, (int) $data['customer_version'], 'Customer details changed. Reload before confirming the plan action.');
            $currentAssignmentVersion = $context->currentAssignment?->version;
            if ($currentAssignmentVersion === null || $currentAssignmentVersion !== (int) $data['assignment_version']) {
                throw new ConflictHttpException('Customer assignment changed. Reload before confirming the plan action.');
            }
            $planRecord = ThriftPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($planRecord->version !== (int) $data['plan_version']) {
                throw new ConflictHttpException('This plan changed. Reload before confirming the action.');
            }

            $fromStatus = $planRecord->status;
            $toStatus = match ($action) {
                'pause' => $fromStatus === ThriftPlanStatus::Active ? ThriftPlanStatus::Paused : null,
                'resume' => $fromStatus === ThriftPlanStatus::Paused ? ThriftPlanStatus::Active : null,
                'cancel' => in_array($fromStatus, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true) ? ThriftPlanStatus::Cancelled : null,
            };
            if ($toStatus === null) {
                throw new ConflictHttpException('This action is not available for the plan’s current state.');
            }

            $customerStatus = $context->customerProfile->operational_status;
            if ($customerStatus === CustomerStatus::Archived || ($action === 'resume' && $customerStatus !== CustomerStatus::Active)) {
                throw new ConflictHttpException('The Customer’s current status does not allow this plan action.');
            }
            if ($action === 'cancel' && ($this->hasCycleActivity($planRecord) || $this->hasFeeObligations($planRecord))) {
                throw new ConflictHttpException('Only a never-used plan with no fee obligation can be cancelled.');
            }

            $reason = trim((string) $data['reason']);
            $customerExplanation = trim((string) $data['customer_explanation']);
            $attempt ??= PlanOperationAttempt::create([
                'attempt_reference' => $attemptReference,
                'user_id' => $context->actor->id,
                'business_id' => BusinessProfile::current()->business_id,
                'operation_type' => $operationType,
                'payload_fingerprint' => $fingerprint,
                'status' => 'in_progress',
                'thrift_plan_id' => $planRecord->id,
                'customer_profile_id' => $planRecord->customer_profile_id,
            ]);

            $fromVersion = $planRecord->version;
            $planRecord->status = $toStatus;
            $planRecord->open_customer_profile_id = $toStatus->isOpen() ? $planRecord->customer_profile_id : null;
            $planRecord->version++;
            $planRecord->save();

            $event = $this->recordLifecycleEvent(
                $planRecord,
                $attempt,
                $action,
                $fromStatus,
                $toStatus,
                $context->actor,
                $context->currentAssignment?->version,
                $reason,
                $customerExplanation,
                ['from_version' => $fromVersion],
            );
            $this->createNotificationIntents($context->customerProfile, $planRecord, $event, $context->currentAgentProfile?->user_id);
            AuditEvent::record(
                eventType: 'thrift_plan.'.$action,
                targetType: ThriftPlan::class,
                targetId: $planRecord->id,
                targetReference: $planRecord->plan_id,
                payload: ['from' => $fromStatus->value, 'to' => $toStatus->value, 'version' => $planRecord->version, 'reason' => $reason],
                actor: $context->actor,

                context: ['executor' => self::class]
            );

            $attempt->status = 'committed';
            $attempt->result_summary = ['plan_id' => $planRecord->plan_id, 'status' => $toStatus->value];
            $attempt->save();

            return $planRecord->fresh(['termsRevisions.feeSnapshot', 'slots', 'lifecycleEvents']);
        }, attempts: 3);
    }

    /**
     * Parse decimal NGN input into integer kobo without using floating point.
     */
    public function amountToKobo(string $amount): int
    {
        $amount = trim($amount);
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount_ngn' => ['Enter an amount from ₦1.00 to ₦10,000,000.00 with no more than two decimal places.']]);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $amountKobo = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($amountKobo < 100 || $amountKobo > 1_000_000_000) {
            throw ValidationException::withMessages(['amount_ngn' => ['Enter an amount from ₦1.00 to ₦10,000,000.00.']]);
        }

        return $amountKobo;
    }

    /** @param array<string, mixed> $data @return array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string} */
    private function normalizeTerms(array $data, string $timezone): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $startDate = trim((string) ($data['start_date'] ?? ''));
        $days = filter_var($data['contribution_days'] ?? null, FILTER_VALIDATE_INT);
        $amount = $this->amountToKobo((string) ($data['amount_ngn'] ?? ''));
        $notes = filled($data['customer_visible_notes'] ?? null) ? trim((string) $data['customer_visible_notes']) : null;

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => ['Plan name is required and must not exceed 100 characters.']]);
        }
        if ($days === false || $days < 1 || $days > 366) {
            throw ValidationException::withMessages(['contribution_days' => ['Contribution days must be an integer from 1 to 366.']]);
        }
        if (! CarbonImmutable::hasFormat($startDate, 'Y-m-d')) {
            throw ValidationException::withMessages(['start_date' => ['Enter a valid calendar date.']]);
        }

        $selectedDate = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($selectedDate->lt($today) || $selectedDate->gt($today->addDays(365))) {
            throw ValidationException::withMessages(['start_date' => ['Start date must be today or within the next 365 calendar days.']]);
        }
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(['customer_visible_notes' => ['Customer-visible notes must not exceed 2,000 characters.']]);
        }

        return [
            'name' => $name,
            'contribution_amount_kobo' => $amount,
            'start_date' => $selectedDate->toDateString(),
            'contribution_days' => (int) $days,
            'customer_visible_notes' => $notes,
        ];
    }

    private function currentPlanRule(int $ruleId, bool $forUpdate = false): FeeRule
    {
        $query = FeeRule::currentPlanOptions()->whereKey($ruleId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $rule = $query->first();
        if ($rule === null) {
            throw ValidationException::withMessages(['fee_rule_id' => ['Choose a currently available plan fee option.']]);
        }

        return $rule;
    }

    private function feeBasisForPreview(FeeRule $rule, int $contributionAmountKobo, int $expectedGrossKobo): int
    {
        return match ($rule->model) {
            FeeRuleModel::OneDay => $contributionAmountKobo,
            FeeRuleModel::Percentage => $rule->timing === FeeRuleTiming::CycleCompletion ? $expectedGrossKobo : 0,
            default => 0,
        };
    }

    private function formatFeeQuote(FeeRule $rule, int $amountKobo): string
    {
        if ($rule->model === FeeRuleModel::NoFee) {
            return 'No fee';
        }
        if ($rule->model === FeeRuleModel::Percentage && $rule->timing === FeeRuleTiming::Withdrawal) {
            return $rule->formattedAmount().' — calculated when a withdrawal is quoted';
        }

        return $rule->model === FeeRuleModel::Percentage
            ? MoneyFormatter::formatNaira($amountKobo).' estimated from expected contributions'
            : MoneyFormatter::formatNaira($amountKobo);
    }

    private function checkedMultiply(int $amount, int $count): int
    {
        if ($amount < 0 || $count < 0 || ($count !== 0 && $amount > intdiv(PHP_INT_MAX, $count))) {
            throw ValidationException::withMessages(['amount_ngn' => ['Expected gross exceeds the supported calculation range.']]);
        }

        return $amount * $count;
    }

    private function assertBusinessTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => ['The configured business timezone is not a valid IANA timezone.']]);
        }
    }

    private function assertCustomerCanCreate(CustomerProfile $customer): void
    {
        if ($customer->operational_status !== CustomerStatus::Active) {
            throw ValidationException::withMessages(['customer' => ['Plans can be created only for Active Customers.']]);
        }
    }

    private function assertNoOpenCycle(CustomerProfile $customer): void
    {
        if (ThriftPlan::query()->where('customer_profile_id', $customer->id)->whereNotNull('open_customer_profile_id')->exists()) {
            throw ValidationException::withMessages(['customer' => ['This Customer already has an open daily thrift plan.']]);
        }
    }

    private function lockCancelledPredecessor(CustomerProfile $customer, mixed $predecessorPlanId): ?ThriftPlan
    {
        if ($predecessorPlanId === null || $predecessorPlanId === '') {
            return null;
        }

        $predecessor = ThriftPlan::query()->where('plan_id', (string) $predecessorPlanId)->lockForUpdate()->first();
        if ($predecessor === null
            || $predecessor->customer_profile_id !== $customer->id
            || $predecessor->status !== ThriftPlanStatus::Cancelled
            || ThriftPlan::query()->where('predecessor_plan_id', $predecessor->id)->exists()) {
            throw ValidationException::withMessages(['predecessor_plan_id' => ['Only a cancelled predecessor without an existing successor can be renewed.']]);
        }

        return $predecessor;
    }

    private function assertPreviewPredecessor(CustomerProfile $customer, mixed $predecessorPlanId): void
    {
        if ($predecessorPlanId === null || $predecessorPlanId === '') {
            return;
        }

        $predecessor = ThriftPlan::query()
            ->where('plan_id', (string) $predecessorPlanId)
            ->where('customer_profile_id', $customer->id)
            ->where('status', ThriftPlanStatus::Cancelled->value)
            ->first();
        if ($predecessor === null || ThriftPlan::query()->where('predecessor_plan_id', $predecessor->id)->exists()) {
            throw ValidationException::withMessages(['predecessor_plan_id' => ['Only a cancelled predecessor without an existing successor can be renewed.']]);
        }
    }

    private function hasCycleActivity(ThriftPlan $plan): bool
    {
        return $plan->activity_started_at !== null;
    }

    private function hasFeeObligations(ThriftPlan $plan): bool
    {
        $snapshotIds = $plan->termsRevisions()->pluck('fee_snapshot_id');

        return $snapshotIds->isNotEmpty() && FeeObligation::query()->whereIn('fee_snapshot_id', $snapshotIds)->exists();
    }

    /**
     * @param  array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string}  $terms
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        array $terms,
        CustomerProfile $customer,
        int $assignmentVersion,
        int $businessVersion,
        int $feeRuleId,
        int $feeRuleVersion,
        ?string $predecessorPlanId = null,
    ): array {
        return [
            'customer_profile_id' => $customer->id,
            'customer_version' => $customer->version,
            'assignment_version' => $assignmentVersion,
            'business_version' => $businessVersion,
            'fee_rule_id' => $feeRuleId,
            'fee_rule_version' => $feeRuleVersion,
            'name' => $terms['name'],
            'contribution_amount_kobo' => $terms['contribution_amount_kobo'],
            'start_date' => $terms['start_date'],
            'contribution_days' => $terms['contribution_days'],
            'customer_visible_notes' => $terms['customer_visible_notes'],
            'predecessor_plan_id' => $predecessorPlanId,
        ];
    }

    /** @param array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string} $terms */
    private function revisionPreviewFingerprint(
        ThriftPlan $plan,
        CustomerProfile $customer,
        int $assignmentVersion,
        int $businessVersion,
        array $terms,
        FeeRule $rule,
        bool $financialTermsChanged,
    ): string {
        return hash('sha256', json_encode([
            'plan_id' => $plan->plan_id,
            'plan_version' => $plan->version,
            'terms_revision' => $plan->current_terms_revision,
            'customer_profile_id' => $customer->id,
            'customer_version' => $customer->version,
            'assignment_version' => $assignmentVersion,
            'business_version' => $businessVersion,
            'terms' => $terms,
            'fee_rule_id' => $rule->id,
            'fee_rule_version' => $rule->version,
            'financial_terms_changed' => $financialTermsChanged,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string} $terms */
    private function financialTermsChanged(array $terms, PlanTermsRevision $revision, FeeRule $rule): bool
    {
        return $terms['contribution_amount_kobo'] !== $revision->contribution_amount_kobo
            || $terms['start_date'] !== $revision->start_date
            || $terms['contribution_days'] !== $revision->contribution_days
            || $rule->id !== $revision->feeSnapshot->fee_rule_id
            || $rule->version !== $revision->feeSnapshot->fee_rule_version;
    }

    private function createFeeSnapshot(
        CustomerProfile $customer,
        ThriftPlan $plan,
        int $revision,
        FeeRule $rule,
        int $quoteAmountKobo,
        int $basisKobo,
    ): FeeSnapshot {
        return FeeSnapshot::create([
            'customer_profile_id' => $customer->id,
            'source_type' => 'plan_terms_revision',
            'source_id' => $plan->plan_id.'-R'.$revision,
            'fee_rule_id' => $rule->id,
            'fee_rule_version' => $rule->version,
            'name' => $rule->name,
            'kind' => $rule->kind->value,
            'model' => $rule->model->value,
            'timing' => $rule->timing->value,
            'basis' => $rule->basis->value,
            'settlement_source' => $rule->settlement_source->value,
            'currency' => $rule->currency,
            'amount_kobo' => $quoteAmountKobo,
            'basis_points' => $rule->basis_points,
            'basis_amount_kobo' => $basisKobo,
            'customer_description' => $rule->customer_description,
            'acknowledged_at' => now(),
        ]);
    }

    /** @param array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string} $terms */
    private function createTermsRevision(
        ThriftPlan $plan,
        int $revision,
        array $terms,
        int $expectedGrossKobo,
        string $timezone,
        int $businessVersion,
        FeeSnapshot $snapshot,
        User $actor,
        ?string $reason,
    ): PlanTermsRevision {
        return PlanTermsRevision::create([
            'thrift_plan_id' => $plan->id,
            'revision' => $revision,
            'name' => $terms['name'],
            'contribution_amount_kobo' => $terms['contribution_amount_kobo'],
            'currency' => 'NGN',
            'start_date' => $terms['start_date'],
            'contribution_days' => $terms['contribution_days'],
            'frequency' => 'daily',
            'timezone' => $timezone,
            'business_version' => $businessVersion,
            'expected_gross_kobo' => $expectedGrossKobo,
            'fee_snapshot_id' => $snapshot->id,
            'customer_visible_notes' => $terms['customer_visible_notes'],
            'reason' => $reason,
            'attested_by_user_id' => $actor->id,
            'attested_at' => now(),
        ]);
    }

    /** @param array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string} $terms */
    private function createSlots(ThriftPlan $plan, PlanTermsRevision $revision, array $terms): void
    {
        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $terms['start_date'], $revision->timezone);
        for ($ordinal = 1; $ordinal <= $terms['contribution_days']; $ordinal++) {
            ContributionSlot::create([
                'thrift_plan_id' => $plan->id,
                'plan_terms_revision_id' => $revision->id,
                'ordinal' => $ordinal,
                'due_date' => $startDate->addDays($ordinal - 1)->toDateString(),
                'expected_amount_kobo' => $terms['contribution_amount_kobo'],
                'active_ordinal' => $ordinal,
            ]);
        }
    }

    /** @param array{name: string, contribution_amount_kobo: int, start_date: string, contribution_days: int, customer_visible_notes: ?string} $terms */
    private function syncSlots(ThriftPlan $plan, PlanTermsRevision $revision, array $terms): void
    {
        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $terms['start_date'], $revision->timezone);
        $currentSlots = $plan->slots()->whereNotNull('active_ordinal')->orderBy('ordinal')->get()->keyBy('ordinal');
        $retained = [];

        for ($ordinal = 1; $ordinal <= $terms['contribution_days']; $ordinal++) {
            $dueDate = $startDate->addDays($ordinal - 1)->toDateString();
            $existing = $currentSlots->get($ordinal);
            if ($existing !== null
                && $existing->due_date === $dueDate
                && $existing->expected_amount_kobo === $terms['contribution_amount_kobo']) {
                $retained[] = $ordinal;

                continue;
            }

            if ($existing !== null) {
                $existing->active_ordinal = null;
                $existing->superseded_at = now();
                $existing->save();
            }

            ContributionSlot::create([
                'thrift_plan_id' => $plan->id,
                'plan_terms_revision_id' => $revision->id,
                'ordinal' => $ordinal,
                'due_date' => $dueDate,
                'expected_amount_kobo' => $terms['contribution_amount_kobo'],
                'active_ordinal' => $ordinal,
            ]);
        }

        foreach ($currentSlots as $ordinal => $slot) {
            if ($ordinal > $terms['contribution_days'] && ! in_array($ordinal, $retained, true)) {
                $slot->active_ordinal = null;
                $slot->superseded_at = now();
                $slot->save();
            }
        }
    }

    private function existingAttempt(string $attemptReference, User $actor, string $operationType, string $fingerprint): ?PlanOperationAttempt
    {
        $attempt = PlanOperationAttempt::query()->where('attempt_reference', $attemptReference)->lockForUpdate()->first();
        if ($attempt === null) {
            return null;
        }
        if ($attempt->user_id !== $actor->id || $attempt->operation_type !== $operationType || $attempt->payload_fingerprint !== $fingerprint) {
            throw new ConflictHttpException('This operation reference belongs to a different request.');
        }

        return $attempt;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $context */
    private function requestFingerprint(array $data, array $context): string
    {
        unset($data['attempt_reference']);

        return hash('sha256', json_encode(['context' => $context, 'data' => $data], JSON_THROW_ON_ERROR));
    }

    private function assertSubmittedVersions(int $currentVersion, int $submittedVersion, string $message): void
    {
        if ($currentVersion !== $submittedVersion) {
            throw new ConflictHttpException($message);
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordLifecycleEvent(
        ThriftPlan $plan,
        PlanOperationAttempt $attempt,
        string $eventType,
        ?ThriftPlanStatus $fromStatus,
        ?ThriftPlanStatus $toStatus,
        ?User $actor,
        ?int $assignmentVersion,
        ?string $reason,
        ?string $customerExplanation,
        ?array $payload,
    ): PlanLifecycleEvent {
        return PlanLifecycleEvent::create([
            'thrift_plan_id' => $plan->id,
            'plan_operation_attempt_id' => $attempt->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
            'actor_user_id' => $actor?->id,
            'assignment_version' => $assignmentVersion,
            'plan_version' => $plan->version,
            'reason' => $reason,
            'customer_explanation' => $customerExplanation,
            'payload' => $payload,
            'effective_at' => now(),
        ]);
    }

    private function createNotificationIntents(
        CustomerProfile $customer,
        ThriftPlan $plan,
        PlanLifecycleEvent $event,
        ?int $currentAgentUserId,
    ): void {
        $customerUser = $customer->user()->first();
        if ($customerUser !== null) {
            $message = $this->notificationMessage($event);
            $this->createNotificationIntent(
                event: $event,
                plan: $plan,
                customer: $customer,
                recipient: $customerUser,
                audienceType: 'subject_customer',
                channel: 'database',
                message: $message,
            );

            if ($customerUser->account_state === AccountState::Active && $customerUser->email_verified_at !== null) {
                $this->createNotificationIntent(
                    event: $event,
                    plan: $plan,
                    customer: $customer,
                    recipient: $customerUser,
                    audienceType: 'subject_customer',
                    channel: 'mail',
                    message: $message,
                );
            }
        }

        if ($currentAgentUserId !== null) {
            $agentUser = User::query()->find($currentAgentUserId);
            if ($agentUser?->user_type === UserType::Agent && $agentUser->account_state === AccountState::Active) {
                $this->createNotificationIntent(
                    event: $event,
                    plan: $plan,
                    customer: $customer,
                    recipient: $agentUser,
                    audienceType: 'current_agent',
                    channel: 'database',
                    message: $this->notificationMessage($event),
                );
            }
        }
    }

    private function createNotificationIntent(
        PlanLifecycleEvent $event,
        ThriftPlan $plan,
        CustomerProfile $customer,
        User $recipient,
        string $audienceType,
        string $channel,
        string $message,
    ): void {
        $intent = PlanNotificationIntent::create([
            'notification_id' => (string) Str::uuid(),
            'plan_lifecycle_event_id' => $event->id,
            'thrift_plan_id' => $plan->id,
            'customer_profile_id' => $customer->id,
            'recipient_user_id' => $recipient->id,
            'audience_type' => $audienceType,
            'channel' => $channel,
            'purpose' => 'plan_lifecycle_changed',
            'payload' => [
                'title' => $audienceType === 'subject_customer' ? 'Your thrift plan was updated' : 'Assigned thrift plan updated',
                'message' => $message,
                'plan_id' => $plan->plan_id,
                'status' => $plan->status->displayName(),
                'url' => route('plans.show', $plan->plan_id),
            ],
            'status' => 'pending',
        ]);

        if ($channel === 'database') {
            app(NotificationPipeline::class)->capture('plan', $intent->id, false);
        }

        DB::afterCommit(static function () use ($intent): void {
            if ($intent->channel === 'database') {
                app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverPlanNotificationIntent::dispatch($intent->id)->afterCommit());
            } else {
                DeliverPlanNotificationIntent::dispatch($intent->id)->afterCommit();
            }
        });
    }

    private function notificationMessage(PlanLifecycleEvent $event): string
    {
        return match ($event->event_type) {
            'created' => 'A daily thrift plan has been created for your account.',
            'renewed' => 'A new daily thrift plan has been created from a cancelled plan.',
            'terms_amended' => 'The terms of your daily thrift plan have been amended.',
            'details_corrected' => 'The name or Customer-visible notes on your daily thrift plan have been updated.',
            'pause' => 'Your daily thrift plan has been paused.',
            'resume' => 'Your daily thrift plan has resumed.',
            'cancel' => 'Your unused daily thrift plan has been cancelled.',
            default => 'Your daily thrift plan has been updated.',
        };
    }
}
