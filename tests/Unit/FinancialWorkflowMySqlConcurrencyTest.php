<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CashRemittance;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\PlanOperationAttempt;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BackgroundRecovery;
use App\Services\CashDisbursementService;
use App\Services\CashExecutionService;
use App\Services\CashRecoveryService;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\CollectionReplacementService;
use App\Services\CollectionService;
use App\Services\CustomerStatusManagementService;
use App\Services\FeeConcessionPosition;
use App\Services\FeeObligationService;
use App\Services\FeeRefundService;
use App\Services\FeeSavingsApplicationReversalOwner;
use App\Services\FeeSavingsApplicationService;
use App\Services\FinancialArtifactService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\ManualChargeService;
use App\Services\NotificationPipeline;
use App\Services\PlanFeeHistoryReadService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanSavingsReadService;
use App\Services\PlanSettlementService;
use App\Services\ReversalService;
use App\Services\StatementPreviewService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use App\Support\PlatformJobMiddleware;
use App\Support\RecoveryLease;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../CollectionNoMoneyFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    config()->set('collections.settlement_enabled', true);
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

/** @param array<string, mixed> $payload */
function financialMysqlSavingsApplication(int $adminId, int $obligationId, array $payload): Closure
{
    return static function () use ($adminId, $obligationId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe savings application worker database.');
        }
        config()->set('fees.savings_applications_enabled', true);
        $request = Request::create('/admin/fees/application', 'POST');
        $session = new Store('fee-application-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);

        return app(FeeSavingsApplicationService::class)->apply(User::findOrFail($adminId), $obligationId, $payload, $request)->posting_reference;
    };
}

test('mysql saved savings fee outcome and replay use current sources after a different worker commits', function (): void {
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Explicit agreed fee settlement.', 'customer_description' => 'Registration fee settled from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $worker = financialMysqlSavingsApplication($admin->id, $fee->id, $payload);

    DB::beginTransaction();
    try {
        expect(DB::table('fee_savings_applications')->count())->toBe(0);
        expect(DB::table('ledger_posting_groups')->count())->toBe(1);
        expect(DB::table('fee_obligation_entries')->count())->toBe(1);
        [$postingReference] = Concurrency::driver('process')->run([$worker]);
        expect(DB::table('fee_savings_applications')->count())->toBe(0);
        expect($owner->status($admin, $fee->id, $payload['attempt_reference']))
            ->toBe(['status' => 'posted', 'posting_reference' => $postingReference]);
        expect(fn () => $owner->assertAttemptPayload($admin, $fee->id, $payload['attempt_reference'], $payload))->not->toThrow(ConflictHttpException::class);
        expect($owner->apply($admin, $fee->id, $payload, financialMysqlRequest())->posting_reference)->toBe($postingReference);
        expect(app(WithdrawalBalanceService::class)->position($customer, $plan, true)['cycle_liability_kobo'])->toBe(80000);
    } finally {
        DB::rollBack();
    }

    expect(DB::table('fee_savings_applications')->count())->toBe(1);
    expect(DB::table('ledger_posting_groups')->count())->toBe(2);
    expect(DB::table('fee_obligation_entries')->count())->toBe(2);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(80000);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect(app(LedgerTransactionReadService::class)->search($customer->user, ['type' => 'fee_application'])['total'])->toBe(1);
});

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
        ->and(DB::table('manual_charge_notification_intents')->count())->toBe(3);
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

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

function mysqlReplacementTask(int $agentId, int $reversalId, array $data): Closure
{
    return static function () use ($agentId, $reversalId, $data): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe replacement race database.');
        }
        config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
        try {
            app(CollectionReplacementService::class)->record(User::findOrFail($agentId), ReversalRequest::findOrFail($reversalId), $data);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql competing replacements consume the original controlled tender only once', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $data = collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00');
    $quote = app(CollectionReplacementService::class)->preview($agent, $reversal, $data);
    $data['preview_fingerprint'] = $quote['preview_fingerprint'];
    $data['replacement_fingerprint'] = $quote['replacement_fingerprint'];
    $results = Concurrency::driver('process')->run([
        mysqlReplacementTask($agent->id, $reversal->id, $data),
        mysqlReplacementTask($agent->id, $reversal->id, [...$data, 'attempt_reference' => (string) Str::uuid()]),
    ]);
    sort($results);
    expect($results)->toBe(['blocked', 'posted'])
        ->and(CollectionReceipt::query()->where('replacement_reversal_id', $reversal->id)->count())->toBe(1)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
});

function mysqlSettlementTask(int $actorId, int $planId, array $data): Closure
{
    return static function () use ($actorId, $planId, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe settlement race database.');
        }
        config()->set('collections.settlement_enabled', true);
        try {
            app(PlanSettlementService::class)->confirm(User::findOrFail($actorId), ThriftPlan::findOrFail($planId), 'close', $data);

            return 'closed';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql competing settled closures record one closure and preserve the losing stale operation', function (): void {
    [$agent, , , $plan] = collectionFixture();
    config()->set('collections.settlement_enabled', true);
    $service = app(PlanSettlementService::class);
    $quote = $service->preview($agent, $plan, 'prepare_termination');
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'End unused cycle.', 'customer_explanation' => 'Separate settled closure.'];
    $service->confirm($agent, $plan, 'prepare_termination', $data);
    $quote = $service->preview($agent, $plan->fresh());
    $data = [...$data, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']];
    $results = Concurrency::driver('process')->run([
        mysqlSettlementTask($agent->id, $plan->id, $data),
        mysqlSettlementTask($agent->id, $plan->id, [...$data, 'attempt_reference' => (string) Str::uuid()]),
    ]);
    sort($results);
    expect($results)->toBe(['blocked', 'closed'])
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed)
        ->and($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
});

test('mysql recovery racing recipient acknowledgement preserves exactly one authoritative disposition', function (): void {
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff outcome is uncertain.', 'confirmed' => true])->assertRedirect();
    $executionId = $execution->id;
    $adminId = $admin->id;
    $recipientId = $customer->user_id;
    $fingerprint = app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'];
    $outcomes = Concurrency::driver('process')->run([
        mysqlRecoveryDispositionTask($executionId, $adminId, $fingerprint),
        mysqlRecoveryDispositionTask($executionId, $recipientId),
    ]);
    expect(count(array_filter($outcomes, fn ($result): bool => $result !== 'blocked')))->toBe(1);
    $posted = CashExecution::findOrFail($executionId)->status === 'posted';
    expect(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe($posted ? 1 : 0)
        ->and(DB::table('cash_recoveries')->count())->toBe($posted ? 0 : 1)
        ->and(DB::table('withdrawal_reservations')->where('status', 'live')->count())->toBe($posted ? 0 : 1);
});

function mysqlRecoveryDispositionTask(int $executionId, int $actorId, ?string $fingerprint = null): Closure
{
    return static function () use ($executionId, $actorId, $fingerprint): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe recovery disposition race database.');
        }
        try {
            if ($fingerprint === null) {
                app(CashExecutionService::class)->confirmReceipt(User::findOrFail($actorId), CashExecution::findOrFail($executionId));

                return 'acknowledged';
            }
            config()->set('withdrawals.cash_compensation_enabled', true);
            $request = Request::create('/cash-recovery', 'POST', ['preview_fingerprint' => $fingerprint]);
            $session = new Store('financial-worker', new ArraySessionHandler(600));
            $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
            $request->setLaravelSession($session);
            app(CashRecoveryService::class)->recordReturn(User::findOrFail($actorId), CashExecution::findOrFail($executionId), (string) Str::uuid(), 'Counted partial return under original attempt.', $request, 1000);

            return 'recorded';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql settled closure racing a new receipt cannot close a cycle with newly posted liability', function (): void {
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $service = app(PlanSettlementService::class);
    $quote = $service->preview($agent, $plan, 'prepare_termination');
    $prepare = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'End unused cycle.', 'customer_explanation' => 'Separate closure follows.'];
    $service->confirm($agent, $plan, 'prepare_termination', $prepare);
    $plan->refresh();
    app(ThriftPlanService::class)->transition($agent, $plan, 'resume', (string) Str::uuid(), [
        'plan_version' => $plan->version, 'customer_version' => $customer->fresh()->version,
        'assignment_version' => $assignment->fresh()->version, 'reason' => 'Resume the unused cycle under its original agreement.',
        'customer_explanation' => 'Your original cycle has resumed.',
    ]);
    $plan->refresh();
    $quote = $service->preview($agent, $plan);
    $close = [...$prepare, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']];
    $receipt = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $receipt['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $receipt)['preview_fingerprint'];
    $outcomes = Concurrency::driver('process')->run([
        mysqlSettlementTask($agent->id, $plan->id, $close),
        mysqlReceiptPostingTask($agent->id, $customer->id, $receipt),
    ]);
    expect(count(array_filter($outcomes, fn ($result): bool => $result !== 'blocked')))->toBe(1);
    $closed = $plan->fresh()->status === ThriftPlanStatus::Closed;
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($closed ? 0 : 200000)
        ->and(CollectionReceipt::query()->count())->toBe($closed ? 0 : 1);
});

function mysqlReceiptPostingTask(int $actorId, int $customerId, array $data): Closure
{
    return static function () use ($actorId, $customerId, $data): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe receipt race database.');
        }
        config()->set('collections.enabled', true);
        try {
            app(CollectionService::class)->record(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $data);

            return 'recorded';
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        }
    };
}

test('mysql competing renewal of a verified Closed cycle creates only one successor and one open cycle', function (bool $sameReference): void {
    config()->set('collections.settlement_enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $settlement = app(PlanSettlementService::class);
    $quote = $settlement->preview($agent, $plan, 'prepare_termination');
    $prepare = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'End unused cycle.', 'customer_explanation' => 'Separate settled closure.'];
    $settlement->confirm($agent, $plan, 'prepare_termination', $prepare);
    $quote = $settlement->preview($agent, $plan->fresh());
    $settlement->confirm($agent, $plan, 'close', [...$prepare, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']]);
    $rule = $plan->currentTermsRevision()->feeSnapshot->feeRule;
    $data = ['name' => 'New cycle', 'amount_ngn' => '2000.00', 'start_date' => $date, 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true, 'predecessor_plan_id' => $plan->plan_id];
    $data['preview_fingerprint'] = app(ThriftPlanService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $reference = (string) Str::uuid();
    $outcomes = Concurrency::driver('process')->run([
        mysqlRenewalTask($agent->id, $customer->id, $data, $reference),
        mysqlRenewalTask($agent->id, $customer->id, $data, $sameReference ? $reference : (string) Str::uuid()),
    ]);
    sort($outcomes);
    expect($outcomes)->toBe($sameReference ? ['created', 'replayed'] : ['blocked', 'created'])
        ->and(ThriftPlan::query()->where('predecessor_plan_id', $plan->id)->count())->toBe(1)
        ->and(ThriftPlan::query()->whereNotNull('open_customer_profile_id')->count())->toBe(1)
        ->and(PlanOperationAttempt::query()->where('operation_type', 'plan_renew')->count())->toBe(1)
        ->and(PlanOperationAttempt::query()->where('operation_type', 'plan_renew')->value('status'))->toBe('committed')
        ->and(CollectionReceipt::query()->count())->toBe(0)
        ->and(LedgerPostingGroup::query()->count())->toBe(0);
    $successor = ThriftPlan::query()->where('predecessor_plan_id', $plan->id)->sole();
    expect($successor->slots()->count())->toBe(2)
        ->and($successor->currentTermsRevision()->feeSnapshot->source_id)->toBe($successor->plan_id.'-R1')
        ->and(DB::table('fee_snapshots')->where('source_type', 'plan_terms_revision')->where('source_id', $successor->plan_id.'-R1')->count())->toBe(1);
})->with(['Distinct references' => false, 'Same reference retry' => true]);

function mysqlRenewalTask(int $actorId, int $customerId, array $data, string $reference): Closure
{
    return static function () use ($actorId, $customerId, $data, $reference): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe renewal race database.');
        }
        config()->set('collections.settlement_enabled', true);
        try {
            $result = app(ThriftPlanService::class)->create(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $reference, $data);

            return $result['replayed'] ? 'replayed' : 'created';
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        }
    };
}

test('mysql artifact lease takeover rejects an old renderer and publishes one private generation', function (): void {
    Queue::fake();
    Storage::fake('local');
    [, $customer] = withdrawalFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $from = now()->startOfMonth()->toDateString();
    $to = now()->toDateString();
    $preview = app(StatementPreviewService::class)->preview($customer->user, $customer, $from, $to, 'Africa/Lagos');
    $artifact = app(FinancialArtifactService::class)->issueStatement($customer->user, $customer, (string) Str::uuid(), $from, $to, $preview['preview_fingerprint']);
    $recovery = app(BackgroundRecovery::class);
    $id = DB::table('platform_recovery_work')->where('owner', 'financial_artifact')->value('id');
    $old = $recovery->claim($id);
    DB::table('platform_recovery_work')->where('id', $id)->update(['lease_expires_at' => now()->subSecond()]);
    $current = $recovery->claim($id);
    $root = Storage::disk('local')->path('');
    $results = Concurrency::driver('process')->run([
        mysqlArtifactLeaseTask($old->workId, $old->token, $old->owner, $root),
        mysqlArtifactLeaseTask($current->workId, $current->token, $current->owner, $root),
    ]);
    expect($results)->toBe(['stale_lease', 'succeeded'])->and($artifact->fresh()->status)->toBe('ready');
    $this->assertDatabaseCount('financial_artifact_events', 1);
    $this->assertDatabaseCount('financial_artifact_notification_intents', 1);
    expect(Storage::disk('local')->exists($artifact->fresh()->storage_path))->toBeTrue();
});

function mysqlArtifactLeaseTask(int $artifactWorkId, int $leaseToken, string $leaseOwner, string $testStorageRoot): Closure
{
    return static function () use ($artifactWorkId, $leaseToken, $leaseOwner, $testStorageRoot): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe artifact race database.');
        }
        config()->set('filesystems.disks.local.root', $testStorageRoot);
        Storage::forgetDisk('local');

        return app(BackgroundRecovery::class)->execute(new RecoveryLease($artifactWorkId, $leaseToken, $leaseOwner));
    };
}

