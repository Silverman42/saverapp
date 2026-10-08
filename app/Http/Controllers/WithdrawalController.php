<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Requests\PreviewWithdrawalRequest;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\BusinessProfile;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CustomerPayoutDestination;
use App\Models\CustomerProfile;
use App\Models\WithdrawalAttempt;
use App\Models\WithdrawalRequest;
use App\Services\AuthorizationService;
use App\Services\ResourceScopeService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use App\Support\Toast;
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
    public function index(Request $request, ResourceScopeService $scope, AuthorizationService $authorization, WithdrawalMethodRegistry $methods): Response
    {
        $query = WithdrawalRequest::query()
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
            ->with(['customerProfile.user', 'plan']);
        if ($request->query('state') === 'needs_reconciliation') {
            $query->where(fn ($query) => $query->where('state', 'outcome_unknown')->orWhereIn('id', BankPayoutAttempt::query()
                ->where('status', 'succeeded')->whereNull('ledger_posting_group_id')->select('withdrawal_request_id')));
        } elseif (is_string($request->query('state')) && $request->query('state') !== '') {
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
            'new_requests_available' => $methods->availableMethods() !== [],
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
            'methods' => $methods->availableMethods(),
            'cash_destination_reference' => 'customer:'.$profile->id,
            'bank_destinations' => CustomerPayoutDestination::query()->where('customer_profile_id', $profile->id)->where('status', 'verified')->get()
                ->map(fn (CustomerPayoutDestination $destination): array => ['reference' => $destination->destination_reference,
                    'label' => $destination->bank_name.' '.$destination->account_mask])->all(),
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

        Toast::success('Withdrawal requested', 'The withdrawal is waiting for review.');

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
                'approved_at' => $withdrawal->approved_at?->toIso8601String(),
                'deadline_at' => $withdrawal->deadline_at->toIso8601String(),
            ],
            'can_execute' => $withdrawal->method === 'cash' && $authorization->allows($request->user(), AdminPermission::CashExecute),
            'can_execute_bank' => $withdrawal->method === 'bank_transfer' && $request->user()->user_type === UserType::Admin
                && $authorization->allows($request->user(), AdminPermission::WithdrawalsReview),
            'timezone' => BusinessProfile::current()->timezone,
            'bank_attempts' => $request->user()->user_type === UserType::Customer ? [] : $this->bankAttempts($withdrawal),
            'bank_returns' => $request->user()->user_type === UserType::Customer ? [] : BankPayoutReturn::query()
                ->whereIn('bank_payout_attempt_id', $withdrawal->bankPayoutAttempts()->select('id'))->orderBy('id')->get()
                ->map(fn (BankPayoutReturn $return): array => ['reference' => $return->return_reference, 'status' => $return->status,
                    'amount_kobo' => $return->amount_kobo, 'recorded_at' => $return->created_at?->toIso8601String()])->all(),
            'cash_execution' => CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->latest('id')->first()?->only(['execution_reference', 'status', 'amount_kobo']),
            'cash_recovery' => CashRecovery::query()->whereIn('cash_execution_id', CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->select('id'))->latest('id')->first()?->only(['recovery_reference', 'status', 'amount_kobo']),
            'cash_recoveries' => CashRecovery::query()->whereIn('cash_execution_id', CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->select('id'))->get(['recovery_reference', 'event_type', 'status', 'amount_kobo']),
            'is_customer' => $request->user()->user_type === UserType::Customer,
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

        Toast::success(match ($action) {
            'approve' => 'Withdrawal approved', 'reject' => 'Withdrawal rejected', 'revoke' => 'Withdrawal revoked', default => 'Withdrawal cancelled'
        }, 'The withdrawal decision was recorded.');

        return redirect()->route('withdrawals.show', $withdrawal);
    }

    /** @return list<array<string, mixed>> */
    private function bankAttempts(WithdrawalRequest $withdrawal): array
    {
        return array_values($withdrawal->bankPayoutAttempts()->orderBy('attempt_number')->get()->map(function (BankPayoutAttempt $attempt): array {
            $posted = $attempt->ledgerPostingGroup;

            return ['reference' => $attempt->attempt_reference, 'number' => $attempt->attempt_number, 'status' => $attempt->status,
                'provider_outcome' => $attempt->provider_outcome, 'failure_code' => $attempt->failure_code, 'amount_kobo' => $attempt->amount_kobo,
                'initiated_at' => $attempt->initiated_at?->toIso8601String(), 'provider_occurred_at' => $attempt->provider_occurred_at?->toIso8601String(),
                'finalized_at' => $attempt->finalized_at?->toIso8601String(), 'posted_at' => $posted?->committed_at->toIso8601String(),
                'occurred_on' => $posted?->occurred_on->toDateString(), 'settled_at' => $attempt->settled_at?->toIso8601String()];
        })->all());
    }

    /** @return array<string, mixed> */
    private function summary(WithdrawalRequest $withdrawal): array
    {
        return [
            'id' => $withdrawal->withdrawal_id, 'customer_id' => $withdrawal->customerProfile->customer_id,
            'customer_name' => $withdrawal->customerProfile->user?->name,
            'plan_id' => $withdrawal->plan->plan_id, 'type' => $withdrawal->type,
            'gross_kobo' => $withdrawal->gross_amount_kobo, 'fee_kobo' => $withdrawal->fee_amount_kobo,
            'net_kobo' => $withdrawal->net_amount_kobo, 'deduction_kobo' => $withdrawal->deduction_amount_kobo, 'method' => $withdrawal->method,
            'state' => $withdrawal->state, 'held' => $withdrawal->held, 'hold_reason' => $withdrawal->hold_reason,
            'submitted_at' => $withdrawal->submitted_at->toIso8601String(),
        ];
    }
}
