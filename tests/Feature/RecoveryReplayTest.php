<?php

use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Services\BackgroundRecovery;
use App\Services\RecoveryReplay;
use App\Support\RecoveryConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
afterEach(function (): void {
    Sleep::fake(false);
});

function recoveryReplayInput(array $overrides = []): array
{
    return [...['operation' => (string) Str::uuid(), 'operator' => 'external-ops', 'reason' => 'Retry after verified repair',
        'incident' => 'INC-recovery', 'run' => null, 'digest' => null, 'owner' => 'audit_projection', 'ids' => null, 'limit' => 100], ...$overrides];
}

function replayAuditWork(): stdClass
{
    AuditEvent::record('customer.status_changed', 'customer', null, null, ['to_status' => 'inactive']);

    return DB::table('platform_recovery_work')->where('owner', 'audit_projection')->orderByDesc('id')->first();
}

/** @return array<string, mixed> */
function approvedRecoveryRun(array $ids): array
{
    $replay = app(RecoveryReplay::class);
    $plan = $replay->command('plan', recoveryReplayInput(['ids' => $ids]));
    $replay->command('approve', recoveryReplayInput(['run' => $plan['run'], 'digest' => $plan['digest']]));
    $replay->command('execute', recoveryReplayInput(['run' => $plan['run']]));

    return $plan;
}

test('dry run freezes explicit IDs hashes counts and samples without executing work', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = app(RecoveryReplay::class)->command('plan', recoveryReplayInput(['ids' => [$work->id]]));

    expect($plan['counts'])->toBe(['eligible' => 1]);
    expect($plan['sample'][0]['work_id'])->toBe((int) $work->id);
    $this->assertDatabaseCount('audit_search_documents', 0);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 0]);
    $this->assertDatabaseHas('platform_replay_manifests', ['id' => $plan['run'], 'digest' => $plan['digest']]);
    expect(AuditEvent::query()->where('event_type', 'platform.replay_plan')->sole()->payload)->not->toHaveKeys(['operator', 'incident', 'reason']);
    Queue::assertPushed(ProjectAuditEvent::class, 2);
});

test('execution requires a separately recorded approval of the exact manifest digest', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $replay = app(RecoveryReplay::class);
    $plan = $replay->command('plan', recoveryReplayInput(['ids' => [$work->id]]));

    expect(fn () => $replay->command('execute', recoveryReplayInput(['run' => $plan['run']])))->toThrow(RecoveryConflict::class, 'matching_approval_required');
    expect(fn () => $replay->command('approve', recoveryReplayInput(['run' => $plan['run'], 'digest' => str_repeat('0', 64)])))->toThrow(RecoveryConflict::class, 'manifest_digest_conflict');
    $this->assertDatabaseCount('platform_replay_approvals', 0);
    $this->assertDatabaseCount('audit_search_documents', 0);
    Queue::assertPushed(ProjectAuditEvent::class, 2);
});

test('repeated operator intent returns the original result and changed input conflicts', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $input = recoveryReplayInput(['ids' => [$work->id]]);
    $replay = app(RecoveryReplay::class);
    $first = $replay->command('plan', $input);

    expect($replay->command('plan', $input))->toBe($first);
    expect(fn () => $replay->command('plan', [...$input, 'reason' => 'Changed intent']))->toThrow(RecoveryConflict::class, 'operation_input_conflict');
    $this->assertDatabaseCount('platform_replay_manifests', 1);
    $this->assertDatabaseCount('platform_recovery_operations', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 2);
});

