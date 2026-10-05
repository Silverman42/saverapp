<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\StaffRecovery;
use App\Models\User;
use App\Services\StaffRecoveryService;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    foreach (AdminPermission::cases() as $permission) {
        Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['status' => 'active']);
    }
    Notification::fake();
});

function staffRecoveryMysqlManager(AdminPermission $permission): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo($permission->value);

    return $admin;
}

function staffRecoveryMysqlSeed(User $requester, User $target): StaffRecovery
{
    return DB::transaction(fn (): StaffRecovery => app(StaffRecoveryService::class)->request($requester, $target, [
        'email' => $target->email, 'procedure_reference' => 'ID', 'notes' => 'Verified in person.',
        'verified_at' => now()->subHour()->toIso8601String(), 'identity_verified' => true,
    ]));
}

function staffRecoveryMysqlTask(int $actorId, int $recoveryId, string $action, int $version): Closure
{
    return static function () use ($actorId, $recoveryId, $action, $version): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe staff recovery worker database.');
        }
        Notification::fake();
        $actor = User::findOrFail($actorId);
        $recovery = StaffRecovery::findOrFail($recoveryId);
        try {
            return app(StaffRecoveryService::class)->{$action}($actor, $recovery, $version, 'Race decision')->state;
        } catch (ConflictHttpException) {
            return 'conflict';
        }
    };
}

test('mysql two concurrent approvals of a single-approval Agent recovery finalize it exactly once', function (): void {
    $requester = staffRecoveryMysqlManager(AdminPermission::AgentsManage);
    $a = staffRecoveryMysqlManager(AdminPermission::AgentsManage);
    $b = staffRecoveryMysqlManager(AdminPermission::AgentsManage);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $recovery = staffRecoveryMysqlSeed($requester, $agent);

    $results = Concurrency::driver('process')->run([
        staffRecoveryMysqlTask($a->id, $recovery->id, 'approve', $recovery->version),
        staffRecoveryMysqlTask($b->id, $recovery->id, 'approve', $recovery->version),
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['awaiting_activation', 'conflict'])
        ->and($recovery->approvals()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'auth.staff_recovery_approved')->count())->toBe(1);
});

test('mysql the two approvals of an Admin recovery both count and finalize once', function (): void {
    $requester = staffRecoveryMysqlManager(AdminPermission::AdminsManage);
    $a = staffRecoveryMysqlManager(AdminPermission::AdminsManage);
    $b = staffRecoveryMysqlManager(AdminPermission::AdminsManage);
    $target = User::factory()->admin()->withTwoFactor()->create();
    $recovery = staffRecoveryMysqlSeed($requester, $target);
    expect($recovery->required_approvals)->toBe(2);

    $first = Concurrency::driver('process')->run([
        staffRecoveryMysqlTask($a->id, $recovery->id, 'approve', $recovery->version),
        staffRecoveryMysqlTask($b->id, $recovery->id, 'approve', $recovery->version),
    ]);
    $loser = $first[0] === 'conflict' ? $a : $b;
    $retry = staffRecoveryMysqlTask($loser->id, $recovery->id, 'approve', $recovery->fresh()->version)();

    expect(collect($first)->sort()->values()->all())->toBe(['awaiting_approval', 'conflict'])
        ->and($retry)->toBe('awaiting_activation')
        ->and($recovery->approvals()->count())->toBe(2)
        ->and(AuditEvent::query()->where('event_type', 'auth.staff_recovery_approved')->count())->toBe(1);
});

test('mysql an approval racing a cancellation never revokes factors for a cancelled recovery', function (): void {
    $requester = staffRecoveryMysqlManager(AdminPermission::AgentsManage);
    $approver = staffRecoveryMysqlManager(AdminPermission::AgentsManage);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $recovery = staffRecoveryMysqlSeed($requester, $agent);

    $results = Concurrency::driver('process')->run([
        staffRecoveryMysqlTask($approver->id, $recovery->id, 'approve', $recovery->version),
        staffRecoveryMysqlTask($requester->id, $recovery->id, 'cancel', $recovery->version),
    ]);

    $state = $recovery->fresh()->state;
    expect(collect($results)->filter(fn (string $result): bool => $result === 'conflict'))->toHaveCount(1)
        ->and($state)->toBeIn(['awaiting_activation', 'cancelled'])
        ->and($agent->fresh()->recovery_pending)->toBe($state === 'awaiting_activation');
});
