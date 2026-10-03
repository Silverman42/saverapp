<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\CashDisbursement;
use App\Models\CollectionBatch;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\FeeConcessionPosition;
use App\Services\FeeSavingsApplicationReversalOwner;
use App\Services\FeeSavingsApplicationService;
use App\Services\FinancialCashPosition;
use App\Services\FinancialWorkflowReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\ReversalService;
use App\Services\StatementPreviewService;
use App\Services\WithdrawalBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

function feeCorrectionFixture(object $test): array
{
    $test->freezeTime();
    config()->set('fees.savings_applications_enabled', true);
    config()->set('fees.savings_application_corrections_enabled', true);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 10001);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed payment.', 'customer_description' => 'Registration fee from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $request = Request::create('/admin/fees/application', 'POST');
    $session = new Store('fee-correction', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);
    $group = $owner->apply($admin, $fee->id, [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']], $request);

    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$agent, $customer, $assignment, $plan, $fee, $group, $admin];
}

function submitFeeCorrection(object $test, array $fixture): ReversalRequest
{
    [$agent, $customer, $assignment, , , $group] = $fixture;
    $quote = $test->actingAs($agent)->postJson(route('reversals.preview', $group->posting_reference))->assertOk()->json();
    $test->post(route('reversals.store', $group->posting_reference), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'incorrect_fee_deduction', 'internal_reason' => 'Incorrect savings payment recorded.',
        'customer_explanation' => 'Savings restored; the valid registration fee remains unpaid.',
        'evidence_text' => 'Original payment and undrawn earnings verified.', 'confirmed' => true,
    ])->assertRedirect();

    return ReversalRequest::query()->sole();
}

function feeCorrectionReview(object $test, ReversalRequest $reversal): array
{
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $quote = $test->actingAs($reviewer)->getJson(route('reversals.review-preview', $reversal))->assertOk()->json();
    $test->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);

    return [$reviewer, ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'decision_reason' => 'Verified erroneous payment.', 'confirmed' => true]];
}

test('independent fee payment review restores savings and valid unpaid debt once through replay and projection rebuild', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 23:30:00', 'UTC'));
    $fixture = feeCorrectionFixture($this);
    [, $customer, , $plan, $fee, $original] = $fixture;
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    $originalRows = DB::table('ledger_entries')->where('ledger_posting_group_id', $original->id)->get();

    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    expect($reversal->fresh()->state)->toBe('approved_posted');
    $compensation = app(FeeSavingsApplicationReversalOwner::class)->assertPosted($reversal->id);
    expect($compensation->event_type)->toBe('fee_application_compensation');
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(10001);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan))->toMatchArray(['liability_kobo' => 100000, 'cycle_available_kobo' => 100000]);
    expect(DB::table('ledger_entries')->where('ledger_posting_group_id', $original->id)->get())->toEqual($originalRows);
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $this->assertDatabaseCount('reversal_requests', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 3);
    $this->assertDatabaseCount('ledger_transaction_projections', 3);
    $this->assertDatabaseCount('fee_obligation_entries', 3);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee->fresh()))->toBe(['savings_kobo' => 0, 'external_kobo' => 0]);
    $statement = app(StatementPreviewService::class)->preview($customer->user, $customer,
        now('Africa/Lagos')->toDateString(), now('Africa/Lagos')->toDateString(), 'Africa/Lagos');
    expect($statement)->toMatchArray(['status' => 'ready', 'closing_kobo' => 100000]);
    expect($statement['lines'])->toHaveCount(3);
    $activity = app(FinancialWorkflowReadService::class)->activity($customer->user,
        CustomerProfile::query()->whereKey($customer->id), [], now()->toDateTimeString());
    $metrics = collect($activity['metrics'])->keyBy('code');
    expect($metrics['savings_fee_compensation']['value'])->toBe(10001);
    expect($metrics['effective_savings_fee_applications']['value'])->toBe(0);
    config()->set('fees.savings_application_corrections_enabled', false);
    expect(app(ReversalService::class)->archivalStatus($customer))->toBe('passed');
    expect(app(LedgerTransactionProjectionService::class)->rebuild())->toMatchArray(['transactions' => 3, 'groups' => 3]);
});

