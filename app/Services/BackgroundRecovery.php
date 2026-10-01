<?php

namespace App\Services;

use App\Enums\ExternalOutcome;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\FinancialArtifact;
use App\Support\ExternalOutcomeLookup;
use App\Support\PlatformBlocked;
use App\Support\RecoveryConflict;
use App\Support\RecoveryLease;
use App\Support\RecoveryOwner;
use App\Support\RecoverySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Throwable;

class BackgroundRecovery
{
    public const OWNERS = ['audit_projection', 'notification_inbox', 'financial_artifact'];

    public function __construct(private PlatformGuard $guard, private AuditRecoveryOwner $audit, private NotificationRecoveryOwner $notifications, private ExternalOutcomeLookup $external) {}

    public function owner(string $owner): RecoveryOwner
    {
        return match ($owner) {
            'audit_projection' => $this->audit,
            'notification_inbox' => $this->notifications,
            'financial_artifact' => app(FinancialArtifactRecoveryOwner::class),
            default => throw new RecoveryConflict('unsupported_owner'),
        };
    }

    public function register(string $owner, int $sourceId): int
    {
        $source = $this->owner($owner)->snapshot($sourceId);

        return DB::transaction(function () use ($owner, $sourceId, $source): int {
            DB::table('platform_recovery_work')->insertOrIgnore($this->registration($owner, $sourceId, $source));
            $work = DB::table('platform_recovery_work')->where('owner', $owner)->where('source_id', $sourceId)->firstOrFail();
            $this->validate($work, $source);

            return (int) $work->id;
        });
    }

    /** @return array<string, mixed> */
    private function registration(string $owner, int $sourceId, RecoverySource $source): array
    {
        return ['owner' => $owner, 'source_id' => $sourceId, 'source_version' => $source->version,
            'payload_hash' => $source->hash, 'operation_key' => $source->operationKey,
            ...($owner === 'financial_artifact' ? ['timeout_seconds' => 120, 'lease_seconds' => 150] : []),
            'correlation_reference' => (string) Str::uuid(), 'state' => $source->state,
            'attempts' => $source->attempts, 'cycle_attempts' => $source->attempts,
            'available_at' => $source->availableAt ?? now(), 'deadline_at' => $source->deadline,
            'result_reference' => $source->result, 'created_at' => now(), 'updated_at' => now()];
    }

    public function runSource(string $owner, int $sourceId): string
    {
        $this->guard->assertAllowed($this->owner($owner)->operation());
        $id = DB::table('platform_recovery_work')->where('owner', $owner)->where('source_id', $sourceId)->value('id');
        if ($id === null) {
            $this->guard->transaction($this->owner($owner)->operation(), fn () => $this->adoptSource($owner, $sourceId));
            $id = DB::table('platform_recovery_work')->where('owner', $owner)->where('source_id', $sourceId)->value('id');
        }

        return $this->run((int) $id);
    }

    public function run(int $workId, ?string $replayRun = null): string
    {
        $lease = $this->claim($workId, $replayRun);
        if ($lease === null) {
            $work = DB::table('platform_recovery_work')->where('id', $workId)->first();
            if ($replayRun !== null && $work !== null && $work->replay_run_id !== $replayRun) {
                return 'replay_conflict';
            }

            return $work->state ?? 'missing';
        }

        return $this->execute($lease);
    }

