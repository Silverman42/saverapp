<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Models\AgentOffboardingCase;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AgentLifecycleService;
use App\Services\AgentOffboardingEligibility;
use App\Services\AgentStatusManagementService;
use App\Services\CustomerActionAuthorizationGuard;
use App\Services\PlatformGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleAgents;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleAgents::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function agentLifecycleMysqlTask(int $actorId, int $agentId, string $action, array $payload): Closure
{
    return static function () use ($actorId, $agentId, $action, $payload): array|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe Agent lifecycle worker database.');
        }
        $actor = User::findOrFail($actorId);
        $request = Request::create('/agents/lifecycle', 'POST');
        $session = new Store('agent_lifecycle_worker', new ArraySessionHandler(120));
        $session->start();
        $session->put(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.fresh_until' => now()->addMinutes(10)->timestamp]);
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): User => $actor);
        try {
            return app(AgentLifecycleService::class)->execute($actor, AgentProfile::findOrFail($agentId), $action, $payload, $request);
        } catch (ConflictHttpException) {
            return 'conflict';
        } catch (ValidationException) {
            return 'blocked';
        }
    };
}

test('mysql competing offboarding starts commit one accountable case', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::AgentsManage);
    $results = Concurrency::driver('process')->run([
        agentLifecycleMysqlTask($admin->id, $agent->id, 'start-offboarding', $this->agentLifecyclePayload($agent)),
        agentLifecycleMysqlTask($other->id, $agent->id, 'start-offboarding', $this->agentLifecyclePayload($agent)),
    ]);
    expect(collect($results)->filter(fn ($result) => is_array($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn ($result) => $result === 'conflict'))->toHaveCount(1);
    $this->assertDatabaseCount('agent_offboarding_cases', 1);
    $this->assertDatabaseCount('agent_lifecycle_histories', 1);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
});

test('mysql duplicate start UUID returns the original result exactly once', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $payload = $this->agentLifecyclePayload($agent);
    $task = agentLifecycleMysqlTask($admin->id, $agent->id, 'start-offboarding', $payload);
    $results = Concurrency::driver('process')->run([$task, $task]);
    expect($results[0])->toBe($results[1]);
    $this->assertDatabaseCount('agent_offboarding_cases', 1);
    $this->assertDatabaseCount('agent_lifecycle_operations', 1);
    $this->assertDatabaseCount('agent_lifecycle_histories', 1);
});

test('mysql suspension racing an Agent Customer mutation never permits work under suspended authority', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id]);
    $userId = $agent->user_id;
    $customerId = $customer->id;
    $results = Concurrency::driver('process')->run([
        agentLifecycleMysqlTask($admin->id, $agent->id, 'suspend', $this->agentLifecyclePayload($agent)),
        (static function () use ($userId, $customerId): string {
            if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe mutation worker database.');
            }
            try {
                return app(PlatformGuard::class)->transaction('mutation', function () use ($userId, $customerId): string {
                    $context = app(CustomerActionAuthorizationGuard::class)->lockAndAuthorize(User::findOrFail($userId), $customerId, 'update');
                    $context->customerProfile->forceFill(['address' => 'Committed before suspension'])->save();

                    return 'committed';
                }, attempts: 3);
            } catch (AuthorizationException) {
                return 'denied';
            }
        })->bindTo(null, null),
    ]);
    expect($results[0])->toBeArray();
    expect($results[1])->toBeIn(['committed', 'denied']);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
    expect(fn () => app(PlatformGuard::class)->transaction('mutation', fn () => app(CustomerActionAuthorizationGuard::class)
        ->lockAndAuthorize($agent->user->fresh(), $customer->id, 'update')))->toThrow(AuthorizationException::class);
    if ($results[1] === 'denied') {
        expect($customer->fresh()->address)->not->toBe('Committed before suspension');
    } else {
        expect($customer->fresh()->address)->toBe('Committed before suspension');
    }
});

test('mysql completion waits for continuity changes and rechecks its gate', function (string $mutation): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    app(AgentLifecycleService::class)->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->ended()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id]);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $replacement->id, 'version' => 2]);
    $barrier = tempnam(sys_get_temp_dir(), 'agent-lifecycle-race-');
    $replacementId = $replacement->id;
    $adminId = $admin->id;
    $departingId = $agent->id;
    $customerId = $customer->id;
    $complete = agentLifecycleMysqlTask($admin->id, $agent->id, 'complete-offboarding', $this->agentLifecyclePayload($agent));
    try {
        $results = Concurrency::driver('process')->run([
            (static function () use ($barrier, $replacementId, $adminId, $mutation, $customerId, $departingId): string {
                if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                    throw new RuntimeException('Unsafe replacement worker database.');
                }

                return app(PlatformGuard::class)->transaction('mutation', function () use ($barrier, $replacementId, $adminId, $mutation, $customerId, $departingId): string {
                    $replacement = AgentProfile::findOrFail($replacementId);
                    if ($mutation === 'replacement') {
                        app(AgentStatusManagementService::class)->transition(User::findOrFail($adminId), $replacement, AgentStatus::Inactive, $replacement->version, 'Unavailable', 'Pause Customer work.');
                    } else {
                        CustomerProfile::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
                        CustomerAssignment::query()->where('customer_profile_id', $customerId)->where('is_current', 1)->update(['is_current' => null, 'status' => 'ended', 'ended_at' => now()]);
                        CustomerAssignment::factory()->create(['customer_profile_id' => $customerId, 'agent_profile_id' => $departingId, 'version' => 3]);
                    }
                    file_put_contents($barrier, 'changed');
                    $deadline = microtime(true) + 10;
                    while (! file_exists($barrier.'.completion')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Completion barrier timed out.');
                        }
                        usleep(10000);
                    }

                    return 'replacement_inactive';
                });
            })->bindTo(null, null),
            (static function () use ($barrier, $complete): array|string {
                $deadline = microtime(true) + 10;
                while (file_get_contents($barrier) !== 'changed') {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Replacement barrier timed out.');
                    }
                    usleep(10000);
                }
                $owner = app(AgentOffboardingEligibility::class);
                app()->instance(AgentOffboardingEligibility::class, new class($owner) extends AgentOffboardingEligibility
                {
                    public function __construct(private AgentOffboardingEligibility $owner) {}

                    public function preview(User $actor, AgentProfile $agent, ?AgentOffboardingCase $case, bool $forUpdate = false): array
                    {
                        $checks = collect($this->owner->preview($actor, $agent, $case, $forUpdate)['checks'])->whereIn('key', ['access', 'customers'])->values()->all();

                        return ['eligible' => collect($checks)->every(fn ($check) => $check['status'] === 'passed'), 'checks' => $checks];
                    }
                });
                file_put_contents($barrier.'.completion', 'started');

                return $complete();
            })->bindTo(null, null),
        ]);
        expect($results)->toBe(['replacement_inactive', 'blocked']);
        expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
        expect($agent->offboardingCases()->first()->status)->toBe('in_progress');
    } finally {
        @unlink($barrier);
        @unlink($barrier.'.completion');
    }
})->with(['replacement', 'assignment']);