function mysqlSharedFeeApprovalTask(int $adminId, int $reversalId, array $data): Closure
{
    return static function () use ($adminId, $reversalId, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe shared-fee correction race database.');
        }
        config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
        $request = Request::create('/reversals/approve', 'POST');
        $session = new Store('shared-fee-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(ReversalService::class)->decide(User::findOrFail($adminId), ReversalRequest::findOrFail($reversalId), 'approve', $data, $request);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        } catch (QueryException $exception) {
            throw new RuntimeException($exception->getMessage());
        }
    };
}

test('mysql independent approvals cannot duplicate shared receipt and plan fee compensation', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: 50000, feeSource: FeeSettlementSource::ExternalReceipt);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '2000.00'),
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '200.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $data = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '300.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Exact original tender and shared cycle fee reviewed.',
        'customer_explanation' => 'The full receipt and linked fee trigger are corrected.', 'evidence_text' => 'Original physical tender remains controlled.', 'confirmed' => true]);
    $admins = User::factory()->admin()->withTwoFactor()->count(2)->create();
    foreach ($admins as $admin) {
        $admin->givePermissionTo(AdminPermission::ReversalsReview);
    }
    $review = $service->reviewPreview($admins[0], $reversal);
    $decision = ['version' => $reversal->version, 'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Reviewed complete shared fee bundle.', 'confirmed' => true];

    $results = Concurrency::driver('process')->run([
        mysqlSharedFeeApprovalTask($admins[0]->id, $reversal->id, [...$decision, 'attempt_reference' => (string) Str::uuid()]),
        mysqlSharedFeeApprovalTask($admins[1]->id, $reversal->id, [...$decision, 'attempt_reference' => (string) Str::uuid()]),
    ]);
    sort($results);
    expect($results)->toBe(['blocked', 'posted']);
    expect($reversal->fresh()->state)->toBe('approved_posted');
    expect($fee->fresh()->assessedAmountKobo())->toBe(0);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect((int) $fee->entries()->where('entry_type', FeeObligationEntryType::SettlementReversal)->sum('amount_kobo'))->toBe(50000);
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('reversal_attempts', 2);
    expect(LedgerPostingGroup::query()->where('event_type', 'receipt_reclassification')->count())->toBe(1);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(220000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(30000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('mysql independent approvals release a fully conceded receipt settlement once without money movement', function (): void {
    [, , , , , $fee, , , $refund, $admin, $reversal, $decision] = noMoneyReceiptFixture($this);
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin->givePermissionTo(AdminPermission::ReversalsReview);
    $groupIds = LedgerPostingGroup::query()->pluck('id')->all();
    $results = Concurrency::driver('process')->run([
        mysqlSharedFeeApprovalTask($admin->id, $reversal->id, $decision),
        mysqlSharedFeeApprovalTask($otherAdmin->id, $reversal->id, [...$decision, 'attempt_reference' => (string) Str::uuid()]),
    ]);
    sort($results);
    expect($results)->toBe(['blocked', 'posted']);
    expect($reversal->fresh()->state)->toBe('approved_no_money');
    expect($reversal->fresh()->compensation_posting_group_id)->toBeNull();
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect((int) $fee->entries()->where('entry_type', FeeObligationEntryType::SettlementReversal)->sum('amount_kobo'))->toBe(50000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groupIds);
    $this->assertDatabaseCount('financial_workflow_supplements', 1);
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('reversal_attempts', 2);
    expect($refund->fresh()->compensation_posting_group_id)->toBeNull();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe(50000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(50000);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('mysql concurrent fixed fee payout acknowledgement leaves the next request without another cycle fee', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture(true, true);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified first fixed-fee payout.', 'confirmed' => true])->assertRedirect();
    $outcomes = Concurrency::driver('process')->run([
        financialMysqlAcknowledgement($customer->user_id, $execution->id),
        financialMysqlAcknowledgement($customer->user_id, $execution->id),
    ]);
    expect($outcomes)->toBe(['acknowledged', 'acknowledged'])
        ->and(DB::table('fee_obligations')->count())->toBe(1)
        ->and(DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->count())->toBe(1)
        ->and(DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->count())->toBe(1);
    $agent = $customer->currentAssignment->agentProfile->user;
    $payload = withdrawalPayload($customer, $customer->currentAssignment, $plan->fresh());
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $payload);
    expect($quote['fee_kobo'])->toBe(0)->and($quote['net_kobo'])->toBe(30000);
    $history = app(PlanFeeHistoryReadService::class)->read($agent, $plan->fresh());
    expect($history['status'])->toBe('available')
        ->and($history['totals'])->toBe(['original_assessed' => '₦6.00', 'assessed' => '₦6.00', 'settled' => '₦6.00', 'waived' => '₦0.00', 'outstanding' => '₦0.00'])
        ->and($history['history']['total'])->toBe(2);
    $summary = app(PlanFeeHistoryReadService::class)->readMany($agent, [$plan->fresh()])[$plan->plan_id];
    expect($summary['status'])->toBe('available')->and($summary['totals'])->toEqual($history['totals'])
        ->and($summary['source_version'])->toBe($history['source_version']);
    $second = submittedWithdrawal($agent, $customer, $customer->currentAssignment, $plan->fresh());
    expect($second->fee_amount_kobo)->toBe(0)->and($second->gross_amount_kobo)->toBe(30000);
    $balanceReader = app(LedgerTransactionReadService::class);
    $balance = $balanceReader->balance($customer->user, $customer);
    expect($balance['status'])->toBe('ready')
        ->and($balanceReader->balances($customer->user, [$customer])[$customer->id])->toEqual($balance)
        ->and($balance['reservations_kobo'])->toBe(30000);
    $savingsReader = app(PlanSavingsReadService::class);
    $savings = $savingsReader->readMany($agent, [$plan->fresh()])[$plan->plan_id];
    $detailSavings = $savingsReader->read($agent, $plan->fresh());
    expect($savings['cycle']['available'])->not->toBeNull()
        ->and($savings['cycle'])->toEqual($detailSavings['cycle'])
        ->and($savings['customer'])->toEqual($detailSavings['customer'])
        ->and($savings['source_version'])->toBe($detailSavings['source_version'])
        ->and($savings['as_of'])->not->toBeNull();
    $activityReader = app(PlanFinancialActivityReadService::class);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $activity = $activityReader->readMany($agent, [$plan->fresh()])[$plan->plan_id];
    $detailActivity = $activityReader->read($agent, $plan->fresh());
    expect($detailActivity['status'])->toBe('available')->and($activity['status'])->toBe('available')->and($activity['metrics'])->toEqual($detailActivity['metrics'])
        ->and($activity['source_version'])->toBe($detailActivity['source_version']);
    $postingHistory = $activityReader->history($agent, $plan->fresh());
    expect($postingHistory['status'])->toBe('available')
        ->and($postingHistory['history']['data'])->toHaveCount(3)
        ->and(array_column($postingHistory['history']['data'], 'component'))->toContain('gross_withdrawals', 'net_cash_payouts', 'withdrawal_fees')
        ->and($postingHistory['source_version'])->toBe($activity['source_version']);
});

/** @param array<string, mixed> $data */
function financialMysqlReservation(int $agentId, int $customerId, array $data): Closure
{
    return static function () use ($agentId, $customerId, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe reservation race database.');
        }
        config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
        Queue::fake();
        try {
            app(WithdrawalService::class)->submit(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $data);

            return 'posted';
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        } catch (Throwable $exception) {
            throw new RuntimeException($exception::class.': '.$exception->getMessage(), previous: $exception);
        }
    };
}

test('mysql reservation deduction and original receipt correction share one nonnegative savings boundary', function (array $owners): void {
    Queue::fake();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true,
        'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $collection = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $collection)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $collection);
    $originalReceipt = $receipt->getAttributes();
    $allocations = $receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::DeductionsManage, AdminPermission::ReversalsReview]);
    $category = app(ManualChargeService::class)->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'mixed-owner-race', 'kind' => 'deduction', 'purpose' => 'Authorized competing savings owner fixture',
        'customer_description' => 'Approved deduction', 'amount_kobo' => 150000], financialMysqlRequest());
    $reversals = app(ReversalService::class);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $quote = $reversals->preview($agent, $original);
    $reversal = $reversals->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'The original actual tender remains fully controlled.', 'customer_explanation' => 'Original receipt requires review.',
        'evidence_text' => 'Original cash and allocation verified.', 'confirmed' => true]);
    $review = $reversals->reviewPreview($admin, $reversal);
    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Reviewed full original receipt correction.', 'confirmed' => true];
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '1500.00',
        'destination_reference' => 'customer:'.$customer->id];
    $withdrawalQuote = app(WithdrawalService::class)->preview($agent, $customer, $instruction);
    $instruction = [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $withdrawalQuote['preview_fingerprint'], 'quote_expires_at' => $withdrawalQuote['quote_expires_at'],
        'customer_version' => $withdrawalQuote['customer_version'], 'assignment_version' => $withdrawalQuote['assignment_version'],
        'plan_version' => $withdrawalQuote['plan_version'], 'business_version' => $withdrawalQuote['business_version'],
        'instruction_attested' => true, 'confirmed' => true];
    $tasks = [
        'reservation' => financialMysqlReservation($agent->id, $customer->id, $instruction),
        'deduction' => financialMysqlDeduction($admin->id, $customer->id, $plan->id, $category->id, (string) Str::uuid()),
        'correction' => mysqlSharedFeeApprovalTask($admin->id, $reversal->id, $decision),
    ];
    $results = Concurrency::driver('process')->run(array_map(fn (string $owner): Closure => $tasks[$owner], $owners));
    expect(array_count_values($results))->toEqual(['posted' => 1, 'blocked' => count($owners) - 1]);
    $position = app(CollectionReadService::class)->position($customer->fresh());
    $reserved = (int) DB::table('withdrawal_reservations')->where('status', 'live')->sum('gross_amount_kobo');
    $deducted = (int) DB::table('manual_charges')->sum('amount_kobo');
    $corrected = $reversal->fresh()->state === 'approved_posted';
    expect($position['liability_kobo'])->toBe($corrected ? 0 : 200000 - $deducted)
        ->and($position['reservations_kobo'])->toBe($reserved)
        ->and($position['available_kobo'])->toBe($position['liability_kobo'] - $reserved)
        ->and($position['available_kobo'])->toBeGreaterThanOrEqual(0)
        ->and(($reserved > 0 ? 1 : 0) + ($deducted > 0 ? 1 : 0) + ($corrected ? 1 : 0))->toBe(1)
        ->and(DB::table('withdrawal_requests')->count())->toBe($reserved > 0 ? 1 : 0)
        ->and(DB::table('manual_charges')->count())->toBe($deducted > 0 ? 1 : 0)
        ->and(LedgerPostingGroup::query()->where('event_type', 'receipt_reclassification')->count())->toBe($corrected ? 1 : 0)
        ->and(DB::table('collection_allocation_releases')->count())->toBe($corrected ? count($allocations) : 0)
        ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
        ->and($receipt->allocations()->orderBy('id')->get()->map->getAttributes()->all())->toBe($allocations);
})->with([
    'reservation versus deduction' => [['reservation', 'deduction']],
    'reservation versus correction' => [['reservation', 'correction']],
    'deduction versus correction' => [['deduction', 'correction']],
    'all three competing owners' => [['reservation', 'deduction', 'correction']],
]);

