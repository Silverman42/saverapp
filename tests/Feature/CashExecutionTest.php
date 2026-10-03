<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\BusinessProfile;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CollectionBatch;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CashExecutionService;
use App\Services\CashRecoveryService;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CustomerLifecycleEligibility;
use App\Services\CustomerStatusManagementService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\NotificationPipeline;
use App\Services\PlanFeeHistoryReadService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanSavingsReadService;
use App\Services\PlanSettlementService;
use App\Services\ReversalService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

uses(CreatesLifecycleCustomers::class);

/** @return array<string, array<int, array<string, mixed>>> */
function cashPercentageOwnerRows(): array
{
    $rows = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations', 'cash_executions'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('later actual payouts retain a once per cycle withdrawal fee after descriptive revision', function (string $model, int $firstFee, int $laterFee, string $disposition = 'normal', bool $largePercentage = false): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Africa/Lagos'));
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true, 'fees.refunds_enabled' => true, 'withdrawals.cash_compensation_enabled' => true]);
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    $agent = $agentProfile->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $rule = FeeRule::create(['version' => 1, 'name' => 'Captured withdrawal timing', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => $model, 'timing' => 'withdrawal',
        'basis' => $model === 'one_day' ? 'contractual_daily_contribution' : ($model === 'percentage' ? 'gross_withdrawal_debit' : 'none'),
        'settlement_source' => 'withdrawal_payout', 'currency' => 'NGN',
        'amount_kobo' => $model === 'fixed' ? $firstFee : 0, 'basis_points' => $model === 'percentage' ? 200 : null,
        'customer_description' => 'Captured withdrawal fee.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated test terms.']);
    $data = ['name' => 'Original agreement', 'amount_ngn' => $largePercentage ? '10000.00' : '2000.00', 'start_date' => '2026-10-05', 'contribution_days' => 4,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $plans->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    $assignment = $customer->currentAssignment;
    $collection = collectionPayload($customer, $assignment, $plan, '2026-10-05', $largePercentage ? '20000.00' : '6000.00');
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $collection)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $collection);
    $this->travel(3)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::findOrFail($receipt->collection_batch_id);
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'ONCE-CYCLE-FEE', 'amount_ngn' => $largePercentage ? '20000.00' : '6000.00', 'handoff_date' => now('Africa/Lagos')->toDateString(),
        'receiving_location' => 'Business till', 'source_attestation' => 'Counted original tender.', 'batch_version' => $batch->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $withdrawals = app(WithdrawalService::class);
    foreach (['partial' => $firstFee, 'full' => $laterFee] as $type => $expectedFee) {
        $plan->refresh();
        $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'type' => $type, 'gross_ngn' => $type === 'full' ? ($disposition === 'compensated' || $disposition === 'damaged compensation' ? '6000.00' : ($disposition === 'concession' ? '3100.00' : '3000.00')) : '3000.00'];
        if ($largePercentage) {
            $instruction['gross_ngn'] = '10000.00';
        }
        if ($type === 'full' && str_starts_with($disposition, 'damaged')) {
            if ($disposition === 'damaged entry type') {
                DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->update(['entry_type' => 'unknown_fee_effect']);
            }
            if ($disposition === 'damaged request fee') {
                DB::table('withdrawal_requests')->where('state', 'posted')->update(['fee_amount_kobo' => 0]);
            }
            if ($disposition === 'damaged posted source') {
                DB::table('ledger_posting_groups')->where('event_type', 'cash_withdrawal')->update(['source_id' => '999999']);
            }
            if ($disposition === 'damaged assessment source') {
                DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->update(['source_id' => '999999']);
            }
            if ($disposition === 'damaged compensation') {
                $compensationId = LedgerPostingGroup::query()->where('event_type', 'withdrawal_compensation')->sole()->id;
                DB::table('ledger_entries')->where('ledger_posting_group_id', $compensationId)
                    ->where('ledger_account_id', LedgerAccount::query()->where('code', 'fee_income_ngn')->sole()->id)->decrement('amount_kobo');
                DB::table('ledger_entries')->where('ledger_posting_group_id', $compensationId)
                    ->where('ledger_account_id', LedgerAccount::query()->where('code', 'cash_recovery_clearing_ngn')->sole()->id)->increment('amount_kobo');
            }
            $before = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
            expect(fn () => $withdrawals->preview($agent, $customer->fresh(), $instruction))->toThrow(ConflictHttpException::class, 'once-per-cycle withdrawal fee history is unavailable');
            expect(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($before);
            expect(app(PlanFeeHistoryReadService::class)->readMany($agent, [$plan->fresh()])[$plan->plan_id]['status'])->toBe('unavailable');

            return;
        }
        if ($type === 'partial' && $disposition === 'damaged daily basis') {
            DB::table('fee_snapshots')->where('id', $plan->currentTermsRevision()->fee_snapshot_id)
                ->update(['amount_kobo' => 100000, 'basis_amount_kobo' => 100000]);
            $before = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
            expect(fn () => $withdrawals->preview($agent, $customer->fresh(), $instruction))
                ->toThrow(ConflictHttpException::class, 'The contractual daily fee basis is unavailable.');
            expect(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($before);
            $this->assertDatabaseCount('withdrawal_requests', 0);

            return;
        }
        $quote = $withdrawals->preview($agent, $customer->fresh(), $instruction);
        expect($quote['fee_kobo'])->toBe($expectedFee)->and($quote['net_kobo'])->toBe($quote['gross_kobo'] - $expectedFee);
        $previousFee = $type === 'partial' || $disposition === 'compensated' ? 0 : $firstFee;
        expect($quote['fee_disclosure']['new_fee'])->toBe(MoneyFormatter::formatNaira($expectedFee))
            ->and($quote['fee_disclosure']['existing_fee_included'])->toBe('₦0.00')
            ->and($quote['fee_disclosure']['already_assessed'])->toBe(MoneyFormatter::formatNaira($previousFee))
            ->and($quote['fee_disclosure']['already_settled'])->toBe(MoneyFormatter::formatNaira($previousFee))
            ->and($quote['fee_disclosure']['already_waived'])->toBe('₦0.00')
            ->and($quote['fee_disclosure']['existing_unpaid'])->toBe('₦0.00');
        $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $instruction)
            ->assertOk()->assertJsonPath('fee_disclosure', $quote['fee_disclosure']);

        $submission = [...$instruction, 'attempt_reference' => (string) Str::uuid(),
            'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
            'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
            'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true];
        $withdrawal = $withdrawals->submit($agent, $customer, $submission);
        if ($type === 'partial' && in_array($disposition, ['cancelled', 'rejected'], true)) {
            $viewer = $disposition === 'cancelled' ? $agent : $admin;
            $this->actingAs($viewer)->withSession(cashSession())->post(route('withdrawals.'.($disposition === 'cancelled' ? 'cancel' : 'reject'), $withdrawal), [
                'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true,
                'internal_reason' => 'Original request will not be paid.', 'decision_note' => 'Original request will not be paid.',
                'customer_explanation' => 'This instruction was not paid.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            expect(DB::table('fee_obligations')->count())->toBe(0);
            $quote = $withdrawals->preview($agent, $customer->fresh(), $instruction);
            expect($quote['fee_kobo'])->toBe($firstFee);
            if ($largePercentage) {
                $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'gross_amount_kobo' => 1000000, 'status' => 'released']);
                expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(2000000);
            }
            $submission = [...$instruction, 'attempt_reference' => (string) Str::uuid(),
                'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
                'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
                'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true];
            $withdrawal = $withdrawals->submit($agent, $customer, $submission);
        }
        if ($largePercentage) {
            expect($quote['gross_kobo'])->toBe(1000000)->and($quote['fee_kobo'])->toBe(20000)->and($quote['net_kobo'])->toBe(980000);
            expect($withdrawal->gross_amount_kobo)->toBe(1000000)->and($withdrawal->fee_amount_kobo)->toBe(20000)->and($withdrawal->net_amount_kobo)->toBe(980000);
            $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'gross_amount_kobo' => 1000000, 'status' => 'live']);
            $beforeReplay = cashPercentageOwnerRows();
            expect($withdrawals->submit($agent, $customer, $submission)->id)->toBe($withdrawal->id);
            expect(cashPercentageOwnerRows())->toBe($beforeReplay);
            expect(DB::table('fee_obligations')->count())->toBe($type === 'partial' ? 0 : 1);
        }
        $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed original cycle funds.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
            'version' => $withdrawal->fresh()->version, 'evidence' => 'Verified Customer at till.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $execution = CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->sole();
        if ($type === 'partial' && $disposition === 'not delivered') {
            $this->post(route('cash-executions.not-delivered', $execution), ['evidence' => 'Definitively no cash handed over.', 'confirmed' => true])->assertRedirect();
            expect($execution->fresh()->status)->toBe('payment_failed')->and(DB::table('fee_obligations')->count())->toBe(0);
            $this->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
                'version' => $withdrawal->fresh()->version, 'evidence' => 'Verified Customer returned to till.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
            $execution = CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->where('status', 'processing')->sole();
        }
        $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified exact net handoff.', 'confirmed' => true])->assertRedirect();
        $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
        expect($execution->fresh()->status)->toBe('posted');
        if ($largePercentage) {
            expect($execution->amount_kobo)->toBe(980000);
            $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'status' => 'consumed']);
            $group = LedgerPostingGroup::findOrFail($execution->fresh()->ledger_posting_group_id);
            foreach (['customer_savings_liability_ngn' => ['debit', 1000000], 'business_cash_ngn' => ['credit', 980000], 'fee_income_ngn' => ['credit', 20000]] as $code => [$side, $amount]) {
                expect((int) $group->entries()->where('ledger_account_id', LedgerAccount::query()->where('code', $code)->sole()->id)->where('side', $side)->sum('amount_kobo'))->toBe($amount);
            }
            expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe($type === 'partial' ? 1000000 : 0);
            $beforeReplay = cashPercentageOwnerRows();
            $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
            expect(cashPercentageOwnerRows())->toBe($beforeReplay);
        }
        if ($type === 'partial' && $disposition === 'concession') {
            $admin->givePermissionTo(AdminPermission::FeesManage);
            $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', FeeObligation::query()->sole()), [
                'refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '100.00',
                'reason' => 'Independent full fee concession.', 'confirmed' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        if ($type === 'partial' && in_array($disposition, ['compensated', 'damaged compensation'], true)) {
            $this->actingAs($admin)->withSession(cashSession())->post(route('cash-executions.return', $execution), [
                'preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'],
                'recovery_reference' => (string) Str::uuid(), 'evidence' => 'Full net cash counted back into the till.', 'confirmed' => true,
            ])->assertRedirect();
            $recovery = CashRecovery::query()->sole();
            $this->actingAs($customer->user)->post(route('cash-recoveries.acknowledge', $recovery), ['confirmed' => true])->assertRedirect();
            $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
            $corrections = app(ReversalService::class);
            $preview = $corrections->preview($agent, $group);
            $correction = $corrections->submit($agent, $group, ['attempt_reference' => (string) Str::uuid(),
                'preview_fingerprint' => $preview['preview_fingerprint'], 'customer_version' => $preview['customer_version'],
                'assignment_version' => $preview['assignment_version'], 'reason_category' => 'incorrect_payout_record',
                'internal_reason' => 'Original payout was erroneous.', 'customer_explanation' => 'Original full payout and fee corrected.',
                'evidence_text' => 'Bound full cash return retained.', 'confirmed' => true]);
            $admin->givePermissionTo(AdminPermission::ReversalsReview);
            $review = $corrections->reviewPreview($admin, $correction);
            $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $correction), [
                'attempt_reference' => (string) Str::uuid(), 'version' => $correction->version,
                'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Verified exact full return.', 'confirmed' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();
            expect(FeeObligation::query()->sole()->assessedAmountKobo())->toBe(0);
        }
        if ($type === 'partial' && $disposition === 'damaged snapshot') {
            DB::table('fee_snapshots')->where('source_type', 'withdrawal')->update(['source_id' => '999999']);
        }
        if ($type === 'partial' && $disposition === 'damaged assessment') {
            DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->delete();
        }
        if ($type === 'partial') {
            $plan->refresh();
            $revision = [...$data, 'name' => 'Customer clarified name', 'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision,
                'reason' => 'Customer confirmed clearer name.', 'customer_explanation' => 'Financial agreement remains unchanged.'];
            $revision['preview_fingerprint'] = $plans->previewRevision($agent, $plan, $revision)['preview_fingerprint'];
            $plan = $plans->revise($agent, $plan, (string) Str::uuid(), $revision);
            app(LedgerTransactionProjectionService::class)->rebuild();
        }
    }
    expect(DB::table('fee_obligations')->count())->toBe($model === 'percentage' || $disposition === 'compensated' ? 2 : 1)
        ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->sum('amount_kobo'))->toBe($firstFee + $laterFee)
        ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->sum('amount_kobo'))->toBe($firstFee + $laterFee)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(0);
    $reader = app(PlanFeeHistoryReadService::class);
    $history = $reader->read($customer->user, $plan->fresh());
    $summary = $reader->readMany($customer->user, [$plan->fresh()])[$plan->plan_id];
    expect($history['status'])->toBe('available')->and($summary['status'])->toBe('available')
        ->and($summary['totals'])->toEqual($history['totals'])
        ->and($summary['source_version'])->toBe($history['source_version']);
    $savings = app(PlanSavingsReadService::class);
    expect($savings->readMany($customer->user, [$plan->fresh()])[$plan->plan_id])
        ->toEqual($savings->read($customer->user, $plan->fresh()));
    $activityReader = app(PlanFinancialActivityReadService::class);
    $posted = $activityReader->readMany($customer->user, [$plan->fresh()])[$plan->plan_id];
    expect($posted['status'])->toBe('available')->and($posted)->toEqual($activityReader->read($customer->user, $plan->fresh()));
    $postingHistory = $activityReader->history($customer->user, $plan->fresh());
    expect($postingHistory['status'])->toBe('available');
    $components = collect($postingHistory['history']['data'])->groupBy('component');
    expect($components->get('gross_withdrawals'))->not->toBeNull()
        ->and($components->get('net_cash_payouts'))->not->toBeNull();
    if ($disposition === 'compensated') {
        expect($components->get('withdrawal_compensation'))->not->toBeNull()
            ->and($components->get('confirmed_cash_returns'))->not->toBeNull();
    }
    if ($model !== 'percentage') {
        $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'type' => 'full', 'gross_ngn' => '3000.00'];
        expect(fn () => $withdrawals->preview($agent, $customer->fresh(), $instruction))
            ->toThrow(ValidationException::class, 'The gross debit exceeds available savings.');
    }
    $foreignCustomer = CustomerProfile::factory()->create();
    DB::table('ledger_posting_groups')->where('thrift_plan_id', $plan->id)->where('event_type', 'cash_withdrawal')
        ->update(['customer_profile_id' => $foreignCustomer->id]);
    expect($activityReader->readMany($admin, [$plan->fresh()])[$plan->plan_id]['metrics'])
        ->toEqual($activityReader->read($admin, $plan->fresh())['metrics']);
})->with(['fixed once' => ['fixed', 10000, 0], 'one day once' => ['one_day', 200000, 0], 'percentage per payout' => ['percentage', 6000, 6000],
    'FEE-AC-015 exact gross percentage' => ['percentage', 20000, 20000, 'normal', true],
    'FEE-AC-015 rejected percentage' => ['percentage', 20000, 20000, 'rejected', true],
    'cancelled does not consume' => ['fixed', 10000, 0, 'cancelled'],
    'rejected does not consume' => ['fixed', 10000, 0, 'rejected'],
    'no handoff does not consume' => ['fixed', 10000, 0, 'not delivered'],
    'concession retains marker' => ['fixed', 10000, 0, 'concession'],
    'full correction restores marker' => ['fixed', 10000, 10000, 'compensated'],
    'damaged compensation' => ['fixed', 10000, 10000, 'damaged compensation'],
    'damaged payment snapshot' => ['fixed', 10000, 0, 'damaged snapshot'],
    'missing assessment' => ['fixed', 10000, 0, 'damaged assessment'],
    'missing original payout source' => ['fixed', 10000, 0, 'damaged posted source'],
    'foreign assessment source' => ['fixed', 10000, 0, 'damaged assessment source'],
    'paid fee hidden in request' => ['fixed', 10000, 0, 'damaged request fee'],
    'unsupported fee entry type' => ['fixed', 10000, 0, 'damaged entry type'],
    'wrong captured daily basis' => ['one_day', 200000, 0, 'damaged daily basis'],
]);

