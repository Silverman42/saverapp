<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\AuthenticationLock;
use App\Models\SecurityCase;
use App\Models\User;
use App\Services\AuthenticationAbuseService;
use App\Services\AuthorizationService;
use App\Services\SecurityCaseService;
use App\Services\UnlockState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function mysqlAuditTask(string $action, int $actorId = 0, int $targetId = 0, string $token = ''): Closure
{
    return static function () use ($action, $actorId, $targetId, $token): int|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe audit worker database');
        }
        if ($action === 'capture') {
            return AuditEvent::record('customer.status_changed', 'customer', 99, null, ['to_status' => 'inactive'], null,
                ['operation_id' => 'mysql-race', 'executor' => 'mysql.acceptance'])->id;
        }
        if (in_array($action, ['case', 'assign'], true)) {
            try {
                app(SecurityCaseService::class)->change(User::query()->findOrFail($actorId), SecurityCase::query()->findOrFail($targetId),
                    $action === 'assign' ? ['expected_version' => 1, 'action' => 'assign', 'owner_id' => $actorId, 'note' => 'Concurrent assignment']
                        : ['expected_version' => 1, 'action' => 'state', 'state' => 'Investigating', 'note' => 'Concurrent review']);

                return 'won';
            } catch (ConflictHttpException) {
                return 'stale';
            } catch (QueryException $exception) {
                throw new RuntimeException($exception->getMessage());
            }
        }
        if ($action === 'unlock') {
            try {
                app(AuthenticationAbuseService::class)->manualUnlock(User::query()->findOrFail($targetId), User::query()->findOrFail($actorId), 'password', 'in_person', 'Verified in person', $token);

                return 'unlocked_first';
            } catch (ConflictHttpException) {
                return 'stale';
            } catch (AuthorizationException) {
                return 'denied';
            } catch (QueryException $exception) {
                throw new RuntimeException($exception->getMessage());
            }
        }
        if ($action === 'revoke') {
            return DB::transaction(function () use ($actorId): string {
                $actor = User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();
                $actor->revokePermissionTo(AdminPermission::SecurityOperationsManage);
                $actor->forceFill(['permission_version' => $actor->permission_version + 1])->save();

                return 'revoked';
            }, attempts: 3);
        }

        return DB::transaction(function () use ($targetId): int {
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
            $target->lockTemporarily(60, 'password');
            $lock = AuthenticationLock::query()->create(['user_id' => $targetId, 'email_normalized' => $target->email_normalized,
                'lock_category' => 'password', 'reason' => 'Temporary password restriction', 'locked_at' => now(), 'locked_until' => now()->addHour(), 'failed_attempts_count' => 20]);
            AuditEvent::record('auth.lock_created', User::class, $targetId, null, ['category' => 'password', 'lock_id' => $lock->id, 'attempt_count' => 20]);

            return $lock->id;
        }, attempts: 3);
    };
}

test('mysql concurrent identical capture commits one immutable event', function () {
    $ids = Concurrency::driver('process')->run([mysqlAuditTask('capture'), mysqlAuditTask('capture')]);
    expect($ids[0])->toBe($ids[1]);
    $this->assertDatabaseCount('canonical_audit_events', 1);
    expect(fn () => DB::table('canonical_audit_events')->update(['outcome' => 'Failed']))->toThrow(QueryException::class);
    expect(fn () => DB::table('canonical_audit_events')->delete())->toThrow(QueryException::class);
});

test('mysql concurrent case versions commit one transition', function () {
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::SecurityOperationsManage);
    AuditEvent::record('auth.lock_created', User::class, null, null, ['category' => 'password', 'lock_id' => 1]);
    $caseId = SecurityCase::query()->sole()->id;
    $results = Concurrency::driver('process')->run([mysqlAuditTask('case', $actor->id, $caseId), mysqlAuditTask('case', $actor->id, $caseId)]);
    sort($results);
    expect($results)->toBe(['stale', 'won']);
    expect(SecurityCase::query()->findOrFail($caseId)->version)->toBe(2);
    expect(DB::table('security_case_transitions')->where('security_case_id', $caseId)->count())->toBe(2);
});

test('mysql manual unlock cannot clear a racing replacement lock', function () {
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::SecurityOperationsManage);
    $target = User::factory()->customer()->create();
    $target->lockTemporarily(30, 'password');
    AuthenticationLock::query()->create(['user_id' => $target->id, 'email_normalized' => $target->email_normalized,
        'lock_category' => 'password', 'reason' => 'Temporary password restriction', 'locked_at' => now(), 'locked_until' => now()->addMinutes(30), 'failed_attempts_count' => 5]);
    $token = app(UnlockState::class)->token($target->fresh(), $actor->fresh(), 'password');
    $results = Concurrency::driver('process')->run([mysqlAuditTask('unlock', $actor->id, $target->id, $token), mysqlAuditTask('replacement', 0, $target->id)]);
    expect($results[0])->toBeIn(['unlocked_first', 'stale']);
    expect(AuthenticationLock::query()->findOrFail($results[1])->unlocked_at)->toBeNull();
    expect($target->fresh()->isTemporarilyLocked('password'))->toBeTrue();
});

test('mysql permission revocation racing a manual unlock lets exactly one committed order win', function () {
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::SecurityOperationsManage);
    $target = User::factory()->customer()->create();
    $target->lockTemporarily(30, 'password');
    $lock = AuthenticationLock::query()->create(['user_id' => $target->id, 'email_normalized' => $target->email_normalized,
        'lock_category' => 'password', 'reason' => 'Temporary password restriction', 'locked_at' => now(), 'locked_until' => now()->addMinutes(30), 'failed_attempts_count' => 5]);
    $token = app(UnlockState::class)->token($target->fresh(), $actor->fresh(), 'password');

    $results = Concurrency::driver('process')->run([mysqlAuditTask('unlock', $actor->id, $target->id, $token), mysqlAuditTask('revoke', $actor->id)]);

    expect($results[1])->toBe('revoked')->and($results[0])->toBeIn(['unlocked_first', 'denied', 'stale']);
    $unlocked = $lock->fresh()->unlocked_at !== null;
    expect($unlocked)->toBe($results[0] === 'unlocked_first')
        ->and($target->fresh()->isTemporarilyLocked('password'))->toBe(! $unlocked)
        ->and(app(AuthorizationService::class)->allows($actor->fresh(), AdminPermission::SecurityOperationsManage))->toBeFalse();
});

test('mysql concurrent assignment and investigation commit one audited transition', function () {
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::SecurityOperationsManage);
    AuditEvent::record('auth.lock_created', User::class, null, null, ['category' => 'password', 'lock_id' => 1]);
    $caseId = SecurityCase::query()->sole()->id;

    $results = Concurrency::driver('process')->run([mysqlAuditTask('assign', $actor->id, $caseId), mysqlAuditTask('case', $actor->id, $caseId)]);

    sort($results);
    expect($results)->toBe(['stale', 'won'])
        ->and(SecurityCase::query()->findOrFail($caseId)->version)->toBe(2)
        ->and(DB::table('security_case_transitions')->where('security_case_id', $caseId)->count())->toBe(2)
        ->and(DB::table('security_case_transitions')->where('security_case_id', $caseId)->whereNull('audit_event_id')->count())->toBe(0);
});
