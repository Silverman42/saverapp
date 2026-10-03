<?php

use App\Enums\AdminPermission;
use App\Jobs\ProjectAuditEvent;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditProjection;
use App\Services\BackgroundRecovery;
use App\Services\CollectionReadService;
use App\Services\FeeSavingsApplicationService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\PlatformState;
use App\Services\StatementPreviewService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

function savingsFeeFreshRequest(): Request
{
    $request = Request::create('/admin/fees/application', 'POST');
    $session = new Store('savings-fee', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);

    return $request;
}

/** @return array<string, array<int, object>> */
function savingsFeeOwnerRows(): array
{
    $rows = [];
    foreach (['fee_savings_applications', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_reservations', 'collection_receipts'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('explicit savings fee application has one scoped transaction and reconciled statement through live projection rebuild and replay', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 23:30:00', 'UTC'));
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $projection = app(LedgerTransactionProjectionService::class);
    expect($projection->rebuild())->toMatchArray(['transactions' => 1, 'groups' => 1]);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE reviewed fee application.', 'customer_description' => 'Registration fee from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];

    $group = $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest());
    $reads = app(LedgerTransactionReadService::class);
    expect($reads->state()['status'])->toBe('ready');
    $reference = null;
    foreach ([$admin, $agent, $customer->user] as $viewer) {
        $result = $reads->search($viewer, ['type' => 'fee_application']);
        expect($result['total'])->toBe(1);
        expect($result['savings_effect_kobo'])->toBe(-20000);
        expect($result['data'][0])->toMatchArray(['type' => 'fee_application', 'gross_amount_kobo' => 20000,
            'savings_effect_kobo' => -20000, 'fee_amount_kobo' => 20000, 'source_type' => 'fee_savings_application']);
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE');
        $reference = $result['data'][0]['reference'];
        expect($reads->detail($viewer, $reference)['posting_group_count'])->toBe(1);
    }
    $foreign = CustomerProfile::factory()->create();
    expect(fn () => $reads->detail($foreign->user, $reference))->toThrow(NotFoundHttpException::class);
    $this->actingAs($admin)->get(route('transactions.index', ['type' => 'fee_application']))
        ->assertInertia(fn (Assert $page) => $page->component('ledger/Index')->where('result.total', 1)
            ->where('result.data.0.type', 'fee_application')->where('result.data.0.savings_effect_kobo', -20000));
    $statement = app(StatementPreviewService::class)->preview($customer->user, $customer,
        $quote['occurred_on'], $quote['occurred_on'], 'Africa/Lagos');
    expect($statement)->toMatchArray(['status' => 'ready', 'opening_kobo' => 0, 'activity_kobo' => 80000, 'closing_kobo' => 80000]);
    expect($statement['lines'])->toHaveCount(2);
    $before = savingsFeeOwnerRows();
    expect($projection->rebuild())->toMatchArray(['transactions' => 2, 'groups' => 2]);
    expect($reads->search($customer->user, ['type' => 'fee_application'])['data'][0]['reference'])->toBe($reference);
    expect($owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest())->id)->toBe($group->id);
    expect(savingsFeeOwnerRows())->toEqual($before);
    $this->assertDatabaseCount('ledger_transaction_references', 2);
});

