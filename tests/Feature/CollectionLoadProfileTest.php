<?php

use App\Enums\LedgerAccountCode;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Services\CollectionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionLoadProfileFixtures.php';

uses(CreatesLifecycleCustomers::class);

test('declared local cash capacity profile reports collection p95 response times', function (): void {
    if (env('COLLECTION_LOAD_PROFILE') !== '1'
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Explicit isolated MySQL load profile only.');
    }

    config()->set('collections.enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();
    FinancialPeriod::factory()->create(['month' => $today->startOfMonth()->toDateString(), 'changed_by_user_id' => $admin->id]);
    LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable)->update(['mapping_status' => 'mapped']);
    app(CollectionService::class)->preview($agent->user, $customer, $this->lifecycleCollectionPayload($customer, $plan));
    collectionLoadProfileDataset($admin, $customer, $agent, $plan, $today);

    $this->actingAs($agent->user);
    $diagnostic = env('COLLECTION_LOAD_DIAGNOSTIC') === '1';
    $sampleCount = $diagnostic ? 1 : 20;
    if ($diagnostic) {
        $slowestMilliseconds = 0.0;
        DB::listen(static function (QueryExecuted $query) use (&$slowestMilliseconds): void {
            if ($query->time <= $slowestMilliseconds || ! str_starts_with(strtolower(ltrim($query->sql)), 'select ')) {
                return;
            }
            $slowestMilliseconds = $query->time;
            $plan = $query->connection->select('EXPLAIN '.$query->sql, $query->bindings);
            file_put_contents('/private/tmp/saverapp-collection-load-diagnostic.json', json_encode([
                'diagnostic' => true, 'milliseconds' => $query->time, 'sql' => $query->sql,
                'bindings' => $query->bindings, 'explain' => $plan,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        });
    }
    $durations = ['workspace' => [], 'search' => [], 'receipt' => []];
    for ($sample = 0; $sample < $sampleCount; $sample++) {
        $start = hrtime(true);
        $this->get(route('collections.index', ['date' => $today->toDateString()]))->assertOk();
        $durations['workspace'][] = (hrtime(true) - $start) / 1_000_000_000;

        $start = hrtime(true);
        $this->get(route('collections.index', ['date' => $today->toDateString(), 'search' => 'Load Customer 30']))->assertOk();
        $durations['search'][] = (hrtime(true) - $start) / 1_000_000_000;

        $payload = $this->lifecycleCollectionPayload($customer, $plan->fresh());
        $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
        $payload['attempt_reference'] = (string) Str::uuid();
        $start = hrtime(true);
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
        $durations['receipt'][] = (hrtime(true) - $start) / 1_000_000_000;
    }

    $p95 = [];
    foreach ($durations as $operation => $samples) {
        sort($samples);
        $p95[$operation] = $samples[(int) ceil($sampleCount * 0.95) - 1];
    }
    $result = ['dataset' => ['customers' => 10000, 'agents' => 30, 'plans' => 20000, 'slots' => 2000000],
        'samples_per_operation' => $sampleCount, 'diagnostic' => $diagnostic, 'concurrency' => 1,
        'device' => 'local PHP test client on macOS', 'network' => 'in-process, no mobile network',
        'p95_seconds' => $p95, 'targets_seconds' => ['workspace' => 3, 'search' => 1, 'receipt' => 2]];
    file_put_contents('/private/tmp/saverapp-collection-load-profile.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($p95['workspace'])->toBeLessThanOrEqual(3.0)
        ->and($p95['search'])->toBeLessThanOrEqual(1.0)
        ->and($p95['receipt'])->toBeLessThanOrEqual(2.0);
});
