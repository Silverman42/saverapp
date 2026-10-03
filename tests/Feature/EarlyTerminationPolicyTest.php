<?php

use App\Enums\AdminPermission;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Models\CollectionReceipt;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\EarlyTerminationPolicy;
use App\Services\FeeObligationService;
use App\Services\FeeSavingsApplicationService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanSettlementService;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

function approvedTerminationFixture(string $model = 'fixed', FeeRuleTiming $timing = FeeRuleTiming::CycleCompletion, int $fee = 10000, bool $funded = true, FeeSettlementSource $source = FeeSettlementSource::SavingsApplication): array
{
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true]);
    $fixture = collectionFixture(2, feeAmountKobo: $fee, feeTiming: $timing, feeSource: $timing === FeeRuleTiming::Withdrawal ? FeeSettlementSource::WithdrawalPayout : $source);
    [$agent, $customer, $assignment, $plan, $date] = $fixture;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $snapshot = $plan->currentTermsRevision()->feeSnapshot;
    $attributes = match ($model) {
        'percentage' => ['model' => 'percentage', 'basis' => 'net_cycle_contributions', 'basis_points' => 200, 'amount_kobo' => 8000],
        'one_day' => ['model' => 'one_day', 'basis' => 'contractual_daily_contribution', 'amount_kobo' => 200000],
        default => [],
    };
    if ($attributes !== []) {
        DB::table('fee_rules')->where('id', $snapshot->fee_rule_id)->update($attributes);
    }
    DB::table('fee_snapshots')->where('id', $snapshot->id)->update([...$attributes,
        'basis_amount_kobo' => $model === 'percentage' ? 400000 : ($model === 'one_day' ? 200000 : 0)]);
    $snapshot->refresh();
    DB::table('fee_snapshots')->where('id', $snapshot->id)->update([
        'early_termination_policy_version' => EarlyTerminationPolicy::VERSION,
        'early_termination_description' => app(EarlyTerminationPolicy::class)->disclosure($snapshot),
    ]);
    if ($funded) {
        $data = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
        $owner = app(CollectionService::class);
        $owner->record($agent, $customer, [...$data, 'preview_fingerprint' => $owner->preview($agent, $customer, $data)['preview_fingerprint']]);
    }

    return [$agent, $customer, $assignment, $plan->fresh(), $date];
}

function terminationInstructions(array $quote): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Customer requested the disclosed early termination.', 'customer_explanation' => 'The agreed fee remains due and closure requires separate settlement.', 'confirmed' => true];
}

test('funded early termination assesses the full agreed once fee without paying or overdrawing savings', function (string $model, int $expected): void {
    [$agent, , , $plan] = approvedTerminationFixture($model);
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan, 'prepare_termination');
    $groups = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();
    $lines = DB::table('ledger_entries')->orderBy('id')->get()->all();
    expect($quote['can_prepare'])->toBeTrue();
    expect($quote['termination_fee']['target_kobo'])->toBe($expected);
    $instructions = terminationInstructions($quote);

    $this->actingAs($agent)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), $instructions)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), $instructions)->assertRedirect()->assertSessionHasNoErrors();

    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation()->sole();
    expect($fee->assessedAmountKobo())->toBe($expected)->and($fee->settledAmountKobo())->toBe(0)->and($fee->outstandingAmountKobo())->toBe($expected);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    expect(DB::table('ledger_posting_groups')->orderBy('id')->get()->all())->toEqual($groups);
    expect(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($lines);
    $this->assertDatabaseCount('financial_workflow_supplements', 1);
    $this->assertDatabaseCount('plan_lifecycle_events', 1);
    $close = $owner->preview($agent, $plan->fresh());
    expect($close['can_close'])->toBeFalse();
    expect(fn () => $owner->confirm($agent, $plan->fresh(), 'close', terminationInstructions($close)))->toThrow(ConflictHttpException::class, 'settlement gates');
})->with(['fixed' => ['fixed', 10000], 'one contractual day with insufficient savings' => ['one_day', 200000], 'percentage of actual contributions' => ['percentage', 2000]]);