test('FEE-AC-039: optional audit index failure recovers the actual fee operation without repeating money', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 23:30:00', 'UTC'));
    Queue::fake([ProjectAuditEvent::class]);
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE original fee settlement review.',
        'customer_description' => 'Registration fee settled from original savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $group = $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest());
    $canonical = DB::table('canonical_audit_events')->where('event_type', 'ledger.fee_posted')
        ->where('target_reference', $group->posting_reference)->sole();
    Queue::assertPushed(ProjectAuditEvent::class, fn (ProjectAuditEvent $job): bool => $job->canonicalEventId === $canonical->id);
    expect(DB::table('audit_projection_work')->where('canonical_event_id', $canonical->id)->value('status'))->toBe('pending')
        ->and(DB::table('audit_search_documents')->where('canonical_event_id', $canonical->id)->count())->toBe(0);
    $before = savingsFeeOwnerRows();
    foreach (['fee_application_notification_intents', 'canonical_audit_events', 'audit_events',
        'audit_protected_payloads', 'ledger_transaction_projections', 'ledger_transaction_references'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    DB::statement('CREATE TRIGGER fail_actual_fee_audit_index BEFORE INSERT ON audit_search_documents WHEN NEW.canonical_event_id = '.(int) $canonical->id." BEGIN SELECT RAISE(ABORT, 'PRIVATE optional fee search outage'); END");
    try {
        expect(app(BackgroundRecovery::class)->runSource('audit_projection', $canonical->id))->toBe('retry_scheduled');
    } finally {
        DB::statement('DROP TRIGGER fail_actual_fee_audit_index');
    }
    $work = DB::table('audit_projection_work')->where('canonical_event_id', $canonical->id)->sole();
    expect($work->status)->toBe('pending')->and($work->attempts)->toBe(1)
        ->and($work->failure_code)->toBe('local_delivery_failure')
        ->and(DB::table('audit_projection_state')->where('id', 1)->value('status'))->toBe('partial')
        ->and(DB::table('audit_search_documents')->where('canonical_event_id', $canonical->id)->count())->toBe(0)
        ->and($owner->status($admin, $fee->id, $payload['attempt_reference']))
        ->toBe(['status' => 'posted', 'posting_reference' => $group->posting_reference])
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(0);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $this->travelTo(CarbonImmutable::parse($work->available_at)->addSecond());
    expect(app(BackgroundRecovery::class)->runSource('audit_projection', $canonical->id))->toBe('succeeded')
        ->and(app(BackgroundRecovery::class)->runSource('audit_projection', $canonical->id))->toBe('succeeded');
    $indexed = DB::table('audit_search_documents')->where('canonical_event_id', $canonical->id)->sole();
    expect(app(AuditProjection::class)->hasVerifiedDocument($canonical, $indexed->index_version))->toBeTrue()
        ->and(DB::table('audit_projection_work')->where('canonical_event_id', $canonical->id)->value('status'))->toBe('complete')
        ->and($owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest())->id)->toBe($group->id);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(80000);
});

test('a live savings fee projection persistence fault rolls back application settlement and journal and permits the same attempt retry', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Approved application.', 'customer_description' => 'Registration fee from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $before = savingsFeeOwnerRows();
    $state = DB::table('ledger_projection_state')->first();
    DB::statement("CREATE TRIGGER fail_explicit_fee_projection BEFORE INSERT ON ledger_transaction_projections WHEN NEW.type = 'fee_application' BEGIN SELECT RAISE(ABORT, 'injected fee projection outage'); END");

    try {
        expect(fn () => $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest()))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_explicit_fee_projection');
    }

    expect(savingsFeeOwnerRows())->toEqual($before);
    expect(DB::table('ledger_projection_state')->first())->toEqual($state);
    $this->assertDatabaseCount('ledger_transaction_references', 1);
    $this->assertDatabaseCount('ledger_transaction_projections', 1);
    $group = $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest());
    expect($owner->status($admin, $fee->id, $payload['attempt_reference'])['posting_reference'])->toBe($group->posting_reference);
    expect(app(LedgerTransactionReadService::class)->search($customer->user, ['type' => 'fee_application'])['total'])->toBe(1);
});