    public function claim(int $workId, ?string $replayRun = null): ?RecoveryLease
    {
        $this->executionBoundary();
        $initial = DB::table('platform_recovery_work')->where('id', $workId)->firstOrFail();
        if (! in_array($initial->owner, self::OWNERS, true)) {
            return null;
        }

        return $this->guard->transaction($this->owner($initial->owner)->operation(), function () use ($workId, $initial, $replayRun): ?RecoveryLease {
            $runId = $replayRun ?? $initial->replay_run_id;
            if ($runId !== null) {
                $run = DB::table('platform_replay_runs')->where('id', $runId)->lockForUpdate()->first();
                if ($run === null || $run->state === 'stopped' || ($replayRun !== null && $run->state !== 'running')) {
                    return null;
                }
            }
            $work = DB::table('platform_recovery_work')->where('id', $workId)->lockForUpdate()->firstOrFail();
            if ($work->replay_run_id !== $runId) {
                return null;
            }
            if (in_array($work->state, ['succeeded', 'cancelled', 'dead_letter', 'outcome_unknown', 'failed'], true)) {
                return null;
            }
            if ($work->state === 'running' && CarbonImmutable::parse($work->lease_expires_at)->isFuture()) {
                return null;
            }
            $owner = $this->owner($work->owner);
            try {
                $source = $owner->snapshot((int) $work->source_id);
                $this->validate($work, $source);
            } catch (RecoveryConflict $exception) {
                $this->reject($work, $exception->getMessage());

                return null;
            }
            if (in_array($source->state, ['succeeded', 'cancelled'], true)) {
                $this->finish($work, $source->state, $source->result);
                if ($work->state === 'running') {
                    $this->attempt($work, 'finished', $source->state, result: $source->result);
                }

                return null;
            }
            if ($source->state === 'dead_letter') {
                $this->reject($work, 'owner_state_conflict');

                return null;
            }
            if ($work->state === 'running') {
                $this->attempt($work, 'finished', 'lease_expired', 'lease_expired');
            }
            if ($work->deadline_at !== null && CarbonImmutable::parse($work->deadline_at)->isPast()) {
                $this->reject($work, 'owner_deadline_expired');

                return null;
            }
            if (($source->availableAt !== null && CarbonImmutable::parse($source->availableAt)->isFuture())
                || ($work->available_at !== null && CarbonImmutable::parse($work->available_at)->isFuture())) {
                return null;
            }
            if ((int) $work->cycle_attempts >= (int) $work->max_attempts) {
                $this->reject($work, 'retry_budget_exhausted');

                return null;
            }
            $token = (int) $work->lease_token + 1;
            $leaseOwner = (string) Str::uuid();
            DB::table('platform_recovery_work')->where('id', $workId)->where('lease_token', $work->lease_token)->update([
                'state' => 'running', 'lease_token' => $token, 'lease_owner' => $leaseOwner,
                'lease_expires_at' => now()->addSeconds((int) $work->lease_seconds), 'heartbeat_at' => now(),
                'attempts' => (int) $work->attempts + 1, 'cycle_attempts' => (int) $work->cycle_attempts + 1,
                'failure_code' => null, 'updated_at' => now(),
            ]);
            $claimed = DB::table('platform_recovery_work')->where('id', $workId)->firstOrFail();
            $this->attempt($claimed, 'claimed', 'running');

            return new RecoveryLease($workId, $token, $leaseOwner);
        });
    }