test('withdrawal timed fees are not assessed or applied during preparation', function (): void {
    [$agent, , , $plan] = approvedTerminationFixture(timing: FeeRuleTiming::Withdrawal);
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan, 'prepare_termination');
    $entries = DB::table('ledger_entries')->orderBy('id')->get()->all();

    $owner->confirm($agent, $plan, 'prepare_termination', terminationInstructions($quote));

    expect($quote['termination_fee']['target_kobo'])->toBe(0);
    $this->assertDatabaseCount('fee_obligations', 0);
    expect(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($entries);
});

test('changing reviewed contributions rejects preparation and leaves monetary owners unchanged', function (): void {
    [$agent, $customer, $assignment, $plan, $date] = approvedTerminationFixture('percentage');
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan, 'prepare_termination');
    $data = collectionPayload($customer, $assignment, $plan, $date, '500.00');
    $collections = app(CollectionService::class);
    $collections->record($agent, $customer, [...$data, 'preview_fingerprint' => $collections->preview($agent, $customer, $data)['preview_fingerprint']]);
    $groups = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();

    $this->actingAs($agent)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), terminationInstructions($quote))->assertConflict();

    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    expect(DB::table('ledger_posting_groups')->orderBy('id')->get()->all())->toEqual($groups);
});

test('early termination disclosure cannot be changed after snapshot creation', function (): void {
    [, , , $plan] = approvedTerminationFixture(funded: false);
    $snapshot = FeeSnapshot::findOrFail($plan->currentTermsRevision()->fee_snapshot_id);

    expect(fn () => $snapshot->update(['early_termination_policy_version' => 2]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $snapshot->refresh()->update(['early_termination_description' => 'Changed policy']))->toThrow(RuntimeException::class, 'immutable');
});

test('a paid once fee is not reassessed or collected again during early preparation', function (): void {
    [$agent, , , $plan] = approvedTerminationFixture(timing: FeeRuleTiming::FirstContribution);
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation()->sole();
    expect($fee->settledAmountKobo())->toBe(10000);
    $before = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan, 'prepare_termination');

    $owner->confirm($agent, $plan, 'prepare_termination', terminationInstructions($quote));

    expect($quote['termination_fee']['assessment_delta_kobo'])->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($before);
});

test('an authorized waiver remains authoritative across early preparation', function (): void {
    [$agent, , , $plan] = approvedTerminationFixture();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $fee = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
    $request = Request::create('/cycle-fee-waiver', 'POST');
    $session = new Store('early-fee-waiver', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);
    app(FeeObligationService::class)->waive($admin, $fee->id, 10000, 'Approved existing cycle concession.',
        'Your agreed cycle fee is waived.', (string) Str::uuid(), $request);
    $before = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan->fresh(), 'prepare_termination');

    $owner->confirm($agent, $plan->fresh(), 'prepare_termination', terminationInstructions($quote));

    expect($quote['termination_fee']['unpaid_after_preparation_kobo'])->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($before);
});

test('completion percentage excludes approved reversals from its termination base', function (): void {
    config()->set('collections.receipt_corrections_enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = approvedTerminationFixture('percentage');
    $first = CollectionReceipt::query()->sole();
    $data = collectionPayload($customer, $assignment, $plan, $date, '500.00');
    $collections = app(CollectionService::class);
    $collections->record($agent, $customer, [...$data, 'preview_fingerprint' => $collections->preview($agent, $customer, $data)['preview_fingerprint']]);
    approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($first->savings_posting_group_id));
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan->fresh(), 'prepare_termination');

    $owner->confirm($agent, $plan->fresh(), 'prepare_termination', terminationInstructions($quote));

    expect($quote['termination_fee']['principal_kobo'])->toBe(50000);
    expect($plan->currentTermsRevision()->feeSnapshot->obligation()->sole()->assessedAmountKobo())->toBe(1000);
});

