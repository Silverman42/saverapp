<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\CustomerRecoveryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleCustomers::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    FinancialPeriod::factory()->create();
    Queue::fake();
    [$this->admin, $this->customer, $this->agent] = $this->createLifecycleFixture();
    $this->admin->givePermissionTo([AdminPermission::CustomersReassign, AdminPermission::SecurityOperationsManage]);
    $this->replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
});

function handoverRaceInput(User $actor, CustomerProfile $customer, AgentProfile $target): array
{
    $preview = app(CustomerReassignmentService::class)->preview($actor, $customer, $target->id);

    return ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'version' => $preview['version'],
        'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
        'target_agent_id' => $target->id, 'reason' => 'Personnel handover', 'customer_explanation' => 'Your contact changed.'];
}

function handoverRaceTask(int $actorId, int $customerId, array $payload): Closure
{
    return static function () use ($actorId, $customerId, $payload): array|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe Customer handover worker database.');
        }
        Queue::fake();
        try {
            return app(CustomerReassignmentService::class)->execute(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $payload);
        } catch (ConflictHttpException|ValidationException|AuthorizationException) {
            return 'blocked';
        }
    };
}

function handoverRecoveryTask(int $actorId, int $customerId, string $action, array $payload, ?string $reference = null): Closure
{
    return static function () use ($actorId, $customerId, $action, $payload, $reference): array|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe Customer handover worker database.');
        }
        Queue::fake();
        $actor = User::findOrFail($actorId);
        $request = Request::create('/customers/recovery', 'POST');
        $session = new Store('handover_worker', new ArraySessionHandler(120));
        $session->start();
        $session->put(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.fresh_until' => now()->addMinutes(10)->timestamp]);
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): User => $actor);
        app()->instance('request', $request);
        try {
            return app(CustomerRecoveryService::class)->execute($actor, CustomerProfile::findOrFail($customerId), $action, $payload, $reference);
        } catch (HttpException|ValidationException|AuthorizationException) {
            return 'blocked';
        }
    };
}

function handoverVerification(CustomerProfile $customer): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'email' => 'claim@example.test',
        'in_person' => true, 'record_compared' => true, 'verified_at' => now()->toIso8601String(),
        'procedure_reference' => 'KYC-1', 'notes' => 'In-person comparison'];
}

test('mysql competing reassignments and original UUID replay produce one assignment episode', function () {
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::CustomersReassign);
    $payload = handoverRaceInput($this->admin, $this->customer, $this->replacement);
    $results = Concurrency::driver('process')->run([
        handoverRaceTask($this->admin->id, $this->customer->id, $payload),
        handoverRaceTask($other->id, $this->customer->id, handoverRaceInput($other, $this->customer, $this->replacement)),
    ]);
    expect(collect($results)->filter(fn ($result) => is_array($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn ($result) => $result === 'blocked'))->toHaveCount(1);
    $this->assertDatabaseCount('customer_handover_operations', 1);
    $this->assertDatabaseCount('customer_assignments', 2);
});

test('mysql duplicate reassignment UUID returns the same committed result once', function () {
    $task = handoverRaceTask($this->admin->id, $this->customer->id, handoverRaceInput($this->admin, $this->customer, $this->replacement));
    $results = Concurrency::driver('process')->run([$task, $task]);
    expect($results[0])->toBeArray()->toBe($results[1]);
    $this->assertDatabaseCount('customer_handover_operations', 1);
    $this->assertDatabaseCount('customer_assignments', 2);
});

