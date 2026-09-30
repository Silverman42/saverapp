<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountCode;
use App\Models\ChargeCategoryVersion;
use App\Models\FeeObligation;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\LedgerTransactionProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

function manualChargeSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

function manualChargeCategory(object $test, User $admin, string $kind): ChargeCategoryVersion
{
    LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->update(['mapping_status' => 'mapped']);
    $test->actingAs($admin)->withSession(manualChargeSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(),
        'category_key' => 'approved-'.$kind, 'kind' => $kind, 'purpose' => 'Approved service category',
        'customer_description' => 'Agreed service charge', 'amount_ngn' => '100.01', 'confirmed' => true,
    ])->assertRedirect();

    return ChargeCategoryVersion::query()->latest('id')->firstOrFail();
}

test('manual fee assessment preserves savings and creates a replay-safe unpaid obligation and durable notice', function (): void {
    config()->set('fees.manual_charges_enabled', true);
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $category = manualChargeCategory($this, $admin, 'manual_fee');
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Customer agreed service terms', 'confirmed' => true];
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $this->assertDatabaseCount('manual_charges', 1);
    $this->assertDatabaseCount('fee_obligations', 1);
    $this->assertDatabaseCount('manual_charge_notification_intents', 1);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
        ->and(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(10001);
    $this->post(route('admin.charges.assess'), [...$payload, 'reason' => 'Changed instructions'])->assertConflict();
});

test('deduction permission is independent and protected savings cannot be charged', function (): void {
    config()->set('fees.manual_charges_enabled', true);
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $category = manualChargeCategory($this, $admin, 'deduction');
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Reviewed charge instruction', 'confirmed' => true];
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $this->post(route('admin.charges.assess'), $payload)->assertSessionHasErrors('customer_status');
    $customer->update(['operational_status' => CustomerStatus::Active]);
    $admin->revokePermissionTo(AdminPermission::DeductionsManage);
    $this->post(route('admin.charges.assess'), $payload)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(89999);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $this->assertDatabaseCount('fee_obligations', 0);
});

test('erroneous deduction requires Agent initiation and independent Admin reversal review to restore savings', function (): void {
    config()->set(['fees.manual_charges_enabled' => true, 'fees.deduction_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $category = manualChargeCategory($this, $admin, 'deduction');
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Incorrect charge under investigation', 'confirmed' => true];
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $charge = ManualCharge::query()->sole();
    $original = LedgerPostingGroup::findOrFail($charge->ledger_posting_group_id);
    $this->postJson(route('reversals.preview', $original->posting_reference))->assertForbidden();
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect((int) DB::table('ledger_transaction_projections')->where('type', 'reversal')->value('savings_effect_kobo'))->toBe(10001);
    $this->assertDatabaseCount('manual_charges', 1);
});

test('category publication replay preserves its version and rejects conflicting terms', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $payload = ['publication_reference' => (string) Str::uuid(), 'category_key' => 'document-service',
        'kind' => 'manual_fee', 'purpose' => 'Customer requested document preparation',
        'customer_description' => 'Document preparation', 'amount_ngn' => '10.01', 'confirmed' => true];
    $this->actingAs($admin)->withSession(manualChargeSession())->post(route('admin.charges.publish'), $payload)->assertRedirect();
    $this->post(route('admin.charges.publish'), $payload)->assertRedirect();
    $this->assertDatabaseCount('charge_category_versions', 1);
    $this->assertDatabaseCount('fee_rules', 1);
    $this->post(route('admin.charges.publish'), [...$payload, 'amount_ngn' => '10.02'])->assertConflict();
    expect(ChargeCategoryVersion::query()->sole()->amount_kobo)->toBe(1001);
});

test('deductions reject stale category approvals and changed account mapping versions without posting', function (string $change): void {
    config()->set('fees.manual_charges_enabled', true);
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $category = manualChargeCategory($this, $admin, 'deduction');
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Approved deduction instruction', 'confirmed' => true];
    if ($change === 'category') {
        manualChargeCategory($this, $admin, 'deduction');
    } else {
        LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->increment('version');
    }
    $this->post(route('admin.charges.assess'), $payload)->assertConflict();
    $this->assertDatabaseCount('manual_charges', 0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
})->with(['category', 'mapping']);

test('deduction cannot consume savings reserved for an approved cash withdrawal', function (): void {
    config()->set('fees.manual_charges_enabled', true);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $category = manualChargeCategory($this, $admin, 'deduction');
    $this->post(route('admin.charges.publish'), ['publication_reference' => (string) Str::uuid(), 'category_key' => $category->category_key,
        'kind' => 'deduction', 'purpose' => 'Approved full deduction', 'customer_description' => 'Full deduction',
        'amount_ngn' => '800.00', 'confirmed' => true])->assertRedirect();
    $category = ChargeCategoryVersion::query()->latest('id')->firstOrFail();
    $this->post(route('admin.charges.assess'), ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'Reviewed large deduction', 'confirmed' => true])->assertConflict();
    $this->assertDatabaseCount('manual_charges', 0);
    $this->assertDatabaseHas('withdrawal_reservations', ['owner_reference' => $withdrawal->withdrawal_id, 'status' => 'live', 'gross_amount_kobo' => 30000]);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});