test('a real partial payout consumes its reservation once without subtracting it twice from available savings', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Africa/Lagos'));
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true]);
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    $agent = $agentProfile->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $rule = FeeRule::create(['version' => 1, 'name' => 'Explicit cycle fee terms', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution',
        'basis' => 'none', 'settlement_source' => 'external_receipt',
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'Captured cycle fee terms', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated test terms.']);
    $data = ['name' => 'Original daily cycle', 'amount_ngn' => '5000.00', 'start_date' => '2026-10-05', 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $plans->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    $assignment = $customer->currentAssignment;
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, '2026-10-05', '10000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    $slots = $plan->slots()->get()->map->getAttributes()->all();
    $originalReceipt = $receipt->getAttributes();
    $allocations = $receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all();
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '3000.00'];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    expect($quote['fee_kobo'])->toBe(0);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    foreach ([$agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.card', $plan))
            ->assertInertia(fn (Assert $page) => $page->where('card.funded_kobo', 1000000)
                ->where('card.position.liability_kobo', 1000000)->where('card.position.reservations_kobo', 300000)
                ->where('card.position.available_kobo', 700000));
    }
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true,
        'decision_note' => 'Independently reviewed partial payout.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->travel(3)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::findOrFail($receipt->collection_batch_id);
    $this->actingAs($admin)->withSession([...cashSession(), 'auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->post(route('collection-batches.remittances.store', $batch), [
            'handoff_reference' => 'PARTIAL-RESERVATION', 'amount_ngn' => '10000.00', 'handoff_date' => now('Africa/Lagos')->toDateString(),
            'receiving_location' => 'Verified business till', 'source_attestation' => 'Counted original Agent cash.',
            'batch_version' => $batch->version, 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    app(LedgerTransactionProjectionService::class)->rebuild();
    [$execution] = startCashFixture($this, $admin, $withdrawal->fresh());
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified partial Customer cash handoff.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'status' => 'consumed', 'gross_amount_kobo' => 300000]);
    expect($withdrawal->fresh()->state)->toBe('posted');
    $baseline = [];
    foreach (['withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'ledger_posting_groups', 'ledger_entries'] as $table) {
        $baseline[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();
    foreach ([$agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.card', $plan))
            ->assertInertia(fn (Assert $page) => $page->where('card.funded_kobo', 1000000)
                ->where('card.position.liability_kobo', 700000)->where('card.position.reservations_kobo', 0)
                ->where('card.position.available_kobo', 700000));
    }
    foreach ($baseline as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    expect($plan->slots()->get()->map->getAttributes()->all())->toBe($slots)
        ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
        ->and($receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all())->toBe($allocations);
});

test('a real open cycle retains capacity after its final date and full acknowledged payout until explicit settled closure', function (string $state, string $amount, int $feeKobo, bool $correctClosed = false): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Africa/Lagos'));
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true]);
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    $agent = $agentProfile->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $rule = FeeRule::create(['version' => 1, 'name' => 'Explicit cycle fee terms', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => $feeKobo > 0 ? 'fixed' : 'no_fee', 'timing' => $feeKobo > 0 ? 'withdrawal' : 'first_contribution',
        'basis' => 'none', 'settlement_source' => $feeKobo > 0 ? 'withdrawal_payout' : 'external_receipt',
        'currency' => 'NGN', 'amount_kobo' => $feeKobo, 'customer_description' => 'Captured cycle fee terms', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated test terms.']);
    $data = ['name' => 'Original daily cycle', 'amount_ngn' => '2000.00', 'start_date' => '2026-10-05', 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $candidate = [...$data, 'name' => 'Future separate cycle', 'start_date' => '2026-10-10'];
    $candidate['preview_fingerprint'] = $plans->preview($agent, $customer, $candidate)['preview_fingerprint'];
    $data['preview_fingerprint'] = $plans->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    $assignment = $customer->currentAssignment;
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, '2026-10-05', $amount);
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    $plan->refresh();
    if ($state === 'paused') {
        $plans->transition($agent, $plan, 'pause', (string) Str::uuid(), ['plan_version' => $plan->version,
            'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
            'reason' => 'Pause collection while settling.', 'customer_explanation' => 'Original dates remain.']);
    }
    $plan->refresh();
    expect($plan->status->value)->toBe($state)
        ->and($plan->currentTermsRevision()->feeSnapshot->source_type)->toBe('plan_terms_revision');
    $slots = $plan->slots()->get()->map->getAttributes()->all();
    $terms = $plan->currentTermsRevision()->getAttributes();
    $grossKobo = $amount === '4000.00' ? 400000 : 200000;
    $beforeCard = app(CollectionReadService::class)->card($plan);
    $paidFields = array_flip(['id', 'ordinal', 'due_date', 'target_kobo', 'funded_kobo', 'remaining_kobo', 'status', 'advance']);
    $paidBefore = collect($beforeCard['slots'])->where('status', 'paid')
        ->map(fn (array $slot): array => array_intersect_key($slot, $paidFields))->values()->all();
    expect($beforeCard['funded_kobo'])->toBe($grossKobo)->and($beforeCard['position']['liability_kobo'])->toBe($grossKobo)
        ->and($beforeCard['position']['reservations_kobo'])->toBe(0)
        ->and($beforeCard['position']['available_kobo'])->toBe($grossKobo)
        ->and($paidBefore)->toHaveCount($amount === '4000.00' ? 2 : 1);
    $originalReceipt = $receipt->getAttributes();
    $originalAllocations = $receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all();
    $receiptCorrectionPreview = null;
    if ($correctClosed) {
        config()->set('collections.receipt_corrections_enabled', true);
        $receiptCorrectionPreview = app(ReversalService::class)->preview($agent,
            LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id));
    }
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'type' => 'full', 'gross_ngn' => $amount];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    expect($quote['fee_kobo'])->toBe($feeKobo);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $reservedCard = app(CollectionReadService::class)->card($plan->fresh());
    expect($reservedCard['funded_kobo'])->toBe($grossKobo)
        ->and($reservedCard['position']['liability_kobo'])->toBe($grossKobo)
        ->and($reservedCard['position']['reservations_kobo'])->toBe($grossKobo)
        ->and($reservedCard['position']['available_kobo'])->toBe(0);
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true,
        'decision_note' => 'Independently reviewed exact full cycle settlement.',
    ])->assertRedirect();
    $this->travel(3)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::findOrFail($receipt->collection_batch_id);
    $this->actingAs($admin)->withSession([...cashSession(), 'auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->post(route('collection-batches.remittances.store', $batch), [
            'handoff_reference' => 'FULL-CYCLE-CAPACITY', 'amount_ngn' => $amount, 'handoff_date' => now('Africa/Lagos')->toDateString(),
            'receiving_location' => 'Verified business till', 'source_attestation' => 'Counted original Agent cash.',
            'batch_version' => $batch->version, 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    app(LedgerTransactionProjectionService::class)->rebuild();
    [$execution] = startCashFixture($this, $admin, $withdrawal->fresh());
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified full Customer cash handoff.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $position = app(WithdrawalBalanceService::class)->position($customer, $plan);
    expect($withdrawal->fresh()->state)->toBe('posted')->and($position['cycle_liability_kobo'])->toBe(0)
        ->and($position['cycle_reservations_kobo'])->toBe(0)->and($plan->fresh()->status->value)->toBe($state)
        ->and($plan->fresh()->open_customer_profile_id)->toBe($customer->id);
    $baseline = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'plan_operation_attempts',
        'plan_lifecycle_events', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $baseline[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    app(LedgerTransactionProjectionService::class)->rebuild();
    foreach ([$agent, $customer->user] as $viewer) {
        $cardResponse = $this->actingAs($viewer)->get(route('plans.card', $plan))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('card.funded_kobo', $grossKobo)
                ->where('card.target_kobo', 400000)->where('card.position.liability_kobo', 0)
                ->where('card.position.reservations_kobo', 0)->where('card.position.available_kobo', 0));
        $paidAfter = collect($cardResponse->inertiaProps('card.slots'))->where('status', 'paid')
            ->map(fn (array $slot): array => array_intersect_key($slot, $paidFields))->values()->all();
        expect($paidAfter)->toEqual($paidBefore)
            ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
            ->and($receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalAllocations);
        $response = $this->actingAs($viewer)->get(route('plans.show', $plan->plan_id));
        $response->assertInertia(fn (Assert $page) => $page
            ->where('plan.savings_summary.customer.liability', '₦0.00')
            ->where('plan.savings_summary.customer.reserved', '₦0.00')
            ->where('plan.savings_summary.customer.available', '₦0.00')
            ->where('plan.savings_summary.cycle.liability', '₦0.00')
            ->where('plan.savings_summary.cycle.reserved', '₦0.00')
            ->where('plan.savings_summary.cycle.available', '₦0.00')
            ->where('plan.financial_summary.funded_principal', $amount === '4000.00' ? '₦4,000.00' : '₦2,000.00')
            ->where('plan.estimate.expected_gross', '₦4,000.00')
            ->where('plan.posted_activity.status', 'available'));
        $movements = collect($response->inertiaProps('plan.posted_activity.metrics'))->keyBy('code');
        expect($movements->get('gross_withdrawals')['value'])->toBe($grossKobo)
            ->and($movements->get('net_cash_payouts')['value'])->toBe($grossKobo - $feeKobo)
            ->and($movements->get('withdrawal_fees')['value'])->toBe($feeKobo)
            ->and($movements->get('withdrawal_compensation')['value'])->toBe(0)
            ->and($movements->get('withdrawal_cash_returns')['value'])->toBe(0)
            ->and($movements->get('effective_withdrawal_cash_paid')['value'])->toBe($grossKobo - $feeKobo)
            ->and($movements->get('other_deductions')['value'])->toBe(0);
    }
    $this->actingAs($agent)->getJson(route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1,
        ...array_intersect_key($candidate, array_flip(['name', 'amount_ngn', 'start_date', 'contribution_days', 'customer_visible_notes', 'fee_rule_id']))]))
        ->assertUnprocessable()->assertInvalid(['customer']);
    $this->postJson(route('customers.plans.store', $customer->customer_id), [...$candidate, 'attempt_reference' => (string) Str::uuid()])
        ->assertUnprocessable()->assertInvalid(['customer']);
    foreach ($baseline as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    expect($plan->slots()->get()->map->getAttributes()->all())->toBe($slots)
        ->and($plan->fresh()->currentTermsRevision()->getAttributes())->toBe($terms);
    if ($state === 'completed') {
        $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.review', $batch), [
            'batch_version' => $batch->fresh()->version, 'reason' => 'Exact original custody verified.', 'confirmed' => true,
        ])->assertRedirect();
        $groups = LedgerPostingGroup::query()->get()->map->getAttributes()->all();
        $receipts = DB::table('collection_receipts')->get()->all();
        $allocations = DB::table('collection_allocations')->get()->all();
        $fees = DB::table('fee_obligation_entries')->get()->all();
        $settlement = app(PlanSettlementService::class);
        $quote = $settlement->preview($agent, $plan->fresh());
        expect($quote['can_close'])->toBeTrue();
        $close = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
            'reason' => 'Fully funded and actually settled cycle.', 'customer_explanation' => 'Your original cycle is closed.'];
        $settlement->confirm($agent, $plan, 'close', $close);
        $settlement->confirm($agent, $plan, 'close', $close);
        expect($plan->fresh()->status->value)->toBe('closed')->and($plan->fresh()->open_customer_profile_id)->toBeNull()
            ->and($plan->slots()->get()->map->getAttributes()->all())->toBe($slots)
            ->and($plan->fresh()->currentTermsRevision()->getAttributes())->toBe($terms)
            ->and(DB::table('collection_receipts')->get()->all())->toEqual($receipts)
            ->and(DB::table('collection_allocations')->get()->all())->toEqual($allocations)
            ->and(DB::table('fee_obligation_entries')->get()->all())->toEqual($fees)
            ->and($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1)
            ->and(LedgerPostingGroup::query()->get()->map->getAttributes()->all())->toBe($groups)
            ->and(ThriftPlan::count())->toBe(1)->and(DB::table('fee_obligations')->count())->toBe($feeKobo > 0 ? 1 : 0)
            ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->sum('amount_kobo'))->toBe($feeKobo);
        if ($correctClosed) {
            config()->set(['withdrawals.cash_compensation_enabled' => true, 'collections.receipt_corrections_enabled' => true]);
            $closedHistory = $plan->lifecycleEvents()->orderBy('id')->get()->map->getAttributes()->all();
            $closedVersion = $plan->fresh()->version;
            $successor = null;
            $successorAttributes = null;
            $successorSlots = [];
            if ($feeKobo > 0) {
                $customer->refresh();
                $candidate = [...$candidate, 'customer_version' => $customer->version,
                    'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version];
                $candidate['preview_fingerprint'] = $plans->preview($agent, $customer, $candidate)['preview_fingerprint'];
                $successor = $plans->create($agent, $customer, (string) Str::uuid(), $candidate)['plan'];
                $successor->refresh();
                $successorAttributes = $successor->getAttributes();
                $successorSlots = $successor->slots()->get()->map->getAttributes()->all();
            }
            $spentSources = [];
            foreach (['customer_profiles', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_lifecycle_events',
                'collection_receipts', 'collection_allocations', 'collection_allocation_releases', 'collection_batches',
                'cash_remittances', 'collection_batch_reviews', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
                'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations',
                'cash_executions', 'cash_recoveries', 'reversal_requests', 'reversal_attempts', 'reversal_events',
                'financial_workflow_supplements'] as $table) {
                $spentSources[$table] = DB::table($table)->orderBy('id')->get()->all();
            }
            $originalReference = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id)->posting_reference;
            $this->actingAs($agent)->postJson(route('reversals.preview', $originalReference))
                ->assertConflict()->assertJsonPath('message', 'Resolve dependent reservations or payouts before removing this receipt.');
            $this->postJson(route('reversals.store', $originalReference), [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $receiptCorrectionPreview['preview_fingerprint'],
                'customer_version' => $customer->fresh()->version, 'assignment_version' => $assignment->fresh()->version,
                'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Attempt to correct the receipt after its savings were paid out.',
                'customer_explanation' => 'The receipt needs review after the original payout is resolved.',
                'evidence_text' => 'Original receipt remains retained; no payout return has occurred.', 'confirmed' => true,
            ])->assertConflict()->assertJsonPath('message', 'Resolve dependent reservations or payouts before removing this receipt.');
            foreach ($spentSources as $table => $rows) {
                expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
            }
            expect(app(WithdrawalBalanceService::class)->position($customer, $plan->fresh())['cycle_liability_kobo'])->toBe(0);
            $this->actingAs($admin)->withSession(cashSession())->post(route('cash-executions.return', $execution), [
                'preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'],
                'recovery_reference' => (string) Str::uuid(), 'evidence' => 'Full actual net cash counted back into the original till.', 'confirmed' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $recovery = CashRecovery::query()->sole();
            $this->actingAs($customer->user)->post(route('cash-recoveries.acknowledge', $recovery), ['confirmed' => true])->assertRedirect();
            $paidGroup = LedgerPostingGroup::findOrFail($execution->fresh()->ledger_posting_group_id);
            $corrections = app(ReversalService::class);
            $preview = $corrections->preview($agent, $paidGroup);
            $correction = $corrections->submit($agent, $paidGroup, ['attempt_reference' => (string) Str::uuid(),
                'preview_fingerprint' => $preview['preview_fingerprint'], 'customer_version' => $preview['customer_version'],
                'assignment_version' => $preview['assignment_version'], 'reason_category' => 'incorrect_payout_record',
                'internal_reason' => 'Original payout was erroneous and its entire net cash was returned.',
                'customer_explanation' => 'The original payout needs a separately reviewed correction.',
                'evidence_text' => 'Bound full Customer-confirmed cash return retained.', 'confirmed' => true]);
            $admin->givePermissionTo(AdminPermission::ReversalsReview);
            $review = $corrections->reviewPreview($admin, $correction);
            $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $correction), [
                'attempt_reference' => (string) Str::uuid(), 'version' => $correction->version,
                'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Verified original full cash return and fee correction.', 'confirmed' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();
            expect($plan->fresh()->status->value)->toBe('closed')
                ->and(app(WithdrawalBalanceService::class)->position($customer, $plan->fresh())['cycle_liability_kobo'])->toBe($grossKobo);
            $receiptCorrection = approveReceiptCorrection($this, $agent, $customer->fresh(), $assignment->fresh(),
                LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
            $closed = $plan->fresh();
            $exception = $closed->lifecycleEvents()->where('event_type', 'receipt_compensated')->sole();
            expect($closed->status->value)->toBe('closed')->and($closed->version)->toBe($closedVersion)
                ->and($closed->open_customer_profile_id)->toBeNull()
                ->and($exception->from_status->value)->toBe('closed')->and($exception->to_status->value)->toBe('closed')
                ->and($exception->payload['closed_plan_exception'])->toBeTrue()
                ->and($exception->payload['reversal_request_id'])->toBe($receiptCorrection->id)
                ->and($closed->slots()->get()->map->getAttributes()->all())->toBe($slots)
                ->and($closed->currentTermsRevision()->getAttributes())->toBe($terms)
                ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
                ->and($receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalAllocations)
                ->and(app(WithdrawalBalanceService::class)->position($customer, $closed)['cycle_liability_kobo'])->toBe(0);
            foreach ($closedHistory as $event) {
                expect(DB::table('plan_lifecycle_events')->where('id', $event['id'])->first())
                    ->toEqual((object) $event);
            }
            $exceptionGate = $settlement->preview($agent, $closed);
            expect($exceptionGate['exceptions'])->toBe([$exception->id])
                ->and($exceptionGate['blockers'])->toContain('Post-closure correction exception remains.')
                ->and($exceptionGate['can_close'])->toBeFalse()
                ->and($plans->archivalStatus($customer))->toBe('blocked');
            $archiveChecks = collect(app(CustomerLifecycleEligibility::class)->preview($admin, $customer)['checks']);
            expect($archiveChecks->firstWhere('key', 'plans')['status'])->toBe('blocked');
            if ($successor !== null) {
                expect($successor->fresh()->getAttributes())->toBe($successorAttributes)
                    ->and($successor->slots()->get()->map->getAttributes()->all())->toBe($successorSlots);
            }
            $correctedGroups = LedgerPostingGroup::query()->orderBy('id')->get()->map->getAttributes()->all();
            $correctedEvents = $closed->lifecycleEvents()->orderBy('id')->get()->map->getAttributes()->all();
            expect($collection->record($agent, $customer->fresh(), $payload)->id)->toBe($receipt->id);
            expect(fn () => $plans->transition($agent, $closed, 'resume', (string) Str::uuid(), [
                'plan_version' => $closed->version, 'customer_version' => $customer->fresh()->version,
                'assignment_version' => $assignment->fresh()->version, 'reason' => 'A correction must not resume a Closed cycle.',
                'customer_explanation' => 'The original cycle stays Closed.',
            ]))->toThrow(ConflictHttpException::class);
            expect($plan->fresh()->status->value)->toBe('closed')
                ->and(LedgerPostingGroup::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($correctedGroups)
                ->and($closed->lifecycleEvents()->orderBy('id')->get()->map->getAttributes()->all())->toBe($correctedEvents);

            return;
        }
        expect($plans->preview($agent, $customer, $candidate)['terms']['start_date'])->toBe('2026-10-10');
        $customer->refresh();
        $candidate = [...$candidate, 'customer_version' => $customer->version,
            'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version];
        $candidate['preview_fingerprint'] = $plans->preview($agent, $customer, $candidate)['preview_fingerprint'];
        $successor = $plans->create($agent, $customer, (string) Str::uuid(), $candidate)['plan'];
        $this->travel(2)->days();
        $nextReceipt = collectionPayload($customer, $customer->currentAssignment, $successor, '2026-10-10', '2000.00');
        $nextReceipt['preview_fingerprint'] = $collection->preview($agent, $customer, $nextReceipt)['preview_fingerprint'];
        $collection->record($agent, $customer, $nextReceipt);
        submittedWithdrawal($agent, $customer, $customer->currentAssignment, $successor->fresh());
        app(LedgerTransactionProjectionService::class)->rebuild();
        $savingsReader = app(PlanSavingsReadService::class);
        $cycleSavings = $savingsReader->readMany($customer->user, [$plan->fresh(), $successor->fresh()]);
        expect($cycleSavings[$plan->plan_id]['customer']['available'])->toBe('₦1,700.00')
            ->and($cycleSavings[$plan->plan_id]['cycle']['available'])->toBe('₦0.00')
            ->and($cycleSavings[$successor->plan_id]['customer']['available'])->toBe('₦1,700.00')
            ->and($cycleSavings[$successor->plan_id]['cycle']['available'])->toBe('₦1,700.00')
            ->and($cycleSavings[$plan->plan_id])->toEqual($savingsReader->read($customer->user, $plan->fresh()))
            ->and($cycleSavings[$successor->plan_id])->toEqual($savingsReader->read($customer->user, $successor->fresh()));
        $activityReader = app(PlanFinancialActivityReadService::class);
        $cycleActivity = $activityReader->readMany($customer->user, [$plan->fresh(), $successor->fresh()]);
        expect($cycleActivity[$plan->plan_id])->toEqual($activityReader->read($customer->user, $plan->fresh()))
            ->and($cycleActivity[$successor->plan_id])->toEqual($activityReader->read($customer->user, $successor->fresh()))
            ->and(collect($cycleActivity[$successor->plan_id]['metrics'])->every(fn (array $metric): bool => $metric['value'] === 0))->toBeTrue();
        $paidGroup = LedgerPostingGroup::query()->where('thrift_plan_id', $plan->id)->where('event_type', 'cash_withdrawal')->sole();
        DB::table('ledger_entries')->where('ledger_posting_group_id', $paidGroup->id)
            ->where('ledger_account_id', LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->sole()->id)
            ->update(['amount_kobo' => 400000.5]);
        $damagedActivity = $activityReader->readMany($customer->user, [$plan->fresh(), $successor->fresh()]);
        expect($damagedActivity[$plan->plan_id]['status'])->toBe('unavailable')
            ->and($activityReader->read($customer->user, $plan->fresh())['status'])->toBe('unavailable')
            ->and($damagedActivity[$successor->plan_id])->toEqual($cycleActivity[$successor->plan_id]);
    }
})->with([
    'Active paid out' => ['active', '2000.00', 0], 'Paused paid out' => ['paused', '2000.00', 0],
    'Completed paid out' => ['completed', '4000.00', 0], 'Completed withdrawal fee settled' => ['completed', '4000.00', 10000],
    'Closed no-fee corrected after actual return' => ['completed', '4000.00', 0, true],
    'Closed fee corrected after actual return' => ['completed', '4000.00', 10000, true],
]);

test('an Inactive Customer settles existing cycle savings through independent review and acknowledged cash without changing contribution history', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $terms = $plan->currentTermsRevision()->getAttributes();
    $allocations = $receipt->allocations()->get()->map->getAttributes()->all();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CustomersManage, AdminPermission::WithdrawalsReview,
        AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Inactive, $customer->version,
        'Customer ends active participation.', 'You can settle your existing savings.');
    $customer->refresh();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan->fresh());
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version,
        'confirmed' => true, 'decision_note' => 'Reviewed Inactive Customer existing savings instruction.',
    ])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('approved')
        ->and($withdrawal->fresh()->reviewed_by_user_id)->toBe($admin->id);
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::findOrFail($receipt->collection_batch_id);
    $this->actingAs($admin)->withSession([...cashSession(), 'auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->post(route('collection-batches.remittances.store', $batch), [
            'handoff_reference' => 'INACTIVE-EXISTING-SAVINGS', 'amount_ngn' => '2000.00',
            'handoff_date' => now('Africa/Lagos')->toDateString(), 'receiving_location' => 'Verified business till',
            'source_attestation' => 'Counted original Agent cash.', 'batch_version' => $batch->version, 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    app(LedgerTransactionProjectionService::class)->rebuild();
    [$execution] = startCashFixture($this, $admin, $withdrawal->fresh());
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified Customer cash handoff.', 'confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown');
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $groups = LedgerPostingGroup::query()->get()->map->getAttributes()->all();
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Inactive)
        ->and($withdrawal->fresh()->state)->toBe('posted')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('consumed')
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(170000)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots)
        ->and($plan->fresh()->currentTermsRevision()->getAttributes())->toBe($terms)
        ->and($plan->fresh()->status->value)->toBe('active')
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and($receipt->allocations()->get()->map->getAttributes()->all())->toBe($allocations)
        ->and($receipt->fresh()->savings_amount_kobo)->toBe(200000)
        ->and(LedgerPostingGroup::query()->get()->map->getAttributes()->all())->toBe($groups)
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1);
});

