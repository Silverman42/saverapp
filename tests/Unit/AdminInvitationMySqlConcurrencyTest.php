<?php

use App\Enums\AdminPermission;
use App\Models\BusinessProfile;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\User;
use App\Services\AdminInvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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
    $business = BusinessProfile::current();
    $business->forceFill(['invitation_sender_email' => 'invitations@saverapp.ng', 'invitation_sender_name' => 'SaverApp Security',
        'is_invitation_sender_verified' => true])->save();
});

function adminInvitationMysqlManager(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    return $admin;
}

function adminInvitationMysqlTask(int $actorId, string $action, array $arguments): Closure
{
    return static function () use ($actorId, $action, $arguments): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe Admin invitation worker database.');
        }
        Queue::fake();
        $actor = User::findOrFail($actorId);
        $service = app(AdminInvitationService::class);
        try {
            return match ($action) {
                'invite' => $service->invite($actor, $arguments['reference'], $arguments['data'])['replayed'] ? 'replayed' : 'created',
                'resend' => (string) $service->resend(User::findOrFail($arguments['target']), $actor)->generation,
                'cancel' => tap('cancelled', fn () => $service->cancel(User::findOrFail($arguments['target']), $actor, 'Race cancellation')),
            };
        } catch (ValidationException) {
            return 'rejected';
        } catch (ConflictHttpException) {
            return 'conflict';
        } catch (QueryException $exception) {
            return 'db:'.substr($exception->getMessage(), 0, 160);
        }
    };
}

test('mysql concurrent invitations for one email create exactly one Administrator', function (): void {
    $first = adminInvitationMysqlManager();
    $second = adminInvitationMysqlManager();
    $data = ['name' => 'Race Admin', 'email' => 'race.admin@example.test', 'permissions' => [AdminPermission::AuditView->value]];

    $results = Concurrency::driver('process')->run([
        adminInvitationMysqlTask($first->id, 'invite', ['reference' => 'race-a', 'data' => $data]),
        adminInvitationMysqlTask($second->id, 'invite', ['reference' => 'race-b', 'data' => $data]),
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['created', 'rejected'])
        ->and(User::query()->where('email', 'race.admin@example.test')->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(1);
});

test('mysql a duplicated attempt reference resolves to one Administrator and one invitation', function (): void {
    $manager = adminInvitationMysqlManager();
    $task = adminInvitationMysqlTask($manager->id, 'invite', ['reference' => 'same-ref',
        'data' => ['name' => 'Twice Admin', 'email' => 'twice@example.test', 'permissions' => []]]);

    $results = Concurrency::driver('process')->run([$task, $task]);

    expect(collect($results)->sort()->values()->all())->toBe(['created', 'replayed'])
        ->and(User::query()->where('email', 'twice@example.test')->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(1);
});

test('mysql a resend racing a cancellation leaves at most one usable link and never a duplicate generation', function (): void {
    $manager = adminInvitationMysqlManager();
    $other = adminInvitationMysqlManager();
    Queue::fake();
    $target = app(AdminInvitationService::class)->invite($manager, 'seed', [
        'name' => 'Target Admin', 'email' => 'target@example.test', 'permissions' => []])['admin'];
    Invitation::query()->update(['created_at' => now()->subMinutes(5)]);

    $results = Concurrency::driver('process')->run([
        adminInvitationMysqlTask($manager->id, 'resend', ['target' => $target->id]),
        adminInvitationMysqlTask($other->id, 'cancel', ['target' => $target->id]),
    ]);

    $invitations = Invitation::query()->where('user_id', $target->id)->get();
    expect($results[1])->toBe('cancelled')
        ->and($results[0])->toBeIn(['2', 'conflict'])
        ->and($invitations->pluck('generation')->duplicates())->toBeEmpty()
        ->and($invitations->whereNull('cancelled_at')->count())->toBeLessThanOrEqual(1);
    if ($results[0] === 'conflict') {
        expect($invitations->whereNull('cancelled_at'))->toBeEmpty();
    }
});