/** @param array<string, mixed> $payload */
function financialMysqlFeeReceipt(int $agentId, int $customerId, array $payload): Closure
{
    return static function () use ($agentId, $customerId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe fee settlement race database.');
        }
        config()->set('collections.enabled', true);
        try {
            app(CollectionService::class)->record(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $payload);

            return 'settled';
        } catch (ValidationException $exception) {
            if ($exception->errors() !== ['fees' => ['A fee component exceeds its current outstanding amount.']]) {
                throw $exception;
            }

            return 'blocked';
        }
    };
}

function financialMysqlFeeWaiver(int $adminId, int $obligationId, int $amount, string $reference): Closure
{
    return static function () use ($adminId, $obligationId, $amount, $reference): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe fee waiver race database.');
        }
        $request = Request::create('/admin/fees/waive', 'POST');
        $session = new Store('fee-waiver-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(FeeObligationService::class)->waive(User::findOrFail($adminId), $obligationId, $amount,
                'Independent relief of unpaid race fee.', 'Your unpaid fee was waived.', $reference, $request);

            return 'waived';
        } catch (ValidationException $exception) {
            if ($exception->errors() !== ['amount_ngn' => ['The amount exceeds the current unpaid fee balance.']]) {
                throw $exception;
            }

            return 'blocked';
        }
    };
}

/** @return array<string, array<int, array<string, mixed>>> */
function financialMysqlFeeRows(): array
{
    $rows = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'collection_receipts', 'collection_fee_components', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('FEE-AC-017 mysql competing fee receipts and waivers cannot exceed the unpaid balance', function (bool $waiver, int $amount): void {
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $fee = reportFeeObligation($agent, $customer);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $terms = $fee->feeSnapshot->getAttributes();
    $slots = DB::table('contribution_slots')->orderBy('id')->get()->all();
    $receipt = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => $amount === 50000 ? '500.00' : '300.00']]];
    $receipt['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $receipt)['preview_fingerprint'];
    $secondReceipt = [...$receipt, 'attempt_reference' => (string) Str::uuid()];
    $waiverReference = (string) Str::uuid();
    $outcomes = Concurrency::driver('process')->run([
        financialMysqlFeeReceipt($agent->id, $customer->id, $receipt),
        $waiver ? financialMysqlFeeWaiver($admin->id, $fee->id, $amount, $waiverReference)
            : financialMysqlFeeReceipt($agent->id, $customer->id, $secondReceipt),
    ]);
    expect(collect($outcomes)->filter(fn (string $outcome): bool => $outcome === 'blocked'))->toHaveCount(1);
    $fee->refresh();
    $settled = $fee->settledAmountKobo();
    $waived = $fee->waivedAmountKobo();
    expect($settled + $waived)->toBe($amount)
        ->and($fee->outstandingAmountKobo())->toBe(50000 - $amount)
        ->and($fee->assessedAmountKobo())->toBe(50000)
        ->and($fee->feeSnapshot->getAttributes())->toBe($terms);
    expect(DB::table('fee_obligation_entries')->count())->toBe(2)
        ->and(DB::table('collection_receipts')->count())->toBe($settled > 0 ? 1 : 0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('contribution_slots')->orderBy('id')->get()->all())->toEqual($slots)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    foreach ([[LedgerAccountCode::FeeIncome, 'credit'], [LedgerAccountCode::AgentReceivable, 'debit']] as [$code, $side]) {
        expect((int) DB::table('ledger_entries')->where('ledger_account_id', LedgerAccount::query()->where('code', $code)->sole()->id)
            ->where('side', $side)->sum('amount_kobo'))->toBe($settled);
    }
    $baseline = financialMysqlFeeRows();
    foreach ($outcomes as $index => $outcome) {
        if ($outcome === 'settled') {
            expect((financialMysqlFeeReceipt($agent->id, $customer->id, $index === 0 ? $receipt : $secondReceipt))())->toBe('settled');
        }
        if ($outcome === 'waived') {
            expect((financialMysqlFeeWaiver($admin->id, $fee->id, $amount, $waiverReference))())->toBe('waived');
        }
    }
    expect(financialMysqlFeeRows())->toBe($baseline);
})->with(['two full payments' => [false, 50000], 'two partial payments' => [false, 30000],
    'full payment versus waiver' => [true, 50000], 'partial payment versus waiver' => [true, 30000]]);

test('FEE-AC-017 mysql stale transaction cannot hide a committed receipt or waiver', function (bool $waiverFirst): void {
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $fee = reportFeeObligation($agent, $customer);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $receipt = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $receipt['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $receipt)['preview_fingerprint'];
    $pay = financialMysqlFeeReceipt($agent->id, $customer->id, $receipt);
    $waive = financialMysqlFeeWaiver($admin->id, $fee->id, 50000, (string) Str::uuid());
    DB::beginTransaction();
    try {
        expect($fee->outstandingAmountKobo())->toBe(50000);
        expect(Concurrency::driver('process')->run([$waiverFirst ? $waive : $pay]))->toBe([$waiverFirst ? 'waived' : 'settled']);
        expect(($waiverFirst ? $pay : $waive)())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    $fee->refresh();
    expect($fee->outstandingAmountKobo())->toBe(0)
        ->and($fee->settledAmountKobo())->toBe($waiverFirst ? 0 : 50000)
        ->and($fee->waivedAmountKobo())->toBe($waiverFirst ? 50000 : 0)
        ->and(DB::table('fee_obligation_entries')->count())->toBe(2)
        ->and(DB::table('collection_receipts')->count())->toBe($waiverFirst ? 0 : 1)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
})->with(['committed receipt' => false, 'committed waiver' => true]);

function financialMysqlApplicationNotice(int $intentId, string $channel): Closure
{
    return static function () use ($intentId, $channel): int {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe fee application notice worker database.');
        }
        Queue::fake();
        if ($channel === 'mail') {
            config()->set('mail.default', 'array');
            $job = new DeliverFeeApplicationNotificationIntent($intentId);
            app(PlatformJobMiddleware::class)->handle($job, fn (DeliverFeeApplicationNotificationIntent $queued) => $queued->handle());

            return count(app('mail.manager')->mailer()->getSymfonyTransport()->messages());
        }
        app(NotificationPipeline::class)->materialize($intentId);

        return 0;
    };
}

test('mysql competing application notice workers retain one delivery outcome and unchanged financial owners', function (string $channel): void {
    Queue::fake();
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE independently reviewed application.', 'customer_description' => 'Registration paid from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $group = $owner->apply($admin, $fee->id, $payload, financialMysqlRequest());
    $notice = DB::table('fee_application_notification_intents')->where('recipient_user_id', $customer->user_id)->where('channel', $channel)->sole();
    $intentId = $channel === 'mail' ? $notice->id : DB::table('notification_inbox_intents')->where('notification_id', $notice->notification_id)->value('id');
    $financial = financialMysqlFeeRows();
    $source = DB::table('fee_savings_applications')->sole();

    $sent = Concurrency::driver('process')->run([
        financialMysqlApplicationNotice($intentId, $channel),
        financialMysqlApplicationNotice($intentId, $channel),
    ]);

    expect(array_sum($sent))->toBe($channel === 'mail' ? 1 : 0);
    expect(DB::table('fee_application_notification_intents')->where('id', $notice->id)->value('status'))->toBe('delivered');
    $this->assertDatabaseCount('fee_savings_applications', 1);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    $this->assertDatabaseHas('audit_events', ['event_type' => 'fee_application.'.($channel === 'mail' ? 'delivery_state_recorded' : 'delivery_attempt'),
        'target_id' => $group->id, 'target_reference' => $group->posting_reference]);
    expect(DB::table('canonical_audit_events')->where('event_type', 'fee_application.'.($channel === 'mail' ? 'delivery_state_recorded' : 'delivery_attempt'))->count())->toBe(1);
    if ($channel === 'mail') {
        $this->assertDatabaseCount('management_delivery_attempts', 1);
        expect(DB::table('management_delivery_attempts')->value('outcome'))->toBe('transport_accepted');
    } else {
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_inbox_attempts', 1);
    }
    expect(financialMysqlFeeRows())->toBe($financial);
    expect(DB::table('fee_savings_applications')->sole())->toEqual($source);
    expect($owner->apply($admin, $fee->id, $payload, financialMysqlRequest())->id)->toBe($group->id);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
})->with(['mail', 'database']);

/** @param array<string, mixed> $payload */
function financialMysqlReviewedManualCharge(int $adminId, int $customerId, int $planId, int $categoryId, array $payload): Closure
{
    return static function () use ($adminId, $customerId, $planId, $categoryId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe reviewed manual charge worker database.');
        }
        config()->set(['fees.manual_charges_enabled' => true, 'fees.savings_applications_enabled' => true]);
        Queue::fake([DeliverFeeApplicationNotificationIntent::class]);
        $request = Request::create('/admin/charges', 'POST');
        $session = new Store('combined-charge-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            return app(ManualChargeService::class)->assess(User::findOrFail($adminId), CustomerProfile::findOrFail($customerId),
                ThriftPlan::findOrFail($planId), ChargeCategoryVersion::findOrFail($categoryId), $payload['operation_reference'],
                $payload['customer_version'], $payload['plan_version'], $payload['reason'], $request,
                ['mode' => $payload['mode'], 'preview_fingerprint' => $payload['preview_fingerprint'], 'quote_expires_at' => $payload['quote_expires_at']])->operation_reference;
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql reviewed combined charge workers commit one assessment payment and notice set for duplicate or competing attempts', function (bool $duplicate): void {
    config()->set(['fees.manual_charges_enabled' => true, 'fees.savings_applications_enabled' => true]);
    [, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(ManualChargeService::class);
    $category = $owner->publish($admin, ['publication_reference' => (string) Str::uuid(), 'category_key' => 'approved-combined-charge',
        'kind' => 'manual_fee', 'purpose' => 'Approved separate service', 'customer_description' => 'Agreed service fee', 'amount_kobo' => 80000], financialMysqlRequest());
    $inputs = ['mode' => 'assess_and_apply', 'customer_version' => $customer->version, 'plan_version' => $plan->version, 'reason' => 'PRIVATE reviewed combined charge.'];
    $quote = $owner->preview($admin, $customer, $plan, $category, $inputs, financialMysqlRequest());
    $first = [...$inputs, 'operation_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $second = [...$first, 'operation_reference' => $duplicate ? $first['operation_reference'] : (string) Str::uuid()];

    $outcomes = Concurrency::driver('process')->run([
        financialMysqlReviewedManualCharge($admin->id, $customer->id, $plan->id, $category->id, $first),
        financialMysqlReviewedManualCharge($admin->id, $customer->id, $plan->id, $category->id, $second),
    ]);

    if ($duplicate) {
        expect($outcomes)->toBe([$first['operation_reference'], $first['operation_reference']]);
    } else {
        expect(collect($outcomes)->filter(fn (string $outcome): bool => $outcome === 'blocked')->count())->toBe(1);
    }
    $this->assertDatabaseCount('manual_charges', 1);
    $this->assertDatabaseCount('fee_obligations', 1);
    $this->assertDatabaseCount('fee_obligation_entries', 2);
    $this->assertDatabaseCount('fee_savings_applications', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $this->assertDatabaseCount('manual_charge_notification_intents', 2);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(20000);
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(0);
})->with(['duplicate original attempt' => true, 'competing distinct attempts' => false]);

/** @param array<string, mixed> $payload */
function mysqlFeePaymentApproval(int $reviewerId, int $reversalId, array $payload): Closure
{
    return static function () use ($reviewerId, $reversalId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe fee payment correction worker database.');
        }
        config()->set(['fees.savings_application_corrections_enabled' => true]);
        Queue::fake();
        $request = Request::create('/reversals/approve', 'POST');
        $session = new Store('fee-reversal-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(ReversalService::class)->decide(User::findOrFail($reviewerId), ReversalRequest::findOrFail($reversalId), 'approve', $payload, $request);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql duplicate or competing fee payment reviews retain one compensation and restored debt', function (bool $duplicate): void {
    Queue::fake();
    config()->set(['fees.savings_applications_enabled' => true, 'fees.savings_application_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 10001);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed payment.', 'customer_description' => 'Registration paid from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $original = $owner->apply($admin, $fee->id, [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']], financialMysqlRequest());
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'incorrect_fee_deduction',
        'internal_reason' => 'Erroneous savings payment.', 'customer_explanation' => 'Savings restored; valid fee unpaid.',
        'evidence_text' => 'Original payment and undrawn income verified.', 'confirmed' => true]);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($reviewer, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Verified erroneous payment.', 'confirmed' => true];
    $secondReviewer = $duplicate ? $reviewer : User::factory()->admin()->withTwoFactor()->create();
    $secondReviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $second = [...$payload, 'attempt_reference' => $duplicate ? $payload['attempt_reference'] : (string) Str::uuid()];
    if (! $duplicate) {
        $second['preview_fingerprint'] = $service->reviewPreview($secondReviewer, $reversal)['preview_fingerprint'];
    }

    $outcomes = Concurrency::driver('process')->run([
        mysqlFeePaymentApproval($reviewer->id, $reversal->id, $payload),
        mysqlFeePaymentApproval($secondReviewer->id, $reversal->id, $second),
    ]);

    expect(collect($outcomes)->filter(fn (string $result): bool => $result === 'posted')->count())->toBe($duplicate ? 2 : 1);
    expect(collect($outcomes)->filter(fn (string $result): bool => $result === 'blocked')->count())->toBe($duplicate ? 0 : 1);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(10001);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(100000);
    $this->assertDatabaseCount('ledger_posting_groups', 3);
    $this->assertDatabaseCount('fee_obligation_entries', 3);
    $this->assertDatabaseCount('reversal_events', 2);
    expect(app(FeeSavingsApplicationReversalOwner::class)->assertPosted($reversal->id)->event_type)->toBe('fee_application_compensation');
})->with(['same review attempt' => true, 'independent competing reviewers' => false]);

function financialMysqlFeeDrawFixture(): array
{
    Queue::fake();
    config()->set(['fees.savings_applications_enabled' => true, 'fees.savings_application_corrections_enabled' => true,
        'fees.cash_disbursements_enabled' => true]);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 10001);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::CashExecute]);
    $batch = CollectionBatch::query()->sole();
    DB::transaction(function () use ($admin, $batch): void {
        $remittance = CashRemittance::create(['collection_batch_id' => $batch->id, 'agent_profile_id' => $batch->agent_profile_id,
            'confirmed_by_user_id' => $admin->id, 'handoff_reference' => 'FEE-DRAW-CONTROLLED-CASH', 'amount_kobo' => 100000,
            'handoff_date' => now('Africa/Lagos')->toDateString(), 'receiving_location' => 'Business office',
            'source_attestation' => 'Counted full original Agent tender into controlled business cash.']);
        $group = app(CollectionLedgerService::class)->postCashRemittance($remittance->id, $batch->agent_profile_id, 100000, $admin);
        $remittance->update(['ledger_posting_group_id' => $group->id]);
    });
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed payment.', 'customer_description' => 'Registration paid from savings.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $original = $owner->apply($admin, $fee->id, [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']], financialMysqlRequest());
    app(LedgerTransactionProjectionService::class)->rebuild();
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'incorrect_fee_deduction',
        'internal_reason' => 'Erroneous savings payment.', 'customer_explanation' => 'Savings restored; valid fee unpaid.',
        'evidence_text' => 'Original payment and undrawn income verified.', 'confirmed' => true]);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($reviewer, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Verified erroneous payment.', 'confirmed' => true];

    return [$customer, $plan, $fee, $admin, $reviewer, $reversal, $payload];
}