test('mysql collection versus reassignment either preserves original cash attribution or denies the former Agent', function () {
    LedgerAccount::whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($this->customer, $this->agent->user);
    $input = $this->lifecycleCollectionPayload($this->customer, $plan);
    $input['preview_fingerprint'] = app(CollectionService::class)->preview($this->agent->user, $this->customer, $input)['preview_fingerprint'];
    $actorId = $this->agent->user_id;
    $customerId = $this->customer->id;
    $originalAssignment = $this->customer->currentAssignment->id;
    $results = Concurrency::driver('process')->run([
        handoverRaceTask($this->admin->id, $customerId, handoverRaceInput($this->admin, $this->customer, $this->replacement)),
        (static function () use ($actorId, $customerId, $input): string {
            if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe Customer handover worker database.');
            }
            Queue::fake();
            try {
                app(CollectionService::class)->record(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $input);

                return 'posted';
            } catch (ConflictHttpException|ValidationException|AuthorizationException) {
                return 'blocked';
            }
        })->bindTo(null, null),
    ]);
    expect($results[1])->toBeIn(['posted', 'blocked']);
    if ($results[1] === 'posted') {
        expect(DB::table('collection_receipts')->value('assignment_id'))->toBe($originalAssignment)
            ->and(DB::table('collection_receipts')->value('recording_agent_profile_id'))->toBe($this->agent->id)
            ->and(DB::table('collection_receipts')->value('recorded_by_user_id'))->toBe($actorId);
    } else {
        $this->assertDatabaseCount('collection_receipts', 0);
        expect($results[0])->toBeArray();
    }
    $receiptCount = $results[1] === 'posted' ? 1 : 0;
    expect(DB::table('collection_allocations')->count())->toBe($receiptCount)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe($receiptCount)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe($receiptCount * 100000);
});

test('mysql recipient suspension versus reassignment rechecks eligibility before commit', function () {
    $targetUserId = $this->replacement->user_id;
    $results = Concurrency::driver('process')->run([
        handoverRaceTask($this->admin->id, $this->customer->id, handoverRaceInput($this->admin, $this->customer, $this->replacement)),
        (static function () use ($targetUserId): string {
            if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe Customer handover worker database.');
            }
            Queue::fake();
            DB::transaction(function () use ($targetUserId): void {
                User::whereKey($targetUserId)->lockForUpdate()->firstOrFail()->forceFill(['account_state' => 'suspended'])->save();
            });

            return 'suspended';
        })->bindTo(null, null),
    ]);
    expect($results[1])->toBe('suspended');
    expect($this->replacement->user->fresh()->canSignIn())->toBeFalse();
    expect($this->customer->fresh()->currentAssignment->agent_profile_id)->toBe(is_array($results[0]) ? $this->replacement->id : $this->agent->id);
});

test('mysql approval versus reassignment preserves an approved challenge or requires replacement verification', function () {
    app(CustomerRecoveryService::class)->execute($this->agent->user, $this->customer, 'request', handoverVerification($this->customer));
    $recovery = CustomerRecovery::firstOrFail();
    $decision = ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'recovery_version' => 1, 'reason' => 'Verified'];
    $results = Concurrency::driver('process')->run([
        handoverRaceTask($this->admin->id, $this->customer->id, handoverRaceInput($this->admin, $this->customer, $this->replacement)),
        handoverRecoveryTask($this->admin->id, $this->customer->id, 'approve', $decision, $recovery->reference),
    ]);
    $recovery->refresh();
    if (is_array($results[1])) {
        expect($recovery->state)->toBe('awaiting_activation')->and($recovery->activation_token_hash)->not->toBeNull();
    } else {
        expect($results[0])->toBeArray();
        expect($recovery->state)->toBe('verification_required')->and($recovery->activation_token_hash)->toBeNull();
    }
});

test('mysql competing normalized email claims reserve the address for exactly one Customer', function () {
    [$otherAdmin, $otherCustomer, $otherAgent] = $this->createLifecycleFixture();
    $results = Concurrency::driver('process')->run([
        handoverRecoveryTask($this->agent->user_id, $this->customer->id, 'request', handoverVerification($this->customer)),
        handoverRecoveryTask($otherAgent->user_id, $otherCustomer->id, 'request', [...handoverVerification($otherCustomer), 'email' => 'CLAIM@example.test']),
    ]);
    expect(collect($results)->filter(fn ($result) => is_array($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn ($result) => $result === 'blocked'))->toHaveCount(1);
    $this->assertDatabaseCount('customer_recoveries', 1);
});
