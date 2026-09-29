<?php

namespace App\Http\Controllers;

use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Http\Requests\PlanTransitionRequest;
use App\Http\Requests\StoreThriftPlanRequest;
use App\Http\Requests\UpdateThriftPlanRequest;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\PlanOperationAttempt;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\RegistrationFeeService;
use App\Services\ResourceScopeService;
use App\Services\ThriftPlanService;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ThriftPlanController extends Controller
{
    public function index(Request $request, ResourceScopeService $scopeService): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        if ($viewer->user_type !== UserType::Customer) {
            Gate::authorize('viewAny', CustomerProfile::class);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,paused,completed,closed,cancelled,all'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $query = ThriftPlan::query()
            ->whereIn('customer_profile_id', $scopeService->forCustomers($viewer)->select('customer_profiles.id'))
            ->with(['customerProfile.user', 'customerProfile.currentAssignment', 'termsRevisions.feeSnapshot'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $status = $filters['status'] ?? null;
        if (filled($status) && $status !== 'all') {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('plan_id', 'like', "%{$search}%")
                    ->orWhereHas('customerProfile', function (Builder $customerQuery) use ($search): void {
                        $customerQuery->where('customer_id', 'like', "%{$search}%")
                            ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
                    })
                    ->orWhereHas('termsRevisions', fn (Builder $revisionQuery) => $revisionQuery->where('name', 'like', "%{$search}%"));
            });
        }

        $plans = $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $plans->through(function (ThriftPlan $plan) use ($viewer): array {
            $revision = $this->currentRevision($plan);

            return [
                'id' => $plan->plan_id,
                'name' => $revision?->name ?? 'Daily thrift plan',
                'customer' => [
                    'id' => $plan->customerProfile->customer_id,
                    'name' => $plan->customerProfile->user?->name ?? 'Customer',
                ],
                'status' => $plan->status->value,
                'status_label' => $plan->status->displayName(),
                'terms_revision' => $plan->current_terms_revision,
                'contribution_amount' => $revision === null ? null : MoneyFormatter::formatNaira($revision->contribution_amount_kobo),
                'start_date' => $revision?->start_date,
                'scheduled_end_date' => $revision === null ? null : $this->scheduledEndDate($revision),
                'financials' => [
                    'status' => 'unavailable',
                    'message' => 'Collections and savings totals are unavailable until the financial modules are connected.',
                ],
                'can_manage' => Gate::forUser($viewer)->allows('managePlan', $plan->customerProfile),
                'created_at' => $plan->created_at?->toDateString(),
            ];
        });

        return Inertia::render('plans/Index', [
            'plans' => $plans,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'viewer_type' => $viewer->user_type->value,
        ]);
    }

    public function create(
        Request $request,
        string $customer,
        ResourceScopeService $scopeService,
        RegistrationFeeService $feeService,
        ThriftPlanService $planService,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();
        $customerProfile = $scopeService->forCustomers($actor)
            ->where('customer_id', $customer)
            ->with(['user', 'currentAssignment'])
            ->first();
        if ($customerProfile === null) {
            abort(404, 'Record unavailable.');
        }
        Gate::authorize('managePlan', $customerProfile);

        $business = BusinessProfile::current();
        $feeOptions = $feeService->getCurrentPlanOptions();
        $predecessorId = $request->validate(['predecessor_plan_id' => ['nullable', 'string', 'max:32']])['predecessor_plan_id'] ?? null;
        $today = CarbonImmutable::now($business->timezone)->toDateString();
        $form = [
            'name' => (string) $request->query('name', $predecessorId ? 'Renewed daily thrift plan' : 'Daily thrift plan'),
            'amount_ngn' => (string) $request->query('amount_ngn', ''),
            'start_date' => (string) $request->query('start_date', $today),
            'contribution_days' => (string) $request->query('contribution_days', '30'),
            'customer_visible_notes' => (string) $request->query('customer_visible_notes', ''),
            'fee_rule_id' => (string) $request->query('fee_rule_id', $feeOptions->first()?->id ?? ''),
        ];

        $preview = null;
        if ($request->boolean('preview')) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'min:1', 'max:100'],
                'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/'],
                'start_date' => ['required', 'date_format:Y-m-d'],
                'contribution_days' => ['required', 'integer', 'between:1,366'],
                'customer_visible_notes' => ['nullable', 'string', 'max:2000'],
                'fee_rule_id' => ['required', 'integer', 'min:1'],
                'predecessor_plan_id' => ['nullable', 'string', 'max:32'],
            ]);
            $preview = $planService->preview($actor, $customerProfile, $validated);
            $form = [
                ...$form,
                'name' => $validated['name'],
                'amount_ngn' => $validated['amount_ngn'],
                'start_date' => $validated['start_date'],
                'contribution_days' => (string) $validated['contribution_days'],
                'customer_visible_notes' => $validated['customer_visible_notes'] ?? '',
                'fee_rule_id' => (string) $validated['fee_rule_id'],
            ];
        }

        return Inertia::render('plans/Create', [
            'customer' => [
                'id' => $customerProfile->customer_id,
                'name' => $customerProfile->user?->name ?? 'Customer',
                'status' => $customerProfile->operational_status->value,
                'version' => $customerProfile->version,
                'assignment_version' => $customerProfile->currentAssignment?->version,
            ],
            'business' => ['timezone' => $business->timezone, 'version' => $business->version],
            'fee_options' => $feeOptions->map(fn ($rule): array => [
                'id' => $rule->id,
                'version' => $rule->version,
                'name' => $rule->name,
                'formatted_amount' => $rule->formattedAmount(),
                'model' => $rule->model->value,
                'timing' => $rule->timing->value,
                'customer_description' => $rule->customer_description,
            ])->values(),
            'form' => $form,
            'preview' => $preview,
            'attempt_reference' => (string) Str::uuid(),
            'predecessor_plan_id' => $predecessorId,
        ]);
    }

    public function store(
        StoreThriftPlanRequest $request,
        string $customer,
        ResourceScopeService $scopeService,
        ThriftPlanService $planService,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $customerProfile = $scopeService->forCustomers($actor)->where('customer_id', $customer)->first();
        if ($customerProfile === null) {
            abort(404, 'Record unavailable.');
        }
        Gate::authorize('managePlan', $customerProfile);

        $data = $request->validated();
        $result = $planService->create($actor, $customerProfile, $data['attempt_reference'], $data);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['replayed'] ? 'Existing plan creation resolved.' : 'Daily thrift plan created.',
        ]);

        return to_route('plans.show', $result['plan']->plan_id);
    }

    public function show(Request $request, string $plan, ResourceScopeService $scopeService): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $record = $this->scopedPlan($scopeService, $viewer, $plan);
        Gate::authorize('view', $record->customerProfile);
        $revision = $this->currentRevision($record);
        $canManage = Gate::forUser($viewer)->allows('managePlan', $record->customerProfile);
        $hasFeeObligation = $record->termsRevisions->contains(fn ($item): bool => $item->feeSnapshot?->obligation !== null);
        $canCancel = $canManage
            && in_array($record->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true)
            && $record->activity_started_at === null
            && ! $hasFeeObligation;
        $hasSuccessor = $record->successor()->exists();

        return Inertia::render('plans/Show', [
            'plan' => $this->serializePlan($record, $viewer),
            'customer' => [
                'id' => $record->customerProfile->customer_id,
                'name' => $record->customerProfile->user?->name ?? 'Customer',
                'status' => $record->customerProfile->operational_status->value,
                'version' => $record->customerProfile->version,
                'assignment_version' => $record->customerProfile->currentAssignment?->version,
            ],
            'actions' => [
                'can_manage' => $canManage,
                'can_edit' => $canManage && in_array($record->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true),
                'can_pause' => $canManage && $record->status === ThriftPlanStatus::Active,
                'can_resume' => $canManage && $record->status === ThriftPlanStatus::Paused
                    && $record->customerProfile->operational_status->value === 'active',
                'can_cancel' => $canCancel,
                'can_renew' => $canManage && $record->status === ThriftPlanStatus::Cancelled && ! $hasSuccessor
                    && $record->customerProfile->operational_status->value === 'active',
            ],
            'attempt_reference' => (string) Str::uuid(),
        ]);
    }

    public function edit(
        Request $request,
        string $plan,
        ResourceScopeService $scopeService,
        RegistrationFeeService $feeService,
        ThriftPlanService $planService,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();
        $record = $this->scopedPlan($scopeService, $actor, $plan);
        Gate::authorize('managePlan', $record->customerProfile);
        $revision = $this->currentRevision($record);
        if ($revision === null) {
            abort(404, 'Plan terms unavailable.');
        }

        $currentRule = $revision->feeSnapshot->feeRule;
        $options = $feeService->getCurrentPlanOptions()->map(fn ($rule): array => [
            'id' => $rule->id,
            'version' => $rule->version,
            'name' => $rule->name,
            'formatted_amount' => $rule->formattedAmount(),
            'model' => $rule->model->value,
            'timing' => $rule->timing->value,
            'customer_description' => $rule->customer_description,
        ])->values();
        if (! $options->contains(fn (array $option): bool => $option['id'] === $currentRule->id)) {
            $options->prepend([
                'id' => $currentRule->id,
                'version' => $currentRule->version,
                'name' => $currentRule->name.' (existing terms)',
                'formatted_amount' => $currentRule->formattedAmount(),
                'model' => $currentRule->model->value,
                'timing' => $currentRule->timing->value,
                'customer_description' => $currentRule->customer_description,
            ]);
        }

        $form = [
            'name' => $revision->name,
            'amount_ngn' => $this->amountInput($revision->contribution_amount_kobo),
            'start_date' => $revision->start_date,
            'contribution_days' => $revision->contribution_days,
            'customer_visible_notes' => $revision->customer_visible_notes ?? '',
            'fee_rule_id' => $currentRule->id,
            'fee_rule_version' => $currentRule->version,
            'reason' => (string) $request->query('reason', ''),
            'customer_explanation' => (string) $request->query('customer_explanation', ''),
        ];
        $preview = null;
        if ($request->boolean('preview')) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'min:1', 'max:100'],
                'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/'],
                'start_date' => ['required', 'date_format:Y-m-d'],
                'contribution_days' => ['required', 'integer', 'between:1,366'],
                'customer_visible_notes' => ['nullable', 'string', 'max:2000'],
                'fee_rule_id' => ['required', 'integer', 'min:1'],
                'reason' => ['nullable', 'string', 'max:500'],
                'customer_explanation' => ['nullable', 'string', 'max:500'],
            ]);
            $preview = $planService->previewRevision($actor, $record, $validated);
            $form = [
                ...$form,
                ...$validated,
                'contribution_days' => (int) $validated['contribution_days'],
                'fee_rule_version' => $preview['fee']['rule_version'],
            ];
        }

        return Inertia::render('plans/Edit', [
            'plan' => $this->serializePlan($record, $actor),
            'customer' => [
                'id' => $record->customerProfile->customer_id,
                'name' => $record->customerProfile->user?->name ?? 'Customer',
                'version' => $record->customerProfile->version,
                'assignment_version' => $record->customerProfile->currentAssignment?->version,
            ],
            'business' => ['version' => BusinessProfile::current()->version],
            'form' => $form,
            'preview' => $preview,
            'fee_options' => $options,
            'attempt_reference' => (string) Str::uuid(),
            'financial_terms_locked' => $record->activity_started_at !== null,
        ]);
    }

    public function update(
        UpdateThriftPlanRequest $request,
        string $plan,
        ResourceScopeService $scopeService,
        ThriftPlanService $planService,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $record = $this->scopedPlan($scopeService, $actor, $plan);
        Gate::authorize('managePlan', $record->customerProfile);
        $data = $request->validated();
        $planService->revise($actor, $record, $data['attempt_reference'], $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan terms updated.']);

        return to_route('plans.show', $record->plan_id);
    }

    public function pause(
        PlanTransitionRequest $request,
        string $plan,
        ResourceScopeService $scopeService,
        ThriftPlanService $planService,
    ): RedirectResponse {
        return $this->applyTransition($request, $plan, 'pause', $scopeService, $planService);
    }

    public function resume(
        PlanTransitionRequest $request,
        string $plan,
        ResourceScopeService $scopeService,
        ThriftPlanService $planService,
    ): RedirectResponse {
        return $this->applyTransition($request, $plan, 'resume', $scopeService, $planService);
    }

    public function cancel(
        PlanTransitionRequest $request,
        string $plan,
        ResourceScopeService $scopeService,
        ThriftPlanService $planService,
    ): RedirectResponse {
        return $this->applyTransition($request, $plan, 'cancel', $scopeService, $planService);
    }

    private function applyTransition(
        PlanTransitionRequest $request,
        string $plan,
        string $action,
        ResourceScopeService $scopeService,
        ThriftPlanService $planService,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $record = $this->scopedPlan($scopeService, $actor, $plan);
        Gate::authorize('managePlan', $record->customerProfile);
        $data = $request->validated();
        $planService->transition($actor, $record, $action, $data['attempt_reference'], $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan status updated.']);

        return to_route('plans.show', $record->plan_id);
    }

    public function showAttempt(Request $request, string $reference, ResourceScopeService $scopeService): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $attempt = PlanOperationAttempt::query()
            ->where('attempt_reference', $reference)
            ->where('user_id', $viewer->id)
            ->first();
        if ($attempt === null) {
            abort(404, 'Record unavailable.');
        }

        if ($attempt->status === 'committed') {
            $accessible = $scopeService->forCustomers($viewer)->whereKey($attempt->customer_profile_id)->exists();
            if (! $accessible) {
                abort(404, 'Record unavailable.');
            }
        }

        return response()->json([
            'status' => $attempt->status,
            'result' => $attempt->result_summary,
        ]);
    }

    private function scopedPlan(ResourceScopeService $scopeService, User $viewer, string $planId): ThriftPlan
    {
        $record = ThriftPlan::query()
            ->where('plan_id', $planId)
            ->whereIn('customer_profile_id', $scopeService->forCustomers($viewer)->select('customer_profiles.id'))
            ->with([
                'customerProfile.user',
                'customerProfile.currentAssignment',
                'termsRevisions.feeSnapshot.feeRule',
                'termsRevisions.feeSnapshot.obligation',
                'slots' => function (Relation $slotQuery): void {
                    $slotQuery->getQuery()->whereNotNull('active_ordinal')->orderBy('active_ordinal');
                },
                'lifecycleEvents.actor',
                'predecessor',
            ])
            ->first();
        if ($record === null) {
            abort(404, 'Record unavailable.');
        }

        return $record;
    }

    private function currentRevision(ThriftPlan $plan): ?PlanTermsRevision
    {
        return $plan->termsRevisions->firstWhere('revision', $plan->current_terms_revision);
    }

    private function scheduledEndDate(PlanTermsRevision $revision): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $revision->start_date, $revision->timezone)
            ->addDays($revision->contribution_days - 1)
            ->toDateString();
    }

    private function amountInput(int $amountKobo): string
    {
        return intdiv($amountKobo, 100).'.'.str_pad((string) ($amountKobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function serializePlan(ThriftPlan $plan, User $viewer): array
    {
        $currentRevision = $this->currentRevision($plan);
        $currentFeeSnapshot = $currentRevision?->feeSnapshot;
        $isCustomer = $viewer->user_type === UserType::Customer;

        return [
            'id' => $plan->plan_id,
            'status' => $plan->status->value,
            'status_label' => $plan->status->displayName(),
            'version' => $plan->version,
            'terms_revision' => $plan->current_terms_revision,
            'activity_started_at' => $plan->activity_started_at?->toIso8601String(),
            'customer' => [
                'id' => $plan->customerProfile->customer_id,
                'name' => $plan->customerProfile->user?->name ?? 'Customer',
            ],
            'predecessor' => $plan->predecessor === null ? null : [
                'id' => $plan->predecessor->plan_id,
                'status' => $plan->predecessor->status->value,
            ],
            'current_terms' => $currentRevision === null ? null : [
                'name' => $currentRevision->name,
                'contribution_amount_kobo' => $currentRevision->contribution_amount_kobo,
                'formatted_contribution_amount' => MoneyFormatter::formatNaira($currentRevision->contribution_amount_kobo),
                'currency' => $currentRevision->currency,
                'start_date' => $currentRevision->start_date,
                'contribution_days' => $currentRevision->contribution_days,
                'scheduled_end_date' => $this->scheduledEndDate($currentRevision),
                'frequency' => $currentRevision->frequency,
                'timezone' => $currentRevision->timezone,
                'expected_gross_kobo' => $currentRevision->expected_gross_kobo,
                'formatted_expected_gross' => MoneyFormatter::formatNaira($currentRevision->expected_gross_kobo),
                'customer_visible_notes' => $currentRevision->customer_visible_notes,
                'reason' => $isCustomer ? null : $currentRevision->reason,
            ],
            'fee' => $currentFeeSnapshot === null ? null : [
                'name' => $currentFeeSnapshot->name,
                'model' => $currentFeeSnapshot->model->value,
                'timing' => $currentFeeSnapshot->timing->value,
                'basis' => $currentFeeSnapshot->basis->value,
                'formatted_amount' => $currentFeeSnapshot->formattedAmount(),
                'estimated_amount' => $currentFeeSnapshot->model->value === 'percentage' && $currentFeeSnapshot->timing->value === 'withdrawal'
                    ? null
                    : MoneyFormatter::formatNaira($currentFeeSnapshot->amount_kobo),
                'estimate_available' => ! ($currentFeeSnapshot->model->value === 'percentage' && $currentFeeSnapshot->timing->value === 'withdrawal'),
                'description' => $currentFeeSnapshot->customer_description,
                'acknowledged_at' => $currentFeeSnapshot->acknowledged_at?->timezone($currentRevision->timezone)->format('Y-m-d H:i'),
            ],
            'slots' => $plan->slots->whereNotNull('active_ordinal')->sortBy('active_ordinal')->values()->map(fn ($slot): array => [
                'ordinal' => $slot->active_ordinal,
                'due_date' => $slot->due_date,
                'formatted_expected_amount' => MoneyFormatter::formatNaira($slot->expected_amount_kobo),
                'collection_status' => 'unavailable',
            ]),
            'revisions' => $plan->termsRevisions->map(fn ($revision): array => [
                'revision' => $revision->revision,
                'name' => $revision->name,
                'formatted_contribution_amount' => MoneyFormatter::formatNaira($revision->contribution_amount_kobo),
                'start_date' => $revision->start_date,
                'contribution_days' => $revision->contribution_days,
                'timezone' => $revision->timezone,
                'reason' => $isCustomer ? null : $revision->reason,
                'created_at' => $revision->created_at?->timezone($revision->timezone)->format('Y-m-d H:i'),
            ])->values(),
            'history' => $plan->lifecycleEvents->map(fn ($event): array => [
                'event' => str_replace('_', ' ', ucfirst($event->event_type)),
                'status' => $event->to_status?->displayName() ?? $event->from_status?->displayName(),
                'explanation' => $event->customer_explanation,
                'reason' => $isCustomer ? null : $event->reason,
                'actor' => $isCustomer ? null : $event->actor?->name,
                'effective_at' => $event->effective_at?->timezone($currentRevision?->timezone ?? 'Africa/Lagos')->format('Y-m-d H:i'),
            ])->values(),
            'financial_summary' => [
                'status' => 'unavailable',
                'message' => 'Actual collections, savings balance, and progress are unavailable until the financial modules are connected.',
            ],
            'created_at' => $plan->created_at?->timezone($currentRevision?->timezone ?? 'Africa/Lagos')->format('Y-m-d H:i'),
        ];
    }
}