function financialMysqlEarningsDraw(int $adminId, string $reference, int $amountKobo = 1): Closure
{
    return static function () use ($adminId, $reference, $amountKobo): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe earnings draw worker database.');
        }
        config()->set('fees.cash_disbursements_enabled', true);
        Queue::fake();
        $request = Request::create('/earnings-draws', 'POST');
        $session = new Store('earnings-draw-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(CashDisbursementService::class)->start(User::findOrFail($adminId), $reference, null, $amountKobo,
                $amountKobo === 1 ? 'One kobo evidenced business draw from cash-backed earnings.' : 'Evidenced business draw of '.$amountKobo.' kobo from cash-backed earnings.', $request);

            return 'held';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql stale fee draw or compensation snapshots cannot spend independently committed earnings twice', function (bool $compensationFirst): void {
    [$customer, $plan, $fee, $admin, $reviewer, $reversal, $payload] = financialMysqlFeeDrawFixture();
    $draw = financialMysqlEarningsDraw($admin->id, (string) Str::uuid());
    $approve = mysqlFeePaymentApproval($reviewer->id, $reversal->id, $payload);
    DB::beginTransaction();
    try {
        expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(10001);
        expect(Concurrency::driver('process')->run([$compensationFirst ? $approve : $draw]))->toBe([$compensationFirst ? 'posted' : 'held']);
        expect(($compensationFirst ? $draw : $approve)())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    expect($fee->fresh()->settledAmountKobo())->toBe($compensationFirst ? 0 : 10001);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($compensationFirst ? 100000 : 89999);
    expect(app(FinancialCashPosition::class)->read()['undrawn_earnings_kobo'])->toBe($compensationFirst ? 0 : 10000);
    $this->assertDatabaseCount('cash_disbursements', $compensationFirst ? 0 : 1);
    $this->assertDatabaseCount('ledger_posting_groups', $compensationFirst ? 4 : 3);
})->with(['committed compensation first' => true, 'committed draw first' => false]);

test('mysql concurrent fee compensation and earnings draw workers retain one affordable owner and exact replay', function (): void {
    [$customer, , $fee, $admin, $reviewer, $reversal, $payload] = financialMysqlFeeDrawFixture();
    $reference = (string) Str::uuid();
    $draw = financialMysqlEarningsDraw($admin->id, $reference);
    $approve = mysqlFeePaymentApproval($reviewer->id, $reversal->id, $payload);

    $outcomes = Concurrency::driver('process')->run([$approve, $draw]);

    expect($outcomes)->toBeIn([['posted', 'blocked'], ['blocked', 'held']]);
    $compensated = $outcomes[0] === 'posted';
    expect($fee->fresh()->settledAmountKobo())->toBe($compensated ? 0 : 10001);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($compensated ? 100000 : 89999);
    expect(app(FinancialCashPosition::class)->read()['undrawn_earnings_kobo'])->toBe($compensated ? 0 : 10000);
    $this->assertDatabaseCount('cash_disbursements', $compensated ? 0 : 1);
    $this->assertDatabaseCount('ledger_posting_groups', $compensated ? 4 : 3);
    $before = financialMysqlFeeRows();
    expect(($compensated ? $approve : $draw)())->toBe($compensated ? 'posted' : 'held');
    expect(($compensated ? $draw : $approve)())->toBe('blocked');
    expect(financialMysqlFeeRows())->toEqual($before);
});

test('current financial cash reads require their owning transaction before reading financial accounts', function (): void {
    $owner = app(FinancialCashPosition::class);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(fn () => $owner->read(forUpdate: true))->toThrow(RuntimeException::class);
    expect(fn () => $owner->balance(LedgerAccountCode::FeeIncome, true))->toThrow(RuntimeException::class);
    expect(fn () => $owner->reservedCashKobo(forUpdate: true))->toThrow(RuntimeException::class);
    expect(fn () => $owner->undrawnEarningsKobo(forUpdate: true))->toThrow(RuntimeException::class);
    expect(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
    DB::flushQueryLog();
});

function financialMysqlReceiptDrawFixture(): array
{
    Queue::fake();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true,
        'fees.cash_disbursements_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 10001);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.01']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::CashExecute]);
    DB::transaction(function () use ($admin, $receipt): void {
        $remittance = CashRemittance::create(['collection_batch_id' => $receipt->collection_batch_id,
            'agent_profile_id' => $receipt->recording_agent_profile_id, 'confirmed_by_user_id' => $admin->id,
            'handoff_reference' => 'RECEIPT-DRAW-CONTROLLED-CASH', 'amount_kobo' => 10001,
            'handoff_date' => now('Africa/Lagos')->toDateString(), 'receiving_location' => 'Business office',
            'source_attestation' => 'Counted full original external fee tender into business cash.']);
        $group = app(CollectionLedgerService::class)->postCashRemittance($remittance->id, $receipt->recording_agent_profile_id, 10001, $admin);
        $remittance->update(['ledger_posting_group_id' => $group->id]);
    });
    app(LedgerTransactionProjectionService::class)->rebuild();
    $original = LedgerPostingGroup::query()->where('source_type', 'collection_receipt')->where('source_id', $receipt->id.'-'.$fee->id)->sole();
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'Original fee tender remains controlled by the business.',
        'customer_explanation' => 'Correcting receipt allocation while valid fee remains unpaid.',
        'evidence_text' => 'Original external receipt and counted remittance reviewed.', 'confirmed' => true]);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($reviewer, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Full controlled receipt and fee correction verified.', 'confirmed' => true];

    return [$customer, $fee, $admin, $reviewer, $reversal, $payload, $receipt];
}

/** @param array<string, mixed> $payload */
function financialMysqlReceiptApproval(int $reviewerId, int $reversalId, array $payload): Closure
{
    return static function () use ($reviewerId, $reversalId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe receipt earnings correction worker database.');
        }
        config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
        Queue::fake();
        $request = Request::create('/reversals/approve', 'POST');
        $session = new Store('receipt-reversal-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(ReversalService::class)->decide(User::findOrFail($reviewerId), ReversalRequest::findOrFail($reversalId), 'approve', $payload, $request);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql stale receipt correction or draw snapshots preserve independently committed earnings ownership', function (bool $compensationFirst): void {
    [$customer, $fee, $admin, $reviewer, $reversal, $payload, $receipt] = financialMysqlReceiptDrawFixture();
    $draw = financialMysqlEarningsDraw($admin->id, (string) Str::uuid());
    $approve = financialMysqlReceiptApproval($reviewer->id, $reversal->id, $payload);
    $originalReceipt = $receipt->getAttributes();
    DB::beginTransaction();
    try {
        expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(10001);
        expect(Concurrency::driver('process')->run([$compensationFirst ? $approve : $draw]))->toBe([$compensationFirst ? 'posted' : 'held']);
        expect(($compensationFirst ? $draw : $approve)())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    expect($fee->fresh()->settledAmountKobo())->toBe($compensationFirst ? 0 : 10001);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->read()['undrawn_earnings_kobo'])->toBe($compensationFirst ? 0 : 10000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe($compensationFirst ? 10001 : 0);
    expect($receipt->fresh()->getAttributes())->toBe($originalReceipt);
    $this->assertDatabaseCount('cash_disbursements', $compensationFirst ? 0 : 1);
    $this->assertDatabaseCount('ledger_posting_groups', $compensationFirst ? 3 : 2);
})->with(['committed receipt correction first' => true, 'committed draw first' => false]);

test('mysql concurrent receipt compensation and draw retain one earnings owner and original custody', function (): void {
    [$customer, $fee, $admin, $reviewer, $reversal, $payload, $receipt] = financialMysqlReceiptDrawFixture();
    $draw = financialMysqlEarningsDraw($admin->id, (string) Str::uuid());
    $approve = financialMysqlReceiptApproval($reviewer->id, $reversal->id, $payload);
    $originalReceipt = $receipt->getAttributes();

    $outcomes = Concurrency::driver('process')->run([$approve, $draw]);

    expect($outcomes)->toBeIn([['posted', 'blocked'], ['blocked', 'held']]);
    $compensated = $outcomes[0] === 'posted';
    expect($fee->fresh()->settledAmountKobo())->toBe($compensated ? 0 : 10001);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->undrawnEarningsKobo())->toBe($compensated ? 0 : 10000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe($compensated ? 10001 : 0);
    expect($receipt->fresh()->getAttributes())->toBe($originalReceipt);
    $this->assertDatabaseCount('cash_disbursements', $compensated ? 0 : 1);
    $this->assertDatabaseCount('ledger_posting_groups', $compensated ? 3 : 2);
    $before = financialMysqlFeeRows();
    expect(($compensated ? $approve : $draw)())->toBe($compensated ? 'posted' : 'held');
    expect(($compensated ? $draw : $approve)())->toBe('blocked');
    expect(financialMysqlFeeRows())->toEqual($before);
});

function financialMysqlReturnedPayoutFixture(): array
{
    Queue::fake();
    config()->set(['withdrawals.cash_compensation_enabled' => true, 'fees.cash_disbursements_enabled' => true]);
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payments = app(CashExecutionService::class);
    $execution = $payments->start($admin, $withdrawal, (string) Str::uuid(), $withdrawal->version,
        'Verified Customer payout backed by counted remittance.', financialMysqlRequest());
    $payments->recordHandoff($admin, $execution, 'Customer received the original full net cash.', financialMysqlRequest());
    $payments->confirmReceipt($customer->user, $execution->fresh());
    $original = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
    $returns = app(CashRecoveryService::class);
    $request = financialMysqlRequest();
    $request->merge(['preview_fingerprint' => $returns->preview($admin, $execution->fresh())['preview_fingerprint']]);
    $recovery = $returns->recordReturn($admin, $execution->fresh(), (string) Str::uuid(),
        'Full original Customer cash counted back into the controlled till.', $request);
    $recovery = $returns->acknowledgeReturn($customer->user, $recovery);
    $agent = $customer->currentAssignment->agentProfile->user;
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'reason_category' => 'incorrect_payout_record',
        'internal_reason' => 'Original erroneous payout and complete returned Customer cash verified.',
        'customer_explanation' => 'Restoring savings after the original full cash return.',
        'evidence_text' => 'Original payout and Customer-confirmed cash return retained.', 'confirmed' => true]);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $quote = $service->reviewPreview($reviewer, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'decision_reason' => 'Full return and retained fee earnings verified.', 'confirmed' => true];

    return [$customer, $plan, FeeObligation::query()->sole(), $admin, $reviewer, $reversal, $payload, $original, $recovery];
}