test('damaged explicit fee sources deny saved outcomes replay and projection promotion without changing financial owners', function (string $table, array $change): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Approved application.', 'customer_description' => 'Registration fee from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $group = $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest());
    DB::table($table)->where($table === 'ledger_posting_groups' ? 'id' : ($table === 'ledger_entries' ? 'ledger_posting_group_id' : 'ledger_posting_reference'),
        $table === 'fee_obligation_entries' ? $group->posting_reference : $group->id)->update($change);
    $before = savingsFeeOwnerRows();

    expect(fn () => app(CollectionReadService::class)->position($customer))->toThrow(RuntimeException::class);
    expect(app(CollectionReadService::class)->positions([$customer->id])[$customer->id])->toBeNull();
    expect(fn () => $owner->status($admin, $fee->id, $payload['attempt_reference']))->toThrow(ConflictHttpException::class);
    expect(fn () => $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest()))->toThrow(ConflictHttpException::class);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    expect(app(LedgerTransactionReadService::class)->state()['status'])->toBe('unavailable');
    expect(savingsFeeOwnerRows())->toEqual($before);
})->with([
    'missing group cycle' => ['ledger_posting_groups', ['thrift_plan_id' => null]],
    'wrong group actor' => ['ledger_posting_groups', ['actor_user_id' => null]],
    'wrong journal amount' => ['ledger_entries', ['amount_kobo' => 1]],
    'missing journal fee dimension' => ['ledger_entries', ['fee_obligation_id' => null]],
    'wrong settlement currency' => ['fee_obligation_entries', ['currency' => 'USD']],
    'wrong settlement amount' => ['fee_obligation_entries', ['amount_kobo' => 1]],
]);

test('HTTP fee review commits once and retains an actor scoped saved outcome when capture is disabled', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed agreed settlement.', 'customer_description' => 'Registration fee settled from savings.'];
    $before = savingsFeeOwnerRows();

    $this->actingAs($admin)->getJson(route('admin.fees.obligations.savings-sources', $fee->id))
        ->assertOk()->assertJsonCount(1, 'sources')->assertJsonPath('sources.0.plan_id', $plan->plan_id);
    $quote = $this->postJson(route('admin.fees.obligations.savings-preview', $fee->id), $data)
        ->assertOk()->assertJsonPath('amount_kobo', 20000)->assertJsonPath('remaining_fee_kobo', 0)
        ->assertJsonPath('display.liability', '₦1,000.00')->assertJsonPath('display.fee', '₦200.00')
        ->assertJsonPath('display.available', '₦1,000.00')
        ->assertJsonPath('display.remaining', '₦800.00')->json();
    expect(savingsFeeOwnerRows())->toEqual($before);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $this->withSession(savingsFeeFreshRequest()->session()->all());
    $posted = $this->postJson(route('admin.fees.obligations.apply-savings', $fee->id), $payload)
        ->assertOk()->assertJsonPath('status', 'posted')->json('posting_reference');

    $this->assertDatabaseCount('fee_savings_applications', 1);
    $this->assertDatabaseHas('ledger_posting_groups', ['posting_reference' => $posted, 'source_type' => 'fee_savings_application',
        'source_id' => $payload['attempt_reference'], 'thrift_plan_id' => $plan->id]);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(80000);
    $before = savingsFeeOwnerRows();
    config()->set('fees.savings_applications_enabled', false);
    $statusRoute = route('admin.fees.obligations.savings-status', [$fee->id, $payload['attempt_reference']]);
    $this->getJson($statusRoute)->assertOk()->assertExactJson(['status' => 'posted', 'posting_reference' => $posted]);
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee->id), $payload)
        ->assertOk()->assertJsonPath('posting_reference', $posted);
    $this->getJson(route('admin.fees.obligations.savings-status', [$fee->id + 1000, $payload['attempt_reference']]))->assertNotFound();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($other)->getJson($statusRoute)->assertNotFound();
    expect(savingsFeeOwnerRows())->toEqual($before);
});

