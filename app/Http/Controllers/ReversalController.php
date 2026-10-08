<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalAttempt;
use App\Models\ReversalEvidenceFile;
use App\Models\ReversalRequest;
use App\Services\AuthorizationService;
use App\Services\ResourceScopeService;
use App\Services\ReversalCapabilityRegistry;
use App\Services\ReversalService;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReversalController extends Controller
{
    public function index(Request $request, ResourceScopeService $scope, AuthorizationService $authorization): Response
    {
        $query = ReversalRequest::query()
            ->whereIn('customer_profile_id', $scope->forCustomers($request->user())->select('id'))
            ->with(['customerProfile.user', 'originalPostingGroup']);
        if (in_array($request->query('state'), ['pending_review', 'rejected', 'cancelled', 'approved_posted', 'approved_no_money'], true)) {
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
                'customer_explanation' => ! $isCustomer || in_array($reversal->state, ['approved_posted', 'approved_no_money'], true)
                    ? $reversal->customer_explanation : null,
                'internal_reason' => $isCustomer || ($request->user()->user_type === UserType::Admin && ! $canReview)
                    ? null : $reversal->internal_reason,
                'evidence_text' => $isCustomer || ($request->user()->user_type === UserType::Admin && ! $canReview)
                    ? null : $reversal->evidence_text,
                'dependency_snapshot' => $isCustomer || ($request->user()->user_type === UserType::Admin && ! $canReview)
                    ? null : $reversal->dependency_snapshot,
            ],
            'evidence_files' => $isCustomer ? [] : $reversal->evidenceFiles()->orderBy('id')->get()
                ->map(fn (ReversalEvidenceFile $file): array => ['id' => $file->id, 'type' => $file->mime_type, 'bytes' => $file->byte_size,
                    'added_at' => $file->created_at->toIso8601String()])->all(),
            'can_add_evidence' => $reversal->state === 'pending_review' && ! $isCustomer
                && Gate::forUser($request->user())->allows('initiateReversal', $reversal->customerProfile) && $reversal->evidenceFiles()->count() < 3,
            'can_replace' => $reversal->state === 'approved_posted'
                && LedgerPostingGroup::query()->find($reversal->compensation_posting_group_id)?->event_type === 'receipt_reclassification'
                && ! CollectionReceipt::query()->where('replacement_reversal_id', $reversal->id)->exists()
                && Gate::forUser($request->user())->allows('recordCollection', $reversal->customerProfile),
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
            'reason_category', 'internal_reason', 'customer_explanation', 'evidence_text', 'confirmed', 'files',
        ]);
        $data = $request->validate([
            'files' => ['sometimes', 'array', 'max:3'], 'files.*' => ['file'],
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
        $uploads = array_values($request->file('files', []));
        unset($data['files']);
        $reversal = $service->submit($request->user(), $original, $data, $uploads);

        Toast::success('Reversal requested', 'The reversal is waiting for review.');

        return redirect()->route('reversals.show', $reversal);
    }

    public function evidenceStore(ReversalRequest $reversal, Request $request, ResourceScopeService $scope, ReversalService $service): RedirectResponse
    {
        $this->authorizeScope($request, $scope, $reversal);
        $this->rejectUnexpected($request, ['files']);
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:3'], 'files.*' => ['file']]);
        $service->addEvidence($request->user(), $reversal, array_values($request->file('files', [])));

        Toast::success('Evidence added', 'The reversal evidence was saved.');

        return redirect()->route('reversals.show', $reversal);
    }

    public function evidenceLink(ReversalRequest $reversal, int $file, Request $request, ResourceScopeService $scope, ReversalService $service): JsonResponse
    {
        $this->authorizeScope($request, $scope, $reversal);
        $service->evidenceFile($request->user(), $reversal, $file);

        return response()->json(['url' => URL::temporarySignedRoute('reversals.evidence.download', now()->addMinutes(2),
            ['reversal' => $reversal->reversal_id, 'file' => $file])]);
    }

    public function evidenceDownload(ReversalRequest $reversal, int $file, Request $request, ResourceScopeService $scope, ReversalService $service): StreamedResponse
    {
        $this->authorizeScope($request, $scope, $reversal);
        $record = $service->evidenceFile($request->user(), $reversal, $file);
        $bytes = $service->evidenceBytes($record);
        AuditEvent::record('reversal.evidence_downloaded', ReversalRequest::class, $reversal->id, $reversal->reversal_id,
            ['customer_profile_id' => $reversal->customer_profile_id, 'file_id' => $record->id], $request->user(),
            context: ['executor' => self::class, 'operation_id' => 'evidence-download:'.$record->id.':'.Str::uuid()]);
        $extension = match ($record->mime_type) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'pdf',
        };

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'reversal-evidence-'.$record->id.'.'.$extension, ['Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
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

        Toast::success(match ($action) {
            'approve' => 'Reversal approved', 'reject' => 'Reversal rejected', default => 'Reversal cancelled'
        }, 'The reversal decision was recorded.');

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
