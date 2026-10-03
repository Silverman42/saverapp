<?php

use App\Enums\AdminPermission;
use App\Models\BusinessProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Services\CollectionService;
use App\Services\FeeObligationService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';

uses(CreatesLifecycleCustomers::class);

function feeQuoteFinancialRows(): array
{
    $rows = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'collection_receipts', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function feeQuoteFreshRequest(): Request
{
    $request = Request::create('/admin/fees', 'POST');
    $session = new Store('fee-quote-review', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);

    return $request;
}

test('withdrawal quotes disclose an existing cycle fee without assessing or deducting it twice', function (string $disposition): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    enableFixtureMethod();
    $completion = $disposition === 'completion';
    $rule = FeeRule::create(['version' => 1, 'name' => 'Existing cycle fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'fixed', 'timing' => $completion ? 'cycle_completion' : 'first_contribution', 'basis' => 'none',
        'settlement_source' => $completion ? 'withdrawal_payout' : 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 10000,
        'customer_description' => 'One hundred naira cycle fee.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated fixture.']);
    $data = ['name' => 'Captured cycle', 'amount_ngn' => '1000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $plans->preview($actor, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($actor, $customer, (string) Str::uuid(), $data)['plan'];
    $receipt = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), $completion ? '2000.00' : '1000.00');
    $receipt['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $receipt)['preview_fingerprint'];
    app(CollectionService::class)->record($actor, $customer, $receipt);
    $plan->refresh();
    $obligation = FeeObligation::query()->sole();
    $payload = [...withdrawalPayload($customer, $customer->currentAssignment, $plan), 'type' => $completion ? 'end_of_cycle' : 'partial',
        'gross_ngn' => $completion ? '2000.00' : '300.00'];
    $initial = app(WithdrawalService::class)->preview($actor, $customer, $payload);
    if (in_array($disposition, ['waived', 'stale'], true)) {
        $admin->givePermissionTo(AdminPermission::FeesManage);
        app(FeeObligationService::class)->waive($admin, $obligation->id, 10000, 'Approved explicit fee disposition.',
            'Your cycle fee was waived.', (string) Str::uuid(), feeQuoteFreshRequest());
    } elseif ($disposition === 'settled') {
        $feeReceipt = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '0.00');
        $feeReceipt['plan_id'] = '';
        $feeReceipt['plan_version'] = null;
        $feeReceipt['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '100.00']];
        $feeReceipt['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $feeReceipt)['preview_fingerprint'];
        app(CollectionService::class)->record($actor, $customer, $feeReceipt);
    } elseif ($disposition === 'fractional snapshot') {
        DB::table('fee_snapshots')->where('id', $plan->currentTermsRevision()->fee_snapshot_id)->update(['amount_kobo' => 10000.5]);
    } elseif ($disposition === 'foreign source') {
        DB::table('fee_obligations')->where('id', $obligation->id)->update(['source_id' => 'PLN-OTHER-R1']);
    }
    $before = feeQuoteFinancialRows();
    if (in_array($disposition, ['foreign source', 'fractional snapshot'], true)) {
        $this->actingAs($actor)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->assertConflict();
    } else {
        $waived = in_array($disposition, ['waived', 'stale'], true);
        $settled = $disposition === 'settled';
        $this->actingAs($actor)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->assertOk()
            ->assertJsonPath('fee_kobo', $completion ? 10000 : 0)
            ->assertJsonPath('net_kobo', $completion ? 190000 : 30000)
            ->assertJsonPath('fee_disclosure.new_fee', '₦0.00')
            ->assertJsonPath('fee_disclosure.existing_fee_included', $completion ? '₦100.00' : '₦0.00')
            ->assertJsonPath('fee_disclosure.already_assessed', '₦100.00')
            ->assertJsonPath('fee_disclosure.already_settled', $settled ? '₦100.00' : '₦0.00')
            ->assertJsonPath('fee_disclosure.already_waived', $waived ? '₦100.00' : '₦0.00')
            ->assertJsonPath('fee_disclosure.existing_unpaid', $waived || $settled ? '₦0.00' : '₦100.00');
        if ($disposition === 'stale') {
            expect(fn () => app(WithdrawalService::class)->submit($actor, $customer, [...$payload, 'attempt_reference' => (string) Str::uuid(),
                'preview_fingerprint' => $initial['preview_fingerprint'], 'quote_expires_at' => $initial['quote_expires_at'],
                'customer_version' => $initial['customer_version'], 'assignment_version' => $initial['assignment_version'],
                'plan_version' => $initial['plan_version'], 'business_version' => $initial['business_version'], 'instruction_attested' => true, 'confirmed' => true]))
                ->toThrow(ConflictHttpException::class, 'Withdrawal terms changed.');
        }
    }
    expect(feeQuoteFinancialRows())->toEqual($before);
})->with(['unpaid', 'waived', 'settled', 'completion', 'stale', 'foreign source', 'fractional snapshot']);