/** @param array<string, mixed> $payload */
function financialMysqlPayoutApproval(int $reviewerId, int $reversalId, array $payload): Closure
{
    return static function () use ($reviewerId, $reversalId, $payload): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe returned-payout earnings correction worker database.');
        }
        config()->set('withdrawals.cash_compensation_enabled', true);
        Queue::fake();
        $request = Request::create('/reversals/approve', 'POST');
        $session = new Store('payout-reversal-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(ReversalService::class)->decide(User::findOrFail($reviewerId), ReversalRequest::findOrFail($reversalId), 'approve', $payload, $request);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql stale returned payout compensation or draw snapshots preserve independently committed earnings', function (bool $compensationFirst): void {
    [$customer, $plan, $fee, $admin, $reviewer, $reversal, $payload, $original, $recovery] = financialMysqlReturnedPayoutFixture();
    $draw = financialMysqlEarningsDraw($admin->id, (string) Str::uuid());
    $approve = financialMysqlPayoutApproval($reviewer->id, $reversal->id, $payload);
    $originalEntries = $original->entries()->orderBy('id')->get()->map->getAttributes()->all();
    DB::beginTransaction();
    try {
        expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(600);
        expect(Concurrency::driver('process')->run([$compensationFirst ? $approve : $draw]))->toBe([$compensationFirst ? 'posted' : 'held']);
        expect(($compensationFirst ? $draw : $approve)())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    expect($fee->fresh()->settledAmountKobo())->toBe($compensationFirst ? 0 : 600);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe($compensationFirst ? 100000 : 70000);
    expect(app(FinancialCashPosition::class)->undrawnEarningsKobo())->toBe($compensationFirst ? 0 : 599);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe($compensationFirst ? 0 : 29400);
    expect($recovery->fresh()->status)->toBe($compensationFirst ? 'consumed' : 'confirmed');
    expect($original->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalEntries);
    $this->assertDatabaseCount('cash_disbursements', $compensationFirst ? 0 : 1);
    $this->assertDatabaseCount('ledger_posting_groups', $compensationFirst ? 5 : 4);
})->with(['committed returned payout compensation first' => true, 'committed draw first' => false]);

test('mysql concurrent returned payout compensation and draw retain one affordable owner and exact return', function (): void {
    [$customer, $plan, $fee, $admin, $reviewer, $reversal, $payload, $original, $recovery] = financialMysqlReturnedPayoutFixture();
    $draw = financialMysqlEarningsDraw($admin->id, (string) Str::uuid());
    $approve = financialMysqlPayoutApproval($reviewer->id, $reversal->id, $payload);
    $originalEntries = $original->entries()->orderBy('id')->get()->map->getAttributes()->all();

    $outcomes = Concurrency::driver('process')->run([$approve, $draw]);

    expect($outcomes)->toBeIn([['posted', 'blocked'], ['blocked', 'held']]);
    $compensated = $outcomes[0] === 'posted';
    expect($fee->fresh()->settledAmountKobo())->toBe($compensated ? 0 : 600);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe($compensated ? 100000 : 70000);
    expect(app(FinancialCashPosition::class)->undrawnEarningsKobo())->toBe($compensated ? 0 : 599);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe($compensated ? 0 : 29400);
    expect($recovery->fresh()->status)->toBe($compensated ? 'consumed' : 'confirmed');
    expect($original->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalEntries);
    $this->assertDatabaseCount('cash_disbursements', $compensated ? 0 : 1);
    $this->assertDatabaseCount('ledger_posting_groups', $compensated ? 5 : 4);
    $before = financialMysqlFeeRows();
    expect(($compensated ? $approve : $draw)())->toBe($compensated ? 'posted' : 'held');
    expect(($compensated ? $draw : $approve)())->toBe('blocked');
    expect(financialMysqlFeeRows())->toEqual($before);
});

function financialMysqlIndependentFeeBacking(): array
{
    [$customer, $fee, $admin, , , , $receipt] = financialMysqlReceiptDrawFixture();
    config()->set('fees.refunds_enabled', true);
    $agent = $customer->currentAssignment->agentProfile->user;
    $other = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create(['customer_profile_id' => $other->id,
        'agent_profile_id' => $receipt->recording_agent_profile_id, 'assigned_by_user_id' => $agent->id]);
    $otherFee = reportFeeObligation($agent, $other, 10001, 2);
    $plan = ThriftPlan::query()->sole();
    $data = [...collectionPayload($other, $assignment, $plan, now('Africa/Lagos')->toDateString(), '0'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $otherFee->id, 'amount_ngn' => '100.01']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $other, $data)['preview_fingerprint'];
    $secondReceipt = app(CollectionService::class)->record($agent, $other, $data);
    DB::transaction(function () use ($admin, $secondReceipt): void {
        $remittance = CashRemittance::create(['collection_batch_id' => $secondReceipt->collection_batch_id,
            'agent_profile_id' => $secondReceipt->recording_agent_profile_id, 'confirmed_by_user_id' => $admin->id,
            'handoff_reference' => 'INDEPENDENT-REFUND-BACKING', 'amount_kobo' => 10001,
            'handoff_date' => now('Africa/Lagos')->toDateString(), 'receiving_location' => 'Business office',
            'source_attestation' => 'Counted separately paid fee cash; it never increases another fee refund capacity.']);
        $group = app(CollectionLedgerService::class)->postCashRemittance($remittance->id, $secondReceipt->recording_agent_profile_id, 10001, $admin);
        $remittance->update(['ledger_posting_group_id' => $group->id]);
    });

    return [$customer, $fee, $admin, $other, $otherFee];
}

function financialMysqlExternalRefund(int $adminId, int $feeId, string $reference, int $amount): Closure
{
    return static function () use ($adminId, $feeId, $reference, $amount): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe retained-fee refund worker database.');
        }
        config()->set('fees.refunds_enabled', true);
        Queue::fake();
        $request = Request::create('/admin/fees/refund', 'POST');
        $session = new Store('retained-fee-refund-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(FeeRefundService::class)->authorizeRefund(User::findOrFail($adminId), FeeObligation::findOrFail($feeId),
                $reference, 'external', $amount, 'Reviewed selected original fee concession.', $request);

            return 'payable';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

function financialMysqlRefundRows(): array
{
    $rows = financialMysqlFeeRows();
    foreach (['fee_refunds', 'cash_disbursements', 'cash_recoveries'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('mysql stale refund snapshots cannot use unrelated earnings to exceed the selected original paid fee', function (): void {
    [$customer, $fee, $admin, $other, $otherFee] = financialMysqlIndependentFeeBacking();
    $first = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 9000);
    $second = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 2000);
    DB::beginTransaction();
    try {
        expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(10001);
        expect(Concurrency::driver('process')->run([$first]))->toBe(['payable']);
        expect($second())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(1001);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(11002);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(9000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe(20002);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(CollectionReadService::class)->position($other)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('fee_refunds', 1);
    $before = financialMysqlRefundRows();
    expect($first())->toBe('payable');
    expect($second())->toBe('blocked');
    expect(financialMysqlRefundRows())->toEqual($before);
});

test('mysql current selected fee sources permit an exact residual refund after another worker commits', function (): void {
    [, $fee, $admin, , $otherFee] = financialMysqlIndependentFeeBacking();
    $first = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 9000);
    $residual = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 1001);
    DB::beginTransaction();
    try {
        expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(10001);
        expect(Concurrency::driver('process')->run([$first]))->toBe(['payable']);
        expect($residual())->toBe('payable');
        expect(app(FeeConcessionPosition::class)->retainedSources($fee, forUpdate: true)['external_kobo'])->toBe(0);
        DB::commit();
    } catch (Throwable $exception) {
        DB::rollBack();
        throw $exception;
    }
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(0);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(10001);
    $this->assertDatabaseCount('fee_refunds', 2);
    $before = financialMysqlRefundRows();
    expect($residual())->toBe('payable');
    expect(financialMysqlRefundRows())->toEqual($before);
});

test('mysql concurrent selected fee refunds cannot share unrelated earnings as extra refundable source', function (): void {
    [$customer, $fee, $admin, $other, $otherFee] = financialMysqlIndependentFeeBacking();
    $first = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 9000);
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin->givePermissionTo(AdminPermission::FeesManage);
    $second = financialMysqlExternalRefund($otherAdmin->id, $fee->id, (string) Str::uuid(), 2000);
    $outcomes = Concurrency::driver('process')->run([$first, $second]);
    expect($outcomes)->toBeIn([['payable', 'blocked'], ['blocked', 'payable']]);
    $amount = $outcomes[0] === 'payable' ? 9000 : 2000;
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(10001 - $amount);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(20002 - $amount);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe($amount);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe(20002);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(CollectionReadService::class)->position($other)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('fee_refunds', 1);
    $before = financialMysqlRefundRows();
    expect(($outcomes[0] === 'payable' ? $first : $second)())->toBe('payable');
    expect(($outcomes[0] === 'payable' ? $second : $first)())->toBe('blocked');
    expect(financialMysqlRefundRows())->toEqual($before);
});

test('current concession sources require an owning transaction before reading fee history', function (): void {
    $fee = new FeeObligation;
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(FeeConcessionPosition::class)->read($fee, forUpdate: true))->toThrow(RuntimeException::class);
    expect(fn () => app(FeeConcessionPosition::class)->retainedSources($fee, forUpdate: true))->toThrow(RuntimeException::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    DB::flushQueryLog();
});

test('mysql stale receipt compensation cannot ignore a separately committed external concession', function (): void {
    [$customer, $fee, $admin, , $otherFee] = financialMysqlIndependentFeeBacking();
    $reversal = ReversalRequest::query()->sole();
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $quote = app(ReversalService::class)->reviewPreview($reviewer, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'decision_reason' => 'Reviewed original controlled tender.', 'confirmed' => true];
    $refund = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 9000);
    $approve = financialMysqlReceiptApproval($reviewer->id, $reversal->id, $payload);
    DB::beginTransaction();
    try {
        expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(10001);
        expect(Concurrency::driver('process')->run([$refund]))->toBe(['payable']);
        expect($approve())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    expect($reversal->fresh()->state)->toBe('pending_review');
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(1001);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(9000);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $review = app(ReversalService::class)->reviewPreview($reviewer, $reversal->fresh());
    $currentPayload = [...$payload, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $review['preview_fingerprint']];
    $currentApproval = financialMysqlReceiptApproval($reviewer->id, $reversal->id, $currentPayload);
    expect($currentApproval())->toBe('posted');
    expect($fee->fresh()->outstandingAmountKobo())->toBe(10001);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(0);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(9000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(1001);
    $before = financialMysqlRefundRows();
    expect($currentApproval())->toBe('posted');
    expect(financialMysqlRefundRows())->toEqual($before);
});

test('mysql concurrent receipt compensation and external concession retain one current financial instruction', function (): void {
    [$customer, $fee, $admin, , $otherFee] = financialMysqlIndependentFeeBacking();
    $reversal = ReversalRequest::query()->sole();
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $quote = app(ReversalService::class)->reviewPreview($reviewer, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'decision_reason' => 'Original controlled tender verified.', 'confirmed' => true];
    $refund = financialMysqlExternalRefund($admin->id, $fee->id, (string) Str::uuid(), 9000);
    $approve = financialMysqlReceiptApproval($reviewer->id, $reversal->id, $payload);
    $outcomes = Concurrency::driver('process')->run([$approve, $refund]);
    expect($outcomes)->toBeIn([['posted', 'blocked'], ['blocked', 'payable']]);
    $compensated = $outcomes[0] === 'posted';
    expect($fee->fresh()->outstandingAmountKobo())->toBe($compensated ? 10001 : 0);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe($compensated ? 0 : 1001);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe($compensated ? 10001 : 11002);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe($compensated ? 0 : 9000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe($compensated ? 10001 : 0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('fee_refunds', $compensated ? 0 : 1);
    $before = financialMysqlRefundRows();
    expect(($compensated ? $approve : $refund)())->toBe($compensated ? 'posted' : 'payable');
    expect(($compensated ? $refund : $approve)())->toBe('blocked');
    expect(financialMysqlRefundRows())->toEqual($before);
});

function financialMysqlPayoutConcessionFixture(): array
{
    $fixture = financialMysqlReturnedPayoutFixture();
    [$customer, , , $admin, $reviewer, $reversal] = $fixture;
    config()->set(['collections.enabled' => true, 'fees.refunds_enabled' => true]);
    $agent = $customer->currentAssignment->agentProfile->user;
    $other = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create(['customer_profile_id' => $other->id,
        'agent_profile_id' => $customer->currentAssignment->agent_profile_id, 'assigned_by_user_id' => $agent->id]);
    $otherFee = reportFeeObligation($agent, $other, 10001, 2);
    $plan = ThriftPlan::query()->sole();
    $data = [...collectionPayload($other, $assignment, $plan, now('Africa/Lagos')->toDateString(), '0'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $otherFee->id, 'amount_ngn' => '100.01']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $other, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $other, $data);
    DB::transaction(function () use ($admin, $receipt): void {
        $remittance = CashRemittance::create(['collection_batch_id' => $receipt->collection_batch_id,
            'agent_profile_id' => $receipt->recording_agent_profile_id, 'confirmed_by_user_id' => $admin->id,
            'handoff_reference' => 'PAYOUT-CONCESSION-INDEPENDENT-BACKING', 'amount_kobo' => 10001,
            'handoff_date' => now('Africa/Lagos')->toDateString(), 'receiving_location' => 'Business office',
            'source_attestation' => 'Counted separately paid fee cash; original payout concession remains distinct.']);
        $group = app(CollectionLedgerService::class)->postCashRemittance($remittance->id, $receipt->recording_agent_profile_id, 10001, $admin);
        $remittance->update(['ledger_posting_group_id' => $group->id]);
    });
    $quote = app(ReversalService::class)->reviewPreview($reviewer, $reversal);
    $fixture[6]['preview_fingerprint'] = $quote['preview_fingerprint'];

    return [...$fixture, $otherFee];
}
function financialMysqlSavingsConcession(int $adminId, int $feeId, string $reference, int $amount): Closure
{
    return static function () use ($adminId, $feeId, $reference, $amount): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe retained-fee refund worker database.');
        }
        config()->set('fees.refunds_enabled', true);
        Queue::fake();
        $request = Request::create('/admin/fees/refund', 'POST');
        $session = new Store('retained-fee-refund-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(FeeRefundService::class)->authorizeRefund(User::findOrFail($adminId), FeeObligation::findOrFail($feeId),
                $reference, 'savings', $amount, 'Reviewed selected original fee concession.', $request);

            return 'conceded';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql stale returned payout compensation cannot ignore separately restored fee savings', function (int $amount): void {
    [$customer, $plan, $fee, $admin, $reviewer, $reversal, $payload, , $recovery, $otherFee] = financialMysqlPayoutConcessionFixture();
    $concede = financialMysqlSavingsConcession($admin->id, $fee->id, (string) Str::uuid(), $amount);
    $approve = financialMysqlPayoutApproval($reviewer->id, $reversal->id, $payload);
    DB::beginTransaction();
    try {
        expect(app(FeeConcessionPosition::class)->read($fee)['savings_kobo'])->toBe(0);
        expect(Concurrency::driver('process')->run([$concede]))->toBe(['conceded']);
        expect($approve())->toBe('blocked');
    } finally {
        DB::rollBack();
    }
    expect($reversal->fresh()->state)->toBe('pending_review');
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000 + $amount);
    expect($recovery->fresh()->status)->toBe('confirmed');
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['savings_kobo'])->toBe(600 - $amount);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);

    $review = app(ReversalService::class)->reviewPreview($reviewer, $reversal->fresh());
    $currentPayload = [...$payload, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $review['preview_fingerprint']];
    $currentApproval = financialMysqlPayoutApproval($reviewer->id, $reversal->id, $currentPayload);
    expect($currentApproval())->toBe('posted');
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(100000);
    expect($recovery->fresh()->status)->toBe('consumed');
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['savings_kobo'])->toBe(0);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(0);
    $before = financialMysqlRefundRows();
    expect($currentApproval())->toBe('posted');
    expect(financialMysqlRefundRows())->toEqual($before);
})->with(['partial concession' => 500, 'full concession' => 600]);