test('HTTP fee confirmation rejects unreviewed instructions with 422 and no financial writes', function (array $changes, string $field): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed agreed settlement.', 'customer_description' => 'Registration fee settled from savings.'];
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee->id), $data)->assertOk()->json();
    $before = savingsFeeOwnerRows();

    $this->withSession(savingsFeeFreshRequest()->session()->all())
        ->postJson(route('admin.fees.obligations.apply-savings', $fee->id), array_replace([
            ...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
            'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        ], $changes))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(savingsFeeOwnerRows())->toEqual($before);
})->with([
    'confirmation declined' => [['confirmed' => false], 'confirmed'],
    'client amount override' => [['amount_kobo' => 1], 'request'],
    'client owner override' => [['customer_profile_id' => 1], 'request'],
    'missing expiry' => [['quote_expires_at' => null], 'quote_expires_at'],
    'malformed review' => [['preview_fingerprint' => 'unreviewed'], 'preview_fingerprint'],
]);

test('HTTP fee application requires fresh authentication with 423 and no financial writes', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed agreed settlement.', 'customer_description' => 'Registration fee settled from savings.'];
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee->id), $data)->assertOk()->json();
    $before = savingsFeeOwnerRows();

    $this->postJson(route('admin.fees.obligations.apply-savings', $fee->id), [...$data,
        'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
    ])->assertStatus(423)->assertJsonPath('message', 'Fresh authentication required.');

    expect(savingsFeeOwnerRows())->toEqual($before);
});

test('HTTP fee application rejects an expired review with 409 and no financial writes', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed agreed settlement.', 'customer_description' => 'Registration fee settled from savings.'];
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee->id), $data)->assertOk()->json();
    $before = savingsFeeOwnerRows();
    $this->travel(10)->minutes();

    $this->withSession(savingsFeeFreshRequest()->session()->all())
        ->postJson(route('admin.fees.obligations.apply-savings', $fee->id), [...$data,
            'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
            'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        ])->assertConflict()->assertJsonPath('message', 'The fee application review expired. Review again.');

    expect(savingsFeeOwnerRows())->toEqual($before);
});

test('platform restrictions permit HTTP fee review and deny financial confirmation without writes', function (string $mode): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(PlatformState::class)->transition(['mode' => $mode, 'expected_version' => 1, 'operation_id' => (string) Str::uuid(),
        'operator' => 'test-operator', 'reason' => 'TEST FIXTURE restricted fee application.', 'incident' => 'TEST-FEE', 'expires_at' => null]);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed agreed settlement.', 'customer_description' => 'Registration fee settled from savings.'];
    $before = savingsFeeOwnerRows();

    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee->id), $data)->assertOk()->json();
    $this->withSession(savingsFeeFreshRequest()->session()->all())
        ->postJson(route('admin.fees.obligations.apply-savings', $fee->id), [...$data,
            'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
            'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        ])->assertServiceUnavailable();

    expect(savingsFeeOwnerRows())->toEqual($before);
})->with(['read only' => ['read_only'], 'financial freeze' => ['financial_freeze']]);

test('HTTP savings sources reject an Admin without fee authority with 403', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer] = withdrawalFixture();
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $before = savingsFeeOwnerRows();

    $this->actingAs($admin)->getJson(route('admin.fees.obligations.savings-sources', $fee->id))->assertForbidden();

    expect(savingsFeeOwnerRows())->toEqual($before);
});

