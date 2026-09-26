<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
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
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\ResourceScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionController extends Controller
{
    public function index(Request $request, ResourceScopeService $scope): Response
    {
        $business = BusinessProfile::current();
        $today = CarbonImmutable::now($business->timezone)->toDateString();
        $date = $request->query('date', $today);
        if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
            || CarbonImmutable::createFromFormat('!Y-m-d', $date, $business->timezone)?->toDateString() !== $date) {
            abort(422, 'Choose a valid business date.');
        }
        $receipts = CollectionReceipt::query()
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
            ->with(['customerProfile.user', 'plan'])
            ->where('received_date', $date)
            ->orderByDesc('recorded_at')->orderByDesc('id')->paginate(25);
        $receipts->setCollection($receipts->getCollection()->map(fn (CollectionReceipt $receipt): array => [
            'id' => $receipt->receipt_reference,
            'customer_id' => $receipt->customerProfile->customer_id,
            'customer_name' => $receipt->customerProfile->user?->name,
            'plan_id' => $receipt->plan?->plan_id,
            'received_date' => $receipt->received_date,
            'recorded_at' => $receipt->recorded_at->toIso8601String(),
            'savings_kobo' => $receipt->savings_amount_kobo,
            'fees_kobo' => $receipt->fee_amount_kobo,
            'tender_kobo' => $receipt->tender_amount_kobo,
        ]));
        $dueSlots = null;
        if ($request->user()->user_type === UserType::Agent) {
            $slots = ContributionSlot::query()->where('due_date', $date)->whereNotNull('active_ordinal')
                ->whereIn('thrift_plan_id', ThriftPlan::query()
                    ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))->select('id'))
                ->with(['plan.customerProfile.user'])->orderBy('id')->paginate(25, ['*'], 'due_page');
            $funding = DB::table('collection_allocations')
                ->join('collection_receipts', 'collection_receipts.id', '=', 'collection_allocations.collection_receipt_id')
                ->whereIn('collection_allocations.contribution_slot_id', $slots->getCollection()->pluck('id'))
                ->selectRaw('collection_allocations.contribution_slot_id, SUM(collection_allocations.amount_kobo) as funded_kobo, SUM(CASE WHEN collection_receipts.received_date < ? THEN collection_allocations.amount_kobo ELSE 0 END) as advance_kobo', [$date])
                ->groupBy('collection_allocations.contribution_slot_id')->get()->keyBy('contribution_slot_id');
            $slots->setCollection($slots->getCollection()->map(fn (ContributionSlot $slot): array => [
                'customer_id' => $slot->plan->customerProfile->customer_id,
                'customer_name' => $slot->plan->customerProfile->user?->name,
                'plan_id' => $slot->plan->plan_id, 'ordinal' => $slot->active_ordinal,
                'target_kobo' => $slot->expected_amount_kobo,
                'funded_kobo' => (int) ($funding[$slot->id]->funded_kobo ?? 0),
                'advance_kobo' => (int) ($funding[$slot->id]->advance_kobo ?? 0),
                'blocked' => $slot->plan->status->value !== 'active',
            ]));
            $dueSlots = $slots;
        }

        return Inertia::render('collections/Index', [
            'receipts' => $receipts,
            'date' => $date,
            'timezone' => $business->timezone,
            'totals' => [
                'tender_kobo' => (int) CollectionReceipt::query()->where('received_date', $date)
                    ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
                    ->sum('tender_amount_kobo'),
                'receipt_count' => CollectionReceipt::query()->where('received_date', $date)
                    ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
                    ->count(),
            ],
            'assigned_customers' => $request->user()->user_type === UserType::Agent
                ? $scope->forCustomers($request->user())->with('user')->orderBy('customer_id')->limit(100)
                    ->get()->map(fn (CustomerProfile $customer): array => [
                        'id' => $customer->customer_id, 'name' => $customer->user?->name,
                    ]) : [],
            'due_slots' => $dueSlots,
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
            'customer' => ['id' => $profile->customer_id, 'name' => $profile->user?->name],
            'plans' => $plans->map(fn (ThriftPlan $plan): array => [
                'id' => $plan->plan_id, 'name' => $plan->currentTermsRevision()?->name,
            ]),
            'fee_obligations' => $obligations,
            'today' => CarbonImmutable::now(BusinessProfile::current()->timezone)->toDateString(),
        ]);
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
        $receipt = CollectionReceipt::query()->where('attempt_reference', $reference)->first();
        if ($receipt === null) {
            return response()->json(['status' => 'unresolved'], 404);
        }
        if ($receipt->recorded_by_user_id !== $request->user()->id
            || ! $scope->forCustomers($request->user())->whereKey($receipt->customer_profile_id)->exists()) {
            abort(404, 'Record unavailable.');
        }

        return response()->json(['status' => 'posted', 'receipt_reference' => $receipt->receipt_reference]);
    }

    public function show(CollectionReceipt $receipt, Request $request, ResourceScopeService $scope, CollectionReadService $read): Response
    {
        if (! $scope->forCustomers($request->user())->whereKey($receipt->customer_profile_id)->exists()) {
            abort(404, 'Record unavailable.');
        }
        $receipt->load(['customerProfile.user', 'plan', 'allocations.slot']);

        return Inertia::render('collections/Show', [
            'receipt' => [
                'id' => $receipt->receipt_reference, 'customer_id' => $receipt->customerProfile->customer_id,
                'customer_name' => $receipt->customerProfile->user?->name,
                'plan_id' => $receipt->plan?->plan_id, 'received_date' => $receipt->received_date,
                'recorded_at' => $receipt->recorded_at->toIso8601String(),
                'timezone' => $receipt->timezone, 'method' => 'Cash',
                'tender_kobo' => $receipt->tender_amount_kobo, 'savings_kobo' => $receipt->savings_amount_kobo,
                'fees_kobo' => $receipt->fee_amount_kobo,
                'allocations' => $receipt->allocations->map(fn ($allocation): array => [
                    'date' => $allocation->slot->due_date, 'amount_kobo' => $allocation->amount_kobo,
                    'is_advance' => $allocation->is_advance,
                ]),
                'position' => $read->position($receipt->customerProfile),
            ],
        ]);
    }

    public function card(ThriftPlan $plan, Request $request, ResourceScopeService $scope, CollectionReadService $read): Response
    {
        if (! $scope->forCustomers($request->user())->whereKey($plan->customer_profile_id)->exists()) {
            abort(404, 'Record unavailable.');
        }

        return Inertia::render('collections/Card', [
            'card' => $read->card($plan),
            'can_record' => Gate::forUser($request->user())->allows('recordCollection', $plan->customerProfile),
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
                (string) $annotation->id, ['kind' => $annotation->kind, 'slot_id' => $current->id], $request->user(),
                context: ['executor' => self::class]
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