test('mysql concurrent returned payout compensation and savings concession retain one affordable instruction', function (): void {
    [$customer, $plan, $fee, $admin, $reviewer, $reversal, $payload, , $recovery, $otherFee] = financialMysqlPayoutConcessionFixture();
    $concede = financialMysqlSavingsConcession($admin->id, $fee->id, (string) Str::uuid(), 500);
    $approve = financialMysqlPayoutApproval($reviewer->id, $reversal->id, $payload);
    $outcomes = Concurrency::driver('process')->run([$approve, $concede]);
    expect($outcomes)->toBeIn([['posted', 'blocked'], ['blocked', 'conceded']]);
    $compensated = $outcomes[0] === 'posted';
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe($compensated ? 100000 : 70500);
    expect($recovery->fresh()->status)->toBe($compensated ? 'consumed' : 'confirmed');
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['savings_kobo'])->toBe($compensated ? 0 : 100);
    expect(app(FeeConcessionPosition::class)->retainedSources($otherFee)['external_kobo'])->toBe(10001);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe($compensated ? 10001 : 10101);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe($compensated ? 0 : 29400);
    $this->assertDatabaseCount('fee_refunds', $compensated ? 0 : 1);
    $before = financialMysqlRefundRows();
    expect(($compensated ? $approve : $concede)())->toBe($compensated ? 'posted' : 'conceded');
    expect(($compensated ? $concede : $approve)())->toBe('blocked');
    expect(financialMysqlRefundRows())->toEqual($before);
});

function financialMysqlClosureReturn(int $adminId, int $customerUserId, int $executionId, string $reference, bool $acknowledged): Closure
{
    return static function () use ($adminId, $customerUserId, $executionId, $reference, $acknowledged): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe closure return worker database.');
        }
        config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
        $owner = app(CashRecoveryService::class);
        $admin = User::findOrFail($adminId);
        $execution = CashExecution::findOrFail($executionId);
        $request = Request::create('/cash-recoveries', 'POST');
        $session = new Store('closure-return-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        $request->merge(['preview_fingerprint' => $owner->preview($admin, $execution)['preview_fingerprint']]);
        $recovery = $owner->recordReturn($admin, $execution, $reference,
            'Complete original Customer payout independently counted back into controlled custody.', $request, 200000);
        if ($acknowledged) {
            $recovery = $owner->acknowledgeReturn(User::findOrFail($customerUserId), $recovery);
        }

        return $recovery->status;
    };
}

test('mysql warmed closure snapshot observes independently committed posted payout returns', function (bool $acknowledged): void {
    Queue::fake();
    config()->set(['collections.enabled' => true, 'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $collections = app(CollectionService::class);
    $payload['preview_fingerprint'] = $collections->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collections->record($agent, $customer, $payload);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    $batch = $receipt->batch;
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'CLOSURE-RETURN-'.$batch->id,
        'amount_ngn' => '2000.00', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted the complete original Customer tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent original custody review.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '2000.00', 'type' => 'end_of_cycle'];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    expect($quote['fee_kobo'])->toBe(0);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $this->post(route('withdrawals.approve', $withdrawal), ['attempt_reference' => (string) Str::uuid(),
        'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed full completed-cycle withdrawal.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $payments = app(CashExecutionService::class);
    $execution = $payments->start($admin, $withdrawal->fresh(), (string) Str::uuid(), $withdrawal->fresh()->version,
        'Original Customer personally verified at business till.', financialMysqlRequest());
    $payments->recordHandoff($admin, $execution, 'Complete net payout counted to the Customer.', financialMysqlRequest());
    $payments->confirmReceipt($customer->user, $execution->fresh());
    expect($execution->fresh()->status)->toBe('posted');
    $settlement = app(PlanSettlementService::class);
    $ready = $settlement->preview($agent, $plan->fresh());
    expect($ready['can_close'])->toBeTrue();
    expect($ready['position']['cycle_liability_kobo'])->toBe(0);
    expect($ready['position']['cycle_reservations_kobo'])->toBe(0);
    $worker = financialMysqlClosureReturn($admin->id, $customer->user_id, $execution->id, (string) Str::uuid(), $acknowledged);
    $journalCount = LedgerPostingGroup::query()->count();
    $terms = $plan->termsRevisions()->get()->toArray();
    $slots = $plan->slots()->get()->toArray();
    DB::beginTransaction();
    try {
        expect(DB::table('cash_recoveries')->count())->toBe(0);
        expect(DB::table('withdrawal_requests')->where('id', $withdrawal->id)->value('state'))->toBe('posted');
        [$status] = Concurrency::driver('process')->run([$worker]);
        expect($status)->toBe($acknowledged ? 'confirmed' : 'awaiting_customer');
        expect(DB::table('cash_recoveries')->count())->toBe(0);
        $current = $settlement->preview($agent, $plan->fresh());
        expect($current['can_close'])->toBeFalse();
        expect($current['preview_fingerprint'])->not->toBe($ready['preview_fingerprint']);
        expect($current['position']['cycle_liability_kobo'])->toBe(0);
        expect($current['position']['cycle_reservations_kobo'])->toBe(0);
        foreach ([$ready, $current] as $review) {
            expect(fn () => $settlement->confirm($agent, $plan->fresh(), 'close', [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $review['preview_fingerprint'],
                'reason' => 'The independently returned payout still requires its own disposition.',
                'customer_explanation' => 'Your returned payout must be settled before closure.',
            ]))->toThrow(ConflictHttpException::class);
        }
    } finally {
        DB::rollBack();
    }
    expect(CashRecovery::query()->sole()->status)->toBe($acknowledged ? 'confirmed' : 'awaiting_customer');
    expect(LedgerPostingGroup::query()->count())->toBe($journalCount + ($acknowledged ? 1 : 0));
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    expect($plan->fresh()->open_customer_profile_id)->toBe($customer->id);
    expect($plan->termsRevisions()->get()->toArray())->toBe($terms);
    expect($plan->slots()->get()->toArray())->toBe($slots);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseCount('reversal_requests', 0);
})->with(['Awaiting Customer confirmation' => false, 'Confirmed original return awaiting compensation' => true]);

/** @return array{User, CustomerProfile, ThriftPlan, CashExecution} */
function financialMysqlCompensatedClosureFixture(object $test): array
{
    Queue::fake();
    config()->set(['collections.enabled' => true, 'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $collections = app(CollectionService::class);
    $payload['preview_fingerprint'] = $collections->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collections->record($agent, $customer, $payload);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    $batch = $receipt->batch;
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'CLOSURE-RETURN-'.$batch->id,
        'amount_ngn' => '2000.00', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted the complete original Customer tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent original custody review.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '2000.00', 'type' => 'end_of_cycle'];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    expect($quote['fee_kobo'])->toBe(0);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $test->post(route('withdrawals.approve', $withdrawal), ['attempt_reference' => (string) Str::uuid(),
        'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed full completed-cycle withdrawal.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $payments = app(CashExecutionService::class);
    $execution = $payments->start($admin, $withdrawal->fresh(), (string) Str::uuid(), $withdrawal->fresh()->version,
        'Original Customer personally verified at business till.', financialMysqlRequest());
    $payments->recordHandoff($admin, $execution, 'Complete net payout counted to the Customer.', financialMysqlRequest());
    $payments->confirmReceipt($customer->user, $execution->fresh());
    expect($execution->fresh()->status)->toBe('posted');

    config()->set('withdrawals.cash_compensation_enabled', true);
    $returnOwner = app(CashRecoveryService::class);
    $returnRequest = financialMysqlRequest();
    $returnRequest->merge(['preview_fingerprint' => $returnOwner->preview($admin, $execution->fresh())['preview_fingerprint']]);
    $return = $returnOwner->recordReturn($admin, $execution->fresh(), (string) Str::uuid(),
        'Entire actual net payout returned to original controlled till.', $returnRequest, 200000);
    $returnOwner->acknowledgeReturn($customer->user, $return);
    $reversals = app(ReversalService::class);
    $original = LedgerPostingGroup::findOrFail($execution->fresh()->ledger_posting_group_id);
    $review = $reversals->preview($agent, $original);
    $reversal = $reversals->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $review['preview_fingerprint'], 'customer_version' => $review['customer_version'],
        'assignment_version' => $review['assignment_version'], 'reason_category' => 'incorrect_payout_record',
        'internal_reason' => 'Original complete returned payout verified.',
        'customer_explanation' => 'Your original payout correction retains its returned cash proof.',
        'evidence_text' => 'Original Customer acknowledgment and counted full cash return.', 'confirmed' => true]);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $reversals->reviewPreview($reviewer, $reversal);
    $reversals->decide($reviewer, $reversal, 'approve', ['attempt_reference' => (string) Str::uuid(),
        'version' => $reversal->version, 'preview_fingerprint' => $review['preview_fingerprint'],
        'decision_reason' => 'Independently verified the complete original return.', 'confirmed' => true], financialMysqlRequest());
    expect($return->fresh()->status)->toBe('consumed');
    expect($reversal->fresh()->state)->toBe('approved_posted');
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan->fresh())['cycle_liability_kobo'])->toBe(200000);
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '2000.00', 'type' => 'end_of_cycle'];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    expect($quote['fee_kobo'])->toBe(0);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $test->post(route('withdrawals.approve', $withdrawal), ['attempt_reference' => (string) Str::uuid(),
        'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed full completed-cycle withdrawal.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $payments = app(CashExecutionService::class);
    $execution = $payments->start($admin, $withdrawal->fresh(), (string) Str::uuid(), $withdrawal->fresh()->version,
        'Original Customer personally verified at business till.', financialMysqlRequest());
    $payments->recordHandoff($admin, $execution, 'Complete net payout counted to the Customer.', financialMysqlRequest());
    $payments->confirmReceipt($customer->user, $execution->fresh());
    expect($execution->fresh()->status)->toBe('posted');

    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(0);

    return [$agent, $customer, $plan, $execution];
}

function financialMysqlCompensationDamage(bool $wrongCustomer, int $reversalId, int $compensationId, int $otherCustomerId): Closure
{
    return static function () use ($wrongCustomer, $reversalId, $compensationId, $otherCustomerId): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe consumed compensation closure damage worker database.');
        }
        DB::transaction(function () use ($wrongCustomer, $reversalId, $compensationId, $otherCustomerId): void {
            if ($wrongCustomer) {
                CustomerProfile::query()->findOrFail($otherCustomerId);
                DB::table('ledger_posting_groups')->where('id', $compensationId)->update(['customer_profile_id' => $otherCustomerId]);
            } else {
                DB::table('reversal_requests')->where('id', $reversalId)->update(['state' => 'rejected']);
            }
        });

        return 'damaged';
    };
}

test('mysql warmed closure snapshot rejects independently damaged consumed payout compensation outcome', function (bool $wrongCustomer): void {
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        throw new RuntimeException('Unsafe consumed compensation closure parent database.');
    }
    [$agent, $customer, $plan] = financialMysqlCompensatedClosureFixture($this);
    $reversal = ReversalRequest::query()->sole();
    $compensation = LedgerPostingGroup::findOrFail($reversal->compensation_posting_group_id);
    $otherCustomer = CustomerProfile::factory()->create();
    $owner = app(PlanSettlementService::class);
    $ready = $owner->preview($agent, $plan->fresh());
    expect($ready['can_close'])->toBeTrue();
    expect($ready['position']['cycle_liability_kobo'])->toBe(0);
    expect($ready['position']['cycle_reservations_kobo'])->toBe(0);
    $reversalId = $reversal->id;
    $compensationId = $compensation->id;
    $otherCustomerId = $otherCustomer->id;
    $worker = financialMysqlCompensationDamage($wrongCustomer, $reversalId, $compensationId, $otherCustomerId);
    $sourceRows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts', 'collection_batches',
        'collection_batch_reviews', 'cash_remittances', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations',
        'withdrawal_events', 'cash_executions', 'cash_recoveries', 'reversal_requests', 'reversal_events',
        'plan_lifecycle_events', 'financial_workflow_supplements', 'financial_cash_notification_intents'] as $table) {
        $sourceRows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
    $damageTable = $wrongCustomer ? 'ledger_posting_groups' : 'reversal_requests';
    foreach ($sourceRows[$damageTable] as &$row) {
        if ((int) $row['id'] === ($wrongCustomer ? $compensationId : $reversalId)) {
            $row[$wrongCustomer ? 'customer_profile_id' : 'state'] = $wrongCustomer ? $otherCustomerId : 'rejected';
        }
    }
    unset($row);
    DB::beginTransaction();
    try {
        expect(DB::table('reversal_requests')->where('id', $reversalId)->value('state'))->toBe('approved_posted');
        expect((int) DB::table('ledger_posting_groups')->where('id', $compensationId)->value('customer_profile_id'))->toBe($customer->id);
        expect(Concurrency::driver('process')->run([$worker]))->toBe(['damaged']);
        expect(DB::table('reversal_requests')->where('id', $reversalId)->value('state'))->toBe('approved_posted');
        expect((int) DB::table('ledger_posting_groups')->where('id', $compensationId)->value('customer_profile_id'))->toBe($customer->id);
        $current = $owner->preview($agent, $plan->fresh());
        expect($current['can_close'])->toBeFalse();
        foreach ([$ready, $current] as $quote) {
            expect(fn () => $owner->confirm($agent, $plan->fresh(), 'close', [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
                'reason' => 'Retained consumed payout compensation must remain authoritative.',
                'customer_explanation' => 'Your original payout correction needs source verification.',
            ]))->toThrow(ConflictHttpException::class);
        }
    } finally {
        DB::rollBack();
    }
    foreach ($sourceRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toEqual($rows);
    }
    expect($reversal->fresh()->state)->toBe($wrongCustomer ? 'approved_posted' : 'rejected');
    expect($compensation->fresh()->customer_profile_id)->toBe($wrongCustomer ? $otherCustomerId : $customer->id);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    expect($plan->fresh()->open_customer_profile_id)->toBe($customer->id);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    expect($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(0);
})->with(['Retained reversal outcome rejected' => false, 'Compensation belongs to another actual Customer' => true]);

