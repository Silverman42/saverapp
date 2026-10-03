<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CustomerReassignmentService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';

function customerBalanceBatchFixture(): array
{
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$agent, $customer, $withdrawal];
}

function customerBalanceBatchRows(): array
{
    $rows = [];
    foreach (['ledger_accounts', 'ledger_entries', 'ledger_posting_groups', 'ledger_projection_state',
        'ledger_transaction_projections', 'withdrawal_requests', 'withdrawal_reservations',
        'collection_receipts', 'customer_assignments', 'fee_obligations', 'fee_obligation_entries'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('scoped Customer balance batches match posted savings and live gross reservations for permitted viewers', function (string $role): void {
    [$agent, $customer] = customerBalanceBatchFixture();
    $other = CustomerProfile::factory()->create();
    $viewer = match ($role) {
        'admin' => User::factory()->admin()->withTwoFactor()->create(),
        'agent' => $agent,
        'customer' => $customer->user,
    };
    $before = customerBalanceBatchRows();
    $reader = app(LedgerTransactionReadService::class);
    $one = $reader->balance($viewer, $customer);
    $batch = $reader->balances($viewer, [$customer, $other]);

    expect($batch[$customer->id])->toEqual($one)
        ->and($one)->toBe(['status' => 'ready', 'liability_kobo' => 100000, 'reservations_kobo' => 30000, 'available_kobo' => 70000]);
    expect($batch[$other->id])->toBe($role === 'admin'
        ? ['status' => 'ready', 'liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0]
        : ['status' => 'unavailable']);
    expect(customerBalanceBatchRows())->toEqual($before);
})->with(['admin', 'agent', 'customer']);

test('one damaged Customer position cannot manufacture zero or hide another verified balance', function (string $damage): void {
    [, $customer, $withdrawal] = customerBalanceBatchFixture();
    $other = CustomerProfile::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    match ($damage) {
        'entry side' => DB::table('ledger_entries')->where('customer_profile_id', $customer->id)
            ->where('ledger_account_id', LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->sole()->id)->update(['side' => 'unknown']),
        'zero entry' => DB::table('ledger_entries')->where('customer_profile_id', $customer->id)
            ->where('ledger_account_id', LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->sole()->id)->update(['amount_kobo' => 0]),
        'fractional entry' => DB::table('ledger_entries')->where('customer_profile_id', $customer->id)
            ->where('ledger_account_id', LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->sole()->id)->update(['amount_kobo' => 100000.5]),
        'zero reservation' => DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->update(['gross_amount_kobo' => 0]),
        'over reservation' => DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->update(['gross_amount_kobo' => 100001]),
    };
    $before = customerBalanceBatchRows();
    $reader = app(LedgerTransactionReadService::class);

    expect($reader->balance($admin, $customer))->toBe(['status' => 'unavailable'])
        ->and($reader->balances($admin, [$customer, $other]))->toBe([
            $customer->id => ['status' => 'unavailable'],
            $other->id => ['status' => 'ready', 'liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0],
        ])->and(customerBalanceBatchRows())->toEqual($before);
})->with(['entry side', 'zero entry', 'fractional entry', 'zero reservation', 'over reservation']);

test('unready projections and invalid mappings make the whole batch unavailable', function (string $damage): void {
    [, $customer] = customerBalanceBatchFixture();
    $other = CustomerProfile::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    match ($damage) {
        'projection' => DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable']),
        'watermark' => DB::table('ledger_projection_state')->where('id', 1)->update(['ledger_group_watermark' => 0]),
        'mapping' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['mapping_status' => 'unmapped']),
        'currency' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['currency' => 'USD']),
        'account class' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['account_class' => 'asset']),
        'normal balance' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['normal_balance' => 'debit']),
    };
    $before = customerBalanceBatchRows();
    $reader = app(LedgerTransactionReadService::class);
    expect($reader->balances($admin, [$customer, $other]))->toBe([
        $customer->id => ['status' => 'unavailable'], $other->id => ['status' => 'unavailable'],
    ])->and($reader->balance($admin, $customer))->toBe(['status' => 'unavailable'])
        ->and(customerBalanceBatchRows())->toEqual($before);
})->with(['projection', 'watermark', 'mapping', 'currency', 'account class', 'normal balance']);

test('current reassignment removes former Agent batch balance access without changing money', function (): void {
    [$agent, $customer] = customerBalanceBatchFixture();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $owner = app(CustomerReassignmentService::class);
    $preview = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
        'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
        'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed transfer.', 'customer_explanation' => 'Your service contact changed.']);
    $before = customerBalanceBatchRows();
    $reader = app(LedgerTransactionReadService::class);
    expect($reader->balances($agent, [$customer])[$customer->id])->toBe(['status' => 'unavailable'])
        ->and($reader->balances($replacement->user, [$customer])[$customer->id])->toEqual($reader->balance($customer->user, $customer))
        ->and(customerBalanceBatchRows())->toEqual($before);
});

test('Customer balance batch queries remain constant across 26 identities', function (): void {
    [, $customer] = customerBalanceBatchFixture();
    $others = CustomerProfile::factory()->count(25)->create();
    $customers = [$customer, ...$others->all()];
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $reader = app(LedgerTransactionReadService::class);
    $reader->balances($admin, [$customer]);
    $before = customerBalanceBatchRows();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $reader->balances($admin, [$customer]);
    $singleCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    $batch = $reader->balances($admin, $customers);
    $batchCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($batchCount)->toBeLessThanOrEqual($singleCount + 1)->and($batch)->toHaveCount(26)
        ->and($batch[$customer->id]['available_kobo'])->toBe(70000);
    foreach ($others as $other) {
        expect($batch[$other->id])->toEqual($reader->balance($admin, $other));
    }
    expect(customerBalanceBatchRows())->toEqual($before);
});

test('empty duplicate and oversized Customer balance batches never query sources', function (): void {
    $customer = CustomerProfile::factory()->create();
    $reader = app(LedgerTransactionReadService::class);
    $owner = app(CollectionReadService::class);
    $viewer = $customer->user;
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($reader->balances($viewer, []))->toBe([])
        ->and($owner->positions([]))->toBe([]);
    expect(fn () => $reader->balances($viewer, [$customer, $customer]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reader->balances($viewer, array_fill(0, 101, $customer)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $owner->positions([$customer->id, $customer->id]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $owner->positions(range(1, 101)))->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});