test('fee payment capture authority cannot initiate or approve its own deterministic correction without review authority', function (): void {
    $fixture = feeCorrectionFixture($this);
    [, , , , , $group, $admin] = $fixture;
    $this->actingAs($admin)->postJson(route('reversals.preview', $group->posting_reference))->assertForbidden();
    $reversal = submitFeeCorrection($this, $fixture);
    $this->actingAs($admin)->getJson(route('reversals.review-preview', $reversal))->assertForbidden();
    $this->assertDatabaseCount('ledger_posting_groups', 2);
});

test('disabled fee payment correction owner rejects initiation without changing its original money', function (): void {
    [$agent, , , , , $group] = feeCorrectionFixture($this);
    config()->set('fees.savings_application_corrections_enabled', false);
    $this->actingAs($agent)->postJson(route('reversals.preview', $group->posting_reference))->assertStatus(503);
    $this->assertDatabaseCount('reversal_requests', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
});

test('fee compensation persistence failure rolls back approval debt savings and immutable review evidence', function (string $fault): void {
    $fixture = feeCorrectionFixture($this);
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    DB::statement($fault);
    try {
        $this->post(route('reversals.approve', $reversal), $payload)->assertStatus(500);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_compensation');
    }
    expect($reversal->fresh()->state)->toBe('pending_review');
    expect($fixture[4]->fresh()->settledAmountKobo())->toBe(10001);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $this->assertDatabaseCount('fee_obligation_entries', 2);
    $this->assertDatabaseMissing('reversal_events', ['event_type' => 'approved_posted']);
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
})->with([
    'settlement persistence' => "CREATE TRIGGER fail_fee_compensation BEFORE INSERT ON fee_obligation_entries WHEN NEW.entry_type = 'settlement_reversal' BEGIN SELECT RAISE(ABORT, 'injected fee compensation outage'); END",
    'canonical audit persistence' => "CREATE TRIGGER fail_fee_compensation BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'reversal.approved_posted' BEGIN SELECT RAISE(ABORT, 'injected fee compensation outage'); END",
    'notice persistence' => "CREATE TRIGGER fail_fee_compensation BEFORE INSERT ON reversal_notification_intents WHEN json_extract(NEW.payload, '$.state') = 'approved_posted' BEGIN SELECT RAISE(ABORT, 'injected fee compensation outage'); END",
    'live projection persistence' => "CREATE TRIGGER fail_fee_compensation BEFORE INSERT ON ledger_transaction_projections WHEN NEW.type = 'reversal' BEGIN SELECT RAISE(ABORT, 'injected fee compensation outage'); END",
]);

test('damaged retained fee compensation denies replay cycle savings concessions and projection promotion', function (string $table, array $change): void {
    $fixture = feeCorrectionFixture($this);
    [, $customer, , $plan, $fee] = $fixture;
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $compensation = app(FeeSavingsApplicationReversalOwner::class)->assertPosted($reversal->id);
    $query = DB::table($table);
    if ($table === 'ledger_entries') {
        $query->where('ledger_posting_group_id', $compensation->id);
    } elseif ($table === 'fee_obligation_entries') {
        $query->where('ledger_posting_reference', $compensation->posting_reference);
    } elseif ($table === 'reversal_events') {
        $query->where('reversal_request_id', $reversal->id)->where('event_type', 'approved_posted');
    } else {
        $query->where('id', $compensation->id);
    }
    $query->update($change);

    $this->postJson(route('reversals.approve', $reversal), $payload)->assertConflict();
    expect(fn () => app(CollectionReadService::class)->position($customer))->toThrow(RuntimeException::class);
    expect(app(CollectionReadService::class)->positions([$customer->id])[$customer->id])->toBeNull();
    $other = CustomerProfile::factory()->create();
    expect(app(CollectionReadService::class)->positions([$customer->id, $other->id]))->toBe([
        $customer->id => null, $other->id => ['liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0],
    ]);
    expect(app(CollectionReadService::class)->scopedPosition(CustomerProfile::query()->whereKey($other->id)))
        ->toBe(['liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0]);
    expect(fn () => app(CollectionReadService::class)->scopedPosition(CustomerProfile::query()->whereKey($customer->id)))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(CollectionReadService::class)->scopedLiability(CustomerProfile::query()->whereKey($customer->id)))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(WithdrawalBalanceService::class)->position($customer, $plan))->toThrow(RuntimeException::class);
    expect(app(WithdrawalBalanceService::class)->positions([$plan])[$plan->id])->toBeNull();
    expect(fn () => app(FeeConcessionPosition::class)->retainedSources($fee->fresh()))
        ->toThrow(ConflictHttpException::class);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('ledger_posting_groups', 3);
})->with([
    'wrong accepted owner proof' => ['ledger_posting_groups', ['payload_hash' => str_repeat('f', 64)]],
    'wrong compensation event' => ['ledger_posting_groups', ['event_type' => 'deduction_compensation']],
    'wrong compensation source identity' => ['ledger_posting_groups', ['source_id' => '999999']],
    'wrong compensation source type' => ['ledger_posting_groups', ['source_type' => 'unknown']],
    'wrong compensation actor' => ['ledger_posting_groups', ['actor_user_id' => null]],
    'wrong compensation amount' => ['ledger_entries', ['amount_kobo' => 10000]],
    'wrong debt effect' => ['fee_obligation_entries', ['amount_kobo' => 10000]],
    'wrong settlement source' => ['fee_obligation_entries', ['source_id' => '999999']],
    'wrong approval source' => ['reversal_events', ['event_type' => 'rejected']],
]);

test('fee payment approval fails closed when reviewed current dependencies change', function (string $dependency): void {
    $fixture = feeCorrectionFixture($this);
    [, , , $plan, $fee] = $fixture;
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    match ($dependency) {
        'period' => FinancialPeriod::query()->update(['status' => 'closed']),
        'terminal' => $plan->update(['status' => 'closed', 'open_customer_profile_id' => null]),
        'mapping' => LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome)->update(['mapping_status' => 'unmapped']),
        'settlement' => DB::table('fee_obligation_entries')->where('fee_obligation_id', $fee->id)->where('entry_type', 'settlement')->update(['amount_kobo' => 10000]),
    };

    $this->postJson(route('reversals.approve', $reversal), $payload)->assertConflict();
    expect($reversal->fresh()->state)->toBe('pending_review');
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $this->assertDatabaseCount('fee_obligation_entries', 2);
})->with(['period', 'terminal', 'mapping', 'settlement']);

test('pending or unknown earnings draws block full fee payment compensation without consuming the original payment', function (string $status): void {
    $fixture = feeCorrectionFixture($this);
    [$agent, , , , $fee, $group, $admin] = $fixture;
    CashDisbursement::create(['execution_reference' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
        'executor_user_id' => $admin->id, 'recipient_user_id' => $admin->id, 'kind' => 'earnings_draw', 'amount_kobo' => 1,
        'cash_mapping_version' => 1, 'debit_mapping_version' => 1, 'method_version' => 1, 'status' => $status,
        'custody_evidence' => 'Isolated pending business draw evidence.']);

    $this->actingAs($agent)->postJson(route('reversals.preview', $group->posting_reference))->assertConflict();
    expect($fee->fresh()->settledAmountKobo())->toBe(10001);
    $this->assertDatabaseCount('reversal_requests', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
})->with(['processing', 'outcome_unknown']);

test('a fee concession remains separate and prevents unsupported full erroneous payment compensation', function (): void {
    $fixture = feeCorrectionFixture($this);
    [$agent, $customer, , $plan, $fee, $group, $admin] = $fixture;
    config()->set('fees.refunds_enabled', true);
    config()->set('collections.enabled', true);
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $batch = CollectionBatch::query()->sole();
    $batch->update(['status' => 'ready_for_review']);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('collection-batches.remittances.store', $batch), ['batch_version' => $batch->version,
            'handoff_reference' => 'FEE-CONCESSION-CASH', 'amount_ngn' => '1000.00', 'handoff_date' => now('Africa/Lagos')->toDateString(),
            'receiving_location' => 'Business office', 'source_attestation' => 'Counted full original Agent tender.', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('admin.fees.refunds.store', $fee), ['refund_reference' => (string) Str::uuid(), 'kind' => 'savings',
            'amount_ngn' => '0.01', 'reason' => 'Independent valid fee concession.', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($agent)->postJson(route('reversals.preview', $group->posting_reference))->assertConflict();
    expect($fee->fresh()->settledAmountKobo())->toBe(10001);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(90000);
    $this->assertDatabaseCount('reversal_requests', 0);
    $this->assertDatabaseCount('fee_refunds', 1);
});

test('Customer balance batches retain one reviewed fee restoration across 26 identities without per Customer source queries', function (): void {
    $fixture = feeCorrectionFixture($this);
    [, $customer] = $fixture;
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $others = CustomerProfile::factory()->count(25)->create();
    $ids = [$customer->id, ...$others->pluck('id')->all()];
    $owner = app(CollectionReadService::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $single = $owner->positions([$customer->id]);
    $singleQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $batch = $owner->positions($ids);
    $batchQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($batchQueries)->toBeLessThanOrEqual($singleQueries + 1);
    expect($batch[$customer->id])->toEqual($single[$customer->id]);
    expect($batch[$customer->id]['available_kobo'])->toBe(100000);
    foreach ($others as $other) {
        expect($batch[$other->id])->toBe(['liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0]);
    }
    $scope = CustomerProfile::query()->whereKey($ids);
    expect($owner->scopedLiability($scope))->toBe(100000);
    expect($owner->scopedPosition($scope))->toBe(['liability_kobo' => 100000, 'reservations_kobo' => 0, 'available_kobo' => 100000]);
});

test('damaged fee restoration Customer dimensions cannot transfer a valid balance between profiles', function (string $dimension): void {
    $fixture = feeCorrectionFixture($this);
    [, $customer] = $fixture;
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $other = CustomerProfile::factory()->create();
    $group = app(FeeSavingsApplicationReversalOwner::class)->assertPosted($reversal->id);
    if ($dimension === 'group') {
        DB::table('ledger_posting_groups')->where('id', $group->id)->update(['customer_profile_id' => $other->id]);
    } else {
        DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'credit')->update(['customer_profile_id' => $other->id]);
    }
    $owner = app(CollectionReadService::class);

    expect($owner->positions([$customer->id, $other->id]))->toBe([$customer->id => null, $other->id => null]);
    foreach ([$customer, $other] as $profile) {
        expect(fn () => $owner->position($profile))->toThrow(RuntimeException::class);
        expect(fn () => $owner->scopedPosition(CustomerProfile::query()->whereKey($profile->id)))->toThrow(RuntimeException::class);
    }
})->with(['group', 'line']);

test('an orphaned malformed fee compensation cannot become a Customer balance in reads or financial command snapshots', function (): void {
    $fixture = feeCorrectionFixture($this);
    [, $customer] = $fixture;
    $reversal = submitFeeCorrection($this, $fixture);
    [, $payload] = feeCorrectionReview($this, $reversal);
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $group = app(FeeSavingsApplicationReversalOwner::class)->assertPosted($reversal->id);
    DB::table('reversal_requests')->where('id', $reversal->id)->update(['compensation_posting_group_id' => null]);
    DB::table('ledger_posting_groups')->where('id', $group->id)->update(['source_id' => 'not-a-retained-request']);
    $owner = app(CollectionReadService::class);
    $rows = DB::table('ledger_entries')->orderBy('id')->get();

    expect($owner->positions([$customer->id])[$customer->id])->toBeNull();
    expect(fn () => $owner->position($customer))->toThrow(RuntimeException::class);
    expect(fn () => DB::transaction(fn (): array => $owner->position($customer, true)))->toThrow(RuntimeException::class);
    expect(app(LedgerTransactionReadService::class)->balance($customer->user, $customer))->toBe(['status' => 'unavailable']);
    expect(fn () => $owner->scopedLiability(CustomerProfile::query()->whereKey($customer->id)))->toThrow(RuntimeException::class);
    expect(DB::table('ledger_entries')->orderBy('id')->get())->toEqual($rows);
});
