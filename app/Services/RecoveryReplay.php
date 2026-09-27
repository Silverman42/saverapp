<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\RecoveryConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use stdClass;

class RecoveryReplay
{
    public function __construct(private BackgroundRecovery $recovery, private PlatformGuard $guard) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function command(string $action, array $input): array
    {
        $input = Validator::make($input, [
            'operation' => ['required', 'uuid'], 'operator' => ['required', 'string', 'max:200', 'regex:/\A[^\x00-\x1F\x7F]+\z/'],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\A[^\x00-\x1F\x7F]+\z/'],
            'incident' => ['required', 'string', 'max:200', 'regex:/\A[^\x00-\x1F\x7F]+\z/'],
            'run' => ['nullable', 'uuid'], 'digest' => ['nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]+\z/'],
            'owner' => ['nullable', 'string', 'in:'.implode(',', BackgroundRecovery::OWNERS)],
            'ids' => ['nullable', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer', 'min:1', 'distinct'],
            'limit' => ['required', 'integer', 'min:1', 'max:1000'],
        ])->validate();
        $input = ['run' => null, 'digest' => null, 'owner' => null, 'ids' => null, ...$input];
        $input['limit'] = (int) $input['limit'];
        $input['operation'] = strtolower($input['operation']);
        if ($input['run'] !== null) {
            $input['run'] = strtolower($input['run']);
        }
        if ($input['ids'] !== null) {
            $input['ids'] = array_map(intval(...), $input['ids']);
            sort($input['ids'], SORT_NUMERIC);
        }
        if (! in_array($action, ['plan', 'approve', 'execute', 'stop', 'resume'], true)) {
            throw new RecoveryConflict('unsupported_replay_action');
        }
        if ($action !== 'plan' && empty($input['run'])) {
            throw new RecoveryConflict('run_required');
        }
        if ($action === 'approve' && empty($input['digest'])) {
            throw new RecoveryConflict('approval_digest_required');
        }
        $hash = AuditProjection::digest(['action' => $action, 'input' => $input]);
        $existing = $this->original($input['operation'], $hash);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $result = $this->guard->transaction('mutation', function () use ($action, $input, $hash): array {
                if ($action !== 'plan') {
                    DB::table('platform_replay_runs')->where('id', $input['run'])->lockForUpdate()->firstOrFail();
                }
                $original = $this->original($input['operation'], $hash);
                if ($original !== null) {
                    return $original;
                }
                $result = match ($action) {
                    'plan' => $this->plan($input),
                    'approve' => $this->approve($input),
                    'execute', 'resume' => $this->start($input),
                    'stop' => $this->stop($input),
                };
                $audit = AuditEvent::record('platform.replay_'.$action, 'platform_replay', null, $result['run'],
                    ['run_id' => $result['run'], 'manifest_digest' => $result['digest'], 'outcome' => $result['state'],
                        'operator' => $input['operator'], 'reason' => $input['reason'], 'incident' => $input['incident']],
                    null, ['executor' => self::class, 'operation_id' => 'replay:'.$input['operation'], 'authority_evidence' => 'external_operator_reference']);
                DB::table('platform_recovery_operations')->insert(['operation_id' => $input['operation'], 'input_hash' => $hash,
                    'result' => json_encode($result, JSON_THROW_ON_ERROR), 'audit_event_id' => $audit->id, 'created_at' => now()]);

                return $result;
            });
        } catch (QueryException $exception) {
            $original = $this->original($input['operation'], $hash);
            if ($original === null) {
                throw $exception;
            }
            $result = $original;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function original(string $operation, string $hash): ?array
    {
        $original = DB::table('platform_recovery_operations')->where('operation_id', $operation)->first();
        if ($original === null) {
            return null;
        }
        if (! hash_equals($original->input_hash, $hash)) {
            throw new RecoveryConflict('operation_input_conflict');
        }

        return json_decode($original->result, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function plan(array $input): array
    {
        $query = DB::table('platform_recovery_work')->orderBy('id');
        if (! empty($input['ids'])) {
            $query->whereIn('id', $input['ids']);
        } else {
            $query->whereIn('state', ['queued', 'retry_scheduled', 'dead_letter', 'outcome_unknown']);
        }
        if (! empty($input['owner'])) {
            $query->where('owner', $input['owner']);
        }
        $works = $query->limit((int) $input['limit'])->get();
        if (! empty($input['ids']) && $works->count() !== count($input['ids'])) {
            throw new RecoveryConflict('selection_incomplete');
        }
        $items = [];
        $counts = [];
        foreach ($works as $work) {
            $classification = $this->classify($work);
            $items[] = ['work_id' => (int) $work->id, 'owner' => $work->owner, 'source_version' => (int) $work->source_version,
                'payload_hash' => $work->payload_hash, 'adapter_version' => (int) $work->adapter_version,
                'classification' => $classification];
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        }
        $manifest = ['version' => 1, 'run_id' => $input['operation'], 'rate_per_second' => 1, 'items' => $items, 'counts' => $counts];
        $digest = AuditProjection::digest($manifest);
        DB::table('platform_replay_manifests')->insert(['id' => $input['operation'], 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'digest' => $digest, 'evidence' => Crypt::encryptString(json_encode($input, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
        DB::table('platform_replay_runs')->insert(['id' => $input['operation'], 'state' => 'planned', 'created_at' => now(), 'updated_at' => now()]);

        return ['run' => $input['operation'], 'digest' => $digest, 'state' => 'planned', 'counts' => $counts, 'sample' => array_slice($items, 0, 10)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function approve(array $input): array
    {
        $manifest = $this->manifest($input['run']);
        if (! hash_equals($manifest['digest'], $input['digest'])) {
            throw new RecoveryConflict('manifest_digest_conflict');
        }
        if (DB::table('platform_replay_approvals')->where('run_id', $input['run'])->exists()) {
            throw new RecoveryConflict('run_already_approved');
        }
        DB::table('platform_replay_approvals')->insert(['operation_id' => $input['operation'], 'run_id' => $input['run'],
            'digest' => $input['digest'], 'evidence' => Crypt::encryptString(json_encode($input, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
        DB::table('platform_replay_runs')->where('id', $input['run'])->update(['state' => 'approved', 'updated_at' => now()]);

        return ['run' => $input['run'], 'digest' => $manifest['digest'], 'state' => 'approved'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function start(array $input): array
    {
        $manifest = $this->manifest($input['run']);
        $this->approval($input['run'], $manifest['digest']);
        $state = DB::table('platform_replay_runs')->where('id', $input['run'])->value('state');
        if ($state !== 'completed') {
            DB::table('platform_replay_runs')->where('id', $input['run'])->update(['state' => 'running', 'updated_at' => now()]);
        }

        return ['run' => $input['run'], 'digest' => $manifest['digest'], 'state' => $state === 'completed' ? 'completed' : 'running'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function stop(array $input): array
    {
        $manifest = $this->manifest($input['run']);
        $state = DB::table('platform_replay_runs')->where('id', $input['run'])->value('state');
        if ($state !== 'completed') {
            DB::table('platform_replay_runs')->where('id', $input['run'])->update(['state' => 'stopped', 'updated_at' => now()]);
        }

        return ['run' => $input['run'], 'digest' => $manifest['digest'], 'state' => $state === 'completed' ? 'completed' : 'stopped'];
    }

    public function classify(stdClass $work, ?string $currentRun = null): string
    {
        if (! in_array($work->owner, BackgroundRecovery::OWNERS, true) || $work->state === 'outcome_unknown') {
            return 'blocked';
        }
        try {
            $source = $this->recovery->owner($work->owner)->snapshot((int) $work->source_id);
            if ((int) $work->adapter_version !== 1 || (int) $work->schema_version !== 1 || ! hash_equals($work->payload_hash, $source->hash)
                || (int) $work->source_version !== $source->version || $work->operation_key !== $source->operationKey) {
                return 'conflict';
            }
            if (in_array($source->state, ['succeeded', 'cancelled'], true)) {
                return 'completed';
            }
            if ($work->replay_run_id !== null && $work->replay_run_id !== $currentRun
                && DB::table('platform_replay_runs')->where('id', $work->replay_run_id)->value('state') !== 'completed') {
                return 'blocked';
            }
            if (! $source->eligible) {
                return 'blocked';
            }
            if ($work->deadline_at !== null && CarbonImmutable::parse($work->deadline_at)->isPast()) {
                return 'blocked';
            }
            if ($work->state === 'dead_letter') {
                return $work->failure_code === 'retry_budget_exhausted' ? 'eligible' : 'blocked';
            }
            if ($source->state !== 'queued' || ! in_array($work->state, ['queued', 'retry_scheduled'], true)) {
                return 'blocked';
            }
            if ($work->available_at !== null && CarbonImmutable::parse($work->available_at)->isFuture()) {
                return 'blocked';
            }

            return 'eligible';
        } catch (RecoveryConflict) {
            return 'conflict';
        }
    }

    /** @return array{digest: string, items: list<array<string, mixed>>} */
    private function manifest(string $run): array
    {
        $stored = DB::table('platform_replay_manifests')->where('id', $run)->firstOrFail();
        $manifest = json_decode($stored->manifest, true, flags: JSON_THROW_ON_ERROR);
        if ((int) $stored->version !== 1 || ! hash_equals($stored->digest, AuditProjection::digest($manifest))) {
            throw new RecoveryConflict('manifest_integrity_conflict');
        }

        return ['digest' => $stored->digest, 'items' => $manifest['items']];
    }

    private function approval(string $run, string $digest): void
    {
        $approval = DB::table('platform_replay_approvals')->where('run_id', $run)->first();
        if ($approval === null || ! hash_equals($digest, $approval->digest)) {
            throw new RecoveryConflict('matching_approval_required');
        }
    }

    /** @return array<string, mixed> */
    public function execute(string $run, int $limit = 100): array
    {
        $this->recovery->limit($limit);
        for ($processed = 0; $processed < $limit;) {
            $step = $this->prepare($run);
            if ($step['action'] === 'stop') {
                break;
            }
            if ($step['action'] === 'wait') {
                Sleep::for(1)->seconds();

                continue;
            }
            $outcome = $step['outcome'];
            if ($step['action'] === 'execute') {
                $state = DB::table('platform_replay_runs')->where('id', $run)->value('state');
                if ($state !== 'running') {
                    break;
                }
                $outcome = $this->recovery->run((int) $step['work_id'], $run);
                if ($outcome === 'queued' && DB::table('platform_replay_runs')->where('id', $run)->value('state') !== 'running') {
                    break;
                }
                if ($outcome === 'running') {
                    Sleep::for(1)->seconds();

                    continue;
                }
            }
            $this->guard->transaction('mutation', function () use ($run, $step, $outcome): void {
                $progress = DB::table('platform_replay_runs')->where('id', $run)->lockForUpdate()->firstOrFail();
                if ((int) $progress->cursor !== $step['index']) {
                    return;
                }
                DB::table('platform_replay_progress')->insertOrIgnore(['run_id' => $run, 'item_index' => $step['index'],
                    'work_id' => $step['work_id'], 'outcome' => $outcome, 'created_at' => now()]);
                DB::table('platform_replay_runs')->where('id', $run)->update(['cursor' => $step['index'] + 1,
                    'state' => count($this->manifest($run)['items']) <= $step['index'] + 1 ? 'completed' : $progress->state, 'updated_at' => now()]);
            });
            $processed++;
        }
        $progress = DB::table('platform_replay_runs')->where('id', $run)->firstOrFail();

        return ['run' => $run, 'state' => $progress->state, 'cursor' => (int) $progress->cursor];
    }

    /** @return array{action: string, outcome: string, index: int, work_id: int} */
    private function prepare(string $run): array
    {
        return $this->guard->transaction('mutation', function () use ($run): array {
            $progress = DB::table('platform_replay_runs')->where('id', $run)->lockForUpdate()->firstOrFail();
            $manifest = $this->manifest($run);
            $this->approval($run, $manifest['digest']);
            $step = ['action' => 'stop', 'outcome' => '', 'index' => (int) $progress->cursor, 'work_id' => 0];
            if ($progress->state !== 'running') {
                return $step;
            }
            if ((int) $progress->cursor >= count($manifest['items'])) {
                DB::table('platform_replay_runs')->where('id', $run)->update(['state' => 'completed', 'updated_at' => now()]);

                return $step;
            }
            if ($progress->next_item_at !== null && CarbonImmutable::parse($progress->next_item_at)->isFuture()) {
                return [...$step, 'action' => 'wait'];
            }
            $item = $manifest['items'][(int) $progress->cursor];
            $work = DB::table('platform_recovery_work')->where('id', $item['work_id'])->lockForUpdate()->firstOrFail();
            $classification = $this->classify($work, $run);
            $step['work_id'] = (int) $work->id;
            DB::table('platform_replay_runs')->where('id', $run)->update(['next_item_at' => now()->addSecond()->format('Y-m-d H:i:s.u'), 'updated_at' => now()]);
            if ($item['payload_hash'] !== $work->payload_hash || $item['source_version'] !== (int) $work->source_version
                || $item['adapter_version'] !== (int) $work->adapter_version) {
                return [...$step, 'action' => 'record', 'outcome' => 'conflict'];
            }
            if ($work->replay_run_id === $run && $work->state === 'running') {
                return [...$step, 'action' => 'execute'];
            }
            if ($classification === 'completed') {
                return [...$step, 'action' => 'record', 'outcome' => 'completed'];
            }
            if ($classification !== 'eligible' || $item['classification'] !== 'eligible') {
                return [...$step, 'action' => 'record', 'outcome' => $classification === 'eligible' ? 'blocked' : $classification];
            }
            if ($work->replay_run_id !== $run) {
                DB::table('platform_recovery_work')->where('id', $work->id)->update([
                    'cycle_attempts' => 0, 'state' => 'queued', 'failure_code' => null, 'replay_run_id' => $run, 'available_at' => now(), 'updated_at' => now(),
                ]);
                $this->recovery->owner($work->owner)->failed((int) $work->source_id, 'retry_scheduled', 'approved_replay', now()->toDateTimeString(), false);
            }

            return [...$step, 'action' => 'execute'];
        });
    }
}
