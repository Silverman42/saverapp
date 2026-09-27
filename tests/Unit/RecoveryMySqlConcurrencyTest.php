<?php

use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Services\AuditProjection;
use App\Services\BackgroundRecovery;
use App\Services\PlatformState;
use App\Services\RecoveryReplay;
use App\Support\PlatformBlocked;
use App\Support\RecoveryLease;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function mysqlRecoveryWork(): int
{
    Queue::fake([ProjectAuditEvent::class]);
    AuditEvent::record('customer.status_changed', 'customer', null, null, ['to_status' => 'inactive']);
    Queue::assertPushed(ProjectAuditEvent::class, 1);

    return (int) DB::table('platform_recovery_work')->value('id');
}

function mysqlRecoveryTask(int $id, string $action = 'claim', ?RecoveryLease $lease = null): Closure
{
    return static function () use ($id, $action, $lease): array|string|null {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe recovery test database.');
        }
        $recovery = app(BackgroundRecovery::class);
        if ($action === 'execute') {
            return $recovery->execute($lease);
        }
        if ($action === 'run') {
            return $recovery->run($id);
        }
        $claimed = $recovery->claim($id);

        return $claimed === null ? null : ['id' => $claimed->workId, 'token' => $claimed->token, 'owner' => $claimed->owner];
    };
}

function startRecoveryCrashProcess(int $id, string $marker, string $phase): Process
{
    $task = static function () use ($id, $marker, $phase): void {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe recovery crash database.');
        }
        if ($phase === 'during_effect') {
            app()->instance(AuditProjection::class, new class($marker) extends AuditProjection
            {
                public function __construct(private string $marker) {}

                public function projectOwned(int $id): void
                {
                    parent::projectOwned($id);
                    file_put_contents($this->marker, 'effect_pending_commit');
                    $deadline = microtime(true) + 10;
                    while (microtime(true) < $deadline) {
                        usleep(10_000);
                    }
                }
            });
        }
        $recovery = app(BackgroundRecovery::class);
        $lease = $recovery->claim($id);
        if ($phase === 'after_claim') {
            file_put_contents($marker, 'claim_committed');
        } else {
            $recovery->execute($lease);
            file_put_contents($marker, 'result_committed');
        }
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            usleep(10_000);
        }
    };
    $process = new Process([PHP_BINARY, base_path('artisan'), 'invoke-serialized-closure'], base_path(),
        ['LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task)))]);
    $process->start();

    return $process;
}

function awaitRecoveryMarker(Process $process, string $marker): void
{
    $deadline = microtime(true) + 10;
    while (! file_exists($marker)) {
        if (! $process->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('Recovery process failed before the crash checkpoint: '.$process->getErrorOutput());
        }
        usleep(10_000);
    }
}

test('mysql competing processes acquire one committed recovery lease', function () {
    $id = mysqlRecoveryWork();
    $results = Concurrency::driver('process')->run([mysqlRecoveryTask($id), mysqlRecoveryTask($id)]);

    expect(collect($results)->filter(fn ($result) => is_array($result)))->toHaveCount(1);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'state' => 'running', 'attempts' => 1, 'lease_token' => 1]);
    $this->assertDatabaseCount('platform_recovery_attempts', 1);
});

test('mysql expired leases fence a stale process and commit exactly one effect', function () {
    $id = mysqlRecoveryWork();
    $first = app(BackgroundRecovery::class)->claim($id);
    DB::table('platform_recovery_work')->where('id', $id)->update(['lease_expires_at' => now()->subSecond()]);
    $second = app(BackgroundRecovery::class)->claim($id);
    $results = Concurrency::driver('process')->run([mysqlRecoveryTask($id, 'execute', $first), mysqlRecoveryTask($id, 'execute', $second)]);

    expect($results)->toBe(['stale_lease', 'succeeded']);
    $this->assertDatabaseCount('audit_search_documents', 1);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'attempts' => 2, 'lease_token' => 2, 'state' => 'succeeded']);
});

test('mysql killed workers retain committed claims and recover without duplicate effects', function (string $phase) {
    $id = mysqlRecoveryWork();
    $marker = sys_get_temp_dir().'/recovery-crash-'.Str::uuid();
    $process = startRecoveryCrashProcess($id, $marker, $phase);
    try {
        awaitRecoveryMarker($process, $marker);
        $process->signal(9);
        $process->wait();
        if ($phase === 'after_result') {
            $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'state' => 'succeeded']);
        } else {
            $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'state' => 'running']);
            $this->assertDatabaseCount('audit_search_documents', 0);
            DB::table('platform_recovery_work')->where('id', $id)->update(['lease_expires_at' => now()->subSecond()]);
        }
        expect(app(BackgroundRecovery::class)->run($id))->toBe('succeeded');
        $this->assertDatabaseCount('audit_search_documents', 1);
        expect(DB::table('platform_recovery_work')->where('id', $id)->value('attempts'))->toBe($phase === 'after_result' ? 1 : 2);
    } finally {
        if ($process->isRunning()) {
            $process->stop();
        }
        if (file_exists($marker)) {
            unlink($marker);
        }
    }
})->with(['after_claim', 'during_effect', 'after_result']);

function mysqlRecoveryTransition(string $operation): Closure
{
    return static function () use ($operation): void {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe recovery transition database.');
        }
        app(PlatformState::class)->transition(['mode' => 'read_only', 'expected_version' => 1,
            'operation_id' => $operation, 'operator' => 'ops-test', 'reason' => 'Contain test', 'incident' => 'TEST', 'expires_at' => null]);
    };
}

test('mysql a mode transition after a committed claim prevents effect without spending the retry budget', function () {
    $id = mysqlRecoveryWork();
    $lease = app(BackgroundRecovery::class)->claim($id);
    $operation = (string) Str::uuid();
    Concurrency::driver('process')->run([mysqlRecoveryTransition($operation)]);

    expect(fn () => app(BackgroundRecovery::class)->execute($lease))->toThrow(PlatformBlocked::class);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'cycle_attempts' => 0, 'state' => 'queued']);
    $this->assertDatabaseCount('audit_search_documents', 0);
});

function mysqlReplayExecute(string $run): Closure
{
    return static function () use ($run): array {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe replay concurrency database.');
        }

        return app(RecoveryReplay::class)->execute($run);
    };
}

test('mysql competing replay executors checkpoint one effect and retain one retry cycle', function () {
    $id = mysqlRecoveryWork();
    $input = ['operation' => (string) Str::uuid(), 'operator' => 'ops-test', 'reason' => 'Verified repair', 'incident' => 'TEST',
        'run' => null, 'digest' => null, 'owner' => 'audit_projection', 'ids' => [$id], 'limit' => 100];
    $replay = app(RecoveryReplay::class);
    $plan = $replay->command('plan', $input);
    $replay->command('approve', [...$input, 'operation' => (string) Str::uuid(), 'run' => $plan['run'], 'digest' => $plan['digest']]);
    $replay->command('execute', [...$input, 'operation' => (string) Str::uuid(), 'run' => $plan['run']]);

    $results = Concurrency::driver('process')->run([mysqlReplayExecute($plan['run']), mysqlReplayExecute($plan['run'])]);
    expect($results[0]['state'])->toBe('completed');
    expect($results[1]['state'])->toBe('completed');
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'attempts' => 1, 'cycle_attempts' => 1, 'state' => 'succeeded']);
    $this->assertDatabaseCount('platform_replay_progress', 1);
    $this->assertDatabaseCount('audit_search_documents', 1);
});
