<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmPlanSettlementRequest;
use App\Http\Requests\PreviewCollectionRequest;
use App\Http\Requests\StoreReplacementReceiptRequest;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Services\CollectionReplacementService;
use App\Services\PlanSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FinancialWorkflowController extends Controller
{
    public function replacement(ReversalRequest $reversal, Request $request): Response
    {
        $customer = CustomerProfile::query()->findOrFail($reversal->customer_profile_id);
        Gate::authorize('recordCollection', $customer);
        abort_unless(config('collections.receipt_corrections_enabled', false), 503);
        $dependencies = $reversal->getAttribute('dependency_snapshot');
        $source = CollectionReceipt::query()->whereKey(is_array($dependencies) ? ($dependencies['summary']['receipt_id'] ?? null) : null)->firstOrFail();
        $controlled = app(CollectionReplacementService::class)->controlledAmount($reversal, $source);
        app(CollectionReplacementService::class)->lockSource($reversal, $customer, $controlled, false);

        return Inertia::render('collections/Create', ['customer' => ['id' => $customer->customer_id, 'name' => $customer->user->name],
            'plans' => ThriftPlan::query()->where('customer_profile_id', $customer->id)->where('status', 'active')->with('termsRevisions.feeSnapshot')->get()->map(fn ($plan): array => ['id' => $plan->plan_id, 'name' => $plan->currentTermsRevision()->name, 'timezone' => $plan->currentTermsRevision()->timezone]),
            'fee_obligations' => FeeObligation::query()->where('customer_profile_id', $customer->id)->get()->filter(fn ($fee): bool => $fee->outstandingAmountKobo() > 0)->map(fn ($fee): array => ['id' => $fee->id, 'description' => $fee->customer_description, 'outstanding_kobo' => $fee->outstandingAmountKobo()])->values(),
            'today' => now(BusinessProfile::current()->timezone)->toDateString(), 'business_timezone' => BusinessProfile::current()->timezone,
            'replacement_reversal' => $reversal->reversal_id, 'replacement_controlled_kobo' => $controlled]);
    }

    public function replacementPreview(ReversalRequest $reversal, PreviewCollectionRequest $request, CollectionReplacementService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $reversal, $request->validated()));
    }

    public function replacementStore(ReversalRequest $reversal, StoreReplacementReceiptRequest $request, CollectionReplacementService $service): RedirectResponse
    {
        $receipt = $service->record($request->user(), $reversal, $request->validated());

        return to_route('collections.show', $receipt);
    }

    public function settlement(ThriftPlan $plan, Request $request, PlanSettlementService $service): Response
    {
        $action = $request->validate(['action' => ['sometimes', 'in:close,prepare_termination,resolve_exception']])['action'] ?? 'close';

        return Inertia::render('plans/Settlement', ['plan' => ['id' => $plan->plan_id, 'status' => $plan->status->value],
            'preview' => $service->preview($request->user(), $plan, $action), 'attempt_reference' => (string) Str::uuid()]);
    }

    public function settlementConfirm(ThriftPlan $plan, string $action, ConfirmPlanSettlementRequest $request, PlanSettlementService $service): RedirectResponse
    {
        $service->confirm($request->user(), $plan, $action, $request->validated());

        return to_route('plans.show', $plan);
    }
}