function financialMysqlPendingFeeReceiptCorrection(int $agentId, int $postingId): Closure
{
    return static function () use ($agentId, $postingId): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe pending fee receipt correction worker database.');
        }
        config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
        Queue::fake();
        $agent = User::findOrFail($agentId);
        $original = LedgerPostingGroup::findOrFail($postingId);
        $owner = app(ReversalService::class);
        $quote = $owner->preview($agent, $original);
        $request = $owner->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
            'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $quote['customer_version'],
            'assignment_version' => $quote['assignment_version'], 'reason_category' => 'incorrect_receipt',
            'internal_reason' => 'Original fee-only receipt needs independent correction review.',
            'customer_explanation' => 'Your original service fee receipt is under separate review.',
            'evidence_text' => 'Retained original receipt and independently assessed manual fee.', 'confirmed' => true]);

        return $request->state;
    };
}

test('mysql warmed closure snapshot observes independently submitted pending fee only receipt correction', function (): void {
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        throw new RuntimeException('Unsafe pending fee receipt closure parent database.');
    }
    Queue::fake();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true,
        'fees.manual_charges_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $settlement = app(PlanSettlementService::class);
    $prepare = $settlement->preview($agent, $plan, 'prepare_termination');
    $settlement->confirm($agent, $plan, 'prepare_termination', ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $prepare['preview_fingerprint'], 'reason' => 'Unused cycle prepared for its separate settlement.',
        'customer_explanation' => 'Your cycle is prepared for separate settlement.']);
    $plan->refresh();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::ReconciliationManage]);
    $manual = app(ManualChargeService::class);
    $category = $manual->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'pending-closure-service', 'kind' => 'manual_fee', 'purpose' => 'Agreed separate service terms.',
        'customer_description' => 'Agreed manual service fee.', 'amount_kobo' => 10001], financialMysqlRequest());
    $reason = 'Independently assessed separate cycle service.';
    $review = $manual->preview($admin, $customer, $plan, $category,
        ['mode' => 'assessment_only', 'customer_version' => $customer->version, 'plan_version' => $plan->version, 'reason' => $reason], financialMysqlRequest());
    $charge = $manual->assess($admin, $customer, $plan, $category, (string) Str::uuid(),
        $customer->version, $plan->version, $reason, financialMysqlRequest(),
        ['mode' => 'assessment_only', 'preview_fingerprint' => $review['preview_fingerprint'], 'quote_expires_at' => $review['quote_expires_at']]);
    $fee = FeeObligation::findOrFail($charge->fee_obligation_id);
    $payment = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.01']]];
    $collections = app(CollectionService::class);
    $payment['preview_fingerprint'] = $collections->preview($agent, $customer, $payment)['preview_fingerprint'];
    $receipt = $collections->record($agent, $customer, $payment);
    $batch = $receipt->batch;
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'PENDING-CLOSURE-'.$batch->id,
        'amount_ngn' => '100.01', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted complete actual fee-only receipt.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent original fee-only custody review.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect($receipt->savings_amount_kobo)->toBe(0);
    $original = LedgerPostingGroup::findOrFail(DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)
        ->orderBy('id')->value('ledger_posting_group_id'));
    expect($original->event_type)->toBe('external_fee_receipt');
    $ready = $settlement->preview($agent, $plan->fresh());
    expect($ready['can_close'])->toBeTrue();
    expect($ready['position']['cycle_liability_kobo'])->toBe(0);
    expect($ready['position']['cycle_reservations_kobo'])->toBe(0);
    $rows = [];
    foreach (['customer_profiles', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_lifecycle_events',
        'financial_workflow_supplements', 'collection_receipts', 'collection_allocations', 'collection_fee_components',
        'collection_batches', 'collection_batch_reviews', 'cash_remittances', 'manual_charges', 'charge_category_versions',
        'fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'withdrawal_requests', 'withdrawal_reservations', 'collection_notification_intents'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $worker = financialMysqlPendingFeeReceiptCorrection($agent->id, $original->id);
    DB::beginTransaction();
    try {
        expect(DB::table('reversal_requests')->count())->toBe(0);
        expect(Concurrency::driver('process')->run([$worker]))->toBe(['pending_review']);
        expect(DB::table('reversal_requests')->count())->toBe(0);
        $current = $settlement->preview($agent, $plan->fresh());
        expect($current['can_close'])->toBeFalse();
        expect($current['blockers'])->toContain('Pending financial owner work remains.');
        foreach ([$ready, $current] as $quote) {
            expect(fn () => $settlement->confirm($agent, $plan->fresh(), 'close', [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
                'reason' => 'Original fee-only receipt correction still requires independent review.',
                'customer_explanation' => 'Your original fee receipt must finish its separate review.',
            ]))->toThrow(ConflictHttpException::class);
        }
    } finally {
        DB::rollBack();
    }
    foreach ($rows as $table => $baseline) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($baseline);
    }
    $pending = ReversalRequest::query()->sole();
    expect($pending->state)->toBe('pending_review');
    expect($pending->original_posting_group_id)->toBe($original->id);
    expect($pending->compensation_posting_group_id)->toBeNull();
    expect($pending->events()->where('event_type', 'submitted')->count())->toBe(1);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    expect($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(0);
});

/** @return array{User, CustomerProfile, ThriftPlan, array<string, mixed>} */
function financialMysqlReadyClosureStaleReservationFixture(object $test): array
{
    Queue::fake();
    config()->set(['collections.enabled' => true, 'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $collections = app(CollectionService::class);
    $payload['preview_fingerprint'] = $collections->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collections->record($agent, $customer, $payload);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    $batch = $receipt->batch;
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'CLOSURE-RETURN-'.$batch->id,
        'amount_ngn' => '2000.00', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted the complete original Customer tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent original custody review.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $withdrawals = app(WithdrawalService::class);
    $staleInstruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '300.00', 'type' => 'partial',
        'destination_reference' => 'customer:'.$customer->id];
    $staleQuote = $withdrawals->preview($agent, $customer, $staleInstruction);
    expect($staleQuote['fee_kobo'])->toBe(0);
    $staleReservation = [...$staleInstruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $staleQuote['preview_fingerprint'], 'quote_expires_at' => $staleQuote['quote_expires_at'],
        'customer_version' => $staleQuote['customer_version'], 'assignment_version' => $staleQuote['assignment_version'],
        'plan_version' => $staleQuote['plan_version'], 'business_version' => $staleQuote['business_version'],
        'instruction_attested' => true, 'confirmed' => true];

    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '2000.00', 'type' => 'end_of_cycle',
        'destination_reference' => 'customer:'.$customer->id];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    expect($quote['fee_kobo'])->toBe(0);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $test->post(route('withdrawals.approve', $withdrawal), ['attempt_reference' => (string) Str::uuid(),
        'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed full completed-cycle withdrawal.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $payments = app(CashExecutionService::class);
    $execution = $payments->start($admin, $withdrawal->fresh(), (string) Str::uuid(), $withdrawal->fresh()->version,
        'Original Customer personally verified at business till.', financialMysqlRequest());
    $payments->recordHandoff($admin, $execution, 'Complete net payout counted to the Customer.', financialMysqlRequest());
    $payments->confirmReceipt($customer->user, $execution->fresh());
    expect($execution->fresh()->status)->toBe('posted');

    expect($customer->fresh()->version)->toBe($staleReservation['customer_version']);
    expect($plan->fresh()->version)->toBe($staleReservation['plan_version']);

    return [$agent, $customer, $plan, $staleReservation];
}

test('mysql stale funded reservation cannot pass admission before after or alongside settled closure', function (string $ordering): void {
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        throw new RuntimeException('Unsafe stale reservation closure parent database.');
    }
    [$agent, $customer, $plan, $reservation] = financialMysqlReadyClosureStaleReservationFixture($this);
    $settlement = app(PlanSettlementService::class);
    $quote = $settlement->preview($agent, $plan->fresh());
    expect($quote['can_close'])->toBeTrue();
    expect($quote['position']['cycle_liability_kobo'])->toBe(0);
    expect($quote['position']['cycle_reservations_kobo'])->toBe(0);
    $close = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Independent full payout settled the original completed cycle.',
        'customer_explanation' => 'Your completed cycle is separately closed after full payout.'];
    $financialRows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_batches', 'collection_batch_reviews',
        'cash_remittances', 'ledger_posting_groups', 'ledger_entries', 'fee_rules', 'fee_snapshots', 'fee_obligations',
        'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations', 'withdrawal_events',
        'cash_executions', 'cash_recoveries', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $originalEvents = $plan->lifecycleEvents()->orderBy('id')->get()->map->getAttributes()->all();
    $reserveWorker = financialMysqlReservation($agent->id, $customer->id, $reservation);
    $closeWorker = mysqlSettlementTask($agent->id, $plan->id, $close);
    if ($ordering === 'concurrent') {
        $outcomes = Concurrency::driver('process')->run([$reserveWorker, $closeWorker]);
    } elseif ($ordering === 'reservation first') {
        $outcomes = [...Concurrency::driver('process')->run([$reserveWorker]), ...Concurrency::driver('process')->run([$closeWorker])];
    } else {
        [$closed] = Concurrency::driver('process')->run([$closeWorker]);
        [$reserved] = Concurrency::driver('process')->run([$reserveWorker]);
        $outcomes = [$reserved, $closed];
    }
    expect($outcomes)->toBe(['blocked', 'closed']);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed);
    expect($plan->fresh()->open_customer_profile_id)->toBeNull();
    expect($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
    $settlement->confirm($agent, $plan->fresh(), 'close', $close);
    expect(Concurrency::driver('process')->run([$reserveWorker]))->toBe(['blocked']);
    foreach ($financialRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    foreach ($originalEvents as $event) {
        expect(DB::table('plan_lifecycle_events')->where('id', $event['id'])->first())->toEqual((object) $event);
    }
    expect(DB::table('withdrawal_requests')->count())->toBe(1);
    expect(DB::table('withdrawal_reservations')->where('status', 'live')->count())->toBe(0);
    expect($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
    expect(DB::table('financial_workflow_supplements')->where('operation_reference', $close['attempt_reference'])->count())->toBe(1);
})->with(['Stale reservation first' => 'reservation first', 'Settled closure first' => 'closure first', 'Concurrent stale reservation and closure' => 'concurrent']);

/** @return array{CustomerProfile, ThriftPlan, FeeObligation, User} */
function financialMysqlCompetingDrawBackingFixture(): array
{
    Queue::fake();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true,
        'fees.cash_disbursements_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 10001);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.01']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::CashExecute]);
    DB::transaction(function () use ($admin, $receipt): void {
        $remittance = CashRemittance::create(['collection_batch_id' => $receipt->collection_batch_id,
            'agent_profile_id' => $receipt->recording_agent_profile_id, 'confirmed_by_user_id' => $admin->id,
            'handoff_reference' => 'RECEIPT-DRAW-CONTROLLED-CASH', 'amount_kobo' => 10001,
            'handoff_date' => now('Africa/Lagos')->toDateString(), 'receiving_location' => 'Business office',
            'source_attestation' => 'Counted full original external fee tender into business cash.']);
        $group = app(CollectionLedgerService::class)->postCashRemittance($remittance->id, $receipt->recording_agent_profile_id, 10001, $admin);
        $remittance->update(['ledger_posting_group_id' => $group->id]);
    });
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(10001);

    return [$customer, $plan, $fee, $admin];
}