test('a fully reversed funded cycle cannot silently become an unused agreement', function (): void {
    config()->set('collections.receipt_corrections_enabled', true);
    [$agent, $customer, $assignment, $plan] = approvedTerminationFixture();
    $receipt = CollectionReceipt::query()->sole();
    approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id));
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan->fresh(), 'prepare_termination');
    $before = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();
    $supplements = DB::table('financial_workflow_supplements')->orderBy('id')->get()->all();

    $this->actingAs($agent)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), terminationInstructions($quote))->assertConflict();

    expect($quote['can_prepare'])->toBeFalse();
    expect($quote['preparation_blockers'])->toContain('Fully reversed contributions require an explicit reviewed fee disposition.');
    expect(DB::table('financial_workflow_supplements')->orderBy('id')->get()->all())->toEqual($supplements);
    expect(DB::table('ledger_posting_groups')->orderBy('id')->get()->all())->toEqual($before);
});

test('incomplete monetary termination retains its contribution base through settled fees and actual payout', function (string $payout, string $type, int $remaining, FeeSettlementSource $source): void {
    [$agent, $customer, $assignment, $plan, $date] = approvedTerminationFixture('percentage', source: $source);
    enableFixtureMethod();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CashExecute, AdminPermission::WithdrawalsReview, AdminPermission::ReconciliationManage, AdminPermission::FeesManage]);
    $owner = app(PlanSettlementService::class);
    $firstQuote = $owner->preview($agent, $plan, 'prepare_termination');
    $owner->confirm($agent, $plan, 'prepare_termination', terminationInstructions($firstQuote));
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation()->sole();
    $request = Request::create('/cycle-fee-waiver', 'POST');
    $session = new Store('early-before-withdrawal-waiver', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);
    if ($source === FeeSettlementSource::SavingsApplication) {
        config()->set('fees.savings_applications_enabled', true);
        $application = app(FeeSavingsApplicationService::class);
        $applicationData = ['plan_id' => $plan->plan_id, 'reason' => 'Apply the disclosed early fee from available cycle savings.', 'customer_description' => 'Your agreed early fee is paid from cycle savings.'];
        $applicationQuote = $application->preview($admin, $fee->id, $applicationData);
        $application->apply($admin, $fee->id, [...$applicationData, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
            'preview_fingerprint' => $applicationQuote['preview_fingerprint'], 'quote_expires_at' => $applicationQuote['quote_expires_at']], $request);
    }
    $plan->refresh();
    $batch = CollectionReceipt::query()->sole()->batch;
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'EARLY-PERCENTAGE-CASH', 'amount_ngn' => '1000.00',
        'handoff_date' => $date, 'receiving_location' => 'Business till', 'source_attestation' => 'Counted original synthetic contribution.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent review matches original synthetic tender.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => $payout, 'type' => $type];
    $payoutQuote = $withdrawals->preview($agent, $customer->fresh(), $instruction);
    expect($payoutQuote['fee_kobo'])->toBe($source === FeeSettlementSource::WithdrawalPayout ? 2000 : 0);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $payoutQuote['preview_fingerprint'], 'quote_expires_at' => $payoutQuote['quote_expires_at'],
        'customer_version' => $payoutQuote['customer_version'], 'assignment_version' => $payoutQuote['assignment_version'],
        'plan_version' => $payoutQuote['plan_version'], 'business_version' => $payoutQuote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed actual cycle funds.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    [$execution] = startCashFixture($this, $admin, $withdrawal->fresh());
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Counted the synthetic exact net payout.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('posted');
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan->fresh(), 'prepare_termination');

    $owner->confirm($agent, $plan->fresh(), 'prepare_termination', terminationInstructions($quote));

    expect($quote['position']['cycle_liability_kobo'])->toBe($remaining);
    expect($quote['termination_fee']['principal_kobo'])->toBe(100000);
    expect($plan->currentTermsRevision()->feeSnapshot->obligation()->sole()->assessedAmountKobo())->toBe(2000);
    $close = $owner->preview($agent, $plan->fresh());
    expect($close['can_close'])->toBe($remaining === 0);
    if ($remaining === 0) {
        $groups = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();
        $lines = DB::table('ledger_entries')->orderBy('id')->get()->all();
        $instructions = terminationInstructions($close);
        $this->actingAs($agent)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $instructions)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $instructions)->assertRedirect()->assertSessionHasNoErrors();
        expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed);
        expect(DB::table('ledger_posting_groups')->orderBy('id')->get()->all())->toEqual($groups);
        expect(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($lines);
        expect($plan->slots()->count())->toBe(2);
        expect((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(100000);
    }
})->with(['earlier partial payout' => ['300.00', 'partial', 68000, FeeSettlementSource::SavingsApplication], 'full early payout and separate closure' => ['980.00', 'full', 0, FeeSettlementSource::SavingsApplication], 'full payout collects existing completion fee' => ['1000.00', 'full', 0, FeeSettlementSource::WithdrawalPayout]]);

