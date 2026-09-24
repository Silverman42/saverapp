<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalAttempt;
use App\Models\ReversalRequest;
use App\Services\AuthorizationService;
use App\Services\ResourceScopeService;
use App\Services\ReversalCapabilityRegistry;
use App\Services\ReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReversalController extends Controller
{
    public function index(Request $request, ResourceScopeService $scope, AuthorizationService $authorization): Response
    {
        $query = ReversalRequest::query()
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
            ->with(['customerProfile.user', 'originalPostingGroup']);
        if (in_array($request->query('state'), ['pending_review', 'rejected', 'cancelled', 'approved_posted'], true)) {
            $query->where('state', $request->query('state'));
        }
        $query = $request->user()->user_type === UserType::Admin
            ? $query->orderByRaw("CASE WHEN state = 'pending_review' THEN 0 ELSE 1 END")->orderBy('created_at')->orderBy('id')
            : $query->orderByDesc('created_at')->orderByDesc('id');
        $requests = $query->paginate(25);
        $requests->setCollection($requests->getCollection()->map(fn (ReversalRequest $reversal): array => $this->summary($reversal)));

        return Inertia::render('reversals/Index', [
            'requests' => $requests, 'state_filter' => $request->query('state', ''),
            'can_review' => $authorization->allows($request->user(), AdminPermission::ReversalsReview),
            'role' => $request->user()->user_type->value,
        ]);
    }

    public function show(ReversalRequest $reversal, Request $request, ResourceScopeService $scope, AuthorizationService $authorization, ReversalCapabilityRegistry $capabilities): Response
    {
        $this->authorizeScope($request, $scope, $reversal);
        $canReview = $authorization->allows($request->user(), AdminPermission::ReversalsReview);
        $isCustomer = $request->user()->user_type === UserType::Customer;

        return Inertia::render('reversals/Show', [
            'reversal' => [
                ...$this->summary($reversal->load(['customerProfile.user', 'originalPostingGroup'])),
                'version' => $reversal->version,
                'customer_explanation' => ! $isCustomer || $reversal->state === 'approved_posted'
                    ? $reversal->customer_explanation : null,
                'internal_reason' => $isCustomer || ($request->user()->user_type === UserType::Admin && ! $canReview)
                    ? null : $reversal->internal_reason,
                'evidence_text' => $isCustomer || ($request->user()->user_type === UserType::Admin && ! $canReview)
                    ? null : $reversal->evidence_text,
                'dependency_snapshot' => $isCustomer || ($request->user()->user_type === UserType::Admin && ! $canReview)
                    ? null : $reversal->dependency_snapshot,
            ],
            'can_review' => $canReview && $reversal->state === 'pending_review',
            'can_approve' => $canReview && $reversal->state === 'pending_review'
                && $capabilities->resolve($reversal->originalPostingGroup) !== null,
            'can_cancel' => $reversal->state === 'pending_review'
                && $reversal->requested_by_user_id === $request->user()->id
                && Gate::forUser($request->user())->allows('initiateReversal', $reversal->customerProfile),
        ]);
    }

    public function preview(string $posting, Request $request, ResourceScopeService $scope, ReversalService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $this->scopedOriginal($posting, $request, $scope)));
    }

    public function store(string $posting, Request $request, ResourceScopeService $scope, ReversalService $service): RedirectResponse
    {
        $original = $this->scopedOriginal($posting, $request, $scope);
        $this->rejectUnexpected($request, [
            'attempt_reference', 'preview_fingerprint', 'customer_version', 'assignment_version',
            'reason_category', 'internal_reason', 'customer_explanation', 'evidence_text', 'confirmed',
        ]);
        $data = $request->validate([
            'attempt_reference' => ['required', 'uuid'], 'preview_fingerprint' => ['required', 'string', 'size:64'],
            'customer_version' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', Rule::in([
                'duplicate_posting', 'wrong_customer', 'wrong_amount_allocation', 'payment_not_received',
                'incorrect_fee_deduction', 'incorrect_payout_record', 'other',
            ])],
            'internal_reason' => ['required', 'string', 'min:1', 'max:1000'],
            'customer_explanation' => ['required', 'string', 'min:1', 'max:500'],
            'evidence_text' => ['required', 'string', 'min:1', 'max:1000'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $reversal = $service->submit($request->user(), $original, $data);

        return redirect()->route('reversals.show', $reversal);
    }

    public function attempt(string $reference, Request $request, ResourceScopeService $scope): JsonResponse
    {
        $attempt = ReversalAttempt::query()->where('attempt_reference', $reference)
            ->where('actor_user_id', $request->user()->id)->firstOrFail();
        $reversal = $attempt->reversalRequest;
        $this->authorizeScope($request, $scope, $reversal);

        return response()->json(['reversal_id' => $reversal->reversal_id, 'state' => $reversal->state]);
    }

    public function reviewPreview(ReversalRequest $reversal, Request $request, ResourceScopeService $scope, ReversalService $service): JsonResponse
    {
        $this->authorizeScope($request, $scope, $reversal);

        return response()->json($service->reviewPreview($request->user(), $reversal));
    }

    public function cancel(ReversalRequest $reversal, Request $request, ReversalService $service): RedirectResponse
    {
        return $this->decide($reversal, $request, $service, 'cancel');
    }

    public function reject(ReversalRequest $reversal, Request $request, ReversalService $service): RedirectResponse
    {
        return $this->decide($reversal, $request, $service, 'reject');
    }

    public function approve(ReversalRequest $reversal, Request $request, ReversalService $service): RedirectResponse
    {
        return $this->decide($reversal, $request, $service, 'approve');
    }

    private function decide(ReversalRequest $reversal, Request $request, ReversalService $service, string $action): RedirectResponse
    {
        $this->rejectUnexpected($request, ['attempt_reference', 'version', 'preview_fingerprint', 'decision_reason', 'confirmed']);
        $data = $request->validate([
            'attempt_reference' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'preview_fingerprint' => [$action === 'approve' ? 'required' : 'nullable', 'string'],
            'decision_reason' => ['required', 'string', 'min:1', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $service->decide($request->user(), $reversal, $action, $data, $request);

        return redirect()->route('reversals.show', $reversal);
    }

    private function scopedOriginal(string $posting, Request $request, ResourceScopeService $scope): LedgerPostingGroup
    {
        return LedgerPostingGroup::query()->where('posting_reference', $posting)
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))->firstOrFail();
    }

    private function authorizeScope(Request $request, ResourceScopeService $scope, ReversalRequest $reversal): void
    {
        abort_unless($scope->forCustomers($request->user())->whereKey($reversal->customer_profile_id)->exists(), 404);
        Gate::forUser($request->user())->authorize('view', $reversal->customerProfile);
    }

    /** @param list<string> $allowed */
    private function rejectUnexpected(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->except('_token')), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages([array_values($unexpected)[0] => ['Unknown reversal field.']]);
        }
    }

    /** @return array<string, mixed> */
    private function summary(ReversalRequest $reversal): array
    {
        return [
            'id' => $reversal->reversal_id, 'customer_id' => $reversal->customerProfile->customer_id,
            'customer_name' => $reversal->customerProfile->user?->name,
            'original_reference' => $reversal->originalPostingGroup->posting_reference,
            'original_amount_kobo' => $reversal->original_amount_kobo,
            'currency' => $reversal->currency, 'state' => $reversal->state,
            'requested_at' => $reversal->created_at->toIso8601String(),
            'reviewed_at' => $reversal->reviewed_at?->toIso8601String(),
        ];
    }
}
