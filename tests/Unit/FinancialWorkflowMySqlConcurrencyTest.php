<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Models\CashExecution;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\BackgroundRecovery;
use App\Services\CashExecutionService;
use App\Services\CashRecoveryService;
use App\Services\CollectionReadService;
use App\Services\CollectionReplacementService;
use App\Services\CollectionService;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ManualChargeService;
use App\Services\PlanSettlementService;
use App\Services\StatementPreviewService;
use App\Services\ThriftPlanService;
use App\Support\RecoveryLease;
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
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
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
    $plan->refresh()->update(['status' => ThriftPlanStatus::Active, 'version' => $plan->version + 1]);
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

test('mysql competing renewal of a verified Closed cycle creates only one successor and one open cycle', function (): void {
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
    $outcomes = Concurrency::driver('process')->run([
        mysqlRenewalTask($agent->id, $customer->id, $data, (string) Str::uuid()),
        mysqlRenewalTask($agent->id, $customer->id, $data, (string) Str::uuid()),
    ]);
    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'created'])
        ->and(ThriftPlan::query()->where('predecessor_plan_id', $plan->id)->count())->toBe(1)
        ->and(ThriftPlan::query()->whereNotNull('open_customer_profile_id')->count())->toBe(1)
        ->and(LedgerPostingGroup::query()->count())->toBe(0);
});

function mysqlRenewalTask(int $actorId, int $customerId, array $data, string $reference): Closure
{
    return static function () use ($actorId, $customerId, $data, $reference): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe renewal race database.');
        }
        config()->set('collections.settlement_enabled', true);
        try {
            app(ThriftPlanService::class)->create(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $reference, $data);

            return 'created';
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