test('cash acknowledgement rolls back the entire accounting audit and notice bundle on owner failure', function (string $failure): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    $beforeEvents = DB::table('withdrawal_events')->count();
    $beforeAudit = DB::table('audit_events')->count();
    $beforeNotices = DB::table('withdrawal_notification_intents')->count();
    $beforeCanonical = DB::table('canonical_audit_events')->count();
    $beforeSharedNotices = DB::table('notification_events')->count();
    $class = null;
    if ($failure === 'posting') {
        $inject = true;
        DB::listen(static function (QueryExecuted $query) use (&$inject): void {
            if ($inject && preg_match('/\Ainsert into ["`]ledger_entries["`]/i', $query->sql)) {
                $inject = false;
                throw new RuntimeException('Injected failure after the first ledger line.');
            }
        });
    } else {
        [$class, $method] = match ($failure) {
            'outbox' => [NotificationPipeline::class, 'capture'],
            'audit' => [AuditCapture::class, 'record'],
            default => [LedgerTransactionProjectionService::class, 'projectWithdrawal'],
        };
        $mock = Mockery::mock($class)->makePartial();
        $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('Injected '.$failure.' failure.'));
        app()->instance($class, $mock);
    }
    expect(fn () => app(CashExecutionService::class)->confirmReceipt($customer->user, $execution))->toThrow(RuntimeException::class);
    expect($execution->fresh()->status)->toBe('outcome_unknown')->and($execution->fresh()->customer_acknowledgement)->toBeNull()
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live')
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(0)
        ->and(DB::table('withdrawal_events')->count())->toBe($beforeEvents)
        ->and(DB::table('audit_events')->count())->toBe($beforeAudit)
        ->and(DB::table('withdrawal_notification_intents')->count())->toBe($beforeNotices)
        ->and(DB::table('canonical_audit_events')->count())->toBe($beforeCanonical)
        ->and(DB::table('notification_events')->count())->toBe($beforeSharedNotices);
    if ($class !== null) {
        app()->forgetInstance($class);
    }
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000);
})->with(['posting', 'audit', 'outbox', 'projection']);