test('approved replay checkpoints each effect and duplicate execution skips committed work', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = approvedRecoveryRun([$work->id]);
    $replay = app(RecoveryReplay::class);

    expect($replay->execute($plan['run']))->toMatchArray(['state' => 'completed', 'cursor' => 1]);
    expect($replay->execute($plan['run']))->toMatchArray(['state' => 'completed', 'cursor' => 1]);
    $this->assertDatabaseCount('audit_search_documents', 1);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'state' => 'succeeded', 'attempts' => 1]);
    $this->assertDatabaseCount('platform_replay_progress', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('completed owner effects are skipped even when completion happens after the manifest', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = approvedRecoveryRun([$work->id]);
    app(BackgroundRecovery::class)->run((int) $work->id);

    app(RecoveryReplay::class)->execute($plan['run']);
    $this->assertDatabaseHas('platform_replay_progress', ['run_id' => $plan['run'], 'outcome' => 'completed']);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 1]);
    $this->assertDatabaseCount('audit_search_documents', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('changed canonical contracts conflict during replay without consuming the source operation', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = approvedRecoveryRun([$work->id]);
    DB::statement('DROP TRIGGER canonical_audit_events_no_update');
    DB::table('canonical_audit_events')->where('id', $work->source_id)->update(['schema_version' => 99]);

    app(RecoveryReplay::class)->execute($plan['run']);
    $this->assertDatabaseHas('platform_replay_progress', ['run_id' => $plan['run'], 'outcome' => 'conflict']);
    $this->assertDatabaseCount('audit_search_documents', 0);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 0]);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('stopping and resuming a bounded replay preserves completed checkpoints and the rate limit', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $first = replayAuditWork();
    $second = replayAuditWork();
    $plan = approvedRecoveryRun([$first->id, $second->id]);
    $replay = app(RecoveryReplay::class);
    expect($replay->execute($plan['run'], 1)['cursor'])->toBe(1);
    $replay->command('stop', recoveryReplayInput(['run' => $plan['run']]));
    expect($replay->execute($plan['run'])['state'])->toBe('stopped');
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $second->id, 'attempts' => 0]);
    $replay->command('resume', recoveryReplayInput(['run' => $plan['run']]));

    expect($replay->execute($plan['run']))->toMatchArray(['state' => 'completed', 'cursor' => 2]);
    $this->assertDatabaseCount('platform_replay_progress', 2);
    $this->assertDatabaseCount('audit_search_documents', 2);
    Sleep::assertSleptTimes(1);
    Queue::assertPushed(ProjectAuditEvent::class, 7);
});

