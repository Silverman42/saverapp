<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Requests\PreviewWithdrawalRequest;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Models\CustomerProfile;
use App\Models\WithdrawalAttempt;
use App\Models\WithdrawalRequest;
use App\Services\AuthorizationService;
use App\Services\ResourceScopeService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class WithdrawalController extends Controller
{
    public function index(Request $request, ResourceScopeService $scope, AuthorizationService $authorization): Response
    {
        $query = WithdrawalRequest::query()
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
            ->with(['customerProfile.user', 'plan']);
        if (is_string($request->query('state')) && $request->query('state') !== '') {
            $query->where('state', $request->query('state'));
        }
        if ($request->user()->user_type === UserType::Admin) {
            $query->orderByRaw("CASE WHEN state IN ('pending_review', 'approved', 'payment_failed') OR held = 1 THEN 0 ELSE 1 END")
                ->orderBy('deadline_at')->orderBy('id');
        } else {
            $query->orderByDesc('submitted_at')->orderByDesc('id');
        }
        $requests = $query->paginate(25);
        $requests->setCollection($requests->getCollection()->map(fn (WithdrawalRequest $withdrawal): array => $this->summary($withdrawal)));

        return Inertia::render('withdrawals/Index', [
            'requests' => $requests, 'state_filter' => $request->query('state', ''),
            'can_review' => $authorization->allows($request->user(), AdminPermission::WithdrawalsReview),
            'role' => $request->user()->user_type->value,
        ]);
    }

    public function create(string $customer, Request $request, WithdrawalMethodRegistry $methods): Response
    {
        $profile = CustomerProfile::query()->where('customer_id', $customer)->firstOrFail();
        Gate::authorize('initiateWithdrawal', $profile);

        return Inertia::render('withdrawals/Create', [
            'customer' => ['id' => $profile->customer_id, 'name' => $profile->user?->name],
            'plans' => $profile->thriftPlans()->whereIn('status', ['active', 'paused', 'completed'])
                ->get()->map(fn ($plan): array => ['id' => $plan->plan_id, 'status' => $plan->status->value]),
            'method_available' => $methods->available(),
        ]);
    }

    public function preview(string $customer, PreviewWithdrawalRequest $request, WithdrawalService $service): JsonResponse
    {
        $profile = CustomerProfile::query()->where('customer_id', $customer)->firstOrFail();

        return response()->json($service->preview($request->user(), $profile, $request->validated()));
    }

    public function store(string $customer, StoreWithdrawalRequest $request, WithdrawalService $service): RedirectResponse
    {
        $profile = CustomerProfile::query()->where('customer_id', $customer)->firstOrFail();
        $withdrawal = $service->submit($request->user(), $profile, $request->validated());

        return redirect()->route('withdrawals.show', $withdrawal);
    }

    public function attempt(string $reference, Request $request): JsonResponse
    {
        $attempt = WithdrawalAttempt::query()->where('attempt_reference', $reference)->where('operation', 'submit')->firstOrFail();
        $withdrawal = WithdrawalRequest::query()->findOrFail($attempt->withdrawal_request_id);
        Gate::authorize('view', $withdrawal->customerProfile);

        return response()->json(['withdrawal_id' => $withdrawal->withdrawal_id, 'state' => $withdrawal->state]);
    }

    public function show(WithdrawalRequest $withdrawal, Request $request, AuthorizationService $authorization, WithdrawalBalanceService $balances): Response
    {
        Gate::authorize('view', $withdrawal->customerProfile);
        $customer = $withdrawal->customerProfile;
        try {
            $position = $balances->position($customer, $withdrawal->plan);
        } catch (RuntimeException $exception) {
            $position = null;
        }

        return Inertia::render('withdrawals/Show', [
            'withdrawal' => [
                ...$this->summary($withdrawal), 'reason' => $withdrawal->reason,
                'customer_explanation' => $withdrawal->events()->whereNotNull('customer_explanation')
                    ->orderByDesc('id')->value('customer_explanation'),
                'internal_notes' => $request->user()->user_type === UserType::Customer ? null : $withdrawal->internal_notes,
                'version' => $withdrawal->version, 'destination_mask' => $withdrawal->destination_mask,
                'deadline_at' => $withdrawal->deadline_at->toIso8601String(),
            ],
            'can_review' => $authorization->allows($request->user(), AdminPermission::WithdrawalsReview),
            'can_cancel' => $request->user()->user_type === UserType::Agent
                && Gate::forUser($request->user())->allows('managePlan', $customer)
                && $withdrawal->state === 'pending_review',
            'position' => $position,
        ]);
    }

    public function approve(WithdrawalRequest $withdrawal, Request $request, WithdrawalService $service): RedirectResponse
    {
        return $this->decide($withdrawal, $request, $service, 'approve');
    }

    public function reject(WithdrawalRequest $withdrawal, Request $request, WithdrawalService $service): RedirectResponse
    {
        return $this->decide($withdrawal, $request, $service, 'reject');
    }

    public function cancel(WithdrawalRequest $withdrawal, Request $request, WithdrawalService $service): RedirectResponse
    {
        return $this->decide($withdrawal, $request, $service, 'cancel');
    }

    public function revoke(WithdrawalRequest $withdrawal, Request $request, WithdrawalService $service): RedirectResponse
    {
        return $this->decide($withdrawal, $request, $service, 'revoke');
    }

    private function decide(WithdrawalRequest $withdrawal, Request $request, WithdrawalService $service, string $action): RedirectResponse
    {
        $unexpected = array_diff(array_keys($request->except('_token')), [
            'attempt_reference', 'version', 'confirmed', 'decision_note', 'internal_reason', 'customer_explanation',
        ]);
        if ($unexpected !== []) {
            throw ValidationException::withMessages([array_values($unexpected)[0] => ['Unknown withdrawal decision field.']]);
        }
        $data = $request->validate([
            'attempt_reference' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
            'decision_note' => [$action === 'approve' ? 'required' : 'nullable', 'string', 'min:1', 'max:500'],
            'internal_reason' => [in_array($action, ['reject', 'revoke', 'cancel'], true) ? 'required' : 'nullable', 'string', 'min:1', 'max:500'],
            'customer_explanation' => [in_array($action, ['reject', 'revoke'], true) ? 'required' : 'nullable', 'string', 'min:1', 'max:500'],
        ]);
        $service->decide($request->user(), $withdrawal, $action, $data, $request);

        return redirect()->route('withdrawals.show', $withdrawal);
    }

    /** @return array<string, mixed> */
    private function summary(WithdrawalRequest $withdrawal): array
    {
        return [
            'id' => $withdrawal->withdrawal_id, 'customer_id' => $withdrawal->customerProfile->customer_id,
            'customer_name' => $withdrawal->customerProfile->user?->name,
            'plan_id' => $withdrawal->plan->plan_id, 'type' => $withdrawal->type,
            'gross_kobo' => $withdrawal->gross_amount_kobo, 'fee_kobo' => $withdrawal->fee_amount_kobo,
            'net_kobo' => $withdrawal->net_amount_kobo, 'method' => $withdrawal->method,
            'state' => $withdrawal->state, 'held' => $withdrawal->held,
            'submitted_at' => $withdrawal->submitted_at->toIso8601String(),
        ];
    }
}