    public function execute(RecoveryLease $lease): string
    {
        $this->executionBoundary();
        $initial = DB::table('platform_recovery_work')->where('id', $lease->workId)->firstOrFail();
        try {
            if ($initial->owner === 'financial_artifact') {
                $started = hrtime(true);
                $this->guard->transaction('derived', function () use ($lease): void {
                    $work = $this->leased($lease);
                    $this->validate($work, $this->owner($work->owner)->snapshot((int) $work->source_id));
                });
                app(FinancialArtifactService::class)->render((int) $initial->source_id, publicationFence: function () use ($lease, $started): void {
                    $work = $this->leased($lease);
                    if ((hrtime(true) - $started) / 1e9 >= (int) $work->timeout_seconds
                        || CarbonImmutable::parse($work->lease_expires_at)->subSeconds((int) $work->lease_seconds)->addSeconds((int) $work->timeout_seconds)->isPast()) {
                        throw new RecoveryConflict('execution_timeout');
                    }
                });

                return $this->guard->transaction('derived', function () use ($lease): string {
                    $work = $this->leased($lease);
                    $source = $this->owner($work->owner)->snapshot((int) $work->source_id);
                    $this->validate($work, $source);
                    if (! in_array($source->state, ['succeeded', 'cancelled'], true)) {
                        throw new RecoveryConflict('owner_result_unverified');
                    }
                    $this->finish($work, $source->state, $source->result);
                    $this->attempt($work, 'finished', $source->state, result: $source->result);

                    return $source->state;
                });
            }

            return $this->guard->transaction($this->owner($initial->owner)->operation(), function () use ($lease): string {
                $work = $this->leased($lease);
                $owner = $this->owner($work->owner);
                $source = $owner->snapshot((int) $work->source_id);
                $this->validate($work, $source);
                if (! in_array($source->state, ['succeeded', 'cancelled'], true)) {
                    if ($source->state !== 'queued') {
                        throw new RecoveryConflict('owner_state_conflict');
                    }
                    $started = hrtime(true);
                    $owner->execute((int) $work->source_id);
                    if ((hrtime(true) - $started) / 1e9 >= (int) $work->timeout_seconds) {
                        throw new RecoveryConflict('execution_deadline_exceeded');
                    }
                    $source = $owner->snapshot((int) $work->source_id);
                    $this->validate($work, $source);
                }
                if (! in_array($source->state, ['succeeded', 'cancelled'], true)) {
                    throw new RecoveryConflict('owner_result_unverified');
                }
                $this->finish($work, $source->state, $source->result);
                $this->attempt($work, 'finished', $source->state, result: $source->result);

                return $source->state;
            });
        } catch (PlatformBlocked $exception) {
            $this->pause($lease);
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof RecoveryConflict && $exception->getMessage() === 'stale_lease') {
                return 'stale_lease';
            }
            try {
                return $this->failure($lease, $exception);
            } catch (PlatformBlocked $blocked) {
                $this->pause($lease);
                throw $blocked;
            }
        }
    }

    public function heartbeat(RecoveryLease $lease): void
    {
        $work = DB::table('platform_recovery_work')->where('id', $lease->workId)->firstOrFail();
        $this->guard->transaction($this->owner($work->owner)->operation(), function () use ($lease): void {
            $work = $this->leased($lease);
            DB::table('platform_recovery_work')->where('id', $work->id)->update([
                'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds((int) $work->lease_seconds), 'updated_at' => now(),
            ]);
        });
    }

    private function leased(RecoveryLease $lease): stdClass
    {
        $work = DB::table('platform_recovery_work')->where('id', $lease->workId)->lockForUpdate()->firstOrFail();
        if ($work->state !== 'running' || (int) $work->lease_token !== $lease->token || $work->lease_owner !== $lease->owner
            || CarbonImmutable::parse($work->lease_expires_at)->isPast()) {
            throw new RecoveryConflict('stale_lease');
        }

        return $work;
    }

    private function failure(RecoveryLease $lease, Throwable $exception): string
    {
        $initial = DB::table('platform_recovery_work')->where('id', $lease->workId)->firstOrFail();

        return $this->guard->transaction($this->owner($initial->owner)->operation(), function () use ($lease, $exception): string {
            $work = DB::table('platform_recovery_work')->where('id', $lease->workId)->lockForUpdate()->firstOrFail();
            if ((int) $work->lease_token !== $lease->token || $work->state !== 'running' || $work->lease_owner !== $lease->owner) {
                return 'stale_lease';
            }
            $permanent = $exception instanceof RecoveryConflict || $exception instanceof InvalidArgumentException;
            $state = $permanent || (int) $work->cycle_attempts >= (int) $work->max_attempts ? 'dead_letter' : 'retry_scheduled';
            $code = $exception instanceof RecoveryConflict ? $exception->getMessage()
                : ($permanent ? 'invalid_contract' : ($state === 'dead_letter' ? 'retry_budget_exhausted' : 'local_delivery_failure'));
            $delay = min((int) $work->backoff_cap_seconds, (int) $work->backoff_seconds * (2 ** min(10, max(0, (int) $work->cycle_attempts - 1))));
            if ($work->owner === 'notification_inbox' && (int) $work->cycle_attempts >= 2) {
                $delay = max($delay, 120);
            }
            $available = $state === 'retry_scheduled' ? now()->addSeconds(min((int) $work->backoff_cap_seconds, $delay + random_int(0, (int) floor($delay * 0.2))))->toDateTimeString() : null;
            DB::table('platform_recovery_work')->where('id', $work->id)->update([
                'state' => $state, 'failure_code' => $code, 'available_at' => $available,
                'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            $this->owner($work->owner)->failed((int) $work->source_id, $state, $code, $available);
            $this->attempt($work, 'finished', $state, $code);

            return $state;
        });
    }

    private function pause(RecoveryLease $lease): void
    {
        DB::transaction(function () use ($lease): void {
            $work = DB::table('platform_recovery_work')->where('id', $lease->workId)->lockForUpdate()->firstOrFail();
            if ($work->state !== 'running' || (int) $work->lease_token !== $lease->token || $work->lease_owner !== $lease->owner) {
                return;
            }
            DB::table('platform_recovery_work')->where('id', $work->id)->update(['state' => 'queued',
                'cycle_attempts' => max(0, (int) $work->cycle_attempts - 1), 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            $this->attempt($work, 'finished', 'paused');
        });
    }

    private function validate(stdClass $work, RecoverySource $source): void
    {
        if ((int) $work->schema_version !== 1 || (int) $work->adapter_version !== 1
            || (int) $work->source_version !== $source->version || $work->operation_key !== $source->operationKey
            || ! hash_equals($work->payload_hash, $source->hash)) {
            throw new RecoveryConflict('source_identity_conflict');
        }
    }

    private function reject(stdClass $work, string $code): void
    {
        if ($code !== 'retry_budget_exhausted') {
            $work->attempts = (int) $work->attempts + 1;
            $work->cycle_attempts = (int) $work->cycle_attempts + 1;
            $work->lease_token = (int) $work->lease_token + 1;
        }
        DB::table('platform_recovery_work')->where('id', $work->id)->update(['state' => 'dead_letter', 'failure_code' => $code,
            'attempts' => $work->attempts, 'cycle_attempts' => $work->cycle_attempts, 'lease_token' => $work->lease_token,
            'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
        $this->owner($work->owner)->failed((int) $work->source_id, 'dead_letter', $code, null, $code !== 'retry_budget_exhausted');
        $this->attempt($work, 'rejected', 'dead_letter', $code);
    }

    private function finish(stdClass $work, string $state, ?string $result): void
    {
        DB::table('platform_recovery_work')->where('id', $work->id)->update(['state' => $state, 'result_reference' => $result,
            'checkpoint' => 'owner_result_committed', 'failure_code' => null, 'lease_owner' => null,
            'lease_expires_at' => null, 'available_at' => null, 'updated_at' => now()]);
    }

    private function attempt(stdClass $work, string $phase, string $outcome, ?string $code = null, ?string $result = null): void
    {
        DB::table('platform_recovery_attempts')->insertOrIgnore(['work_id' => $work->id,
            'lease_token' => $work->lease_token, 'attempt_number' => $work->attempts, 'phase' => $phase,
            'outcome' => $outcome, 'failure_code' => $code, 'result_reference' => $result,
            'replay_run_id' => $work->replay_run_id, 'created_at' => now()]);
    }

    public function restartArtifact(FinancialArtifact $artifact): void
    {
        $work = DB::table('platform_recovery_work')->where('owner', 'financial_artifact')->where('source_id', $artifact->id)->lockForUpdate()->first();
        if ($work === null) {
            $this->register('financial_artifact', $artifact->id);

            return;
        }
        DB::table('platform_recovery_work')->where('id', $work->id)->update(['state' => 'queued', 'cycle_attempts' => 0,
            'lease_token' => (int) $work->lease_token + 1, 'lease_owner' => null, 'lease_expires_at' => null,
            'result_reference' => null, 'failure_code' => null, 'available_at' => now(), 'updated_at' => now()]);
    }

    public function adopt(string $owner, int $limit = 100): int
    {
        $this->limit($limit);
        $adapter = $this->owner($owner);

        return $this->guard->transaction($adapter->operation(), function () use ($owner, $limit): int {
            $cursor = DB::table('platform_recovery_adoption')->where('owner', $owner)->lockForUpdate()->firstOrFail();
            [$table, $column] = match ($owner) {
                'audit_projection' => ['audit_projection_work', 'canonical_event_id'], 'financial_artifact' => ['financial_artifacts', 'id'], default => ['notification_inbox_intents', 'id']
            };
            $ids = DB::table($table)->where($column, '>', $cursor->cursor)->orderBy($column)->limit($limit)->pluck($column);
            foreach ($ids as $id) {
                $this->adoptSource($owner, (int) $id);
                DB::table('platform_recovery_adoption')->where('owner', $owner)->update(['cursor' => $id, 'updated_at' => now()]);
            }

            return $ids->count();
        });
    }

    private function adoptSource(string $owner, int $sourceId): void
    {
        if (DB::table('platform_recovery_work')->where('owner', $owner)->where('source_id', $sourceId)->exists()) {
            return;
        }
        try {
            $source = $this->owner($owner)->snapshot($sourceId);
            $id = $this->register($owner, $sourceId);
            $unverified = $owner === 'audit_projection' && $source->attempts > 0 && $source->state !== 'succeeded';
            if ($owner === 'notification_inbox' && $source->attempts > 0) {
                $recorded = DB::table('notification_inbox_attempts')->where('intent_id', $sourceId);
                $unverified = $recorded->count() !== $source->attempts || (int) $recorded->max('attempt_number') !== $source->attempts;
            }
            if ($unverified) {
                DB::table('platform_recovery_work')->where('id', $id)->update(['state' => 'dead_letter', 'failure_code' => 'historical_outcome_unverified']);
            }
        } catch (RecoveryConflict) {
            $table = match ($owner) {
                'audit_projection' => 'audit_projection_work', 'financial_artifact' => 'financial_artifacts', default => 'notification_inbox_intents'
            };
            $column = $owner === 'audit_projection' ? 'canonical_event_id' : 'id';
            $attempts = $owner === 'financial_artifact' ? 0 : (DB::table($table)->where($column, $sourceId)->value($owner === 'audit_projection' ? 'attempts' : 'attempt_count') ?? 0);
            $source = new RecoverySource(1, hash('sha256', 'unverified:'.$owner.':'.$sourceId), 'unverified:'.$owner.':'.$sourceId, 'dead_letter', (int) $attempts);
            DB::table('platform_recovery_work')->insertOrIgnore([...$this->registration($owner, $sourceId, $source), 'failure_code' => 'historical_outcome_unverified']);
        }
    }

    public function drain(string $owner, int $limit = 100): int
    {
        $this->limit($limit);
        $this->guard->assertAllowed($this->owner($owner)->operation());
        $this->adopt($owner, $limit);
        $ids = $this->due($owner, $limit);
        $count = 0;
        foreach ($ids as $id) {
            if (in_array($this->run((int) $id), ['succeeded', 'cancelled'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return Collection<int, mixed> */
    private function due(string $owner, int $limit): Collection
    {
        return DB::table('platform_recovery_work')->where('owner', $owner)->where(function ($query): void {
            $query->whereIn('state', ['queued', 'retry_scheduled'])->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('state', 'running')->where('lease_expires_at', '<=', now()));
        })->orderBy('id')->limit($limit)->pluck('id');
    }

    public function dispatchNotifications(int $limit = 100): void
    {
        $this->limit($limit);
        $this->guard->assertAllowed('external');
        $this->adopt('notification_inbox', $limit);
        foreach ($this->due('notification_inbox', $limit) as $id) {
            $work = DB::table('platform_recovery_work')->where('id', $id)->firstOrFail();
            $source = DB::table('notification_inbox_intents')->where('id', $work->source_id)->first();
            if ($source !== null && ($source->next_attempt_at === null || ! CarbonImmutable::parse($source->next_attempt_at)->isFuture())) {
                $this->guard->transaction('external', function () use ($work): void {
                    MaterializeNotificationIntent::dispatch((int) $work->source_id)->afterCommit();
                });
            }
        }
    }

    public function reconcileExternal(int $workId): ExternalOutcome
    {
        return $this->guard->transaction('external', function () use ($workId): ExternalOutcome {
            $work = DB::table('platform_recovery_work')->where('id', $workId)->lockForUpdate()->firstOrFail();
            if ($work->state !== 'outcome_unknown' || in_array($work->owner, self::OWNERS, true)) {
                throw new RecoveryConflict('not_external_unknown');
            }
            $work->lease_token = (int) $work->lease_token + 1;
            $outcome = $this->external->lookup($work->owner, $work->operation_key, $work->payload_hash);
            DB::table('platform_recovery_work')->where('id', $workId)->update([
                'state' => $outcome === ExternalOutcome::ConfirmedSuccess ? 'succeeded' : ($outcome === ExternalOutcome::SafeToRetry ? 'failed' : 'outcome_unknown'),
                'result_reference' => $outcome === ExternalOutcome::ConfirmedSuccess ? $work->operation_key : null,
                'lease_token' => $work->lease_token,
                'failure_code' => $outcome === ExternalOutcome::SafeToRetry ? 'provider_confirmed_safe_to_retry' : ($outcome === ExternalOutcome::Unknown ? 'provider_outcome_unverified' : null),
                'updated_at' => now(),
            ]);
            $this->attempt($work, 'reconciled', $outcome->value);

            return $outcome;
        });
    }

    private function executionBoundary(): void
    {
        if (DB::transactionLevel() > (app()->runningUnitTests() ? 1 : 0)) {
            throw new \LogicException('Recovery must execute after the owning transaction commits.');
        }
    }

    public function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Choose a limit from 1 to 1000.');
        }
    }
}