test('approved replay adds one bounded cycle without erasing historical attempts', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    DB::statement("CREATE TRIGGER replay_projection_outage BEFORE INSERT ON audit_search_documents BEGIN SELECT RAISE(ABORT, 'Projection offline'); END");
    $recovery = app(BackgroundRecovery::class);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $recovery->run((int) $work->id);
        $this->travel(5)->minutes();
    }
    $plan = approvedRecoveryRun([$work->id]);
    app(RecoveryReplay::class)->execute($plan['run']);
    expect(DB::table('platform_recovery_work')->where('id', $work->id)->value('attempts'))->toBe(4);
    for ($attempt = 5; $attempt <= 6; $attempt++) {
        $this->travel(5)->minutes();
        $this->artisan('audit:drain')->assertSuccessful();
    }
    $this->travel(1)->days();
    $this->artisan('audit:drain')->assertSuccessful();

    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 6, 'cycle_attempts' => 3, 'state' => 'dead_letter']);
    expect(DB::table('platform_recovery_attempts')->where('work_id', $work->id)->where('phase', 'finished')->count())->toBe(6);
    DB::statement('DROP TRIGGER replay_projection_outage');
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('permanent dead letters never become eligible through replay approval', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    DB::table('platform_recovery_work')->where('id', $work->id)->update(['state' => 'dead_letter', 'failure_code' => 'source_integrity_conflict']);
    $plan = approvedRecoveryRun([$work->id]);

    app(RecoveryReplay::class)->execute($plan['run']);
    $this->assertDatabaseHas('platform_replay_progress', ['run_id' => $plan['run'], 'outcome' => 'blocked']);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 0, 'state' => 'dead_letter']);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('replay manifests approvals progress and operation results are immutable', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = approvedRecoveryRun([$work->id]);
    app(RecoveryReplay::class)->execute($plan['run']);

    foreach (['platform_replay_manifests', 'platform_replay_approvals', 'platform_replay_progress', 'platform_recovery_operations'] as $table) {
        expect(fn () => DB::table($table)->delete())->toThrow(QueryException::class);
    }
    expect(fn () => DB::table('platform_replay_manifests')->update(['digest' => str_repeat('0', 64)]))->toThrow(QueryException::class);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('a failed canonical operator audit rolls back the replay manifest and result', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    DB::statement("CREATE TRIGGER replay_audit_outage BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type LIKE 'platform.replay_%' BEGIN SELECT RAISE(ABORT, 'Audit offline'); END");

    expect(fn () => app(RecoveryReplay::class)->command('plan', recoveryReplayInput(['ids' => [$work->id]])))->toThrow(QueryException::class);
    $this->assertDatabaseCount('platform_replay_manifests', 0);
    $this->assertDatabaseCount('platform_recovery_operations', 0);
    DB::statement('DROP TRIGGER replay_audit_outage');
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('explicit selections cannot be silently truncated or include absent work', function (array $selection, int $limit) {
    Queue::fake([ProjectAuditEvent::class]);
    replayAuditWork();
    replayAuditWork();

    expect(fn () => app(RecoveryReplay::class)->command('plan', recoveryReplayInput(['ids' => $selection, 'limit' => $limit])))->toThrow(RecoveryConflict::class, 'selection_incomplete');
    $this->assertDatabaseCount('platform_replay_manifests', 0);
    Queue::assertPushed(ProjectAuditEvent::class, 2);
})->with([[[1, 2], 1], [[1, 999], 100]]);

test('Artisan replay rejects missing evidence and accepts the full plan approval execution workflow', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $operation = (string) Str::uuid();
    $this->artisan('platform:replay', ['action' => 'plan', '--operation' => $operation, '--ids' => (string) $work->id])->assertFailed();
    $options = ['--operator' => 'ops-test', '--reason' => 'Verified repair', '--incident' => 'INC-test', '--json' => true];
    $this->artisan('platform:replay', ['action' => 'plan', '--operation' => $operation, '--ids' => (string) $work->id, ...$options])->assertSuccessful();
    $digest = DB::table('platform_replay_manifests')->where('id', $operation)->value('digest');
    $this->artisan('platform:replay', ['action' => 'approve', '--operation' => (string) Str::uuid(), '--run' => $operation, '--digest' => $digest, ...$options])->assertSuccessful();
    $this->artisan('platform:replay', ['action' => 'execute', '--operation' => (string) Str::uuid(), '--run' => $operation, ...$options])->assertSuccessful();

    $this->assertDatabaseHas('platform_replay_runs', ['id' => $operation, 'state' => 'completed', 'cursor' => 1]);
    $this->assertDatabaseCount('audit_search_documents', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('an interrupted run recovers its already granted cycle without granting another budget', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = approvedRecoveryRun([$work->id]);
    DB::table('platform_recovery_work')->where('id', $work->id)->update(['replay_run_id' => $plan['run']]);
    $lease = app(BackgroundRecovery::class)->claim((int) $work->id);
    DB::table('platform_recovery_work')->where('id', $work->id)->update(['lease_expires_at' => now()->subSecond()]);

    app(RecoveryReplay::class)->execute($plan['run']);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 2, 'cycle_attempts' => 2, 'state' => 'succeeded']);
    expect(app(BackgroundRecovery::class)->execute($lease))->toBe('stale_lease');
    $this->assertDatabaseCount('platform_replay_progress', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 4);
});

test('stopped replay work cannot execute through the scheduled recovery path before resume', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $plan = approvedRecoveryRun([$work->id]);
    DB::table('platform_recovery_work')->where('id', $work->id)->update(['replay_run_id' => $plan['run']]);
    $replay = app(RecoveryReplay::class);
    $replay->command('stop', recoveryReplayInput(['run' => $plan['run']]));

    expect(app(BackgroundRecovery::class)->run((int) $work->id))->toBe('queued');
    $this->assertDatabaseCount('audit_search_documents', 0);
    $replay->command('resume', recoveryReplayInput(['run' => $plan['run']]));
    expect($replay->execute($plan['run'])['state'])->toBe('completed');
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 1]);
    Queue::assertPushed(ProjectAuditEvent::class, 6);
});

test('overlapping replay selections cannot take over an unfinished approved cycle', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = replayAuditWork();
    $first = approvedRecoveryRun([$work->id]);
    $second = approvedRecoveryRun([$work->id]);
    DB::table('platform_recovery_work')->where('id', $work->id)->update(['replay_run_id' => $first['run']]);

    app(RecoveryReplay::class)->execute($second['run']);
    $this->assertDatabaseHas('platform_replay_progress', ['run_id' => $second['run'], 'outcome' => 'blocked']);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 0, 'replay_run_id' => $first['run']]);
    app(RecoveryReplay::class)->execute($first['run']);
    $this->assertDatabaseCount('audit_search_documents', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 7);
});

test('equivalent normalized operator inputs resolve the same dry run across CLI and typed callers', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $first = replayAuditWork();
    $second = replayAuditWork();
    $input = recoveryReplayInput(['ids' => [(int) $first->id, (int) $second->id]]);
    $replay = app(RecoveryReplay::class);
    $plan = $replay->command('plan', $input);

    expect($replay->command('plan', [...$input, 'operation' => strtoupper($input['operation']), 'limit' => '100',
        'ids' => [(string) $second->id, (string) $first->id]]))->toBe($plan);
    $this->assertDatabaseCount('platform_replay_manifests', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 3);
});
