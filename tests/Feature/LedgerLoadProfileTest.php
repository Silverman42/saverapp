<?php

use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\FinancialArtifact;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FinancialArtifactService;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionLoadProfileFixtures.php';

uses(CreatesLifecycleCustomers::class);

/**
 * Seeds a verified ledger around the load-profile Customers: 1,990 posted contributions for the fixture Customer across the last 365 days
 * (a statement near the 2,000-row limit) plus 198,000 balanced contributions for the other Customers, with matching references and projection rows.
 *
 * @return array{reference: string, groups: int}
 */
function ledgerLoadProfileLedger(CustomerProfile $customer, User $actor, CarbonImmutable $today): array
{
    $liability = (int) LedgerAccount::query()->where('code', LedgerAccountCode::CustomerSavingsLiability->value)->value('id');
    $receivable = (int) LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable->value)->value('id');
    $total = 200000;
    $statementRows = 1990;
    $base = 5000000;
    $now = now();
    $firstReference = null;
    for ($first = 0; $first < $total; $first += 500) {
        $groups = $entries = $references = $projections = [];
        for ($index = $first; $index < min($first + 500, $total); $index++) {
            $id = $base + $index;
            $own = $index < $statementRows;
            $customerId = $own ? $customer->id : 200001 + ($index % 9999);
            $date = $today->subDays($own ? $index % 365 : 400 + ($index % 300))->toDateString();
            $committed = $today->subDays($own ? $index % 365 : 400 + ($index % 300))->setTime(9, 0)->utc()->toDateTimeString();
            $reference = 'TXN-LOAD-'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
            $firstReference ??= $reference;
            $groups[] = ['id' => $id, 'posting_reference' => 'PST-LOAD-'.$index, 'idempotency_key' => 'load-'.$index, 'payload_hash' => str_repeat('a', 64),
                'source_type' => 'collection_receipt', 'source_id' => (string) $id, 'event_type' => 'cash_contribution', 'currency' => 'NGN',
                'actor_user_id' => $actor->id, 'customer_profile_id' => $customerId, 'occurred_at' => $committed, 'occurred_on' => $date,
                'business_timezone' => 'Africa/Lagos', 'schema_version' => 1, 'committed_at' => $committed, 'created_at' => $now, 'updated_at' => $now];
            $entries[] = ['ledger_posting_group_id' => $id, 'line_number' => 1, 'ledger_account_id' => $receivable, 'side' => 'debit', 'amount_kobo' => 1000,
                'customer_profile_id' => $customerId, 'created_at' => $now, 'updated_at' => $now];
            $entries[] = ['ledger_posting_group_id' => $id, 'line_number' => 2, 'ledger_account_id' => $liability, 'side' => 'credit', 'amount_kobo' => 1000,
                'customer_profile_id' => $customerId, 'created_at' => $now, 'updated_at' => $now];
            $references[] = ['id' => $id, 'root_type' => 'collection_receipt', 'root_id' => (string) $id, 'transaction_reference' => $reference, 'created_at' => $now, 'updated_at' => $now];
            $projections[] = ['ledger_transaction_reference_id' => $id, 'projection_version' => 1, 'customer_profile_id' => $customerId, 'type' => 'contribution',
                'status' => 'posted', 'occurred_on' => $date, 'committed_at' => $committed, 'timezone' => 'Africa/Lagos', 'currency' => 'NGN',
                'gross_amount_kobo' => 1000, 'savings_effect_kobo' => 1000, 'fee_amount_kobo' => 0, 'posting_group_count' => 1,
                'source_max_group_id' => $id, 'source_hash' => str_repeat('b', 64), 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('ledger_posting_groups')->insert($groups);
        DB::table('ledger_entries')->insert($entries);
        DB::table('ledger_transaction_references')->insert($references);
        DB::table('ledger_transaction_projections')->insert($projections);
    }
    DB::table('ledger_projection_state')->where('id', 1)->update(['active_version' => 1, 'ledger_group_watermark' => $base + $total - 1,
        'verified_at' => $now, 'status' => 'ready', 'updated_at' => $now]);

    return ['reference' => 'TXN-LOAD-0000000', 'groups' => $total];
}

test('declared ledger and statement profile reports p95 response times against the Module 10 objectives', function (): void {
    if (env('LEDGER_LOAD_PROFILE') !== '1'
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Explicit isolated MySQL load profile only.');
    }

    config()->set(['collections.enabled' => true]);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();
    foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13] as $monthsBack) {
        FinancialPeriod::factory()->create(['month' => $today->startOfMonth()->subMonths($monthsBack)->toDateString(), 'changed_by_user_id' => $admin->id]);
    }
    LedgerAccount::query()->whereIn('code', [LedgerAccountCode::AgentReceivable->value, LedgerAccountCode::CustomerSavingsLiability->value])->update(['mapping_status' => 'mapped']);
    collectionLoadProfileDataset($admin, $customer, $agent, $plan, $today);
    $seeded = ledgerLoadProfileLedger($customer, $admin, $today);

    $this->withoutMiddleware(ThrottleRequests::class)->actingAs($agent->user);
    $samples = 20;
    $durations = ['balance_and_first_page' => [], 'reference_lookup' => [], 'statement_preview' => []];
    $from = $today->subDays(365)->toDateString();
    $to = $today->toDateString();
    $reference = DB::table('ledger_transaction_references')->join('ledger_transaction_projections as p', 'p.ledger_transaction_reference_id', '=', 'ledger_transaction_references.id')
        ->where('p.customer_profile_id', $customer->id)->value('ledger_transaction_references.transaction_reference');
    for ($sample = 0; $sample < $samples; $sample++) {
        $start = hrtime(true);
        $this->getJson(route('customers.ledger-balance', $customer->customer_id))->assertOk()->assertJsonPath('status', 'ready');
        $this->get(route('transactions.index', ['customer' => $customer->customer_id, 'from' => $from, 'to' => $to]))->assertOk();
        $durations['balance_and_first_page'][] = (hrtime(true) - $start) / 1_000_000_000;

        $start = hrtime(true);
        $this->get(route('transactions.show', $reference))->assertOk();
        $durations['reference_lookup'][] = (hrtime(true) - $start) / 1_000_000_000;

        $start = hrtime(true);
        $this->get(route('customers.statements.preview', ['customer' => $customer->customer_id, 'from' => $from, 'to' => $to]))->assertOk();
        $durations['statement_preview'][] = (hrtime(true) - $start) / 1_000_000_000;
    }
    $preview = app(StatementPreviewService::class)->preview($agent->user, $customer, $from, $to, 'Africa/Lagos');
    expect($preview['status'])->toBe('ready')->and(count($preview['lines']))->toBe(1990);
    $generation = [];
    $generationSamples = 5;
    for ($sample = 0; $sample < $generationSamples; $sample++) {
        $start = hrtime(true);
        $this->post(route('customers.statements.issue', $customer->customer_id), ['operation_reference' => (string) Str::uuid(), 'from' => $from, 'to' => $to,
            'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true])->assertRedirect();
        $artifact = FinancialArtifact::query()->where('kind', 'statement')->latest('id')->firstOrFail();
        app(FinancialArtifactService::class)->render($artifact->id);
        $generation[] = (hrtime(true) - $start) / 1_000_000_000;
        expect([$artifact->fresh()->status, $artifact->fresh()->failure_code])->toBe(['ready', null]);
        expect($artifact->fresh()->status)->toBe('ready')->and($artifact->fresh()->byte_size ?? 1)->toBeGreaterThan(0);
    }
    $durations['statement_generation_1990_rows'] = $generation;

    $p95 = [];
    foreach ($durations as $operation => $values) {
        sort($values);
        $p95[$operation] = $values[(int) ceil(count($values) * 0.95) - 1];
    }
    $result = ['dataset' => ['customers' => 10000, 'agents' => 30, 'plans' => 20000, 'slots' => 2000000, 'ledger_groups' => $seeded['groups'], 'statement_rows' => 1990],
        'samples_per_operation' => $samples, 'generation_samples' => $generationSamples, 'concurrency' => 1,
        'device' => 'local PHP test client on macOS', 'network' => 'in-process, no mobile network', 'p95_seconds' => $p95,
        'targets_seconds' => ['balance_and_first_page' => 2, 'reference_lookup' => 1, 'statement_preview' => 2, 'statement_generation_1990_rows' => 30]];
    file_put_contents('/private/tmp/saverapp-ledger-load-profile.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($p95['balance_and_first_page'])->toBeLessThanOrEqual(2.0)->and($p95['reference_lookup'])->toBeLessThanOrEqual(1.0)
        ->and($p95['statement_preview'])->toBeLessThanOrEqual(2.0)->and($p95['statement_generation_1990_rows'])->toBeLessThanOrEqual(30.0);
});
