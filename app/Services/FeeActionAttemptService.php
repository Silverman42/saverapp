<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\FeeActionAttempt;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\MoneyAmount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FeeActionAttemptService
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function prepare(User $actor, int $obligationId, array $data, Request $request): array
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $obligationId, $data, $request): array {
            $admin = $this->authorize($actor, $request);
            $payload = $this->canonical($data['operation'], $data['payload']);
            $attempt = $this->bound($admin, $obligationId, $data['operation'], $data['attempt_reference'], $payload);
            if ($attempt !== null) {
                return $this->outcome($attempt, $admin);
            }
            $recorded = $this->source($admin, $obligationId, $data['operation'], $data['attempt_reference'], $payload, $data['payload'] ?? null);
            if ($recorded !== null) {
                return $this->envelope($obligationId, $data['operation'], $data['attempt_reference'], $recorded);
            }
            if ($data['operation'] === 'apply_savings') {
                app(FeeSavingsApplicationService::class)->validatePreparation($admin, $obligationId, $data['payload']);
            } else {
                app(FeeObligationService::class)->validatePreparation($obligationId, $data['operation'], $payload);
            }
            $attempt = $this->create($admin, $obligationId, $data['operation'], $data['attempt_reference'], $payload);

            return $this->outcome($attempt, $admin);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function cancel(User $actor, int $obligationId, array $data, Request $request): array
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $obligationId, $data, $request): array {
            $admin = $this->authorize($actor, $request);
            $payload = isset($data['payload']) ? $this->canonical($data['operation'], $data['payload']) : null;
            $attempt = $this->bound($admin, $obligationId, $data['operation'], $data['attempt_reference'], $payload);
            $recorded = $this->source($admin, $obligationId, $data['operation'], $data['attempt_reference'], $attempt === null ? $payload : null, $data['payload'] ?? null);
            if ($recorded !== null) {
                if ($attempt !== null && $attempt->status === 'cancelled') {
                    throw new ConflictHttpException('Cancelled attempt has inconsistent retained financial evidence.');
                }
                if ($attempt !== null && $attempt->status === 'prepared') {
                    $this->markRecorded($attempt, $data['operation'] === 'apply_savings' ? 'fee_savings_application' : 'fee_obligation_entry',
                        $data['operation'] === 'apply_savings' ? $data['attempt_reference'] : (string) $recorded['entry_id']);
                }

                return $attempt === null ? $this->envelope($obligationId, $data['operation'], $data['attempt_reference'], $recorded) : $this->outcome($attempt, $admin);
            }
            if ($attempt !== null && $attempt->status !== 'prepared') {
                return $this->outcome($attempt, $admin);
            }
            FeeObligation::query()->whereKey($obligationId)->firstOrFail();
            $attempt ??= $this->create($admin, $obligationId, $data['operation'], $data['attempt_reference'], $payload);
            $audit = AuditEvent::record(eventType: 'fee.action.cancelled', targetType: FeeActionAttempt::class,
                targetId: $attempt->id, targetReference: $attempt->attempt_reference,
                payload: ['attempt_reference' => $attempt->attempt_reference, 'fee_obligation_id' => $obligationId,
                    'operation' => $attempt->operation, 'payload_hash' => $attempt->payload_hash, 'reason_code' => 'operator_cancelled'],
                actor: $admin, context: ['executor' => self::class, 'required_permission' => 'fees.manage']);
            $attempt->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason_code' => 'operator_cancelled', 'cancellation_audit_event_id' => $audit->id]);

            return $this->outcome($attempt, $admin);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function status(User $actor, int $obligationId, string $reference): array
    {
        return DB::transaction(function () use ($actor, $obligationId, $reference): array {
            $admin = $this->authorize($actor);
            $attempt = FeeActionAttempt::query()->where('attempt_reference', $reference)->lockForUpdate()->first();
            if ($attempt === null || $attempt->actor_user_id !== $admin->id || $attempt->fee_obligation_id !== $obligationId) {
                throw new NotFoundHttpException('Record unavailable.');
            }

            return $this->outcome($attempt, $admin);
        });
    }

    /** @param array<string, mixed> $payload */
    public function reserveForCommit(User $admin, int $obligationId, string $operation, string $reference, array $payload): ?FeeActionAttempt
    {
        if (! Schema::hasTable('fee_action_attempts')) {
            throw new ConflictHttpException('Durable fee action preparation is unavailable.');
        }
        $canonical = $this->canonical($operation, $payload);
        $attempt = $this->bound($admin, $obligationId, $operation, $reference, $canonical);
        if ($attempt?->status === 'cancelled') {
            $this->outcome($attempt, $admin);
            throw new ConflictHttpException('This fee action attempt was cancelled. Review a new action.');
        }
        if ($attempt !== null) {
            if ($attempt->status === 'recorded') {
                $this->outcome($attempt, $admin);
            }

            return $attempt;
        }
        if ($this->source($admin, $obligationId, $operation, $reference, $canonical, $payload) !== null) {
            return null;
        }

        return $this->create($admin, $obligationId, $operation, $reference, $canonical);
    }

    public function markRecorded(?FeeActionAttempt $attempt, string $sourceType, string $sourceId): void
    {
        if ($attempt === null) {
            return;
        }
        if ($attempt->status === 'recorded') {
            if ($attempt->source_type !== $sourceType || $attempt->source_id !== $sourceId) {
                throw new ConflictHttpException('Retained fee action source conflicts with its attempt.');
            }

            return;
        }
        $attempt->update(['status' => 'recorded', 'source_type' => $sourceType, 'source_id' => $sourceId]);
    }

    private function authorize(User $actor, ?Request $request = null): User
    {
        $admin = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! app(AuthorizationService::class)->allows($admin, AdminPermission::FeesManage)) {
            throw new AuthorizationException('Current authority to manage fees is required.');
        }
        if ($request !== null && ! app(FreshAuthenticationService::class)->isFresh($admin, $request)) {
            throw new ConflictHttpException('Fresh password and authenticator confirmation is required.');
        }

        return $admin;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function canonical(string $operation, array $payload): array
    {
        $result = ['reason' => trim($payload['reason']), 'customer_description' => trim($payload['customer_description'])];
        if ($operation === 'apply_savings') {
            return ['reason' => $payload['reason'], 'customer_description' => $payload['customer_description']] + ['plan_id' => $payload['plan_id'], 'confirmed' => (bool) $payload['confirmed'],
                'preview_fingerprint' => $payload['preview_fingerprint'], 'quote_expires_at' => $payload['quote_expires_at']];
        }
        try {
            $result['amount_kobo'] = $payload['amount_kobo'] ?? MoneyAmount::parseNairaToKobo($payload['amount_ngn']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['payload.amount_ngn' => [$exception->getMessage()]]);
        }
        if ($operation === 'correct') {
            $result['direction'] = $payload['direction'];
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function hash(User $admin, int $obligationId, string $operation, array $payload): string
    {
        return hash_hmac('sha256', json_encode([$admin->id, $obligationId, $operation, $payload], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** @param array<string, mixed> $payload */
    private function bound(User $admin, int $obligationId, string $operation, string $reference, ?array $payload): ?FeeActionAttempt
    {
        $attempt = FeeActionAttempt::query()->where('attempt_reference', $reference)->lockForUpdate()->first();
        if ($attempt !== null && ($attempt->payload_hash === null ? $attempt->status !== 'cancelled' : preg_match('/\A[a-f0-9]{64}\z/', $attempt->payload_hash) !== 1)) {
            throw new ConflictHttpException('Retained fee action binding is unavailable.');
        }
        if ($attempt !== null && ($attempt->actor_user_id !== $admin->id || $attempt->fee_obligation_id !== $obligationId
            || $attempt->operation !== $operation || ($payload !== null && $attempt->payload_hash !== null && ! hash_equals($attempt->payload_hash ?? '', $this->hash($admin, $obligationId, $operation, $payload))))) {
            throw new ConflictHttpException('Changed fee action conflicts with its original attempt.');
        }

        return $attempt;
    }

    /** @param array<string, mixed> $payload */
    private function create(User $admin, int $obligationId, string $operation, string $reference, ?array $payload): FeeActionAttempt
    {
        $attempt = FeeActionAttempt::query()->firstOrCreate(['attempt_reference' => $reference], ['actor_user_id' => $admin->id, 'fee_obligation_id' => $obligationId,
            'operation' => $operation, 'payload_hash' => $payload === null ? null : $this->hash($admin, $obligationId, $operation, $payload), 'status' => 'prepared']);
        if ($attempt->actor_user_id !== $admin->id || $attempt->fee_obligation_id !== $obligationId || $attempt->operation !== $operation
            || $attempt->status !== 'prepared' || $attempt->payload_hash !== ($payload === null ? null : $this->hash($admin, $obligationId, $operation, $payload))) {
            throw new ConflictHttpException('Changed fee action conflicts with its original attempt.');
        }

        return $attempt;
    }

    /** @param array<string, mixed>|null $payload
     * @param  array<string, mixed>|null  $originalPayload
     * @return array<string, mixed>|null
     */
    private function source(User $admin, int $obligationId, string $operation, string $reference, ?array $payload = null, ?array $originalPayload = null): ?array
    {
        $application = Schema::hasTable('fee_savings_applications') ? DB::table('fee_savings_applications')->where('operation_reference', $reference)->lockForUpdate()->first() : null;
        CustomerProfile::query()->whereIn('id', FeeObligation::query()->select('customer_profile_id')->whereKey($obligationId))->lockForUpdate()->first();
        if ($application !== null) {
            ThriftPlan::query()->whereKey($application->thrift_plan_id)->lockForUpdate()->firstOrFail();
        }
        $fee = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->first();
        $entries = FeeObligationEntry::query()->where(fn ($query) => $query->where('source_id', $reference)->orWhere('idempotency_key', 'fee-admin-'.$reference))->lockForUpdate()->get();
        if ($operation === 'apply_savings' && $entries->contains(fn (FeeObligationEntry $entry): bool => $entry->source_type === 'manual_charge')) {
            $charge = ManualCharge::query()->where('operation_reference', $reference)->lockForUpdate()->first();
            $fee = FeeObligation::query()->with(['feeSnapshot' => fn ($query) => $query->lockForUpdate()])->whereKey($obligationId)->lockForUpdate()->first();
            $snapshot = $fee?->feeSnapshot;
            $entries = $entries->reject(function (FeeObligationEntry $entry) use ($charge, $fee, $snapshot, $admin, $obligationId, $reference): bool {
                if ($entry->source_type !== 'manual_charge') {
                    return false;
                }
                if ($charge === null || $fee === null || $snapshot === null || $charge->fee_obligation_id !== $obligationId
                    || $charge->actor_user_id !== $admin->id || $charge->customer_profile_id !== $fee->customer_profile_id
                    || $charge->amount_kobo !== $fee->amount_kobo || $fee->kind !== 'manual'
                    || $fee->source_type !== 'manual_charge' || $fee->source_id !== $reference
                    || $snapshot->source_type !== 'manual_charge' || $snapshot->source_id !== $reference
                    || $snapshot->customer_profile_id !== $fee->customer_profile_id || $snapshot->amount_kobo !== $fee->amount_kobo
                    || $entry->fee_obligation_id !== $obligationId || $entry->getRawOriginal('entry_type') !== 'assessment'
                    || $entry->idempotency_key !== 'fee-assessment-'.$snapshot->id || $entry->source_id !== $reference
                    || $entry->actor_user_id !== $admin->id || $entry->amount_kobo !== $fee->amount_kobo
                    || $entry->currency !== 'NGN' || $entry->ledger_posting_reference !== null) {
                    throw new ConflictHttpException('Retained manual fee assessment source is unavailable.');
                }

                return true;
            });
        }
        $groups = LedgerPostingGroup::query()->where(fn ($query) => $query->where('source_id', $reference)->orWhere('idempotency_key', 'admin-fee-apply-'.$reference))->lockForUpdate()->get();
        $eventExists = Schema::hasTable('fee_obligation_events') && DB::table('fee_obligation_events')->where('operation_reference', $reference)->lockForUpdate()->first() !== null;
        if ($entries->isEmpty() && $application === null && $groups->isEmpty() && ! $eventExists) {
            return null;
        }
        if ($fee === null) {
            throw new ConflictHttpException('Retained fee obligation source is unavailable.');
        }
        if ($operation === 'apply_savings') {
            if ($application === null || (int) $application->actor_user_id !== $admin->id || (int) $application->fee_obligation_id !== $obligationId
                || $entries->contains(fn (FeeObligationEntry $entry): bool => $entry->source_type !== 'fee_savings_application')) {
                throw new ConflictHttpException('Retained fee application source is unavailable.');
            }
            $group = app(FeeSavingsApplicationService::class)->assertPosted($reference, true);
            if ($payload !== null) {
                app(FeeSavingsApplicationService::class)->assertAttemptPayload($admin, $obligationId, $reference, ($originalPayload ?? $payload) + ['attempt_reference' => $reference]);
            }

            return ['status' => 'recorded', 'posting_reference' => $group->posting_reference];
        }
        if ($application !== null || $groups->isNotEmpty() || $entries->count() !== 1) {
            throw new ConflictHttpException('Retained administrative fee action is unavailable.');
        }
        $result = app(FeeObligationService::class)->administrativeActionStatus($admin, $obligationId, $reference);
        $entry = $entries->first();
        if ($result['action'] !== $operation || ($payload !== null && ($entry->amount_kobo !== $payload['amount_kobo']
            || $entry->reason !== $payload['reason'] || $entry->customer_description !== $payload['customer_description']
            || ($operation === 'correct' && $result['direction'] !== $payload['direction'])))) {
            throw new ConflictHttpException('Changed fee action conflicts with its original attempt.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function outcome(FeeActionAttempt $attempt, User $admin): array
    {
        if ($attempt->payload_hash === null ? $attempt->status !== 'cancelled' : preg_match('/\A[a-f0-9]{64}\z/', $attempt->payload_hash) !== 1) {
            throw new ConflictHttpException('Retained fee action binding is unavailable.');
        }
        $source = $this->source($admin, $attempt->fee_obligation_id, $attempt->operation, $attempt->attempt_reference);
        if ($attempt->status === 'recorded') {
            if ($source === null || $attempt->source_type !== ($attempt->operation === 'apply_savings' ? 'fee_savings_application' : 'fee_obligation_entry')
                || $attempt->source_id !== ($attempt->operation === 'apply_savings' ? $attempt->attempt_reference : (string) $source['entry_id'])) {
                throw new ConflictHttpException('Retained fee action source is unavailable.');
            }

            return $this->envelope($attempt->fee_obligation_id, $attempt->operation, $attempt->attempt_reference, $source);
        }
        if ($source !== null || ! in_array($attempt->status, ['prepared', 'cancelled'], true) || $attempt->source_id !== null || $attempt->source_type !== null) {
            throw new ConflictHttpException('Retained fee action outcome is inconsistent.');
        }
        if ($attempt->status === 'cancelled') {
            $audit = AuditEvent::query()->whereKey($attempt->cancellation_audit_event_id)->lockForUpdate()->first();
            if ($attempt->cancelled_at === null || $attempt->cancellation_reason_code !== 'operator_cancelled' || $audit === null
                || $audit->event_type !== 'fee.action.cancelled' || $audit->actor_id !== $admin->id || $audit->actor_type !== UserType::Admin->value
                || $audit->target_type !== FeeActionAttempt::class || $audit->target_id !== $attempt->id
                || $audit->target_reference !== $attempt->attempt_reference || ($audit->payload['payload_hash'] ?? null) !== $attempt->payload_hash
                || ($audit->payload['operation'] ?? null) !== $attempt->operation || ($audit->payload['fee_obligation_id'] ?? null) !== $attempt->fee_obligation_id
                || ($audit->payload['attempt_reference'] ?? null) !== $attempt->attempt_reference || ($audit->payload['reason_code'] ?? null) !== 'operator_cancelled') {
                throw new ConflictHttpException('Retained cancellation evidence is unavailable.');
            }
        }

        return $this->envelope($attempt->fee_obligation_id, $attempt->operation, $attempt->attempt_reference,
            ['status' => $attempt->status, ...($attempt->status === 'cancelled' ? ['cancelled_at' => $attempt->cancelled_at->toIso8601String()] : [])]);
    }

    /** @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private function envelope(int $obligationId, string $operation, string $reference, array $outcome): array
    {
        return $outcome + ['operation' => $operation, 'attempt_reference' => $reference, 'obligation_id' => $obligationId];
    }
}
