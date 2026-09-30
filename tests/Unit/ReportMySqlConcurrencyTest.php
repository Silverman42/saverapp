<?php

use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportReadService;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleCustomers::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }

    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    FinancialPeriod::factory()->create();
});

/** @return array<string, mixed> */
function reportMysqlPlans(User $viewer, ?string $cursor = null): array
{
    $filters = ['page_size' => 1, 'group' => ''];
    if ($cursor !== null) {
        $filters['cursor'] = $cursor;
    }

    return app(ReportReadService::class)->read($viewer, 'plans', $filters);
}

/** @param array<string, mixed> $section */
function reportMysqlMetric(array $section, string $code): ?int
{
    return collect($section['metrics'])->firstWhere('code', $code)['value'] ?? null;
}

/** @return Closure(): array{status: string, funded: ?int, metrics: int} */
function reportMysqlReaderTask(int $adminId): Closure
{
    return static function () use ($adminId): array {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe report reader database.');
        }

        $data = app(ReportReadService::class)->read(User::findOrFail($adminId), 'plans', ['page_size' => 1, 'group' => '']);
        $section = $data['sections']['funding_progress'];
        $funded = collect($section['metrics'])->firstWhere('code', 'funded_principal')['value'] ?? null;

        return ['status' => $section['status'], 'funded' => $funded,
            'metrics' => count($section['metrics'])];
    };
}

/** @param array<string, mixed> $payload
 * @return Closure(): string
 */
function reportMysqlReceiptTask(int $agentId, int $customerId, array $payload): Closure
{
    return static function () use ($agentId, $customerId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe report posting database.');
        }

        app(CollectionService::class)->record(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $payload);

        return 'posted';
    };
}

test('mysql plan funding read and receipt post stay internally consistent and invalidate continuation', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    [, $secondCustomer, $secondAgent] = $this->createLifecycleFixture();
    $this->createLifecyclePlan($secondCustomer, $secondAgent->user);
    LedgerAccount::query()->whereIn('code', [LedgerAccountCode::AgentReceivable->value, LedgerAccountCode::BusinessCash->value])
        ->update(['mapping_status' => 'mapped']);
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();

    $before = reportMysqlPlans($admin);
    $beforeFunding = $before['sections']['funding_progress'];
    expect($beforeFunding['status'])->toBe('Partial');
    expect($beforeFunding['total'])->toBe(2);
    expect(reportMysqlMetric($beforeFunding, 'funded_principal'))->toBe(0);
    $cursor = $beforeFunding['next_cursor'];
    expect($cursor)->toBeString();

    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $payload['attempt_reference'] = (string) Str::uuid();
    $results = Concurrency::driver('process')->run([
        reportMysqlReaderTask($admin->id),
        reportMysqlReceiptTask($agent->user_id, $customer->id, $payload),
    ]);

    expect($results[1])->toBe('posted');
    expect($results[0]['status'])->toBeIn(['Partial', 'Unavailable']);
    if ($results[0]['status'] === 'Partial') {
        expect($results[0]['funded'])->toBe(0);
    } else {
        expect($results[0]['metrics'])->toBe(0);
    }

    app(LedgerTransactionProjectionService::class)->rebuild();
    $after = reportMysqlPlans($admin)['sections']['funding_progress'];
    expect($after['status'])->toBe('Partial');
    expect(reportMysqlMetric($after, 'funded_principal'))->toBe(100000);
    expect(reportMysqlMetric($after, 'remaining_scheduled_target'))->toBe(700000);
    expect(fn () => reportMysqlPlans($admin, $cursor))->toThrow(HttpException::class);
});
