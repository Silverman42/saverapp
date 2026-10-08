<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CustomerReassignmentService;
use App\Services\FinancialWorkflowReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanSavingsReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../ManualChargeFixtures.php';
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
    $payload = reviewManualCharge($this, $payload);
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $this->assertDatabaseCount('manual_charges', 1);
    $this->assertDatabaseCount('fee_obligations', 1);
    $this->assertDatabaseCount('manual_charge_notification_intents', 2);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
        ->and(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(10001);
    $this->post(route('admin.charges.assess'), reviewManualCharge($this, [...$payload, 'reason' => 'Changed instructions']))->assertConflict();
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
    $payload = reviewManualCharge($this, $payload);
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
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set(['fees.manual_charges_enabled' => true, 'fees.deduction_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $category = manualChargeCategory($this, $admin, 'deduction');
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Incorrect charge under investigation', 'confirmed' => true];
    $payload = reviewManualCharge($this, $payload);
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $charge = ManualCharge::query()->sole();
    $original = LedgerPostingGroup::findOrFail($charge->ledger_posting_group_id);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $beforeCorrection = app(PlanFinancialActivityReadService::class)->read($customer->user, $plan->fresh());
    expect($beforeCorrection['status'])->toBe('available');
    expect(app(PlanFinancialActivityReadService::class)->readMany($customer->user, [$plan->fresh()])[$plan->plan_id])->toEqual($beforeCorrection);
    $savingsReader = app(PlanSavingsReadService::class);
    $beforeSavings = $savingsReader->readMany($customer->user, [$plan->fresh()])[$plan->plan_id];
    expect($beforeSavings['cycle']['liability'])->toBe('₦899.99')
        ->and($beforeSavings)->toEqual($savingsReader->read($customer->user, $plan->fresh()));
    $originalMetrics = collect($beforeCorrection['metrics'])->keyBy('code');
    expect($originalMetrics['other_deductions']['value'])->toBe(10001)
        ->and($originalMetrics['deduction_compensation']['value'])->toBe(0)
        ->and($originalMetrics['effective_deductions']['value'])->toBe(10001);
    $this->postJson(route('reversals.preview', $original->posting_reference))->assertForbidden();
    $this->travel(1)->days();
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp]);
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $afterCorrection = app(PlanFinancialActivityReadService::class)->read($customer->user, $plan->fresh());
    expect($afterCorrection['status'])->toBe('available');
    $postingHistory = app(PlanFinancialActivityReadService::class)->history($customer->user, $plan->fresh());
    expect($postingHistory['status'])->toBe('available')
        ->and(array_column($postingHistory['history']['data'], 'component'))->toBe(['deduction_compensation', 'other_deductions'])
        ->and(array_column($postingHistory['history']['data'], 'amount'))->toBe(['₦100.01', '₦100.01']);
    expect(app(PlanFinancialActivityReadService::class)->readMany($customer->user, [$plan->fresh()])[$plan->plan_id])->toEqual($afterCorrection);
    $afterSavings = $savingsReader->readMany($customer->user, [$plan->fresh()])[$plan->plan_id];
    expect($afterSavings['cycle']['liability'])->toBe('₦1,000.00')
        ->and($afterSavings)->toEqual($savingsReader->read($customer->user, $plan->fresh()));
    $window = app(FinancialWorkflowReadService::class)->activity($customer->user,
        CustomerProfile::query()->whereKey($customer->id), ['plan' => $plan->plan_id, 'from' => now('Africa/Lagos')->toDateString()], now()->toDateTimeString());
    $windowMetrics = collect($window['metrics'])->keyBy('code');
    expect($windowMetrics['effective_deductions']['value'])->toBe(-10001)
        ->and($windowMetrics['effective_deductions']['display'])->toBe('-₦100.01');
    $correctedMetrics = collect($afterCorrection['metrics'])->keyBy('code');
    expect($correctedMetrics['other_deductions']['value'])->toBe(10001)
        ->and($correctedMetrics['deduction_compensation']['value'])->toBe(10001)
        ->and($correctedMetrics['effective_deductions']['value'])->toBe(0);
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

test('different manual fee categories keep independent category versions and unique original pricing rule versions through republication and replay', function (): void {
    $this->freezeTime();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $base = ['kind' => 'manual_fee', 'purpose' => 'Reviewed Customer service category.',
        'customer_description' => 'Agreed service charge.', 'amount_ngn' => '10.01', 'confirmed' => true];
    $first = [...$base, 'publication_reference' => (string) Str::uuid(), 'category_key' => 'document-service'];
    $second = [...$base, 'publication_reference' => (string) Str::uuid(), 'category_key' => 'statement-service'];
    $revision = [...$first, 'publication_reference' => (string) Str::uuid(), 'amount_ngn' => '12.01'];

    foreach ([$first, $second, $revision] as $payload) {
        $this->actingAs($admin)->withSession(manualChargeSession())->post(route('admin.charges.publish'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    $categories = ChargeCategoryVersion::query()->orderBy('id')->get();
    expect($categories->pluck('version')->all())->toBe([1, 1, 2]);
    $rules = FeeRule::query()->where('kind', 'manual')->orderBy('version')->get();
    expect($rules->pluck('version')->all())->toBe([1, 2, 3]);
    expect($rules->pluck('rule_key')->all())->toBe(['manual-document-service', 'manual-statement-service', 'manual-document-service']);
    expect($rules->pluck('amount_kobo')->all())->toBe([1001, 1001, 1201]);
    expect($categories->pluck('fee_rule_id')->all())->toBe($rules->pluck('id')->all());
    $before = ['categories' => $categories->toArray(), 'rules' => $rules->toArray()];
    $this->post(route('admin.charges.publish'), $first)->assertRedirect()->assertSessionHasNoErrors();
    expect(['categories' => ChargeCategoryVersion::query()->orderBy('id')->get()->toArray(),
        'rules' => FeeRule::query()->where('kind', 'manual')->orderBy('version')->get()->toArray()])->toBe($before);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
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
    $payload = reviewManualCharge($this, $payload);
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
    $this->postJson(route('admin.charges.preview'), ['customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'Reviewed large deduction', 'mode' => 'deduction'])->assertConflict();
    $this->assertDatabaseCount('manual_charges', 0);
    $this->assertDatabaseHas('withdrawal_reservations', ['owner_reference' => $withdrawal->withdrawal_id, 'status' => 'live', 'gross_amount_kobo' => 30000]);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});

/** @return array<string, array<int, object>> */
function manualChargeAuthorityRows(): array
{
    $rows = [];
    foreach (['manual_charges', 'fee_obligations', 'fee_obligation_entries', 'fee_snapshots',
        'ledger_posting_groups', 'ledger_entries', 'manual_charge_notification_intents',
        'fee_savings_applications', 'fee_application_notification_intents', 'ledger_transaction_projections', 'ledger_transaction_references', 'management_mail_dispatches',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts',
        'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('FEE-AC-002: prepared charge rejects revoked grants or suspended actors without financial changes', function (string $kind, string $loss): void {
    config()->set('fees.manual_charges_enabled', true);
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $permission = $kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage;
    $admin->givePermissionTo($permission);
    $category = manualChargeCategory($this, $admin, $kind);
    $this->get(route('admin.charges.index', ['customer' => $customer->customer_id]))
        ->assertInertia(fn (Assert $page) => $page->where('customer.version', $customer->version)
            ->has('categories', 1)->where('categories.0.id', $category->id));
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Reviewed exact service instruction.', 'confirmed' => true];
    $payload = reviewManualCharge($this, $payload);
    if ($loss === 'grant') {
        $admin->revokePermissionTo($permission);
    } else {
        $admin->forceFill(['account_state' => AccountState::Suspended])->save();
    }
    $before = manualChargeAuthorityRows();
    if ($loss === 'grant') {
        $this->post(route('admin.charges.assess'), $payload)->assertForbidden();
    } else {
        $this->post(route('admin.charges.assess'), $payload)->assertRedirect(route('login'));
        $this->assertGuest();
    }
    expect(manualChargeAuthorityRows())->toEqual($before);
})->with(['manual fee grant revoked' => ['manual_fee', 'grant'], 'deduction grant revoked' => ['deduction', 'grant'],
    'manual fee actor suspended' => ['manual_fee', 'suspension'], 'deduction actor suspended' => ['deduction', 'suspension']]);

test('FEE-AC-002: actual reassignment preserves Admin charge authority and changes current Agent follow-up', function (string $kind): void {
    config()->set('fees.manual_charges_enabled', true);
    [$oldAgent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $permission = $kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage;
    $admin->givePermissionTo([$permission, AdminPermission::CustomersReassign]);
    $category = manualChargeCategory($this, $admin, $kind);
    $this->get(route('admin.charges.index', ['customer' => $customer->customer_id]))->assertOk();
    $payload = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Reviewed business-wide service instruction.', 'confirmed' => true];
    $payload = reviewManualCharge($this, $payload);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer, $replacement->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $quote['version'], 'assignment_version' => $quote['assignment_version'],
        'preview_token' => $quote['preview_token'], 'target_agent_id' => $replacement->id,
        'confirmed' => true, 'reason' => 'Reviewed service handover.', 'customer_explanation' => 'Your service Agent has changed.']);
    $before = manualChargeAuthorityRows();
    $this->post(route('admin.charges.assess'), $payload)->assertConflict();
    expect(manualChargeAuthorityRows())->toEqual($before);
    $customer = $customer->fresh();
    $this->get(route('admin.charges.index', ['customer' => $customer->customer_id]))
        ->assertInertia(fn (Assert $page) => $page->where('customer.version', $customer->version));
    $payload['customer_version'] = $customer->version;
    $payload = reviewManualCharge($this, $payload);
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('admin.charges.assess'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(ManualCharge::query()->count())->toBe(1);
    expect(ManualCharge::query()->sole()->actor_user_id)->toBe($admin->id);
    expect($admin->fresh()->hasPermissionTo($permission))->toBeTrue();
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($kind === 'manual_fee' ? 100000 : 89999);
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts',
        'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    $this->actingAs($oldAgent)->get(route('plans.show', $plan->plan_id))->assertNotFound();
    $this->actingAs($replacement->user)->get(route('plans.show', $plan->plan_id))->assertOk();
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertOk();
    $this->assertDatabaseHas('manual_charge_notification_intents', ['manual_charge_id' => ManualCharge::query()->sole()->id,
        'recipient_user_id' => $customer->user_id, 'customer_profile_id' => $customer->id]);
})->with(['manual fee' => 'manual_fee', 'deduction' => 'deduction']);

test('FEE-AC-002: actual fee receipt confirmation loses former Agent scope and binds current cash custodian', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set(['fees.manual_charges_enabled' => true, 'collections.enabled' => true]);
    [$oldAgent, $customer, $assignment, $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::CustomersReassign]);
    $category = manualChargeCategory($this, $admin, 'manual_fee');
    $this->post(route('admin.charges.assess'), reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(),
        'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id, 'category_id' => $category->id,
        'customer_version' => $customer->version, 'plan_version' => $plan->version,
        'reason' => 'Agreed outstanding service fee.', 'confirmed' => true]))->assertRedirect();
    $obligation = FeeObligation::query()->sole();
    $receipt = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => BusinessProfile::current()->version,
        'method' => 'cash', 'received_date' => now('Africa/Lagos')->toDateString(), 'savings_ngn' => '0.00',
        'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '100.01']], 'confirmed' => true];
    $receipt['preview_fingerprint'] = $this->actingAs($oldAgent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)
        ->assertOk()->json('preview_fingerprint');
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer, $replacement->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $quote['version'], 'assignment_version' => $quote['assignment_version'],
        'preview_token' => $quote['preview_token'], 'target_agent_id' => $replacement->id, 'confirmed' => true,
        'reason' => 'Reviewed change of service contact.', 'customer_explanation' => 'Your service Agent has changed.']);
    $before = manualChargeAuthorityRows();
    $originalCustody = DB::table('ledger_entries')->where('agent_profile_id', $oldAgent->agentProfile->id)->orderBy('id')->get()->all();
    $this->post(route('customers.collections.store', $customer->customer_id), $receipt)->assertNotFound();
    expect(manualChargeAuthorityRows())->toEqual($before);
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(10001);
    $customer = $customer->fresh();
    $receipt['customer_version'] = $customer->version;
    $receipt['assignment_version'] = $customer->currentAssignment->version;
    $receipt['attempt_reference'] = (string) Str::uuid();
    $receipt['preview_fingerprint'] = $this->actingAs($replacement->user)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $posted = CollectionReceipt::query()->where('attempt_reference', $receipt['attempt_reference'])->sole();
    expect($posted->recording_agent_profile_id)->toBe($replacement->id);
    expect($posted->recorded_by_user_id)->toBe($replacement->user_id);
    expect($posted->savings_amount_kobo)->toBe(0);
    expect($posted->fee_amount_kobo)->toBe(10001);
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
    expect(DB::table('ledger_entries')->where('agent_profile_id', $oldAgent->agentProfile->id)->orderBy('id')->get()->all())->toEqual($originalCustody);
    expect(DB::table('ledger_entries')->where('agent_profile_id', $replacement->id)->sum('amount_kobo'))->toBe(10001);
    expect(DB::table('collection_fee_components')->where('collection_receipt_id', $posted->id)->count())->toBe(1);
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_allocations',
        'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    Queue::assertPushed(DeliverCollectionNotificationIntent::class);
});

/** @return array{CustomerProfile, ThriftPlan, User, ChargeCategoryVersion, array<string, mixed>} */
function manualChargeReviewedFixture(object $test, string $kind = 'manual_fee', string $mode = 'assess_and_apply'): array
{
    $test->freezeTime();
    config()->set(['fees.manual_charges_enabled' => true, 'fees.savings_applications_enabled' => true]);
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo($kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage);
    $category = manualChargeCategory($test, $admin, $kind);
    $payload = reviewManualCharge($test, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'PRIVATE separately agreed service.', 'mode' => $mode, 'confirmed' => true]);

    return [$customer, $plan, $admin, $category, $payload];
}