test('withdrawal fee quotes reject zero or negative net payouts without capping the fee or posting money', function (string $gross): void {
    [$agent, $customer, $assignment, $plan] = approvedTerminationFixture(timing: FeeRuleTiming::Withdrawal);
    enableFixtureMethod();
    $before = [];
    foreach (['fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations', 'ledger_posting_groups', 'ledger_entries'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => $gross])->assertUnprocessable()->assertJsonValidationErrors('gross_ngn');

    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['zero net' => ['100.00'], 'negative net' => ['90.00']]);

test('full early payout rejects an absent or damaged authoritative preparation without posting financial owners', function (string $damage): void {
    [$agent, $customer, $assignment, $plan] = approvedTerminationFixture(source: FeeSettlementSource::WithdrawalPayout);
    enableFixtureMethod();
    if ($damage === 'unprepared') {
        app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
        $plan->update(['status' => ThriftPlanStatus::Paused]);
    } else {
        $owner = app(PlanSettlementService::class);
        $quote = $owner->preview($agent, $plan, 'prepare_termination');
        $owner->confirm($agent, $plan, 'prepare_termination', terminationInstructions($quote));
        if ($damage === 'legacy disclosure') {
            DB::table('fee_snapshots')->where('id', $plan->currentTermsRevision()->fee_snapshot_id)->update(['early_termination_policy_version' => null, 'early_termination_description' => null]);
        } elseif ($damage === 'missing preparation proof') {
            DB::table('financial_workflow_supplements')->where('thrift_plan_id', $plan->id)->where('kind', 'early_termination_prepared')->delete();
        } elseif ($damage === 'current version') {
            $plan->refresh()->increment('version');
        } else {
            $event = $plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')->sole();
            $payload = $event->payload;
            $payload['termination_fee']['snapshot_id']++;
            DB::table('plan_lifecycle_events')->where('id', $event->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
    }
    $before = DB::table('ledger_entries')->orderBy('id')->get()->all();

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '1000.00', 'type' => 'full'])->assertConflict();

    $this->assertDatabaseCount('withdrawal_requests', 0);
    $this->assertDatabaseCount('withdrawal_reservations', 0);
    expect(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($before);
})->with(['unprepared', 'legacy disclosure', 'current version', 'wrong snapshot', 'missing preparation proof']);

test('cash execution rechecks the bound early preparation after withdrawal approval', function (): void {
    [$agent, $customer, $assignment, $plan] = approvedTerminationFixture(source: FeeSettlementSource::WithdrawalPayout);
    enableFixtureMethod();
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan, 'prepare_termination');
    $owner->confirm($agent, $plan, 'prepare_termination', terminationInstructions($quote));
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '1000.00', 'type' => 'full'];
    $payoutQuote = $withdrawals->preview($agent, $customer->fresh(), $instruction);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $payoutQuote['preview_fingerprint'], 'quote_expires_at' => $payoutQuote['quote_expires_at'],
        'customer_version' => $payoutQuote['customer_version'], 'assignment_version' => $payoutQuote['assignment_version'],
        'plan_version' => $payoutQuote['plan_version'], 'business_version' => $payoutQuote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CashExecute, AdminPermission::WithdrawalsReview]);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed disclosed early cycle payout.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $plan->refresh()->increment('version');
    $before = DB::table('ledger_entries')->orderBy('id')->get()->all();

    $this->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'Synthetic counted cash.', 'confirmed' => true])->assertConflict();

    $this->assertDatabaseCount('cash_executions', 0);
    expect($withdrawal->fresh()->state)->toBe('approved');
    expect(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($before);
});