test('reviewed registration fee savings application settles once from its selected cycle without slot allocation', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE agreed explicit registration settlement.', 'customer_description' => 'Registration fee paid from your selected cycle.'];
    $owner = app(FeeSavingsApplicationService::class);
    $before = savingsFeeOwnerRows();
    $quote = $owner->preview($admin, $fee->id, $data);
    expect($quote['amount_kobo'])->toBe(20000);
    expect($quote['remaining_cycle_savings_kobo'])->toBe(80000);
    expect(savingsFeeOwnerRows())->toEqual($before);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'], 'confirmed' => true];
    $group = $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest());
    expect($group->thrift_plan_id)->toBe($plan->id);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(80000);
    expect(app(WithdrawalBalanceService::class)->positions([$plan])[$plan->id]['cycle_liability_kobo'])->toBe(80000);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('collection_allocations', 0);
    $this->assertDatabaseCount('fee_savings_applications', 1);
    $this->assertDatabaseHas('fee_obligation_entries', ['fee_obligation_id' => $fee->id, 'entry_type' => 'settlement', 'amount_kobo' => 20000,
        'source_type' => 'fee_savings_application', 'source_id' => $payload['attempt_reference'], 'ledger_posting_reference' => $group->posting_reference]);
    expect(DB::table('fee_savings_applications')->value('reason'))->not->toContain('PRIVATE');
    $before = savingsFeeOwnerRows();
    expect($owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest())->id)->toBe($group->id);
    expect(savingsFeeOwnerRows())->toEqual($before);
    expect(fn () => $owner->apply($admin, $fee->id, [...$payload, 'reason' => 'Changed original instruction.'], savingsFeeFreshRequest()))->toThrow(ConflictHttpException::class);
    expect(savingsFeeOwnerRows())->toEqual($before);
    expect(fn () => DB::table('fee_savings_applications')->update(['amount_kobo' => 1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('fee_savings_applications')->delete())->toThrow(QueryException::class);
});

test('reviewed fee application cannot consume funds held by an actual live withdrawal reservation', function (): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $fee = reportFeeObligation($agent, $customer, 20000);
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '900.00'];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $before = savingsFeeOwnerRows();
    expect(fn () => app(FeeSavingsApplicationService::class)->preview($admin, $fee->id, ['plan_id' => $plan->plan_id,
        'reason' => 'Requested full fee settlement.', 'customer_description' => 'Full fee application.']))->toThrow(ValidationException::class);
    expect(savingsFeeOwnerRows())->toEqual($before);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(20000);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(10000);
});

test('fee application rejects lost authority freshness changed review or unavailable accounting without financial writes', function (string $change): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    $period = FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Explicit approved settlement.', 'customer_description' => 'Full registration fee settlement.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'], 'confirmed' => true];
    $request = savingsFeeFreshRequest();
    $exception = ConflictHttpException::class;
    if ($change === 'authority') {
        $admin->revokePermissionTo(AdminPermission::FeesManage);
        $exception = AuthorizationException::class;
    }
    if ($change === 'freshness') {
        $request->session()->forget('auth.mfa_confirmed_at');
    }
    if ($change === 'review') {
        $payload['reason'] = 'Different unreviewed settlement.';
    }
    if ($change === 'mapping') {
        LedgerAccount::query()->where('code', 'fee_income_ngn')->update(['mapping_status' => 'unmapped']);
    }
    if ($change === 'period') {
        $period->update(['status' => 'closed']);
    }
    if ($change === 'disabled') {
        config()->set('fees.savings_applications_enabled', false);
    }
    if ($change === 'confirmation') {
        $payload['confirmed'] = false;
        $exception = ValidationException::class;
    }
    $baseline = savingsFeeOwnerRows();
    expect(fn () => $owner->apply($admin, $fee->id, $payload, $request))->toThrow($exception);
    expect(savingsFeeOwnerRows())->toEqual($baseline);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(20000);
})->with(['authority', 'freshness', 'review', 'mapping', 'period', 'disabled', 'confirmation']);

test('fee application persistence fault rolls back its durable owner and both ledger lines before same-attempt retry', function (string $table): void {
    $this->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Explicit approved settlement.', 'customer_description' => 'Full registration fee settlement.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'], 'confirmed' => true];
    $baseline = savingsFeeOwnerRows();
    DB::statement('CREATE TRIGGER fail_fee_application BEFORE INSERT ON '.$table." BEGIN SELECT RAISE(ABORT, 'injected application persistence outage'); END");
    try {
        expect(fn () => $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest()))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_application');
    }
    expect(savingsFeeOwnerRows())->toEqual($baseline);
    $owner->apply($admin, $fee->id, $payload, savingsFeeFreshRequest());
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    $this->assertDatabaseCount('fee_savings_applications', 1);
})->with(['ledger_entries', 'fee_obligation_entries']);