test('FEE-AC-038: committed charge replay and status require the retained matching permission', function (string $kind, string $mode): void {
    Queue::fake([DeliverFeeApplicationNotificationIntent::class]);
    [$customer, , $admin, , $payload] = manualChargeReviewedFixture($this, $kind, $mode);
    $permission = $kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage;
    $unrelatedPermission = $kind === 'manual_fee' ? AdminPermission::DeductionsManage : AdminPermission::FeesManage;
    $this->postJson(route('admin.charges.assess'), $payload)
        ->assertExactJson(['status' => 'confirmed', 'charge_reference' => $payload['operation_reference']]);
    $charge = ManualCharge::query()->sole();
    expect($charge->actor_user_id)->toBe($admin->id)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(89999);
    if ($kind === 'manual_fee') {
        $fee = FeeObligation::findOrFail($charge->fee_obligation_id);
        expect($fee->settledAmountKobo())->toBe(10001)->and($fee->outstandingAmountKobo())->toBe(0);
        $this->assertDatabaseCount('fee_savings_applications', 1);
    } else {
        $this->assertDatabaseCount('fee_obligations', 0);
        expect(LedgerPostingGroup::findOrFail($charge->ledger_posting_group_id)->event_type)->toBe('other_deduction');
    }
    $committed = manualChargeAuthorityRows();
    $this->postJson(route('admin.charges.assess'), $payload)
        ->assertExactJson(['status' => 'confirmed', 'charge_reference' => $payload['operation_reference']]);
    $this->getJson(route('admin.charges.status', $payload['operation_reference']))
        ->assertExactJson(['status' => 'confirmed', 'charge_reference' => $payload['operation_reference']]);
    expect(manualChargeAuthorityRows())->toEqual($committed);
    $admin->givePermissionTo($unrelatedPermission);
    $admin->revokePermissionTo($permission);
    $baseline = manualChargeAuthorityRows();
    foreach ([$this->postJson(route('admin.charges.assess'), $payload),
        $this->getJson(route('admin.charges.status', $payload['operation_reference']))] as $denied) {
        $denied->assertForbidden()->assertJsonMissingPath('charge_reference');
        expect($denied->getContent())->not->toContain($payload['operation_reference'])
            ->not->toContain($payload['reason']);
    }
    expect(manualChargeAuthorityRows())->toEqual($baseline)
        ->and($charge->fresh()->actor_user_id)->toBe($admin->id);
    $this->assertDatabaseCount('manual_charges', 1);
})->with(['Combined manual fee payment' => ['manual_fee', 'assess_and_apply'],
    'Separate other deduction' => ['deduction', 'deduction']]);

