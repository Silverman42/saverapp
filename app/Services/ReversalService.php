<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalAttempt;
use App\Models\ReversalEvent;
use App\Models\ReversalEvidenceFile;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class ReversalService
{
    public function __construct(
        private ReversalCapabilityRegistry $capabilities,
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private ReversalNoticeService $notices,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, LedgerPostingGroup $original, bool $forUpdate = false): array
    {
        $customer = CustomerProfile::query()->whereKey($original->customer_profile_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $customer->load('currentAssignment');
        Gate::forUser($actor)->authorize('initiateReversal', $customer);

        if ($original->currency !== 'NGN' || $original->source_type === 'reversal_request') {
            throw new ConflictHttpException('This posting is not eligible for a full Customer reversal.');
        }
        if (ReversalRequest::query()->where('original_posting_group_id', $original->id)
            ->whereIn('state', ['pending_review', 'approved_posted', 'approved_no_money'])->exists()) {
            throw new ConflictHttpException('A reversal already exists for this posting.');
        }

        $owner = $this->capabilities->resolve($original);
        if ($owner === null) {
            abort(503, 'Reversal requests are unavailable until the full owner compensation contract is approved.');
        }

        $ownerPreview = $owner->preview($original, $customer, $forUpdate);
        if ($ownerPreview['gross_kobo'] <= 0 || $ownerPreview['gross_kobo'] > 999_999_999_999
            || $ownerPreview['fingerprint'] === '') {
            throw new ConflictHttpException('The original financial effect is unavailable.');
        }
        $assignment = $customer->currentAssignment;
        if ($assignment === null) {
            throw new ConflictHttpException('The current Customer assignment is unavailable.');
        }
        $fingerprint = hash('sha256', json_encode([
            $actor->id, $customer->id, $customer->version, $assignment->id,
            $assignment->version, $original->id, $original->payload_hash,
            $ownerPreview['fingerprint'],
        ], JSON_THROW_ON_ERROR));

        return [
            'original_posting_reference' => $original->posting_reference,
            'customer_id' => $customer->customer_id,
            'customer_version' => $customer->version,
            'assignment_version' => $assignment->version,
            'gross_kobo' => $ownerPreview['gross_kobo'],
            'currency' => 'NGN',
            'summary' => $ownerPreview['summary'],
            'dependencies' => $ownerPreview['dependencies'],
            'owner_fingerprint' => $ownerPreview['fingerprint'],
            'preview_fingerprint' => $fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    public function reviewPreview(User $actor, ReversalRequest $reversal): array
    {
        if (! $this->authorization->allows($actor, AdminPermission::ReversalsReview)) {
            throw new AuthorizationException('Reversal review permission is required.');
        }
        if ($reversal->state !== 'pending_review') {
            throw new ConflictHttpException('This reversal request is no longer pending.');
        }
        $original = $reversal->originalPostingGroup;
        $owner = $this->capabilities->resolve($original);
        if ($owner === null) {
            abort(503, 'Reversal posting is unavailable until its owner contract is approved.');
        }
        $preview = $owner->preview($original, $reversal->customerProfile, false);

        return [
            'preview_fingerprint' => $preview['fingerprint'],
            'gross_kobo' => $preview['gross_kobo'],
            'summary' => $preview['summary'],
            'dependencies' => $preview['dependencies'],
            'request_version' => $reversal->version,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $uploads
     */
    public function submit(User $actor, LedgerPostingGroup $original, array $data, array $uploads = []): ReversalRequest
    {
        $files = $this->prepareEvidence($uploads);
        $payloadHash = $this->payloadHash('submit', $actor, $original->id, [...$data, 'evidence_checksums' => array_column($files, 'checksum')]);

        try {
            return $this->submitWithEvidence($actor, $original, $data, $files, $payloadHash);
        } finally {
            foreach ($files as $file) {
                app(CollectionEvidenceFiles::class)->removeUnreferencedFile($file['storage_path']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $files
     */
    private function submitWithEvidence(User $actor, LedgerPostingGroup $original, array $data, array $files, string $payloadHash): ReversalRequest
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $original, $data, $files, $payloadHash): ReversalRequest {
            $replay = $this->replay($data['attempt_reference'], 'submit', $actor, $payloadHash);
            if ($replay !== null) {
                Gate::forUser($actor)->authorize('view', $replay->customerProfile);

                return $replay;
            }
            $customer = CustomerProfile::query()->whereKey($original->customer_profile_id)->lockForUpdate()->firstOrFail();
            $lockedOriginal = LedgerPostingGroup::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            $quote = $this->preview($actor, $lockedOriginal, true);
            if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint'])
                || (int) $data['customer_version'] !== $quote['customer_version']
                || (int) $data['assignment_version'] !== $quote['assignment_version']) {
                throw new ConflictHttpException('The reversal preview changed. Review it again.');
            }
            $assignment = $customer->currentAssignment;
            $reversal = ReversalRequest::create([
                'reversal_id' => (string) Str::uuid(),
                'customer_profile_id' => $customer->id,
                'original_posting_group_id' => $lockedOriginal->id,
                'live_original_posting_group_id' => $lockedOriginal->id,
                'requested_by_user_id' => $actor->id,
                'initiating_agent_profile_id' => $actor->agentProfile->id,
                'assignment_id' => $assignment->id,
                'state' => 'pending_review', 'version' => 1,
                'reason_category' => $data['reason_category'],
                'internal_reason' => trim($data['internal_reason']),
                'customer_explanation' => trim($data['customer_explanation']),
                'evidence_text' => trim($data['evidence_text']),
                'dependency_fingerprint' => $quote['owner_fingerprint'],
                'dependency_snapshot' => ['summary' => $quote['summary'], 'dependencies' => $quote['dependencies']],
                'original_amount_kobo' => $quote['gross_kobo'], 'currency' => $quote['currency'],
            ]);
            ReversalAttempt::create([
                'attempt_reference' => $data['attempt_reference'], 'reversal_request_id' => $reversal->id,
                'actor_user_id' => $actor->id, 'operation' => 'submit', 'payload_hash' => $payloadHash,
            ]);
            $this->recordEvent($reversal, $actor, 'submitted');
            $this->attachEvidence($reversal, $actor, $files);

            return $reversal;
        }, attempts: 3);
    }

    /**
     * Add scanned evidence files to a still-pending request. Files are immutable: a supplement only adds, up to three in total.
     *
     * @param  list<UploadedFile>  $uploads
     */
    public function addEvidence(User $actor, ReversalRequest $reversal, array $uploads): int
    {
        Gate::forUser($actor)->authorize('initiateReversal', $reversal->customerProfile);
        if ($reversal->state !== 'pending_review') {
            throw new ConflictHttpException('Evidence can be added only while the request is pending review.');
        }
        $files = $this->prepareEvidence($uploads, $reversal->evidenceFiles()->count());
        if ($files === []) {
            throw ValidationException::withMessages(['files' => 'Choose at least one evidence file.']);
        }
        try {
            return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $reversal, $files): int {
                $customer = CustomerProfile::query()->whereKey($reversal->customer_profile_id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('initiateReversal', $customer);
                $locked = ReversalRequest::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();
                if ($locked->state !== 'pending_review') {
                    throw new ConflictHttpException('Evidence can be added only while the request is pending review.');
                }
                if ($locked->evidenceFiles()->lockForUpdate()->count() + count($files) > 3) {
                    throw ValidationException::withMessages(['files' => 'A request holds at most three evidence files.']);
                }
                $this->attachEvidence($locked, $actor, $files);

                return count($files);
            }, attempts: 3);
        } finally {
            foreach ($files as $file) {
                app(CollectionEvidenceFiles::class)->removeUnreferencedFile($file['storage_path']);
            }
        }
    }

    /** The one authorized read of a stored evidence file; Customers never receive internal investigation files. */
    public function evidenceFile(User $actor, ReversalRequest $reversal, int $fileId): ReversalEvidenceFile
    {
        $customer = $reversal->customerProfile;
        $allowed = match ($actor->user_type) {
            UserType::Agent => Gate::forUser($actor)->allows('initiateReversal', $customer),
            UserType::Admin => $this->authorization->allows($actor, AdminPermission::ReversalsReview) && Gate::forUser($actor)->allows('view', $customer),
            default => false,
        };
        abort_unless($allowed, 404);

        return $reversal->evidenceFiles()->whereKey($fileId)->firstOrFail();
    }

    public function evidenceBytes(ReversalEvidenceFile $file): string
    {
        return app(CollectionEvidenceFiles::class)->fileBytes((object) $file->getAttributes());
    }

    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<array{storage_path: string, checksum: string, mime_type: string, byte_size: int, scanner_version: string, scanned_at: mixed, created_at: mixed}>
     */
    private function prepareEvidence(array $uploads, int $existing = 0): array
    {
        if ($existing + count($uploads) > 3) {
            throw ValidationException::withMessages(['files' => 'A request holds at most three evidence files.']);
        }
        $files = [];
        try {
            foreach ($uploads as $upload) {
                $files[] = app(CollectionEvidenceFiles::class)->prepareFile($upload);
            }
        } catch (Throwable $exception) {
            foreach ($files as $file) {
                app(CollectionEvidenceFiles::class)->removeUnreferencedFile($file['storage_path']);
            }
            throw $exception;
        }

        return $files;
    }

    /** @param list<array<string, mixed>> $files */
    private function attachEvidence(ReversalRequest $reversal, User $actor, array $files): void
    {
        if ($files === []) {
            return;
        }
        foreach ($files as $file) {
            ReversalEvidenceFile::create([...$file, 'reversal_request_id' => $reversal->id, 'uploaded_by_user_id' => $actor->id]);
        }
        AuditEvent::record('reversal.evidence_added', ReversalRequest::class, $reversal->id, $reversal->reversal_id,
            ['customer_profile_id' => $reversal->customer_profile_id, 'state' => $reversal->state, 'version' => $reversal->version,
                'file_count' => count($files)], $actor,
            context: ['executor' => self::class, 'operation_id' => 'evidence:'.$reversal->reversal_id.':'.$reversal->evidenceFiles()->count()]);
    }

    /** @param array<string, mixed> $data */
    public function decide(User $actor, ReversalRequest $reversal, string $action, array $data, Request $httpRequest): ReversalRequest
    {
        if (! in_array($action, ['cancel', 'reject', 'approve'], true)) {
            throw new \InvalidArgumentException('Unknown reversal action.');
        }
        $payloadHash = $this->payloadHash($action, $actor, $reversal->id, $data);

        try {
            return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reversal, $action, $data, $httpRequest, $payloadHash): ReversalRequest {
                $replay = $this->replay($data['attempt_reference'], $action, $actor, $payloadHash);
                if ($replay !== null) {
                    Gate::forUser($actor)->authorize('view', $replay->customerProfile);

                    return $replay;
                }
                $customer = CustomerProfile::query()->whereKey($reversal->customer_profile_id)->lockForUpdate()->firstOrFail();
                $locked = ReversalRequest::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();
                if ($action === 'cancel') {
                    Gate::forUser($actor)->authorize('initiateReversal', $customer);
                    if ($locked->requested_by_user_id !== $actor->id) {
                        throw new ConflictHttpException('Only the requesting Agent may cancel this request.');
                    }
                } elseif (! $this->authorization->allows($actor, AdminPermission::ReversalsReview)
                    || ! $this->freshAuthentication->isFresh($actor, $httpRequest)) {
                    throw new AuthorizationException('Fresh authorized Admin review is required.');
                } else {
                    Gate::forUser($actor)->authorize('view', $customer);
                }
                if ($locked->state !== 'pending_review' || $locked->version !== (int) $data['version']) {
                    throw new ConflictHttpException('The reversal request changed. Reload it before deciding.');
                }

                $compensation = null;
                if ($action === 'approve') {
                    $original = LedgerPostingGroup::query()->whereKey($locked->original_posting_group_id)->lockForUpdate()->firstOrFail();
                    $owner = $this->capabilities->resolve($original);
                    if ($owner === null) {
                        abort(503, 'Reversal posting is unavailable until its owner contract is approved.');
                    }
                    if ($customer->operational_status->value === 'archived') {
                        throw new ConflictHttpException('Restore the Archived Customer before correction.');
                    }
                    $current = $owner->preview($original, $customer, true);
                    $fingerprint = $data['preview_fingerprint'] ?? '';
                    if (! is_string($fingerprint) || ! hash_equals($current['fingerprint'], $fingerprint)) {
                        throw new ConflictHttpException('The dependency preview changed. Review it again.');
                    }
                    $compensation = $owner->compensate($locked, $current, $actor);
                    if ($compensation instanceof LedgerPostingGroup) {
                        $this->assertCompensation($locked, $compensation);
                        if ($compensation->id < 1) {
                            throw new ConflictHttpException('The compensation posting was not persisted.');
                        }
                        $locked->state = 'approved_posted';
                        $locked->compensation_posting_group_id = $compensation->id;
                    } else {
                        $proof = app(CollectionNoMoneyCorrection::class)->assertOutcome($locked, false);
                        if ($proof->id !== $compensation->id) {
                            throw new ConflictHttpException('The no-money correction proof was not persisted.');
                        }
                        $locked->state = 'approved_no_money';
                    }
                    $locked->posted_original_posting_group_id = $locked->original_posting_group_id;
                } else {
                    $locked->state = $action === 'reject' ? 'rejected' : 'cancelled';
                }
                $locked->live_original_posting_group_id = null;
                $reviewerId = $actor->id;
                if ($reviewerId < 1) {
                    throw new ConflictHttpException('The reviewer identity is invalid.');
                }
                $locked->reviewed_by_user_id = $action === 'cancel' ? null : $reviewerId;
                $locked->reviewed_at = now();
                $locked->decision_reason = trim($data['decision_reason'] ?? '');
                $locked->version++;
                $locked->save();
                ReversalAttempt::create([
                    'attempt_reference' => $data['attempt_reference'], 'reversal_request_id' => $locked->id,
                    'actor_user_id' => $actor->id, 'operation' => $action, 'payload_hash' => $payloadHash,
                ]);
                $this->recordEvent($locked, $actor, $locked->state, $compensation);
                if ($locked->state === 'approved_no_money' || ($compensation instanceof LedgerPostingGroup
                    && $locked->state === 'approved_posted' && in_array($compensation->event_type, ['receipt_reclassification', 'withdrawal_compensation', 'deduction_compensation', 'fee_application_compensation'], true))) {
                    app(LedgerTransactionProjectionService::class)->projectReversal($locked);
                }

                return $locked;
            }, attempts: 3);
        } catch (ConflictHttpException $exception) {
            if ($action === 'approve' && CustomerProfile::query()->whereKey($reversal->customer_profile_id)->value('operational_status') === CustomerStatus::Archived) {
                AuditEvent::record('reversal.archived_discovery', ReversalRequest::class, $reversal->id, $reversal->reversal_id,
                    ['customer_profile_id' => $reversal->customer_profile_id, 'original_posting_group_id' => $reversal->original_posting_group_id,
                        'state' => $reversal->state, 'version' => $reversal->version], $actor, ['executor' => self::class]);
            }
            throw $exception;
        }
    }

    public function assertApprovedReceiptCorrection(ReversalRequest $request, CollectionReceipt $receipt): void
    {
        $snapshot = $request->getAttribute('dependency_snapshot');
        $original = LedgerPostingGroup::query()->find($request->original_posting_group_id);
        $originalId = $receipt->savings_posting_group_id ?? DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->orderBy('id')->value('ledger_posting_group_id');
        if (! is_array($snapshot) || ($snapshot['summary']['receipt_id'] ?? null) !== $receipt->id
            || $original?->source_type !== 'collection_receipt' || $original->customer_profile_id !== $receipt->customer_profile_id
            || $original->currency !== 'NGN' || $originalId !== $request->original_posting_group_id
            || $request->original_amount_kobo !== $receipt->tender_amount_kobo
            || $request->customer_profile_id !== $receipt->customer_profile_id || $request->currency !== 'NGN'
            || $request->posted_original_posting_group_id !== $request->original_posting_group_id
            || $request->live_original_posting_group_id !== null || $request->reviewed_at === null
            || $request->reviewed_by_user_id === null || $request->reviewed_by_user_id === $request->requested_by_user_id
            || $request->events()->where('event_type', $request->state)->where('actor_user_id', $request->reviewed_by_user_id)->count() !== 1) {
            throw new ConflictHttpException('The receipt has no independently approved correction outcome.');
        }
        if ($request->state === 'approved_no_money') {
            app(CollectionNoMoneyCorrection::class)->assertOutcome($request);

            return;
        }
        app(CollectionReplacementService::class)->controlledAmount($request, $receipt);
        $compensation = LedgerPostingGroup::query()->with('entries.account')->findOrFail($request->compensation_posting_group_id);
        $this->assertCompensation($request, $compensation);
        if ($receipt->savings_amount_kobo > 0) {
            $credits = $original->entries()->with('account')->where('side', LedgerEntrySide::Credit)->get();
            $debits = $compensation->entries->filter(fn ($entry): bool => $entry->side === LedgerEntrySide::Debit
                && $entry->account->code === LedgerAccountCode::CustomerSavingsLiability);
            $credit = $credits->first();
            $debit = $debits->first();
            if ($original->source_id !== (string) $receipt->id || $original->thrift_plan_id !== $receipt->thrift_plan_id
                || $original->actor_user_id !== $receipt->recorded_by_user_id || $original->occurred_on?->toDateString() !== $receipt->received_date
                || $credits->count() !== 1 || $credit === null || $debits->count() !== 1 || $debit === null
                || $credit->account->code !== LedgerAccountCode::CustomerSavingsLiability
                || $credit->account->account_class !== LedgerAccountClass::CustomerSavingsLiability
                || $credit->account->currency !== 'NGN' || $credit->account->normal_balance !== LedgerEntrySide::Credit
                || $credit->amount_kobo !== $receipt->savings_amount_kobo || $credit->customer_profile_id !== $receipt->customer_profile_id
                || $credit->thrift_plan_id !== $receipt->thrift_plan_id || $credit->agent_profile_id !== null || $credit->fee_obligation_id !== null
                || $debit->ledger_account_id !== $credit->ledger_account_id || $debit->amount_kobo !== $receipt->savings_amount_kobo
                || $debit->customer_profile_id !== $receipt->customer_profile_id || $debit->thrift_plan_id !== $receipt->thrift_plan_id
                || $debit->agent_profile_id !== null || $debit->fee_obligation_id !== null
                || $compensation->actor_user_id !== $request->reviewed_by_user_id || $compensation->thrift_plan_id !== $receipt->thrift_plan_id
                || ($compensation->metadata['receipt_id'] ?? null) !== $receipt->id
                || ($compensation->metadata['original_posting_group_id'] ?? null) !== $original->id
                || $compensation->payload_hash !== $request->dependency_fingerprint) {
                throw new ConflictHttpException('The correction has no exact original savings compensation.');
            }
        }
    }

    public function assertCompensation(ReversalRequest $request, LedgerPostingGroup $group, bool $forUpdate = false): void
    {
        if ($group->source_type !== 'reversal_request' || $group->source_id !== (string) $request->id
            || $group->customer_profile_id !== $request->customer_profile_id || $group->currency !== $request->currency) {
            throw new ConflictHttpException('The compensation posting identity is invalid.');
        }
        $entries = $group->entries()->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $debits = $entries->where('side', LedgerEntrySide::Debit)->sum('amount_kobo');
        $credits = $entries->where('side', LedgerEntrySide::Credit)->sum('amount_kobo');
        if ($debits <= 0 || $debits !== $credits || $entries->count() < 2) {
            throw new ConflictHttpException('The compensation posting is not balanced.');
        }
    }

    private function replay(string $reference, string $operation, User $actor, string $payloadHash): ?ReversalRequest
    {
        $attempt = ReversalAttempt::query()->where('attempt_reference', $reference)->lockForUpdate()->first();
        if ($attempt === null) {
            return null;
        }
        if ($attempt->actor_user_id !== $actor->id || $attempt->operation !== $operation
            || ! hash_equals($attempt->payload_hash, $payloadHash)) {
            throw new ConflictHttpException('This reversal operation reference belongs to a different action.');
        }

        $reversal = $attempt->reversalRequest;
        if ($reversal->state === 'approved_posted'
            && $reversal->originalPostingGroup?->source_type === 'fee_savings_application') {
            app(FeeSavingsApplicationReversalOwner::class)->assertPosted($reversal->id, true);
        }

        return $reversal;
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(string $operation, User $actor, int $targetId, array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode([$operation, $actor->id, $targetId, $data], JSON_THROW_ON_ERROR));
    }

    private function recordEvent(ReversalRequest $reversal, User $actor, string $eventType, LedgerPostingGroup|FinancialWorkflowSupplement|null $compensation = null): void
    {
        $event = ReversalEvent::create([
            'reversal_request_id' => $reversal->id, 'actor_user_id' => $actor->id,
            'event_type' => $eventType, 'effective_at' => now(),
            'customer_explanation' => in_array($eventType, ['approved_posted', 'approved_no_money'], true) ? $reversal->customer_explanation : null,
            'metadata' => ['version' => $reversal->version,
                'compensation_posting_group_id' => $reversal->compensation_posting_group_id,
                'no_money_supplement_id' => $compensation instanceof FinancialWorkflowSupplement ? $compensation->id : null,
                'owner_fingerprint' => $compensation?->payload_hash],
        ]);
        AuditEvent::record('reversal.'.$eventType, ReversalRequest::class, $reversal->id,
            $reversal->reversal_id, [
                'customer_profile_id' => $reversal->customer_profile_id,
                'original_posting_group_id' => $reversal->original_posting_group_id,
                'state' => $reversal->state, 'version' => $reversal->version,
                'compensation_posting_group_id' => $reversal->compensation_posting_group_id,
            ], $actor,
            context: ['executor' => self::class, 'approver_id' => $reversal->reviewed_by_user_id,
                'source_version' => $reversal->version, 'correlation_reference' => 'reversal-event:'.$event->id,
                'required_permission' => $actor->user_type === UserType::Admin ? 'reversals.review' : null]
        );
        $this->notices->queue($reversal, $event);
    }

    public function archivalStatus(CustomerProfile $customer): string
    {
        foreach (ReversalRequest::query()->where('customer_profile_id', $customer->id)->get() as $request) {
            if ($request->state === 'pending_review') {
                return 'blocked';
            }
            if (in_array($request->state, ['rejected', 'cancelled'], true)) {
                if ($request->reviewed_at === null || $request->live_original_posting_group_id !== null
                    || ! $request->events()->where('event_type', $request->state)->exists()) {
                    return 'unavailable';
                }

                continue;
            }
            if ($request->state !== 'approved_posted') {
                if ($request->state === 'approved_no_money') {
                    app(CollectionNoMoneyCorrection::class)->assertOutcome($request);

                    continue;
                }

                return 'unavailable';
            }
            $original = $request->originalPostingGroup;
            if ($original === null || ($original->source_type !== 'fee_savings_application' && $this->capabilities->resolve($original) === null)) {
                return 'unavailable';
            }
            $group = LedgerPostingGroup::query()->find($request->compensation_posting_group_id);
            if ($group === null) {
                return 'unavailable';
            }
            $this->assertCompensation($request, $group);
            if ($original->source_type === 'fee_savings_application') {
                app(FeeSavingsApplicationReversalOwner::class)->assertPosted($request->id, DB::transactionLevel() > 0);
            }
        }

        return 'passed';
    }

    public function agentOffboardingStatus(AgentProfile $agent, bool $forUpdate = false): string
    {
        $query = ReversalRequest::query()->where('initiating_agent_profile_id', $agent->id)->orderBy('id');
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $request) {
            if ($request->state === 'approved_no_money') {
                app(CollectionNoMoneyCorrection::class)->assertOutcome($request);

                continue;
            }
            if (in_array($request->state, ['pending_review'], true) && app(CustomerReassignmentService::class)
                ->hasVerifiedHandover($request->customerProfile, $agent->id, $forUpdate)) {
                continue;
            }
            if (! in_array($request->state, ['rejected', 'cancelled'], true)
                || $request->reviewed_at === null || $request->live_original_posting_group_id !== null
                || ! $request->events()->where('event_type', $request->state)->exists()) {
                return 'unavailable';
            }
        }

        return 'passed';
    }
}
