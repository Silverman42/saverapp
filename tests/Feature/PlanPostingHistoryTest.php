<?php

use App\Enums\AdminPermission;
use App\Models\ChargeCategoryVersion;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanFinancialActivityReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/../ManualChargeFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';

function postingHistoryFixture(object $test, int $count = 1): array
{
    $test->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('fees.manual_charges_enabled', true);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $test->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('admin.charges.publish'), ['publication_reference' => (string) Str::uuid(),
            'category_key' => 'posting-history-deduction', 'kind' => 'deduction', 'purpose' => 'Approved service category',
            'customer_description' => 'Agreed service charge', 'amount_ngn' => '1.00', 'confirmed' => true])->assertRedirect();
    $category = ChargeCategoryVersion::query()->sole();
    for ($index = 0; $index < $count; $index++) {
        $test->post(route('admin.charges.assess'), reviewManualCharge($test, ['operation_reference' => (string) Str::uuid(),
            'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id, 'category_id' => $category->id,
            'customer_version' => $customer->fresh()->version, 'plan_version' => $plan->fresh()->version,
            'reason' => 'Reviewed service instruction '.$index, 'confirmed' => true]))->assertRedirect();
    }
    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$agent, $customer, $plan->fresh(), $admin];
}

function postingHistoryRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'ledger_posting_groups', 'ledger_entries', 'manual_charges', 'ledger_projection_state',
        'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('cycle posting history paginates actual equal-time deductions without changing full totals or financial records', function (): void {
    [$agent, $customer, $plan, $admin] = postingHistoryFixture($this, 26);
    $reader = app(PlanFinancialActivityReadService::class);
    $before = postingHistoryRows();
    $first = $reader->history($customer->user, $plan);
    $second = $reader->history($customer->user, $plan, 2);
    $all = $reader->history($customer->user, $plan, 1, 50);
    expect($first['status'])->toBe('available')->and($first['history']['total'])->toBe(26)
        ->and($first['history']['data'])->toHaveCount(25)->and($second['history']['data'])->toHaveCount(1)
        ->and([...$first['history']['data'], ...$second['history']['data']])->toEqual($all['history']['data'])
        ->and(array_unique(array_column($all['history']['data'], 'key')))->toHaveCount(26);
    foreach ($all['history']['data'] as $entry) {
        expect($entry['amount'])->toBe('₦1.00')->and($entry['component'])->toBe('other_deductions')
            ->and(array_keys($entry))->toBe(['key', 'reference', 'component', 'title', 'amount', 'occurred_on', 'committed_at', 'timezone']);
    }
    expect(collect($reader->read($customer->user, $plan)['metrics'])->keyBy('code')['other_deductions']['value'])->toBe(2600)
        ->and($reader->history($agent, $plan)['history'])->toEqual($first['history'])
        ->and($reader->history($admin, $plan)['history'])->toEqual($first['history']);
    $this->actingAs($customer->user)->get(route('plans.show', ['plan' => $plan->plan_id, 'activity_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('plan.posting_history.history.total', 26)
            ->where('plan.posting_history.history.current_page', 2)->has('plan.posting_history.history.data', 1));
    expect($reader->history($customer->user, $plan, 3)['history']['data'])->toBe([])
        ->and(postingHistoryRows())->toEqual($before);
});

test('cycle posting history denies foreign and stale identities and keeps damaged sources unavailable', function (string $damage): void {
    [, $customer, $plan] = postingHistoryFixture($this);
    $reader = app(PlanFinancialActivityReadService::class);
    expect(fn () => $reader->history(User::factory()->customer()->create(), $plan))->toThrow(NotFoundHttpException::class);
    $stale = clone $plan;
    $stale->version++;
    expect($reader->history($customer->user, $stale)['history'])->toBeNull();
    match ($damage) {
        'mapping' => LedgerAccount::query()->update(['mapping_status' => 'unmapped']),
        'projection' => DB::table('ledger_projection_state')->update(['status' => 'rebuilding']),
        'amount' => DB::table('ledger_entries')->whereIn('ledger_posting_group_id', DB::table('ledger_posting_groups')->where('event_type', 'other_deduction')->select('id'))
            ->update(['amount_kobo' => 100.5]),
    };
    $before = postingHistoryRows();
    expect($reader->history($customer->user, $plan)['status'])->toBe('unavailable')
        ->and($reader->history($customer->user, $plan)['history'])->toBeNull()
        ->and(postingHistoryRows())->toEqual($before);
})->with(['mapping', 'projection', 'amount']);

test('cycle posting history rejects unsupported pagination before reading sources', function (int $page, int $size): void {
    $reader = app(PlanFinancialActivityReadService::class);
    expect(fn () => $reader->history(User::factory()->customer()->make(), new ThriftPlan, $page, $size))
        ->toThrow(InvalidArgumentException::class);
})->with([[0, 25], [1000001, 25], [1, 10], [1, 101]]);

test('the fees report lists every posted manual deduction with the same full total as cycle history', function (): void {
    [, $customer, , $admin] = postingHistoryFixture($this, 3);
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();

    $this->actingAs($admin)->get(route('reports.show', 'fees'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.other_deductions.total', 3)
        ->where('report.sections.other_deductions.rows.0.customer', $customer->customer_id)
        ->where('report.sections.other_deductions.metrics', fn ($metrics) => collect($metrics)->firstWhere('code', 'fee_activity_amount')['value'] === 300));
});
