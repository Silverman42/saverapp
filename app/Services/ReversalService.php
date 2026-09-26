<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalAttempt;
use App\Models\ReversalEvent;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
            ->whereIn('state', ['pending_review', 'approved_posted'])->exists()) {
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

    /** @param array<string, mixed> $data */
    public function submit(User $actor, LedgerPostingGroup $original, array $data): ReversalRequest
    {
        $payloadHash = $this->payloadHash('submit', $actor, $original->id, $data);

        return DB::transaction(function () use ($actor, $original, $data, $payloadHash): ReversalRequest {
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

            return $reversal;
        });
    }

    /** @param array<string, mixed> $data */
    public function decide(User $actor, ReversalRequest $reversal, string $action, array $data, Request $httpRequest): ReversalRequest
    {
        if (! in_array($action, ['cancel', 'reject', 'approve'], true)) {
            throw new \InvalidArgumentException('Unknown reversal action.');
        }
        $payloadHash = $this->payloadHash($action, $actor, $reversal->id, $data);

        return DB::transaction(function () use ($actor, $reversal, $action, $data, $httpRequest, $payloadHash): ReversalRequest {
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
                $this->assertCompensation($locked, $compensation);
                if ($compensation->id < 1) {
                    throw new ConflictHttpException('The compensation posting was not persisted.');
                }
                $locked->state = 'approved_posted';
                $locked->posted_original_posting_group_id = $locked->original_posting_group_id;
                $locked->compensation_posting_group_id = $compensation->id;
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
            $this->recordEvent($locked, $actor, $locked->state);

            return $locked;
        });
    }

    private function assertCompensation(ReversalRequest $request, LedgerPostingGroup $group): void
    {
        if ($group->source_type !== 'reversal_request' || $group->source_id !== (string) $request->id
            || $group->customer_profile_id !== $request->customer_profile_id || $group->currency !== $request->currency) {
            throw new ConflictHttpException('The compensation posting identity is invalid.');
        }
        $debits = (int) $group->entries()->where('side', 'debit')->sum('amount_kobo');
        $credits = (int) $group->entries()->where('side', 'credit')->sum('amount_kobo');
        if ($debits <= 0 || $debits !== $credits || $group->entries()->count() < 2) {
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

        return $attempt->reversalRequest;
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(string $operation, User $actor, int $targetId, array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode([$operation, $actor->id, $targetId, $data], JSON_THROW_ON_ERROR));
    }

    private function recordEvent(ReversalRequest $reversal, User $actor, string $eventType): void
    {
        $event = ReversalEvent::create([
            'reversal_request_id' => $reversal->id, 'actor_user_id' => $actor->id,
            'event_type' => $eventType, 'effective_at' => now(),
            'customer_explanation' => $eventType === 'approved_posted' ? $reversal->customer_explanation : null,
            'metadata' => ['version' => $reversal->version,
                'compensation_posting_group_id' => $reversal->compensation_posting_group_id],
        ]);
        AuditEvent::record('reversal.'.$eventType, ReversalRequest::class, $reversal->id,
            $reversal->reversal_id, [
                'customer_profile_id' => $reversal->customer_profile_id,
                'original_posting_group_id' => $reversal->original_posting_group_id,
                'state' => $reversal->state, 'version' => $reversal->version,
                'compensation_posting_group_id' => $reversal->compensation_posting_group_id,
            ], $actor,
            context: ['executor' => self::class, 'approver_id' => $reversal->reviewed_by_user_id, 'required_permission' => $actor?->user_type === UserType::Admin ? 'reversals.review' : null]
        );
        $this->notices->queue($reversal, $event);
    }
}