test('mysql competing business draws cannot hold the same independently earned cash twice', function (string $ordering): void {
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        throw new RuntimeException('Unsafe competing business draw parent database.');
    }
    [$customer, $plan, $fee, $firstAdmin] = financialMysqlCompetingDrawBackingFixture();
    $secondAdmin = User::factory()->admin()->withTwoFactor()->create();
    $secondAdmin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::CashExecute]);
    expect($secondAdmin->id)->not->toBe($firstAdmin->id);
    $references = [(string) Str::uuid(), (string) Str::uuid()];
    $workers = [financialMysqlEarningsDraw($firstAdmin->id, $references[0], 6000),
        financialMysqlEarningsDraw($secondAdmin->id, $references[1], 6000)];
    $rows = [];
    foreach (['customer_profiles', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts',
        'collection_batches', 'collection_allocations', 'cash_remittances', 'fee_rules', 'fee_snapshots', 'fee_obligations',
        'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $liability = app(CollectionReadService::class)->position($customer)['liability_kobo'];
    DB::beginTransaction();
    try {
        expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(10001);
        expect(DB::table('cash_disbursements')->count())->toBe(0);
        if ($ordering === 'concurrent') {
            $outcomes = Concurrency::driver('process')->run($workers);
        } elseif ($ordering === 'first Admin first') {
            $outcomes = [...Concurrency::driver('process')->run([$workers[0]]), ...Concurrency::driver('process')->run([$workers[1]])];
        } else {
            [$second] = Concurrency::driver('process')->run([$workers[1]]);
            [$first] = Concurrency::driver('process')->run([$workers[0]]);
            $outcomes = [$first, $second];
        }
        expect($outcomes)->toBeIn([['held', 'blocked'], ['blocked', 'held']]);
        if ($ordering !== 'concurrent') {
            expect($outcomes)->toBe($ordering === 'first Admin first' ? ['held', 'blocked'] : ['blocked', 'held']);
        }
        expect(DB::table('cash_disbursements')->count())->toBe(0);
        $current = app(FinancialCashPosition::class)->read(forUpdate: true);
        expect($current['pending_cash_kobo'])->toBe(6000);
        expect($current['undrawn_earnings_kobo'])->toBe(4001);
        expect($current['free_cash_kobo'])->toBe(4001);
        expect($current['draw_limit_kobo'])->toBe(4001);
    } finally {
        DB::rollBack();
    }
    $winnerIndex = $outcomes[0] === 'held' ? 0 : 1;
    $winner = CashDisbursement::query()->sole();
    expect($winner->execution_reference)->toBe($references[$winnerIndex]);
    expect($winner->executor_user_id)->toBe($winnerIndex === 0 ? $firstAdmin->id : $secondAdmin->id);
    expect($winner->amount_kobo)->toBe(6000);
    expect($winner->kind)->toBe('earnings_draw');
    expect($winner->status)->toBe('processing');
    expect($winner->ledger_posting_group_id)->toBeNull();
    $winnerBefore = $winner->getAttributes();
    $notices = DB::table('financial_cash_notification_intents')->orderBy('id')->get()->all();
    expect(Concurrency::driver('process')->run([$workers[$winnerIndex]]))->toBe(['held']);
    expect(Concurrency::driver('process')->run([$workers[1 - $winnerIndex]]))->toBe(['blocked']);
    expect(CashDisbursement::query()->sole()->getAttributes())->toBe($winnerBefore);
    expect(DB::table('financial_cash_notification_intents')->orderBy('id')->get()->all())->toEqual($notices);
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(4001);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($liability);
    expect($fee->fresh()->settledAmountKobo())->toBe(10001);
    foreach ($rows as $table => $baseline) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($baseline);
    }
})->with(['First Admin committed first' => 'first Admin first', 'Second Admin committed first' => 'second Admin first', 'Simultaneous independent Admins' => 'concurrent']);

function financialMysqlPayoutAdmission(int $adminId, int $customerId, int $withdrawalId, string $action, int $customerVersion, int $withdrawalVersion, string $reference): Closure
{
    return static function () use ($adminId, $customerId, $withdrawalId, $action, $customerVersion, $withdrawalVersion, $reference): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe payout admission worker database.');
        }
        config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
        Queue::fake();
        $request = Request::create('/withdrawals/admission', 'POST');
        $session = new Store('payout-admission-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        $admin = User::findOrFail($adminId);
        try {
            if ($action === 'start') {
                app(CashExecutionService::class)->start($admin, WithdrawalRequest::findOrFail($withdrawalId),
                    $reference, $withdrawalVersion, 'Original Customer verified at the business till.', $request);
            } elseif ($action === 'restriction') {
                app(CustomerStatusManagementService::class)->transition($admin, CustomerProfile::findOrFail($customerId),
                    CustomerStatus::Restricted, $customerVersion, 'Reviewed temporary participation restriction.',
                    'Your approved unpaid withdrawal remains retained for review.');
            } else {
                app(WithdrawalService::class)->decide($admin, WithdrawalRequest::findOrFail($withdrawalId), 'revoke', [
                    'attempt_reference' => $reference, 'version' => $withdrawalVersion,
                    'internal_reason' => 'Reviewed revocation before any payout execution.',
                    'customer_explanation' => 'Your unexecuted approval was revoked without moving savings.',
                ], $request);
            }

            return $action;
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        }
    };
}

test('mysql approved payout admission serializes current restriction and revocation without releasing in flight custody', function (string $owner, string $ordering): void {
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        throw new RuntimeException('Unsafe payout admission parent database.');
    }
    Queue::fake();
    config()->set(['collections.enabled' => true, 'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CustomersManage, AdminPermission::WithdrawalsReview,
        AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $collections = app(CollectionService::class);
    $payload['preview_fingerprint'] = $collections->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collections->record($agent, $customer, $payload);
    $batch = $receipt->batch;
    $recordedAt = now()->toImmutable();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelTo($recordedAt);
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'PAYOUT-ADMISSION-'.$batch->id,
        'amount_ngn' => '2000.00', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted the complete original Customer tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent full cash review before approved payout.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => '2000.00',
        'type' => 'end_of_cycle', 'destination_reference' => 'customer:'.$customer->id];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $withdrawals->decide($admin, $withdrawal, 'approve', ['attempt_reference' => (string) Str::uuid(),
        'version' => $withdrawal->version, 'decision_note' => 'Reviewed completed cycle and verified cash backing.',
    ], financialMysqlRequest());
    $withdrawal->refresh();
    expect($withdrawal->state)->toBe('approved')->and($withdrawal->held)->toBeFalse();
    $money = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_fee_components', 'cash_remittances',
        'ledger_posting_groups', 'ledger_entries', 'fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $money[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $start = financialMysqlPayoutAdmission($admin->id, $customer->id, $withdrawal->id, 'start',
        $customer->version, $withdrawal->version, (string) Str::uuid());
    $mutation = financialMysqlPayoutAdmission($admin->id, $customer->id, $withdrawal->id, $owner,
        $customer->version, $withdrawal->version, (string) Str::uuid());
    if ($ordering === 'owner first') {
        $ownerResult = Concurrency::driver('process')->run([$mutation])[0];
        $startResult = Concurrency::driver('process')->run([$start])[0];
    } elseif ($ordering === 'execution first') {
        $startResult = Concurrency::driver('process')->run([$start])[0];
        $ownerResult = Concurrency::driver('process')->run([$mutation])[0];
    } else {
        [$ownerResult, $startResult] = Concurrency::driver('process')->run([$mutation, $start]);
    }
    $started = $startResult === 'start';
    if ($ordering !== 'concurrent') {
        expect($started)->toBe($ordering === 'execution first');
    }
    expect($startResult)->toBeIn(['start', 'blocked'])
        ->and($ownerResult)->toBe($owner === 'restriction' || ! $started ? $owner : 'blocked');
    $withdrawal->refresh();
    $reservation = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->sole();
    expect($withdrawal->state)->toBe($started ? 'payout_processing' : ($owner === 'restriction' ? 'approved' : 'cancelled'))
        ->and($withdrawal->held)->toBe($owner === 'restriction' && ! $started)
        ->and($reservation->status)->toBe($owner === 'revoke' && ! $started ? 'released' : 'live')
        ->and($customer->fresh()->operational_status->value)->toBe($owner === 'restriction' ? 'restricted' : 'active')
        ->and(CashExecution::query()->count())->toBe($started ? 1 : 0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and(app(CollectionReadService::class)->position($customer)['reservations_kobo'])->toBe($owner === 'revoke' && ! $started ? 0 : 200000);
    if ($started) {
        expect(CashExecution::query()->sole()->status)->toBe('processing')
            ->and(CashExecution::query()->sole()->ledger_posting_group_id)->toBeNull();
    }
    $history = [];
    foreach (['withdrawal_requests', 'withdrawal_reservations', 'withdrawal_attempts', 'withdrawal_events', 'cash_executions',
        'customer_profiles', 'customer_status_histories', 'customer_status_notification_intents', 'financial_cash_notification_intents',
        'audit_events', 'canonical_audit_events'] as $table) {
        $history[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    expect(Concurrency::driver('process')->run([$start]))->toBe([$startResult]);
    if ($owner === 'restriction') {
        $mutation = financialMysqlPayoutAdmission($admin->id, $customer->id, $withdrawal->id, $owner,
            $customer->fresh()->version, $withdrawal->version, (string) Str::uuid());
    }
    expect(Concurrency::driver('process')->run([$mutation]))->toBe([$ownerResult]);
    foreach ([...$money, ...$history] as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Restriction first' => ['restriction', 'owner first'], 'Execution before restriction' => ['restriction', 'execution first'],
    'Concurrent restriction' => ['restriction', 'concurrent'], 'Revocation first' => ['revoke', 'owner first'],
    'Execution before revocation' => ['revoke', 'execution first'], 'Concurrent revocation' => ['revoke', 'concurrent']]);
