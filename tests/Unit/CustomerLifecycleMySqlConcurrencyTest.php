<?php

use App\Enums\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\FeeSnapshot;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CustomerLifecycleService;
use App\Services\FeeObligationService;
use App\Services\PlatformGuard;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleCustomers::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    FinancialPeriod::factory()->create();
});

function lifecycleMysqlTask(int $actorId, int $customerId, string $action, array $payload): Closure
{
    return static function () use ($actorId, $customerId, $action, $payload): array|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe lifecycle worker database.');
        }
        try {
            return app(CustomerLifecycleService::class)->execute(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $action, $payload);
        } catch (ConflictHttpException) {
            return 'conflict';
        }
    };
}

test('mysql competing lifecycle operations commit one status history and preserve the losing version', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::CustomersManage);
    $results = Concurrency::driver('process')->run([
        lifecycleMysqlTask($admin->id, $customer->id, 'archive', $this->lifecyclePayload($customer)),
        lifecycleMysqlTask($other->id, $customer->id, 'archive', $this->lifecyclePayload($customer)),
    ]);
    expect(collect($results)->filter(fn ($result) => is_array($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn ($result) => $result === 'conflict'))->toHaveCount(1);
    $this->assertDatabaseCount('customer_lifecycle_operations', 1);
    $this->assertDatabaseCount('customer_status_histories', 1);
    expect($customer->fresh()->operational_status->value)->toBe('archived');
});

test('mysql duplicate lifecycle submissions return the same original result exactly once', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $payload = $this->lifecyclePayload($customer);
    $results = Concurrency::driver('process')->run([
        lifecycleMysqlTask($admin->id, $customer->id, 'archive', $payload),
        lifecycleMysqlTask($admin->id, $customer->id, 'archive', $payload),
    ]);
    expect($results[0])->toBe($results[1]);
    $this->assertDatabaseCount('customer_lifecycle_operations', 1);
    $this->assertDatabaseCount('customer_status_histories', 1);
});

test('mysql archive waits for an in-flight assessment and rejects the newly committed obligation', function () {
    [$admin, $customer, $agent, $zero] = $this->createLifecycleFixture();
    $rule = $zero->feeRule->replicate();
    $rule->forceFill(['version' => 2, 'model' => 'fixed', 'amount_kobo' => 100])->save();
    $terms = $zero->getAttributes();
    unset($terms['id'], $terms['created_at'], $terms['updated_at']);
    $terms = [...$terms, 'source_type' => 'late_assessment', 'source_id' => (string) Str::uuid(),
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 2, 'model' => 'fixed', 'amount_kobo' => 100];
    $barrier = tempnam(sys_get_temp_dir(), 'lifecycle-race-');
    $customerId = $customer->id;
    $actorId = $agent->user_id;
    $archive = lifecycleMysqlTask($admin->id, $customer->id, 'archive', $this->lifecyclePayload($customer));
    try {
        $results = Concurrency::driver('process')->run([
            (static function () use ($terms, $barrier, $actorId, $customerId): string {
                if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                    throw new RuntimeException('Unsafe lifecycle assessment database.');
                }

                return app(PlatformGuard::class)->transaction('financial', function () use ($terms, $barrier, $actorId, $customerId): string {
                    User::whereKey($actorId)->lockForUpdate()->firstOrFail();
                    CustomerProfile::whereKey($customerId)->lockForUpdate()->firstOrFail();
                    $snapshot = FeeSnapshot::create($terms);
                    app(FeeObligationService::class)->assessSnapshot($snapshot, User::findOrFail($actorId));
                    file_put_contents($barrier, 'assessed');
                    $deadline = microtime(true) + 10;
                    while (! file_exists($barrier.'.archive')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Archive barrier timed out.');
                        }
                        usleep(10000);
                    }

                    return 'assessed';
                });
            })->bindTo(null, null),
            (static function () use ($archive, $barrier): string {
                $deadline = microtime(true) + 10;
                while (file_get_contents($barrier) !== 'assessed') {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Assessment barrier timed out.');
                    }
                    usleep(10000);
                }
                file_put_contents($barrier.'.archive', 'started');
                try {
                    $archive();

                    return 'archived';
                } catch (ValidationException) {
                    return 'blocked';
                }
            })->bindTo(null, null),
        ]);
        expect($results)->toBe(['assessed', 'blocked']);
        expect($customer->fresh()->operational_status->value)->toBe('active');
        $this->assertDatabaseCount('fee_obligations', 1);
        $this->assertDatabaseCount('customer_lifecycle_operations', 0);
    } finally {
        @unlink($barrier);
        @unlink($barrier.'.archive');
    }
});