test('cash requires a separate direct grant and verified remitted funding', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture(false);
    $payload = ['execution_reference' => (string) Str::uuid(), 'version' => 1, 'evidence' => 'Verified custody.', 'confirmed' => true];
    $admin->revokePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('withdrawals.cash.start', $withdrawal), $payload)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($admin->fresh())->postJson(route('withdrawals.cash.start', $withdrawal), $payload)->assertConflict();
    expect(CashExecution::query()->count())->toBe(0)->and($withdrawal->fresh()->state)->toBe('approved');
});

test('Customer acknowledged cash posts gross fee and net exactly once and rebuilds history', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution, $payload] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('withdrawals.cash.start', $withdrawal), $payload)->assertRedirect();
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Cash handed to the verified Customer.', 'confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('posted')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('consumed')
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(70000);
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
    expect($group->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe(30000)
        ->and($group->entries()->where('side', 'credit')->sum('amount_kobo'))->toBe(30000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('type', 'withdrawal')->latest('id')->value('savings_effect_kobo'))->toBe(-30000);
});

test('claimed cash handoff cannot expire be resent or be reported as non-delivery', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff claimed.', 'confirmed' => true])->assertRedirect();
    $this->postJson(route('cash-executions.not-delivered', $execution), ['evidence' => 'No receipt.', 'confirmed' => true])->assertConflict();
    $this->postJson(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'Second attempt.', 'confirmed' => true])->assertConflict();
    $this->travel(10)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
});

test('confirmed non-delivery preserves savings reservation and permits a separately identified retry', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.not-delivered', $execution), ['evidence' => 'Cash never left the till.', 'confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('payment_failed')->and($withdrawal->fresh()->state)->toBe('payment_failed')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
    $this->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'New verified attempt.', 'confirmed' => true])->assertRedirect();
    expect(CashExecution::query()->count())->toBe(2);
});

test('another Customer and the custodian cannot acknowledge the recipient cash', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff claimed.', 'confirmed' => true])->assertRedirect();
    $this->postJson(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertForbidden();
    $this->actingAs(User::factory()->customer()->create())->postJson(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertForbidden();
    expect(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(0);
});
