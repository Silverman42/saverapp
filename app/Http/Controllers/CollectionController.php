<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Http\Requests\PreviewCollectionRequest;
use App\Http\Requests\StoreCollectionRequest;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionAllocation;
use App\Models\CollectionAnnotation;
use App\Models\CollectionReceipt;
use App\Models\ContributionSlot;
use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use App\Services\AuthorizationService;
use App\Services\BusinessSettings;
use App\Services\CollectionMethodCatalogue;
use App\Services\CollectionReadService;
use App\Services\CollectionReceivedTime;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\ResourceScopeService;
use App\Support\PlatformBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CollectionController extends Controller
{
    public function index(Request $request, ResourceScopeService $scope, CollectionWorkspaceService $workspace): Response
    {
        $business = BusinessProfile::current();
        $today = CarbonImmutable::now($business->timezone)->toDateString();
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,paid,partial,pending,missed,advance-covered,blocked,unavailable,service-interrupted'],
        ]);
        $date = $filters['date'] ?? $today;
        if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
            || CarbonImmutable::createFromFormat('!Y-m-d', $date, $business->timezone)?->toDateString() !== $date) {
            abort(422, 'Choose a valid business date.');
        }
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? 'all';
        $receipts = CollectionReceipt::query()
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
            ->with(['customerProfile.user', 'customerProfile.currentAssignment', 'plan'])
            ->where('received_date', $date)
            ->orderByDesc('recorded_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $receipts->setCollection($receipts->getCollection()->map(fn (CollectionReceipt $receipt): array => [
            'id' => $receipt->receipt_reference,
            'customer_id' => $receipt->customerProfile->customer_id,
            'customer_name' => $receipt->customerProfile->user?->name,
            'can_record' => $receipt->customerProfile->operational_status->value === 'active'
                && Gate::forUser($request->user())->allows('recordCollection', $receipt->customerProfile),
            'plan_id' => $receipt->plan?->plan_id,
            'received_date' => $receipt->received_date,
            'method' => $receipt->method_label,
            'recorded_at' => $receipt->recorded_at->toIso8601String(),
            'savings_kobo' => $receipt->savings_amount_kobo,
            'fees_kobo' => $receipt->fee_amount_kobo,
            'tender_kobo' => $receipt->tender_amount_kobo,
        ]));
        $due = $workspace->dueWork($request->user(), $date, $today, $search, $status);

        return Inertia::render('collections/Index', [
            'receipts' => $receipts,
            'date' => $date,
            'timezone' => $business->timezone,
            'totals' => $workspace->received($request->user(), $date),
            'due_totals' => $due['totals'],
            'due_slots' => $due['slots'],
            'filters' => ['search' => $search, 'status' => $status],
            'viewer_type' => $request->user()->user_type->value,
            'can_review_evidence' => app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage),
        ]);
    }

    public function create(string $customer, Request $request, ResourceScopeService $scope): Response
    {
        $profile = $this->customer($scope, $request, $customer);
        Gate::authorize('recordCollection', $profile);
        $plans = ThriftPlan::query()->where('customer_profile_id', $profile->id)
            ->where('status', 'active')->get();
        $obligations = $profile->feeObligations()->with('entries')->get()
            ->filter(fn ($fee): bool => $fee->outstandingAmountKobo() > 0)
            ->map(fn ($fee): array => [
                'id' => $fee->id, 'description' => $fee->customer_description,
                'outstanding_kobo' => $fee->outstandingAmountKobo(),
            ])->values();

        return Inertia::render('collections/Create', [
            'customer' => ['id' => $profile->customer_id, 'resource_id' => $profile->id, 'name' => $profile->user?->name,
                'version' => $profile->version, 'assignment_version' => $profile->currentAssignment?->version],
            'collection_methods' => app(CollectionMethodCatalogue::class)->available(),
            'cash_enabled' => app(BusinessSettings::class)->featureEnabled('collection_cash'),
            'initial_evidence' => $request->validate(['evidence' => ['nullable', 'uuid']])['evidence'] ?? null,
            'plans' => $plans->map(fn (ThriftPlan $plan): array => [
                'id' => $plan->plan_id, 'name' => $plan->currentTermsRevision()?->name,
                'timezone' => $plan->currentTermsRevision()?->timezone,
            ]),
            'fee_obligations' => $obligations,
            'today' => CarbonImmutable::now(BusinessProfile::current()->timezone)->toDateString(),
            'business_timezone' => BusinessProfile::current()->timezone,
        ]);
    }

    public function timeOptions(string $customer, Request $request, ResourceScopeService $scope,
        CollectionReceivedTime $receivedTimes): JsonResponse
    {
        $profile = $this->customer($scope, $request, $customer);
        Gate::authorize('recordCollection', $profile);
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'max:32'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'received_local_time' => ['required', 'date_format:H:i'],
        ]);
        $plan = ThriftPlan::query()->where('customer_profile_id', $profile->id)
            ->where('plan_id', $data['plan_id'])->where('status', 'active')->firstOrFail();
        $businessTimezone = BusinessProfile::current()->timezone;
        if ($plan->currentTermsRevision()?->timezone === $businessTimezone) {
            throw ValidationException::withMessages(['plan_id' => ['This plan does not need a received time.']]);
        }

        return response()->json(['timezone' => $businessTimezone, 'options' => $receivedTimes->options(
            $data['received_date'], $data['received_local_time'], $businessTimezone,
        )]);
    }

    public function preview(string $customer, PreviewCollectionRequest $request, ResourceScopeService $scope, CollectionService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(),
            $this->customer($scope, $request, $customer), $request->validated()));
    }

    public function store(string $customer, StoreCollectionRequest $request, ResourceScopeService $scope, CollectionService $service): RedirectResponse
    {
        $receipt = $service->record($request->user(),
            $this->customer($scope, $request, $customer), $request->validated());

        return redirect()->route('collections.show', $receipt);
    }

    public function attempt(string $reference, Request $request, ResourceScopeService $scope): JsonResponse
    {
        $customerReference = $request->query('customer');
        if (! is_string($customerReference)) {
            abort(404, 'Record unavailable.');
        }
        $customer = $this->customer($scope, $request, $customerReference);
        $receipt = CollectionReceipt::query()->where('attempt_reference', $reference)->first();
        if ($receipt === null) {
            return response()->json(['status' => 'unresolved'], 404);
        }
        if ($receipt->recorded_by_user_id !== $request->user()->id || $receipt->customer_profile_id !== $customer->id) {
            abort(404, 'Record unavailable.');
        }

        return response()->json(['status' => 'posted', 'receipt_reference' => $receipt->receipt_reference]);
    }

    public function show(CollectionReceipt $receipt, Request $request, ResourceScopeService $scope, CollectionReadService $read): Response|HttpResponse
    {
        if (! $scope->forCustomers($request->user())->whereKey($receipt->customer_profile_id)->exists()) {
            abort(404, 'Record unavailable.');
        }
        $receipt->load(['customerProfile.user', 'plan', 'allocations.slot']);
        try {
            $position = $read->position($receipt->customerProfile);
        } catch (ServiceUnavailableHttpException) {
            return (new PlatformBlocked('collection_read_unavailable'))->response($request);
        }

        return Inertia::render('collections/Show', [
            'receipt' => [
                'id' => $receipt->receipt_reference, 'customer_id' => $receipt->customerProfile->customer_id,
                'customer_name' => $receipt->customerProfile->user?->name,
                'plan_id' => $receipt->plan?->plan_id, 'received_date' => $receipt->received_date,
                'received_at_utc' => $receipt->received_at_utc?->toIso8601String(),
                'recorded_at' => $receipt->recorded_at->toIso8601String(),
                'timezone' => $receipt->timezone, 'method' => $receipt->method_label,
                'method_reference' => $receipt->method_reference,
                'tender_kobo' => $receipt->tender_amount_kobo, 'savings_kobo' => $receipt->savings_amount_kobo,
                'fees_kobo' => $receipt->fee_amount_kobo,
                'allocations' => $receipt->allocations->map(fn ($allocation): array => [
                    'date' => $allocation->slot->due_date, 'amount_kobo' => $allocation->amount_kobo,
                    'is_advance' => $allocation->is_advance,
                ]),
                'position' => $position,
            ],
        ]);
    }

    public function card(ThriftPlan $plan, Request $request, ResourceScopeService $scope, CollectionReadService $read): Response|HttpResponse
    {
        if (! $scope->forCustomers($request->user())->whereKey($plan->customer_profile_id)->exists()) {
            abort(404, 'Record unavailable.');
        }

        try {
            $card = $read->card($plan);
        } catch (ServiceUnavailableHttpException) {
            return (new PlatformBlocked('collection_read_unavailable'))->response($request);
        }

        return Inertia::render('collections/Card', [
            'card' => $card,
            'can_record' => $plan->status->value === 'active'
                && $plan->customerProfile->operational_status->value === 'active'
                && Gate::forUser($request->user())->allows('recordCollection', $plan->customerProfile),
            'customer_id' => $plan->customerProfile->customer_id,
        ]);
    }

    public function annotate(ThriftPlan $plan, ContributionSlot $slot, Request $request,
        ResourceScopeService $scope): RedirectResponse
    {
        if (! $scope->forCustomers($request->user())->whereKey($plan->customer_profile_id)->exists()) {
            abort(404, 'Record unavailable.');
        }
        Gate::authorize('recordCollection', $plan->customerProfile);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'kind' => ['required', 'in:missed,skipped,clear'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        DB::transaction(function () use ($plan, $slot, $request, $data): void {
            $current = ContributionSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();
            if ($current->thrift_plan_id !== $plan->id || $current->active_ordinal === null) {
                throw new ConflictHttpException('Slot is unavailable for annotation.');
            }
            if (CollectionAllocation::query()->where('contribution_slot_id', $current->id)->exists()) {
                throw new ConflictHttpException('A funded slot cannot receive an attendance note.');
            }
            $revision = $plan->currentTermsRevision();
            if ($revision === null) {
                throw new ConflictHttpException('Plan terms are unavailable.');
            }
            $today = CarbonImmutable::now($revision->timezone)->toDateString();
            if ($data['kind'] !== 'clear' && $current->due_date > $today) {
                throw new ConflictHttpException('A future slot cannot be marked missed or skipped.');
            }
            $latest = CollectionAnnotation::query()->where('contribution_slot_id', $current->id)
                ->orderByDesc('version')->first();
            if (($latest === null ? 0 : $latest->version) !== (int) $data['version']) {
                throw new ConflictHttpException('Slot annotation changed. Reload the card.');
            }
            $annotation = CollectionAnnotation::create([
                'contribution_slot_id' => $current->id, 'actor_user_id' => $request->user()->id,
                'version' => (int) $data['version'] + 1, 'kind' => $data['kind'],
                'reason' => trim($data['reason']),
            ]);
            AuditEvent::record('collection.slot_annotated', CollectionAnnotation::class, $annotation->id,
                (string) $annotation->id, ['kind' => $annotation->kind, 'slot_id' => $current->id,
                    'version' => $annotation->version, 'plan_id' => $plan->plan_id,
                    'customer_profile_id' => $plan->customer_profile_id, 'reason' => $annotation->reason], $request->user(),
                context: ['executor' => self::class, 'source_version' => $annotation->version,
                    'correlation_reference' => 'annotation:'.$annotation->id]
            );
        }, attempts: 3);

        return redirect()->route('plans.card', $plan);
    }

    private function customer(ResourceScopeService $scope, Request $request, string $reference): CustomerProfile
    {
        $profile = $scope->forCustomers($request->user())->where('customer_id', $reference)
            ->with(['user', 'currentAssignment'])->first();
        if ($profile === null) {
            abort(404, 'Record unavailable.');
        }

        return $profile;
    }
}
