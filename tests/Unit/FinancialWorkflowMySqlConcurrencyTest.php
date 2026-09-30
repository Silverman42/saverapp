<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\CashExecution;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CashExecutionService;
use App\Services\CollectionReadService;
use App\Services\ManualChargeService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../CashExecutionFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function financialMysqlRequest(): Request
{
    $request = Request::create('/admin/charges', 'POST');
    $session = new Store('financial-worker', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);

    return $request;
}

function financialMysqlDeduction(int $adminId, int $customerId, int $planId, int $categoryId, string $reference): Closure
{
    return static function () use ($adminId, $customerId, $planId, $categoryId, $reference): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe deduction race database.');
        }
        config()->set('fees.manual_charges_enabled', true);
        $request = Request::create('/admin/charges', 'POST');
        $session = new Store('financial-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        $customer = CustomerProfile::findOrFail($customerId);
        $plan = ThriftPlan::findOrFail($planId);
        try {
            app(ManualChargeService::class)->assess(User::findOrFail($adminId), $customer, $plan, ChargeCategoryVersion::findOrFail($categoryId),
                $reference, $customer->version, $plan->version, 'Confirmed deduction race instruction.', $request);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql competing deductions cannot both consume the same available savings', function (): void {
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->update(['mapping_status' => 'mapped']);
    $category = app(ManualChargeService::class)->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'race-deduction', 'kind' => 'deduction', 'purpose' => 'Authorized deduction race fixture',
        'customer_description' => 'Approved deduction', 'amount_kobo' => 80000], financialMysqlRequest());
    $outcomes = Concurrency::driver('process')->run([
        financialMysqlDeduction($admin->id, $customer->id, $plan->id, $category->id, (string) Str::uuid()),
        financialMysqlDeduction($admin->id, $customer->id, $plan->id, $category->id, (string) Str::uuid()),
    ]);
    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'posted'])
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(20000)
        ->and(DB::table('manual_charges')->count())->toBe(1)
        ->and(DB::table('manual_charge_notification_intents')->count())->toBe(1);
});

function financialMysqlAcknowledgement(int $customerUserId, int $executionId): Closure
{
    return static function () use ($customerUserId, $executionId): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe financial race database.');
        }
        app(CashExecutionService::class)->confirmReceipt(User::findOrFail($customerUserId), CashExecution::findOrFail($executionId));

        return 'acknowledged';
    };
}

test('mysql simultaneous acknowledgement posts one complete cash accounting and notice bundle', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified full Customer handoff for race.', 'confirmed' => true])->assertRedirect();
    $outcomes = Concurrency::driver('process')->run([
        financialMysqlAcknowledgement($customer->user_id, $execution->id),
        financialMysqlAcknowledgement($customer->user_id, $execution->id),
    ]);
    expect($outcomes)->toBe(['acknowledged', 'acknowledged'])
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1);
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
    expect($group->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe('30000')
        ->and($group->entries()->where('side', 'credit')->sum('amount_kobo'))->toBe('30000')
        ->and(DB::table('withdrawal_events')->where('event_type', 'cash_posted')->count())->toBe(1)
        ->and(DB::table('withdrawal_reservations')->where('status', 'consumed')->count())->toBe(1)
        ->and(CashExecution::findOrFail($execution->id)->status)->toBe('posted');
});

test('mysql payout racing a deduction preserves the gross reservation and never overdraws savings', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->update(['mapping_status' => 'mapped']);
    $category = app(ManualChargeService::class)->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'payout-race-deduction', 'kind' => 'deduction', 'purpose' => 'Authorized payout and deduction race fixture',
        'customer_description' => 'Approved deduction', 'amount_kobo' => 80000], financialMysqlRequest());
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified full Customer handoff before mixed race.', 'confirmed' => true])->assertRedirect();
    $outcomes = Concurrency::driver('process')->run([
        financialMysqlAcknowledgement($customer->user_id, $execution->id),
        financialMysqlDeduction($admin->id, $customer->id, $plan->id, $category->id, (string) Str::uuid()),
    ]);
    expect($outcomes)->toBe(['acknowledged', 'blocked'])
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(70000)
        ->and(DB::table('manual_charges')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->where('status', 'consumed')->count())->toBe(1);
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
    expect($group->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe('30000')
        ->and($group->entries()->where('side', 'credit')->sum('amount_kobo'))->toBe('30000');
});
