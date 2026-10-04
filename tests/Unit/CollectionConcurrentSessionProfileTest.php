<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

require_once __DIR__.'/../CollectionLoadProfileFixtures.php';

uses(TestCase::class, CreatesLifecycleCustomers::class);

/**
 * One authenticated session served by the real HTTP kernel in its own process.
 *
 * @param  array{user_id: int, kind: string, agent_index: int, steady_seconds: int, think_min: float, think_max: float}  $profile
 */
function collectionConcurrentSession(array $profile): Closure
{
    return static function () use ($profile): array {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe concurrent session profile database.');
        }
        config()->set('collections.enabled', true);
        $kernel = app(Kernel::class);
        $user = User::findOrFail($profile['user_id']);
        $today = CarbonImmutable::now('Africa/Lagos')->toDateString();
        $samples = ['workspace' => [], 'search' => [], 'receipt' => [], 'directory' => []];
        $statuses = [];
        $attempts = [];
        $send = static function (string $method, string $uri, array $data = []) use ($kernel, $user, &$statuses): float {
            Auth::guard('web')->setUser($user);
            $request = Request::create($uri, $method, $data);
            $start = hrtime(true);
            $response = $kernel->handle($request);
            $elapsed = (hrtime(true) - $start) / 1_000_000_000;
            $kernel->terminate($request, $response);
            $statuses[] = $response->getStatusCode();

            return $elapsed;
        };
        $think = static fn () => usleep((int) (random_int((int) ($profile['think_min'] * 1000), (int) ($profile['think_max'] * 1000)) * 1000));
        $deadline = microtime(true) + $profile['steady_seconds'];
        for ($round = 1; microtime(true) < $deadline; $round++) {
            if ($profile['kind'] === 'admin') {
                $samples['directory'][] = $send('GET', route('plans.index', [], false));
                $think();
                $samples['directory'][] = $send('GET', route('admin.fees.index', [], false));
                $think();

                continue;
            }
            $index = $profile['agent_index'] + 30 * $round;
            if ($index >= 10000) {
                break;
            }
            $customer = CustomerProfile::query()->findOrFail(200000 + $index);
            $plan = ThriftPlan::query()->findOrFail(300000 + 2 * $index);
            $samples['workspace'][] = $send('GET', route('collections.index', ['date' => $today], false));
            $think();
            $samples['search'][] = $send('GET', route('collections.index', ['date' => $today, 'search' => 'Load Customer '.$index], false));
            $think();
            $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
                'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1,
                'plan_id' => $plan->plan_id, 'plan_version' => $plan->version, 'received_date' => $today,
                'savings_ngn' => '1000.00', 'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
            $payload['preview_fingerprint'] = app(CollectionService::class)->preview($user, $customer, $payload)['preview_fingerprint'];
            $samples['receipt'][] = $send('POST', route('customers.collections.store', $customer->customer_id, false), $payload);
            $attempts[] = $payload['attempt_reference'];
            $think();
        }

        return ['samples' => $samples, 'statuses' => $statuses, 'attempts' => $attempts];
    };
}