test('mysql archival versus real collection posting retains the receipt and refuses archival', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $archive = lifecycleMysqlTask($admin->id, $customer->id, 'archive', $this->lifecyclePayload($customer));
    $agentId = $agent->user_id;
    $customerId = $customer->id;
    $results = Concurrency::driver('process')->run([
        (static function () use ($archive): string {
            try {
                $archive();

                return 'archived';
            } catch (ValidationException) {
                return 'blocked';
            }
        })->bindTo(null, null),
        (static function () use ($agentId, $customerId, $payload): string {
            if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe collection race database.');
            }
            app(CollectionService::class)->record(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $payload);

            return 'posted';
        })->bindTo(null, null),
    ]);
    expect($results)->toBe(['blocked', 'posted']);
    $this->assertDatabaseCount('collection_receipts', 1);
    expect($customer->fresh()->operational_status->value)->toBe('active')
        ->and(DB::table('collection_receipts')->value('recorded_by_user_id'))->toBe($agent->user_id)
        ->and(DB::table('collection_receipts')->value('assignment_id'))->toBe($customer->currentAssignment->id)
        ->and(DB::table('collection_allocations')->count())->toBe(1)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(100000);
});

function enableLifecycleReservationFixture(): void
{
    app()->instance(WithdrawalMethodRegistry::class, new class extends WithdrawalMethodRegistry
    {
        public function resolve(int $customerProfileId, string $method, string $destinationReference): array
        {
            return ['version' => 1, 'destination_reference' => 'fixture-cash', 'destination_mask' => 'Fixture cash'];
        }
    });
}

test('mysql archival versus reservation creation preserves the pending request and savings coverage', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $collection = $this->lifecycleCollectionPayload($customer, $plan);
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $collection)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $collection);
    enableLifecycleReservationFixture();
    $input = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00',
        'method' => 'cash', 'destination_reference' => 'fixture-cash', 'reason' => 'Fixture request'];
    $quote = app(WithdrawalService::class)->preview($agent->user, $customer, $input);
    $payload = [...$input, ...Arr::only($quote, ['customer_version', 'assignment_version', 'plan_version', 'business_version', 'quote_expires_at', 'preview_fingerprint']),
        'attempt_reference' => (string) Str::uuid()];
    $actorId = $agent->user_id;
    $customerId = $customer->id;
    $archive = lifecycleMysqlTask($admin->id, $customer->id, 'archive', $this->lifecyclePayload($customer));
    $results = Concurrency::driver('process')->run([
        (static function () use ($archive): string {
            try {
                $archive();

                return 'archived';
            } catch (ValidationException) {
                return 'blocked';
            }
        })->bindTo(null, null),
        (static function () use ($actorId, $customerId, $payload): string {
            if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
                throw new RuntimeException('Unsafe reservation race database.');
            }
            $registry = Mockery::mock(WithdrawalMethodRegistry::class);
            $registry->shouldReceive('resolve')->andReturn(['version' => 1, 'destination_reference' => 'fixture-cash', 'destination_mask' => 'Fixture cash']);
            app()->instance(WithdrawalMethodRegistry::class, $registry);
            app(WithdrawalService::class)->submit(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $payload);

            return 'reserved';
        })->bindTo(null, null),
    ]);
    expect($results)->toBe(['blocked', 'reserved']);
    $this->assertDatabaseCount('withdrawal_reservations', 1);
    $this->assertDatabaseCount('withdrawal_requests', 1);
    expect(app(CollectionReadService::class)->position($customer)['reservations_kobo'])->toBe(30000);
    expect($customer->fresh()->operational_status->value)->toBe('active');
});
