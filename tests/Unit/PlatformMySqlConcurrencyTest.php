<?php

use App\Models\User;
use App\Services\PlatformGuard;
use App\Services\PlatformState;
use App\Support\PlatformBlocked;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function platformMysqlTask(string $mode, string $operation): Closure
{
    return static function () use ($mode, $operation): array|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe platform worker database.');
        }
        try {
            return app(PlatformState::class)->transition(['mode' => $mode, 'expected_version' => 1, 'operation_id' => $operation,
                'operator' => 'mysql-test-service', 'reason' => 'Concurrent containment', 'incident' => 'INC-race', 'expires_at' => null]);
        } catch (ConflictHttpException) {
            return 'conflict';
        }
    };
}

test('mysql competing mode transitions publish one version and one operation result', function () {
    $results = Concurrency::driver('process')->run([
        platformMysqlTask('financial_freeze', (string) Str::uuid()),
        platformMysqlTask('read_only', (string) Str::uuid()),
    ]);

    expect(collect($results)->filter(fn ($result) => is_array($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn ($result) => $result === 'conflict'))->toHaveCount(1);
    $this->assertDatabaseCount('platform_operations', 1);
    $this->assertDatabaseCount('platform_transitions', 2);
    expect(DB::table('canonical_audit_events')->where('event_type', 'platform.mode_changed')->count())->toBe(1);
});

test('mysql identical mode transition attempts return the same committed result', function () {
    $operation = (string) Str::uuid();
    $results = Concurrency::driver('process')->run([
        platformMysqlTask('financial_freeze', $operation), platformMysqlTask('financial_freeze', $operation),
    ]);

    expect($results[0])->toBe($results[1])->toMatchArray(['version' => 2, 'mode' => 'financial_freeze']);
    $this->assertDatabaseCount('platform_operations', 1);
    $this->assertDatabaseCount('platform_transitions', 2);
});

function platformMysqlFreezeTasks(int $actorId, string $barrier, string $operation): array
{
    return [
        static function () use ($actorId, $barrier): int {
            if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe platform race database.');
            }

            return app(PlatformGuard::class)->transaction('financial', function () use ($actorId, $barrier): int {
                file_put_contents($barrier, 'locked');
                $deadline = microtime(true) + 10;
                while (! file_exists($barrier.'.transition')) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Platform transition barrier timed out.');
                    }
                    usleep(10_000);
                }
                User::query()->whereKey($actorId)->update(['name' => 'Committed before freeze']);

                return (int) DB::table('platform_state')->value('version');
            });
        },
        static function () use ($barrier, $operation): array {
            if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe platform race database.');
            }
            $deadline = microtime(true) + 10;
            while (file_get_contents($barrier) !== 'locked') {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Platform write barrier timed out.');
                }
                usleep(10_000);
            }
            file_put_contents($barrier.'.transition', 'started');

            return app(PlatformState::class)->transition(['mode' => 'financial_freeze', 'expected_version' => 1,
                'operation_id' => $operation, 'operator' => 'mysql-test-service', 'reason' => 'Contain concurrent write',
                'incident' => 'INC-race', 'expires_at' => null]);
        },
    ];
}

test('mysql freeze waits for an earlier financial transaction and blocks later writes', function () {
    $actor = User::factory()->customer()->create();
    $actorId = $actor->id;
    $barrier = tempnam(sys_get_temp_dir(), 'platform-race-');
    $operation = (string) Str::uuid();
    try {
        $results = Concurrency::driver('process')->run(platformMysqlFreezeTasks($actorId, $barrier, $operation));
        expect($results[0])->toBe(1);
        expect($results[1]['version'])->toBe(2);
        expect(fn () => app(PlatformGuard::class)->transaction('financial', fn () => $actor->update(['name' => 'Forbidden later write'])))->toThrow(PlatformBlocked::class);
        expect($actor->fresh()->name)->toBe('Committed before freeze');
    } finally {
        @unlink($barrier);
        @unlink($barrier.'.transition');
    }
});