test('declared concurrent authenticated session profile reports collection p95 and keeps postings exact', function (): void {
    if (env('COLLECTION_CONCURRENT_PROFILE') !== '1'
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Explicit isolated MySQL concurrent session profile only.');
    }

    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    config()->set('collections.enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();
    FinancialPeriod::factory()->create(['month' => $today->startOfMonth()->toDateString(), 'changed_by_user_id' => $admin->id]);
    LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable)->update(['mapping_status' => 'mapped']);
    app(CollectionService::class)->preview($agent->user, $customer, $this->lifecycleCollectionPayload($customer, $plan));
    $agentIds = collectionLoadProfileDataset($admin, $customer, $agent, $plan, $today);
    $agentUserIds = DB::table('agent_profiles')->whereIn('id', $agentIds)->orderBy('id')->pluck('user_id')->all();
    foreach (['model_has_roles', 'model_has_permissions'] as $assignments) {
        $template = DB::table($assignments)->where('model_type', $agent->user->getMorphClass())->where('model_id', $agent->user_id)->get();
        $rows = [];
        foreach (array_slice($agentUserIds, 1) as $userId) {
            foreach ($template as $row) {
                $rows[] = [...(array) $row, 'model_id' => $userId];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($assignments)->insert($chunk);
        }
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $admins = [$admin->id];
    foreach ([1, 2] as $number) {
        $extra = User::factory()->admin()->withTwoFactor()->create();
        $extra->givePermissionTo(...$admin->fresh()->getAllPermissions()->pluck('name')->all());
        $admins[] = $extra->id;
    }

    $steady = (int) (env('COLLECTION_CONCURRENT_STEADY_SECONDS') ?: 600);
    $thinkMax = (float) (env('COLLECTION_CONCURRENT_THINK_MAX') ?: 15);
    $thinkMin = (float) (env('COLLECTION_CONCURRENT_THINK_MIN') ?: 5);
    $sessions = [];
    foreach ($agentUserIds as $position => $userId) {
        $sessions[] = collectionConcurrentSession(['user_id' => $userId, 'kind' => 'agent', 'agent_index' => $position,
            'steady_seconds' => $steady, 'think_min' => $thinkMin, 'think_max' => $thinkMax]);
    }
    foreach ($admins as $userId) {
        $sessions[] = collectionConcurrentSession(['user_id' => $userId, 'kind' => 'admin', 'agent_index' => 0,
            'steady_seconds' => $steady, 'think_min' => $thinkMin, 'think_max' => $thinkMax]);
    }

    $baselineReceipts = DB::table('collection_receipts')->count();
    $results = Concurrency::driver('process')->run($sessions, $steady + 300);

    $durations = ['workspace' => [], 'search' => [], 'receipt' => [], 'directory' => []];
    $statuses = [];
    $attempts = [];
    foreach ($results as $result) {
        foreach ($result['samples'] as $operation => $samples) {
            $durations[$operation] = [...$durations[$operation], ...$samples];
        }
        $statuses = [...$statuses, ...$result['statuses']];
        $attempts = [...$attempts, ...$result['attempts']];
    }
    $p = static function (array $samples, float $fraction): float {
        sort($samples);

        return $samples === [] ? 0.0 : $samples[max(0, (int) ceil(count($samples) * $fraction) - 1)];
    };
    $summary = [];
    foreach ($durations as $operation => $samples) {
        $summary[$operation] = ['count' => count($samples), 'p50_seconds' => $p($samples, 0.5), 'p95_seconds' => $p($samples, 0.95), 'max_seconds' => $samples === [] ? 0.0 : max($samples)];
    }
    $statusCounts = array_count_values($statuses);
    ksort($statusCounts);
    $groups = DB::table('ledger_posting_groups')->count();
    $unbalanced = DB::table('ledger_entries')->select('ledger_posting_group_id')->groupBy('ledger_posting_group_id')
        ->havingRaw("SUM(CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END) <> 0")->get()->count();
    $receipts = DB::table('collection_receipts')->count() - $baselineReceipts;

    file_put_contents('/private/tmp/saverapp-collection-concurrent-profile.json', json_encode([
        'dataset' => ['customers' => 10000, 'agents' => 30, 'plans' => 20000, 'slots' => 2000000],
        'sessions' => ['agents' => count($agentUserIds), 'admins' => count($admins)],
        'steady_seconds' => $steady, 'think_seconds' => [$thinkMin, $thinkMax],
        'operations' => $summary, 'http_statuses' => $statusCounts,
        'receipts_created' => $receipts, 'receipt_attempts' => count($attempts),
        'posting_groups' => $groups, 'unbalanced_groups' => $unbalanced,
        'targets_seconds' => ['workspace' => 3, 'search' => 1, 'receipt' => 2],
        'concurrency' => count($sessions), 'transport' => 'one authenticated session per process through the real HTTP kernel',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    expect($receipts)->toBe(count($attempts))
        ->and($unbalanced)->toBe(0)
        ->and(array_filter(array_keys($statusCounts), static fn (int $status): bool => $status >= 500))->toBe([])
        ->and($summary['workspace']['p95_seconds'])->toBeLessThanOrEqual(3.0)
        ->and($summary['search']['p95_seconds'])->toBeLessThanOrEqual(1.0)
        ->and($summary['receipt']['p95_seconds'])->toBeLessThanOrEqual(2.0);
});