test('FEE-AC-024: combined reviewed manual assessment and savings payment commit once with a recoverable original outcome', function (): void {
    Queue::fake([DeliverFeeApplicationNotificationIntent::class]);
    [$customer, , $admin, , $payload] = manualChargeReviewedFixture($this);
    $before = manualChargeAuthorityRows();

    $this->postJson(route('admin.charges.assess'), $payload)->assertExactJson(['status' => 'confirmed', 'charge_reference' => $payload['operation_reference']]);

    $charge = ManualCharge::query()->sole();
    $fee = FeeObligation::query()->findOrFail($charge->fee_obligation_id);
    expect($fee->assessedAmountKobo())->toBe(10001);
    expect($fee->settledAmountKobo())->toBe(10001);
    expect($fee->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(89999);
    $this->assertDatabaseHas('fee_savings_applications', ['operation_reference' => $payload['operation_reference'], 'fee_obligation_id' => $fee->id, 'amount_kobo' => 10001]);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    $this->assertDatabaseCount('manual_charge_notification_intents', 2);
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts', 'collection_allocations'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    $after = manualChargeAuthorityRows();
    config()->set(['fees.manual_charges_enabled' => false, 'fees.savings_applications_enabled' => false]);
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    $this->getJson(route('admin.charges.status', $payload['operation_reference']))->assertExactJson(['status' => 'confirmed', 'charge_reference' => $payload['operation_reference']]);
    expect(manualChargeAuthorityRows())->toEqual($after);
    $this->postJson(route('admin.charges.assess'), [...$payload, 'mode' => 'assessment_only'])->assertConflict();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($other)->getJson(route('admin.charges.status', $payload['operation_reference']))->assertNotFound();
    $this->actingAs($customer->user)->getJson(route('admin.charges.status', $payload['operation_reference']))->assertForbidden();
    Queue::assertPushed(DeliverFeeApplicationNotificationIntent::class, 1);
});

test('FEE-AC-024: late combined payment persistence failure leaves no new assessment debt posting projection or notice and allows exact retry', function (string $fault): void {
    Queue::fake([DeliverFeeApplicationNotificationIntent::class]);
    [$customer, , , , $payload] = manualChargeReviewedFixture($this);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $before = manualChargeAuthorityRows();
    if ($fault === 'projection') {
        DB::statement("CREATE TRIGGER fail_combined_charge BEFORE INSERT ON ledger_transaction_projections WHEN NEW.type = 'fee_application' BEGIN SELECT RAISE(ABORT, 'injected combined projection outage'); END");
    } else {
        DB::statement("CREATE TRIGGER fail_combined_charge BEFORE INSERT ON fee_application_notification_intents BEGIN SELECT RAISE(ABORT, 'injected combined notice outage'); END");
    }
    try {
        $this->postJson(route('admin.charges.assess'), $payload)->assertInternalServerError();
    } finally {
        DB::statement('DROP TRIGGER fail_combined_charge');
    }

    expect(manualChargeAuthorityRows())->toEqual($before);
    $this->assertDatabaseCount('manual_charges', 0);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_savings_applications', 0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
    Queue::assertNothingPushed();
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(89999);
})->with(['projection', 'notice']);

test('reviewed charges reject expired edited forged and changed savings reviews without owner effects', function (string $change): void {
    [$customer, , , , $payload] = manualChargeReviewedFixture($this);
    if ($change === 'expiry') {
        $this->travel(11)->minutes();
        $this->withSession(manualChargeSession());
    } elseif ($change === 'reason') {
        $payload['reason'] = 'Changed review reason.';
    } elseif ($change === 'fingerprint') {
        $payload['preview_fingerprint'] = str_repeat('0', 64);
    } else {
        [$agent, $currentCustomer, $assignment, $plan] = [User::query()->where('user_type', 'agent')->sole(), $customer, $customer->currentAssignment, ThriftPlan::query()->where('customer_profile_id', $customer->id)->sole()];
        enableFixtureMethod();
        submittedWithdrawal($agent, $currentCustomer, $assignment, $plan);
    }
    $before = manualChargeAuthorityRows();

    $this->postJson(route('admin.charges.assess'), $payload)->assertConflict();

    expect(manualChargeAuthorityRows())->toEqual($before);
    $this->assertDatabaseCount('manual_charges', 0);
    $this->assertDatabaseCount('fee_obligations', 0);
})->with(['expiry', 'reason', 'fingerprint', 'reservation']);

test('FEE-AC-025: deduction requires its separate current permission and explicit reviewed confirmation', function (): void {
    [$customer, , $admin, , $payload] = manualChargeReviewedFixture($this, 'deduction', 'deduction');
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $admin->revokePermissionTo(AdminPermission::DeductionsManage);
    $before = manualChargeAuthorityRows();
    $this->postJson(route('admin.charges.assess'), $payload)->assertForbidden();
    $previewInputs = array_intersect_key($payload, array_flip(['customer_id', 'plan_id', 'category_id', 'customer_version', 'plan_version', 'reason', 'mode']));
    $this->postJson(route('admin.charges.preview'), $previewInputs)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $this->withSession(manualChargeSession())->postJson(route('admin.charges.assess'), [...$payload, 'confirmed' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $unreviewed = $payload;
    unset($unreviewed['preview_fingerprint']);
    $this->postJson(route('admin.charges.assess'), $unreviewed)->assertUnprocessable()->assertJsonValidationErrors('preview_fingerprint');
    expect(manualChargeAuthorityRows())->toEqual($before);
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(89999);
    $this->assertDatabaseCount('fee_obligations', 0);
});

test('FEE-AC-026: reviewed five hundred naira other deduction preserves funded slots and receipts and remains outside fee earnings', function (): void {
    $this->freezeTime();
    config()->set(['fees.manual_charges_enabled' => true, 'collections.enabled' => true]);
    require_once __DIR__.'/../CollectionFixtures.php';
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3, 0, 50000);
    $receipt = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $receipt['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::DeductionsManage, AdminPermission::FeesManage]);
    LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->update(['mapping_status' => 'mapped']);
    $this->actingAs($admin)->withSession(manualChargeSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => 'approved-independent-service', 'kind' => 'deduction',
        'purpose' => 'Approved independent service', 'customer_description' => 'Agreed separate service deduction', 'amount_ngn' => '500.00', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->sole();
    $payload = reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'Reviewed approved service deduction.', 'confirmed' => true]);
    $before = manualChargeAuthorityRows();
    expect(DB::table('collection_allocations')->sum('amount_kobo'))->toBe(100000);

    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();

    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(50000);
    $group = LedgerPostingGroup::query()->where('source_type', 'manual_charge')->sole();
    expect($group->event_type)->toBe('other_deduction');
    expect($group->entries->count())->toBe(2);
    foreach ($group->entries as $entry) {
        expect($entry->amount_kobo)->toBe(50000);
        expect($entry->account->code)->toBe($entry->side === LedgerEntrySide::Debit ? LedgerAccountCode::CustomerSavingsLiability : LedgerAccountCode::OtherDeductionDestination);
    }
    foreach (['contribution_slots', 'collection_allocations', 'collection_receipts', 'plan_terms_revisions'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    $this->get(route('admin.fees.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.earnings.status', 'available')->where('summary.earnings.lifetime_gross_kobo', 0)
        ->where('summary.earnings.lifetime_net_kobo', 0)->where('summary.outstanding_amount_kobo', 0));
    $this->assertDatabaseCount('fee_obligations', 0);
    $after = manualChargeAuthorityRows();
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    $this->getJson(route('admin.charges.status', $payload['operation_reference']))->assertOk();
    expect(manualChargeAuthorityRows())->toEqual($after);
});

test('manual charge preview discloses assessment-only and combined consequences without financial or notice effects', function (): void {
    [$customer, , , , $payload] = manualChargeReviewedFixture($this, 'manual_fee', 'assessment_only');
    $before = manualChargeAuthorityRows();
    $inputs = array_intersect_key($payload, array_flip(['customer_id', 'plan_id', 'category_id', 'customer_version', 'plan_version', 'reason', 'mode']));

    $this->postJson(route('admin.charges.preview'), $inputs)->assertOk()
        ->assertJsonPath('posted_savings', '₦1,000.00')->assertJsonPath('available_savings', '₦1,000.00')
        ->assertJsonPath('amount', '₦100.01')->assertJsonPath('remaining_savings', '₦1,000.00')
        ->assertJsonPath('remaining_fee', '₦100.01')->assertJsonPath('destination_code', LedgerAccountCode::FeeIncome->value);
    $this->postJson(route('admin.charges.preview'), [...$inputs, 'mode' => 'assess_and_apply'])->assertOk()
        ->assertJsonPath('remaining_savings', '₦899.99')->assertJsonPath('remaining_available', '₦899.99')->assertJsonPath('remaining_fee', '₦0.00');

    expect(manualChargeAuthorityRows())->toEqual($before);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(10001);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
    $this->assertDatabaseCount('fee_savings_applications', 0);
    $this->get(route('admin.fees.index'))->assertInertia(fn (Assert $page) => $page->where('summary.earnings.lifetime_gross_kobo', 0));
});

test('undefined approved purpose cannot be published or used by a prepared manual charge', function (): void {
    [, , , $category, $payload] = manualChargeReviewedFixture($this);
    $publicationCount = ChargeCategoryVersion::query()->count();
    $this->postJson(route('admin.charges.publish'), ['publication_reference' => (string) Str::uuid(), 'category_key' => 'undefined-purpose',
        'kind' => 'manual_fee', 'purpose' => '', 'customer_description' => 'A charge with no approved purpose', 'amount_ngn' => '100.00', 'confirmed' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('purpose');
    $this->assertDatabaseCount('charge_category_versions', $publicationCount);
    DB::table('charge_category_versions')->where('id', $category->id)->update(['purpose' => '']);
    $before = manualChargeAuthorityRows();

    $this->postJson(route('admin.charges.assess'), $payload)->assertConflict();

    expect(manualChargeAuthorityRows())->toEqual($before);
    $this->assertDatabaseCount('fee_obligations', 0);
});

test('FEE-AC-028: linked unpaid manual correction preserves original terms through full corrected payment and original correction replay', function (string $direction, string $amount, int $corrected, int $remainingSavings): void {
    [$customer, $plan, , , $assessment] = manualChargeReviewedFixture($this, 'manual_fee', 'assessment_only');
    $this->postJson(route('admin.charges.assess'), $assessment)->assertOk();
    $fee = FeeObligation::query()->sole();
    $originalFee = $fee->getAttributes();
    $originalSnapshot = $fee->feeSnapshot->getAttributes();
    $originalAssessment = $fee->entries()->sole()->getAttributes();
    $correction = ['amount_ngn' => $amount, 'direction' => $direction,
        'reason' => 'PRIVATE evidence correcting the unpaid agreed assessment.', 'customer_description' => 'Your unpaid service assessment was corrected.',
        'attempt_reference' => (string) Str::uuid()];

    $this->post(route('admin.fees.obligations.correct', $fee), $correction)->assertRedirect()->assertSessionHasNoErrors();

    expect($fee->fresh()->assessedAmountKobo())->toBe($corrected);
    expect($fee->fresh()->outstandingAmountKobo())->toBe($corrected);
    expect($fee->fresh()->getAttributes())->toBe($originalFee);
    expect($fee->fresh()->feeSnapshot->getAttributes())->toBe($originalSnapshot);
    expect($fee->entries()->firstOrFail()->getAttributes())->toBe($originalAssessment);
    $this->assertDatabaseHas('fee_obligation_entries', ['fee_obligation_id' => $fee->id,
        'source_type' => 'admin_assessment_correction', 'source_id' => $correction['attempt_reference']]);
    $this->get(route('admin.fees.index'))->assertInertia(fn (Assert $page) => $page->where('summary.earnings.lifetime_gross_kobo', 0));
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE confirmed corrected fee payment.', 'customer_description' => 'Corrected service fee paid from savings.'];
    $quote = $this->postJson(route('admin.fees.obligations.savings-preview', $fee), $data)->assertOk()->json();
    $payment = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payment)->assertOk();
    expect($fee->fresh()->settledAmountKobo())->toBe($corrected);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($remainingSavings);
    $after = manualChargeAuthorityRows();
    $this->post(route('admin.fees.obligations.correct', $fee), $correction)->assertRedirect()->assertSessionHasNoErrors();
    expect(manualChargeAuthorityRows())->toEqual($after);
    $this->postJson(route('admin.fees.obligations.correct', $fee), [...$correction, 'attempt_reference' => (string) Str::uuid()])->assertConflict();
    $this->postJson(route('admin.fees.obligations.correct', $fee), [...$correction, 'amount_ngn' => '1.00'])->assertConflict();
    expect(manualChargeAuthorityRows())->toEqual($after);
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payment)->assertOk();
    expect(manualChargeAuthorityRows())->toEqual($after);
})->with(['reduce original unpaid fee' => ['reduce', '40.00', 6001, 93999], 'increase through linked evidence' => ['increase', '20.00', 12001, 87999]]);

test('FEE-AC-028: waived debt cannot be restored through a new correction or undefined reset instruction', function (): void {
    [, , , , $assessment] = manualChargeReviewedFixture($this, 'manual_fee', 'assessment_only');
    $this->postJson(route('admin.charges.assess'), $assessment)->assertOk();
    $fee = FeeObligation::query()->sole();
    $this->post(route('admin.fees.obligations.waive', $fee), ['amount_ngn' => '40.00', 'reason' => 'PRIVATE independently approved waiver.',
        'customer_description' => 'Forty naira of your fee was waived.', 'attempt_reference' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
    $before = manualChargeAuthorityRows();
    $correction = ['amount_ngn' => '40.00', 'direction' => 'increase', 'reason' => 'Attempt to restore waived debt.',
        'customer_description' => 'Attempted reset.', 'attempt_reference' => (string) Str::uuid()];

    $this->postJson(route('admin.fees.obligations.correct', $fee), $correction)->assertConflict();
    $this->postJson(route('admin.fees.obligations.correct', $fee), [...$correction, 'direction' => 'reset'])->assertUnprocessable()->assertJsonValidationErrors('direction');

    expect(manualChargeAuthorityRows())->toEqual($before);
    expect($fee->fresh()->waivedAmountKobo())->toBe(4000);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(6001);
});

test('corrected savings payment rejects damaged retained correction evidence on replay without changing money', function (string $damage): void {
    [, $plan, , , $assessment] = manualChargeReviewedFixture($this, 'manual_fee', 'assessment_only');
    $this->postJson(route('admin.charges.assess'), $assessment)->assertOk();
    $fee = FeeObligation::query()->sole();
    $correctionReference = (string) Str::uuid();
    $correction = ['amount_ngn' => '20.00', 'direction' => 'increase', 'reason' => 'PRIVATE evidence for the corrected unpaid amount.',
        'customer_description' => 'Your unpaid service assessment was corrected.', 'attempt_reference' => $correctionReference];
    $this->post(route('admin.fees.obligations.correct', $fee), $correction)->assertRedirect()->assertSessionHasNoErrors();
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE corrected fee payment.', 'customer_description' => 'Corrected fee paid from savings.'];
    $quote = $this->postJson(route('admin.fees.obligations.savings-preview', $fee), $data)->assertOk()->json();
    $payment = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payment)->assertOk();
    $entry = DB::table('fee_obligation_entries')->where('source_id', $correctionReference)->sole();
    if ($damage === 'missing') {
        $noticeSources = DB::table('fee_obligation_events')->where('fee_obligation_entry_id', $entry->id)->pluck('id');
        DB::table('fee_obligation_notification_intents')->whereIn('fee_obligation_event_id', $noticeSources)->delete();
        DB::table('fee_obligation_events')->whereIn('id', $noticeSources)->delete();
        DB::table('fee_obligation_entries')->where('id', $entry->id)->delete();
    } else {
        DB::table('fee_obligation_entries')->where('id', $entry->id)->update(match ($damage) {
            'amount' => ['amount_kobo' => 1000], 'currency' => ['currency' => 'USD'], 'source' => ['source_type' => 'undefined_reset']
        });
    }
    $before = manualChargeAuthorityRows();

    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payment)->assertConflict();

    expect(manualChargeAuthorityRows())->toEqual($before);
})->with(['missing', 'amount', 'currency', 'source']);

test('correction replay rejects damaged administrative source identity without restoring debt', function (): void {
    [, , , , $assessment] = manualChargeReviewedFixture($this, 'manual_fee', 'assessment_only');
    $this->postJson(route('admin.charges.assess'), $assessment)->assertOk();
    $fee = FeeObligation::query()->sole();
    $payload = ['amount_ngn' => '40.00', 'direction' => 'reduce', 'reason' => 'PRIVATE linked evidence.',
        'customer_description' => 'Your unpaid service assessment was corrected.', 'attempt_reference' => (string) Str::uuid()];
    $this->post(route('admin.fees.obligations.correct', $fee), $payload)->assertRedirect()->assertSessionHasNoErrors();
    DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->update(['source_type' => 'undefined_reset']);
    $before = manualChargeAuthorityRows();

    $this->postJson(route('admin.fees.obligations.correct', $fee), $payload)->assertConflict();

    expect(manualChargeAuthorityRows())->toEqual($before);
});

test('FEE-AC-046: an actual reviewed deduction rejects a protected destination override without financial or authority changes', function (): void {
    $this->freezeTime();
    config()->set(['fees.manual_charges_enabled' => true, 'collections.enabled' => true]);
    require_once __DIR__.'/../CollectionFixtures.php';
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    $receipt = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
    $receipt['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $category = manualChargeCategory($this, $admin, 'deduction');
    $payload = reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'Reviewed independently agreed service deduction.', 'confirmed' => true]);
    $before = manualChargeAuthorityRows();
    foreach (['customer_profiles', 'charge_category_versions', 'fee_rules', 'ledger_accounts', 'collection_batches',
        'collection_fee_components', 'permissions', 'roles'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    foreach (['model_has_permissions' => ['permission_id', 'model_type', 'model_id'],
        'model_has_roles' => ['role_id', 'model_type', 'model_id'], 'role_has_permissions' => ['role_id', 'permission_id']] as $table => $columns) {
        $query = DB::table($table);
        foreach ($columns as $column) {
            $query->orderBy($column);
        }
        $before[$table] = $query->get()->all();
    }
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(100000);

    $this->postJson(route('admin.charges.assess'), [...$payload, 'destination_code' => LedgerAccountCode::BusinessCash->value])
        ->assertUnprocessable()->assertJsonValidationErrors('request');

    foreach ($before as $table => $rows) {
        $query = DB::table($table);
        $columns = match ($table) {
            'model_has_permissions' => ['permission_id', 'model_type', 'model_id'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id'],
            'role_has_permissions' => ['role_id', 'permission_id'],
            default => ['id'],
        };
        foreach ($columns as $column) {
            $query->orderBy($column);
        }
        expect($query->get()->all())->toEqual($rows);
    }
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(89999)
        ->and(ManualCharge::query()->sole()->charge_category_version_id)->toBe($category->id);
});
