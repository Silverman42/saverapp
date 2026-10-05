<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\User;
use App\Services\AgentRegistrationService;
use App\Services\CustomerRegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();

    foreach (['admin', 'agent', 'customer'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
    foreach (AdminPermission::cases() as $permission) {
        Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['status' => 'active']);
    }
    BusinessProfile::current()->forceFill(['invitation_sender_email' => 'invitations@saverapp.ng', 'invitation_sender_name' => 'SaverApp Security',
        'is_invitation_sender_verified' => true])->save();
});

function registrationMysqlAdmin(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    return $admin;
}

function registrationMysqlAgent(): User
{
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $agent->assignRole(UserType::Agent->value);
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    return $agent;
}

function registrationMysqlFeeRule(User $publisher): FeeRule
{
    return FeeRule::create(['version' => 1, 'name' => 'Registration fee', 'kind' => FeeRuleKind::Registration,
        'rule_key' => 'registration', 'model' => FeeRuleModel::Fixed, 'timing' => FeeRuleTiming::Registration,
        'basis' => FeeRuleBasis::None, 'settlement_source' => FeeSettlementSource::ExternalReceipt, 'currency' => 'NGN',
        'amount_kobo' => 50000, 'customer_description' => 'Registration fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $publisher->id, 'publication_reason' => 'Race fixture']);
}

function registrationMysqlTask(string $owner, int $actorId, string $reference, array $data): Closure
{
    return static function () use ($owner, $actorId, $reference, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe registration worker database.');
        }
        Queue::fake();
        $actor = User::findOrFail($actorId);
        $service = app($owner === 'agent' ? AgentRegistrationService::class : CustomerRegistrationService::class);
        try {
            return $service->register($actor, $reference, $data)['replayed'] ? 'replayed' : 'created';
        } catch (ValidationException $exception) {
            return 'rejected:'.implode(',', array_keys($exception->errors()));
        } catch (ConflictHttpException) {
            return 'conflict';
        } catch (QueryException $exception) {
            return 'db:'.substr($exception->getMessage(), 0, 160);
        }
    };
}

test('mysql concurrent Agent registrations for one email create exactly one Agent', function (): void {
    $first = registrationMysqlAdmin();
    $second = registrationMysqlAdmin();
    $email = 'race.agent@example.test';

    $results = Concurrency::driver('process')->run([
        registrationMysqlTask('agent', $first->id, 'agent-race-a', ['name' => 'Race Agent', 'email' => $email, 'phone' => '+2348011110001']),
        registrationMysqlTask('agent', $second->id, 'agent-race-b', ['name' => 'Race Agent', 'email' => $email, 'phone' => '+2348011110002']),
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['created', 'rejected:email'])
        ->and(User::query()->where('email', $email)->count())->toBe(1)
        ->and(AgentProfile::query()->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(1);
});

test('mysql concurrent Customer registrations for one email create exactly one Customer', function (): void {
    $first = registrationMysqlAgent();
    $second = registrationMysqlAgent();
    registrationMysqlFeeRule(registrationMysqlAdmin());
    $email = 'race.customer@example.test';

    $results = Concurrency::driver('process')->run([
        registrationMysqlTask('customer', $first->id, 'customer-race-a', ['name' => 'Race Customer', 'email' => $email,
            'phone' => '+2348022220001', 'fee_rule_version' => 1]),
        registrationMysqlTask('customer', $second->id, 'customer-race-b', ['name' => 'Race Customer', 'email' => $email,
            'phone' => '+2348022220002', 'fee_rule_version' => 1]),
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['created', 'rejected:email'])
        ->and(User::query()->where('email', $email)->count())->toBe(1)
        ->and(CustomerProfile::query()->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(1);
});
