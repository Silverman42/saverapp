<?php

use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Jobs\DeliverCustomerHandoverNotice;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AgentEligibilityService;
use App\Services\AgentLifecycleService;
use App\Services\AgentStatusManagementService;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\CustomerReassignmentService;
use App\Services\CustomerStatusManagementService;
use App\Services\FinancialPeriodService;
use App\Services\ManualChargeService;
use App\Services\PlanEstimateService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\CreatesLifecycleAgents;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleCustomers::class, CreatesLifecycleAgents::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    FinancialPeriod::factory()->create();
});

function planMysqlTask(int $actorId, int $customerId, string $reference, array $data): Closure
{
    return static function () use ($actorId, $customerId, $reference, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe plan concurrency database.');
        }
        try {
            app(ThriftPlanService::class)->create(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), $reference, $data);

            return 'created';
        } catch (ValidationException|ConflictHttpException) {
            return 'blocked';
        }
    };
}

function restrictCustomerMysqlTask(int $actorId, int $customerId, int $version): Closure
{
    return static function () use ($actorId, $customerId, $version): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe Customer restriction database.');
        }
        try {
            app(CustomerStatusManagementService::class)->transition(
                User::findOrFail($actorId), CustomerProfile::findOrFail($customerId), CustomerStatus::Restricted,
                $version, 'Financial review hold.', 'Your account is under review.',
            );

            return 'restricted';
        } catch (ValidationException|ConflictHttpException|AuthorizationException) {
            return 'blocked';
        }
    };
}

function receiptMysqlTask(int $actorId, int $customerId, string $reference, array $data, ?string $at = null): Closure
{
    return static function () use ($actorId, $customerId, $reference, $data, $at): string {
        if ($at !== null) {
            CarbonImmutable::setTestNow($at);
        }
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe collection concurrency database.');
        }
        try {
            app(CollectionService::class)->record(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId),
                [...$data, 'attempt_reference' => $reference]);

            return 'posted';
        } catch (ValidationException|ConflictHttpException|AuthorizationException) {
            return 'blocked';
        }
    };
}

function periodCloseMysqlTask(int $adminId, string $month, string $at): Closure
{
    return static function () use ($adminId, $month, $at): string {
        CarbonImmutable::setTestNow($at);
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe period race database.');
        }
        $request = Request::create('/admin/financial-periods/'.$month.'/close', 'POST');
        $session = new Store('financial-period-race', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(FinancialPeriodService::class)->transition(User::findOrFail($adminId), $month, 'close', 1,
                'All prior-month cash batches are settled.', $request);

            return 'closed';
        } catch (ConflictHttpException|AuthorizationException) {
            return 'blocked';
        }
    };
}

function planRevisionMysqlTask(int $actorId, int $planId, string $reference, array $data): Closure
{
    return static function () use ($actorId, $planId, $reference, $data): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe plan revision database.');
        }
        try {
            app(ThriftPlanService::class)->revise(User::findOrFail($actorId), ThriftPlan::findOrFail($planId), $reference, $data);

            return 'revised';
        } catch (ValidationException|ConflictHttpException) {
            return 'blocked';
        }
    };
}

function planTransitionMysqlTask(int $actorId, int $planId, string $reference, array $data): Closure
{
    return static function () use ($actorId, $planId, $reference, $data): string {
        if (DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe plan lifecycle database.');
        }
        try {
            app(ThriftPlanService::class)->transition(User::findOrFail($actorId), ThriftPlan::findOrFail($planId), 'pause', $reference, $data);

            return 'paused';
        } catch (ValidationException|ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('competing plan creations preserve one open cycle and one set of slots', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $rule = FeeRule::create(['version' => 1, 'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Fixture']);
    $data = ['name' => 'Daily plan', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $data['preview_fingerprint'] = app(ThriftPlanService::class)->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $actorId = $agent->user_id;
    $customerId = $customer->id;
    $results = Concurrency::driver('process')->run([
        planMysqlTask($actorId, $customerId, (string) Str::uuid(), $data),
        planMysqlTask($actorId, $customerId, (string) Str::uuid(), $data),
    ]);

    expect($results)->toContain('created')->toContain('blocked');
    expect(ThriftPlan::count())->toBe(1);
    expect(DB::table('contribution_slots')->count())->toBe(2);
});

test('competing receipts cannot exceed a slot and keep balanced postings', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $base = $this->lifecycleCollectionPayload($customer, $plan);
    $base['savings_ngn'] = '2000.00';
    $base['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $base)['preview_fingerprint'];
    $actorId = $agent->user_id;
    $customerId = $customer->id;
    $results = Concurrency::driver('process')->run([
        receiptMysqlTask($actorId, $customerId, (string) Str::uuid(), $base),
        receiptMysqlTask($actorId, $customerId, (string) Str::uuid(), $base),
    ]);

    expect($results)->toContain('posted')->toContain('blocked');
    expect(DB::table('collection_receipts')->count())->toBe(1);
    expect((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(200000);
    expect(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1);
});

test('receipt posting and month close serialize to one valid outcome', function (): void {
    $period = FinancialPeriod::query()->sole();
    $month = $period->month->format('Y-m');
    $nextMonth = $period->month->startOfMonth()->addMonth()->setTime(12, 0);
    $this->travelTo($nextMonth);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::FinancialPeriodsManage);
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['received_date'] = $period->month->endOfMonth()->toDateString();
    $payload['late_reason'] = 'Cash received at the previous month end.';
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $actorId = $agent->user_id;
    $adminId = $admin->id;
    $customerId = $customer->id;
    $reference = (string) Str::uuid();
    $at = $nextMonth->toIso8601String();

    $results = Concurrency::driver('process')->run([
        receiptMysqlTask($actorId, $customerId, $reference, $payload, $at),
        periodCloseMysqlTask($adminId, $month, $at),
    ]);

    expect($results)->toContain('blocked');
    expect(collect($results)->filter(fn (string $result): bool => $result !== 'blocked'))->toHaveCount(1);
    $posted = DB::table('collection_receipts')->count();
    expect($posted)->toBe($results[0] === 'posted' ? 1 : 0)
        ->and($period->fresh()->status)->toBe($posted === 1 ? 'open' : 'closed')
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe($posted)
        ->and(DB::table('collection_allocations')->count())->toBe($posted);
});

test('exactly fitting independent cash attempts both post after a stale review is refreshed', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $base = $this->lifecycleCollectionPayload($customer, $plan);
    $base['savings_ngn'] = '1000.00';
    $base['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $base)['preview_fingerprint'];
    $references = [(string) Str::uuid(), (string) Str::uuid()];
    $results = Concurrency::driver('process')->run([
        receiptMysqlTask($agent->user_id, $customer->id, $references[0], $base),
        receiptMysqlTask($agent->user_id, $customer->id, $references[1], $base),
    ]);
    expect($results)->toContain('posted');

    foreach ($results as $index => $result) {
        if ($result === 'posted') {
            continue;
        }
        $fresh = [...$base, 'plan_version' => $plan->fresh()->version];
        $fresh['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $fresh)['preview_fingerprint'];
        app(CollectionService::class)->record($agent->user, $customer, [
            ...$fresh, 'attempt_reference' => $references[$index],
        ]);
    }

    expect(DB::table('collection_receipts')->count())->toBe(2)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(200000)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(2);
    $entryTotals = DB::table('ledger_entries as entries')
        ->join('ledger_posting_groups as groups', 'groups.id', '=', 'entries.ledger_posting_group_id')
        ->where('groups.event_type', 'cash_contribution')
        ->selectRaw("SUM(CASE WHEN entries.side = 'debit' THEN entries.amount_kobo ELSE 0 END) as debit_kobo, SUM(CASE WHEN entries.side = 'credit' THEN entries.amount_kobo ELSE 0 END) as credit_kobo")
        ->first();
    expect((int) $entryTotals->debit_kobo)->toBe(200000)
        ->and((int) $entryTotals->credit_kobo)->toBe(200000);
    expect(DB::table('collection_allocations')->distinct()->count('contribution_slot_id'))->toBe(1)
        ->and(DB::table('collection_receipts')->distinct()->count('recorded_by_user_id'))->toBe(1);
});

test('collection and a Customer restriction serialize without partial money', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];

    $results = Concurrency::driver('process')->run([
        restrictCustomerMysqlTask($admin->id, $customer->id, $customer->version),
        receiptMysqlTask($agent->user_id, $customer->id, (string) Str::uuid(), $payload),
    ]);

    expect($results[0])->toBe('restricted')
        ->and($results[1])->toBeIn(['posted', 'blocked'])
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted);
    $receiptCount = $results[1] === 'posted' ? 1 : 0;
    expect(DB::table('collection_receipts')->count())->toBe($receiptCount)
        ->and(DB::table('collection_allocations')->count())->toBe($receiptCount)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe($receiptCount)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe($receiptCount * 100000);
    if ($receiptCount === 1) {
        expect((int) DB::table('collection_receipts')->value('recorded_by_user_id'))->toBe($agent->user_id)
            ->and((int) DB::table('collection_receipts')->value('assignment_id'))->toBe($customer->currentAssignment->id);
    }
});

test('mysql Admin readiness uses canonical Agent eligibility and preserves covered money', function (string $readiness, bool $eligible): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2500.00'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $payload);
    $user = $agent->user;
    if ($readiness === 'missing-confirmation') {
        $user->forceFill(['two_factor_confirmed_at' => null])->save();
    } elseif ($readiness === 'wrong-role') {
        $user->syncRoles('admin');
    } else {
        $user->forceFill(['locked_until' => now()->addHour(), 'lock_category' => 'password'])->save();
    }

    expect(app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($user->fresh()))->toBe($eligible);
    $work = app(CollectionWorkspaceService::class)->dueWork($admin, '2026-10-06', '2026-10-06', '', 'all');
    expect($work['slots']->items()[0]['status'])->toBe($eligible ? 'partial' : 'service-interrupted')
        ->and($work['totals']['covered_kobo'])->toBe(50000)
        ->and($work['totals']['outstanding_kobo'])->toBe($eligible ? 150000 : 0)
        ->and(DB::table('collection_receipts')->count())->toBe(1)
        ->and(DB::table('collection_annotations')->count())->toBe(0);
})->with([
    'MFA confirmation missing' => ['missing-confirmation', false],
    'Synchronized role wrong' => ['wrong-role', false],
    'Established-session temporary lock' => ['temporary-lock', true],
]);

test('mysql assigned Agent interruption preserves covered money outside actionable outstanding', function (bool $delayed, bool $reassign, bool $suspended, bool $missingPrefix = false): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::AgentsManage);
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2500.00'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $payload);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Africa/Lagos'));
    $statuses = app(AgentStatusManagementService::class);
    if ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
        $agent->refresh();
    } else {
        $agent = $statuses->transition($admin, $agent, AgentStatus::Inactive, $agent->version,
            'Reviewed service interruption.', 'Your service work is temporarily unavailable.');
    }
    $workspace = app(CollectionWorkspaceService::class);

    $work = $workspace->dueWork($admin, '2026-10-06', '2026-10-06', '', 'service-interrupted');
    expect($work['slots']->total())->toBe(1)->and($work['slots']->items()[0]['status'])->toBe('service-interrupted')
        ->and($work['totals']['covered_kobo'])->toBe(50000)->and($work['totals']['outstanding_kobo'])->toBe(0)
        ->and($work['totals']['service_interrupted_target_kobo'])->toBe(200000);
    if ($suspended) {
        $this->assertDatabaseHas('agent_lifecycle_histories', ['agent_profile_id' => $agent->id,
            'to_account_state' => 'suspended', 'created_at' => '2026-10-06 11:00:00']);
        expect($agent->operational_status)->toBe(AgentStatus::Active);
    } else {
        $this->assertDatabaseHas('agent_status_histories', ['agent_profile_id' => $agent->id,
            'to_status' => 'inactive', 'created_at' => '2026-10-06 11:00:00']);
    }
    if ($delayed) {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Africa/Lagos'));
    }
    if ($reassign) {
        $admin->givePermissionTo(AdminPermission::CustomersReassign);
        $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        $handover = app(CustomerReassignmentService::class);
        $preview = $handover->preview($admin, $customer->fresh(), $replacement->id);
        $handover->execute($admin, $customer->fresh(), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
            'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
            'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed service transfer.',
            'customer_explanation' => 'Your service contact has changed.']);
    } elseif ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    } else {
        $statuses->transition($admin, $agent, AgentStatus::Active, $agent->version,
            'Reviewed service restoration.', 'Your service work is available again.');
    }
    if ($missingPrefix) {
        $historyTable = $suspended ? 'agent_lifecycle_histories' : 'agent_status_histories';
        $noticeTable = $suspended ? 'agent_lifecycle_notification_intents' : 'agent_status_notification_intents';
        $historyColumn = $suspended ? 'agent_lifecycle_history_id' : 'agent_status_history_id';
        $firstHistory = DB::table($historyTable)->where('agent_profile_id', $agent->id)->orderBy('created_at')->orderBy('id')->value('id');
        DB::table($noticeTable)->where($historyColumn, $firstHistory)->delete();
        DB::table($historyTable)->where('id', $firstHistory)->delete();
    }
    $restored = $workspace->dueWork($admin, '2026-10-06', $delayed ? '2026-10-08' : '2026-10-06', '', 'all');
    expect($restored['slots']->items()[0]['status'])->toBe($missingPrefix ? 'unavailable' : ($delayed ? 'service-interrupted' : 'partial'))
        ->and($restored['totals']['outstanding_kobo'])->toBe($delayed ? 0 : 150000)
        ->and($restored['totals']['covered_kobo'])->toBe(50000)
        ->and(DB::table('collection_receipts')->count())->toBe(1)
        ->and(DB::table('collection_annotations')->count())->toBe(0);
})->with([
    'Same-day operational restoration' => [false, false, false],
    'Historical operational restoration' => [true, false, false],
    'Historical operational reassignment' => [true, true, false],
    'Same-day account restoration' => [false, false, true],
    'Historical account restoration' => [true, false, true],
    'Suspended-Agent reassignment' => [true, true, true],
    'Missing operational prefix' => [true, false, false, true],
    'Missing account prefix' => [true, false, true, true],
]);

test('mysql retained Agent suffix disagreement preserves funding outside actionable totals', function (bool $suspended, bool $recordedActive): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    if (! FinancialPeriod::query()->where('month', '2026-10-01')->exists()) {
        FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    }
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::AgentsManage);
    $agent->user->forceFill(['recovery_codes_acknowledged_at' => now()])->save();
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2500.00'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $payload);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Africa/Lagos'));
    if ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent),
            $this->agentLifecycleRequest($admin));
        $agent->refresh();
        if ($recordedActive) {
            app(AgentLifecycleService::class)->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent),
                $this->agentLifecycleRequest($admin));
        }
        DB::table('users')->where('id', $agent->user_id)->update(['account_state' => $recordedActive ? 'suspended' : 'active']);
    } else {
        $statuses = app(AgentStatusManagementService::class);
        $agent = $statuses->transition($admin, $agent, AgentStatus::Inactive, $agent->version,
            'Reviewed service interruption.', 'Your original schedule remains.');
        if ($recordedActive) {
            $agent = $statuses->transition($admin, $agent, AgentStatus::Active, $agent->version,
                'Reviewed service restoration.', 'Your original schedule remains.');
        }
        DB::table('agent_profiles')->where('id', $agent->id)->update(['operational_status' => $recordedActive ? 'inactive' : 'active']);
    }
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'customer_assignments', 'agent_profiles',
        'agent_status_histories', 'agent_lifecycle_histories', 'agent_status_notification_intents', 'agent_lifecycle_notification_intents',
        'plan_lifecycle_events', 'collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups',
        'ledger_entries', 'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations', 'collection_annotations'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $workspace = app(CollectionWorkspaceService::class);
    $paid = $workspace->dueWork($admin, '2026-10-05', '2026-10-06', '', 'all');
    expect($paid['slots']->items()[0]['status'])->toBe('paid');
    expect($paid['totals']['covered_kobo'])->toBe(200000);
    $work = $workspace->dueWork($admin, '2026-10-06', '2026-10-06', '', 'all');
    expect($work['slots']->items()[0]['status'])->toBe('unavailable');
    expect($work['totals']['covered_kobo'])->toBe(50000);
    expect($work['totals']['outstanding_kobo'])->toBe(0);
    expect($work['totals']['unavailable_target_kobo'])->toBe(200000);
    expect($workspace->dueWork($admin, '2026-10-06', '2026-10-06', '', 'missed')['slots']->total())->toBe(0);
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'unavailable']);
    expect(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000]);
    expect($agent->user->fresh()->account_state->value)->toBe($suspended && $recordedActive ? 'suspended' : 'active');
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Operational recorded inactive' => [false, false], 'Operational recorded restored' => [false, true],
    'Account recorded suspended' => [true, false], 'Account recorded restored' => [true, true]]);

test('mysql legacy participation uncertainty retains verified partial funding outside actionable totals', function (string $owner, bool $retainedHistory): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    if ($retainedHistory) {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Africa/Lagos'));
        if ($owner === 'customer') {
            $statuses = app(CustomerStatusManagementService::class);
            $customer = $statuses->transition($admin, $customer, CustomerStatus::Inactive, $customer->version,
                'Earlier reviewed interruption.', 'Original dates remain.');
            $customer = $statuses->transition($admin, $customer, CustomerStatus::Active, $customer->version,
                'Earlier reviewed restoration.', 'Original dates remain.');
        } else {
            foreach (['pause', 'resume'] as $action) {
                app(ThriftPlanService::class)->transition($agent->user, $plan, $action, (string) Str::uuid(), [
                    'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
                    'plan_version' => $plan->version, 'reason' => 'Earlier reviewed participation transition.',
                    'customer_explanation' => 'Original dates remain.']);
                $plan->refresh();
            }
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    }
    $payload = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2500.00'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $payload);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Africa/Lagos'));
    if ($owner === 'customer') {
        DB::table('customer_profiles')->where('id', $customer->id)->update(['operational_status' => 'inactive']);
        $customer->refresh();
        app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Active,
            $customer->version, 'Current legacy status restored after review.', 'Original contributions remain.');
    } else {
        DB::table('thrift_plans')->where('id', $plan->id)->update(['status' => 'paused']);
        $plan->refresh();
        app(ThriftPlanService::class)->transition($agent->user, $plan, 'resume', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Current legacy cycle resumes after review.', 'customer_explanation' => 'Original dates remain.']);
    }
    $workspace = app(CollectionWorkspaceService::class);
    $unknown = $workspace->dueWork($agent->user, '2026-10-06', '2026-10-08', '', 'all');
    $paid = $workspace->dueWork($agent->user, '2026-10-05', '2026-10-08', '', 'all');
    expect($unknown['slots']->items()[0]['status'])->toBe('unavailable')
        ->and($unknown['totals']['covered_kobo'])->toBe(50000)
        ->and($unknown['totals']['outstanding_kobo'])->toBe(0)
        ->and($unknown['totals']['unavailable_target_kobo'])->toBe(200000)
        ->and($paid['slots']->items()[0]['status'])->toBe('paid')
        ->and($paid['totals']['unavailable_target_kobo'])->toBe(0)
        ->and(app(CollectionReadService::class)->fundingCard($plan->fresh())['funded_kobo'])->toBe(250000)
        ->and(DB::table('collection_receipts')->count())->toBe(1)
        ->and(DB::table('collection_annotations')->count())->toBe(0);
})->with([
    'Missing-prefix Customer restoration' => ['customer', false],
    'Missing-prefix plan resume' => ['plan', false],
    'Contradictory Customer history' => ['customer', true],
    'Contradictory plan history' => ['plan', true],
]);

test('mysql historical due work excludes restored Customer and resumed plan blocking intervals', function (string $owner): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $rule = FeeRule::create(['version' => 1, 'name' => 'No fee history fixture', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'TEST FIXTURE agreed current terms.']);
    $originalData = ['name' => 'Lagos captured fixture', 'amount_ngn' => '2000.00', 'start_date' => '2026-10-05',
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $originalData['preview_fingerprint'] = app(ThriftPlanService::class)->preview($agent->user, $customer, $originalData)['preview_fingerprint'];
    $plan = app(ThriftPlanService::class)->create($agent->user, $customer, (string) Str::uuid(), $originalData)['plan'];
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $payload);
    $plan->refresh();
    [, $foreignCustomer, $foreignAgent] = $this->createLifecycleFixture();
    BusinessProfile::current()->update(['timezone' => 'America/New_York']);
    $rule = $plan->currentTermsRevision()->feeSnapshot->feeRule;
    $newData = ['name' => 'New York captured fixture', 'amount_ngn' => '2000.00', 'start_date' => '2026-10-05',
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $foreignCustomer->version, 'assignment_version' => $foreignCustomer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $newData['preview_fingerprint'] = app(ThriftPlanService::class)->preview($foreignAgent->user, $foreignCustomer, $newData)['preview_fingerprint'];
    app(ThriftPlanService::class)->create($foreignAgent->user, $foreignCustomer, (string) Str::uuid(), $newData);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 00:30:00', 'Africa/Lagos'));
    if ($owner === 'customer') {
        $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Restricted,
            $customer->version, 'Owner review blocks participation.', 'Original contributions remain.');
    } else {
        app(ThriftPlanService::class)->transition($agent->user, $plan, 'pause', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Customer requested this dated pause.', 'customer_explanation' => 'Original dates remain.']);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-07 00:30:00', 'Africa/Lagos'));
    if ($owner === 'customer') {
        app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Active,
            $customer->version, 'Review restores participation.', 'Original remaining targets remain.');
    } else {
        $plan->refresh();
        app(ThriftPlanService::class)->transition($agent->user, $plan, 'resume', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Customer reviewed the retained schedule.', 'customer_explanation' => 'Resume original dates.']);
    }
    $workspace = app(CollectionWorkspaceService::class);
    $blocked = $workspace->dueWork($agent->user, '2026-10-06', '2026-10-07', '', 'all');
    $partial = $workspace->dueWork($agent->user, '2026-10-05', '2026-10-07', '', 'all');
    $mixed = $workspace->dueWork($admin, '2026-10-06', '2026-10-07', '', 'all');
    expect($mixed['slots']->total())->toBe(2)
        ->and($mixed['totals']['blocked_target_kobo'])->toBe(200000)
        ->and($mixed['totals']['outstanding_kobo'])->toBe(200000);
    expect($blocked['slots']->total())->toBe(1)
        ->and($blocked['slots']->items()[0]['status'])->toBe('blocked')
        ->and($blocked['totals']['outstanding_kobo'])->toBe(0)
        ->and($partial['slots']->items()[0]['status'])->toBe('partial')
        ->and($partial['totals']['covered_kobo'])->toBe(100000)
        ->and($plan->slots()->count())->toBe(2)
        ->and(DB::table('collection_receipts')->count())->toBe(1)
        ->and(DB::table('collection_annotations')->count())->toBe(0);
})->with(['Customer status owner' => 'customer', 'Plan lifecycle owner' => 'plan']);

test('collection and a plan pause serialize without a partial receipt', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $pause = ['customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Customer requested a pause.',
        'customer_explanation' => 'Your plan is paused.'];

    $results = Concurrency::driver('process')->run([
        planTransitionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $pause),
        receiptMysqlTask($agent->user_id, $customer->id, (string) Str::uuid(), $payload),
    ]);

    expect($results)->toContain('blocked');
    expect(in_array('paused', $results, true) || in_array('posted', $results, true))->toBeTrue();
    $receiptCount = $results[1] === 'posted' ? 1 : 0;
    expect(DB::table('collection_receipts')->count())->toBe($receiptCount)
        ->and(DB::table('collection_allocations')->count())->toBe($receiptCount)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe($receiptCount)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe($receiptCount * 100000);
    expect($plan->fresh()->status->value)->toBe($results[0] === 'paused' ? 'paused' : 'active');
});

test('competing plan revisions append one new terms version', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = ['name' => 'Revised daily plan', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 3,
        'customer_visible_notes' => '', 'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
        'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'customer_agreement_attested' => true,
        'reason' => 'Customer agreed to one more day.', 'customer_explanation' => 'Three-day plan.'];
    $data['preview_fingerprint'] = app(ThriftPlanService::class)->previewRevision($agent->user, $plan, $data)['preview_fingerprint'];
    $results = Concurrency::driver('process')->run([
        planRevisionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $data),
        planRevisionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $data),
    ]);

    expect($results)->toContain('revised')->toContain('blocked');
    expect($plan->fresh()->current_terms_revision)->toBe(2);
    expect($plan->termsRevisions()->count())->toBe(2);
    expect($plan->slots()->whereNotNull('active_ordinal')->count())->toBe(3);
});

test('competing plan pauses preserve one lifecycle transition', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = ['customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Customer requested a pause.',
        'customer_explanation' => 'Your plan is paused.'];
    $results = Concurrency::driver('process')->run([
        planTransitionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $data),
        planTransitionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $data),
    ]);

    expect($results)->toContain('paused')->toContain('blocked');
    expect($plan->fresh()->status->value)->toBe('paused');
    expect(DB::table('plan_lifecycle_events')->where('thrift_plan_id', $plan->id)->where('event_type', 'pause')->count())->toBe(1);
});

test('mysql positive cycle fee survives descriptive revision and later funding', function (string $timing): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Once per cycle fixture', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'fixed', 'timing' => $timing, 'basis' => 'none', 'settlement_source' => 'savings_application',
        'currency' => 'NGN', 'amount_kobo' => 10000, 'customer_description' => 'One hundred naira once per cycle.',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $actor->id, 'publication_reason' => 'Isolated fixture.']);
    $data = ['name' => 'Original agreement', 'amount_ngn' => '2000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 3, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->preview($actor, $customer, $data)['preview_fingerprint'];
    $plan = $service->create($actor, $customer, (string) Str::uuid(), $data)['plan'];
    $snapshotId = $plan->currentTermsRevision()->fee_snapshot_id;
    $collection = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2000.00'];
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $collection)['preview_fingerprint'];
    app(CollectionService::class)->record($actor, $customer, $collection);
    $plan->refresh();
    $revision = [...$data, 'name' => 'Clarified agreement', 'plan_version' => $plan->version, 'terms_revision' => 1,
        'reason' => 'Customer confirms the clearer name.', 'customer_explanation' => 'Financial terms remain unchanged.'];
    $revision['preview_fingerprint'] = $service->previewRevision($actor, $plan, $revision)['preview_fingerprint'];
    $plan = $service->revise($actor, $plan, (string) Str::uuid(), $revision);
    $next = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '4000.00'];
    $next['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $next)['preview_fingerprint'];
    app(CollectionService::class)->record($actor, $customer, $next);
    $plan->refresh();
    $terms = $plan->currentTermsRevision();
    expect($terms->fee_snapshot_id)->toBe($snapshotId)
        ->and(DB::table('fee_obligations')->count())->toBe(1)
        ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->sum('amount_kobo'))->toBe(10000)
        ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->sum('amount_kobo'))->toBe(10000)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(590000)
        ->and(app(PlanEstimateService::class)->forSnapshot($plan, $terms)['estimated_fee'])->toBe('₦100.00');
})->with(['first_contribution', 'cycle_completion']);

function planChargeMysqlRequest(): Request
{
    $request = Request::create('/admin/charges', 'POST');
    $session = new Store('plan-charge-race', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);

    return $request;
}

function planManualChargeMysqlTask(int $adminId, int $customerId, int $planId, int $categoryId, string $reference): Closure
{
    return static function () use ($adminId, $customerId, $planId, $categoryId, $reference): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe cycle charge race database.');
        }
        config()->set('fees.manual_charges_enabled', true);
        $request = Request::create('/admin/charges', 'POST');
        $session = new Store('plan-charge-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(ManualChargeService::class)->assess(User::findOrFail($adminId), CustomerProfile::findOrFail($customerId),
                ThriftPlan::findOrFail($planId), ChargeCategoryVersion::findOrFail($categoryId), $reference,
                1, 1, 'Independent approved service fee.', $request);

            return 'charged';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

function planCancellationMysqlTask(int $actorId, int $planId, string $reference, array $data): Closure
{
    return static function () use ($actorId, $planId, $reference, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe cycle cancellation race database.');
        }
        try {
            app(ThriftPlanService::class)->transition(User::findOrFail($actorId), ThriftPlan::findOrFail($planId), 'cancel', $reference, $data);

            return 'cancelled';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql manual cycle fee and unused cancellation serialize without abandoning an obligation', function (?string $first): void {
    config()->set('fees.manual_charges_enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $originalTerms = $plan->currentTermsRevision()->getAttributes();
    $originalSlots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $category = app(ManualChargeService::class)->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'unused-cycle-race', 'kind' => 'manual_fee', 'purpose' => 'Approved service fee',
        'customer_description' => 'Independent service fee', 'amount_kobo' => 10000], planChargeMysqlRequest());
    $charge = planManualChargeMysqlTask($admin->id, $customer->id, $plan->id, $category->id, (string) Str::uuid());
    $cancel = planCancellationMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), [
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Requested unused cycle cancellation.', 'customer_explanation' => 'Reviewed cancellation.']);
    if ($first === 'charge') {
        expect($charge())->toBe('charged');
    } elseif ($first === 'cancel') {
        expect($cancel())->toBe('cancelled');
    }
    $outcomes = Concurrency::driver('process')->run([$charge, $cancel]);
    $plan->refresh();
    if ($plan->status->value === 'cancelled') {
        expect($outcomes)->toBe(['blocked', 'cancelled'])
            ->and($plan->open_customer_profile_id)->toBeNull()
            ->and(DB::table('manual_charges')->count())->toBe(0)
            ->and(DB::table('fee_obligations')->count())->toBe(0)
            ->and(DB::table('fee_obligation_entries')->count())->toBe(0)
            ->and(DB::table('plan_operation_attempts')->where('operation_type', 'plan_cancel')->count())->toBe(1)
            ->and($plan->lifecycleEvents()->where('event_type', 'cancel')->count())->toBe(1);
    } else {
        expect($outcomes)->toBe(['charged', 'blocked'])
            ->and($plan->status->value)->toBe('active')
            ->and($plan->open_customer_profile_id)->toBe($customer->id)
            ->and(DB::table('manual_charges')->count())->toBe(1)
            ->and(DB::table('fee_obligations')->count())->toBe(1)
            ->and(DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->count())->toBe(1)
            ->and(DB::table('plan_operation_attempts')->count())->toBe(0)
            ->and($plan->lifecycleEvents()->count())->toBe(0);
    }
    expect($plan->currentTermsRevision()->getAttributes())->toBe($originalTerms)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
})->with(['concurrent' => null, 'charge committed first' => 'charge', 'cancellation committed first' => 'cancel']);

function distinctAgentPlanMysqlTask(int $actorId, int $customerId, array $data): Closure
{
    return static function () use ($actorId, $customerId, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe distinct-Agent plan race database.');
        }
        Queue::fake([DeliverPlanNotificationIntent::class]);
        try {
            app(ThriftPlanService::class)->create(User::findOrFail($actorId), CustomerProfile::findOrFail($customerId),
                $data['attempt_reference'], $data);
            Queue::assertPushed(DeliverPlanNotificationIntent::class);

            return 'created';
        } catch (ValidationException|ConflictHttpException|AuthorizationException|NotFoundHttpException) {
            Queue::assertNothingPushed();

            return 'blocked';
        } catch (Throwable $exception) {
            throw new RuntimeException($exception::class.': '.$exception->getMessage(), previous: $exception);
        }
    };
}

test('TPC-AC-008: former and current Agent creation or renewal attempts preserve one authoritative open cycle', function (bool $renewal): void {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $rule = FeeRule::create(['version' => 1, 'name' => 'Race no fee', 'kind' => 'plan', 'rule_key' => 'distinct-agent-race',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Isolated race fixture.']);
    $data = ['name' => 'Reviewed daily cycle', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => 1,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $service = app(ThriftPlanService::class);
    $predecessor = null;
    if ($renewal) {
        $data['preview_fingerprint'] = $service->preview($agent->user, $customer, $data)['preview_fingerprint'];
        $predecessor = $service->create($agent->user, $customer, (string) Str::uuid(), $data)['plan'];
        $service->transition($agent->user, $predecessor, 'cancel', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $predecessor->version, 'reason' => 'End unused agreement before renewal.',
            'customer_explanation' => 'The unused agreement has ended.']);
        $predecessor->refresh();
        $data['predecessor_plan_id'] = $predecessor->plan_id;
    }
    $old = [...$data, 'attempt_reference' => (string) Str::uuid()];
    $old['preview_fingerprint'] = $service->preview($agent->user, $customer, $old)['preview_fingerprint'];
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer, $replacement->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $quote['version'], 'assignment_version' => $quote['assignment_version'],
        'preview_token' => $quote['preview_token'], 'target_agent_id' => $replacement->id,
        'confirmed' => true, 'reason' => 'Reviewed change of service contact.',
        'customer_explanation' => 'Your current service Agent has changed.']);
    $customer = $customer->fresh();
    $current = [...$data, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'attempt_reference' => (string) Str::uuid()];
    $current['preview_fingerprint'] = $service->preview($replacement->user, $customer, $current)['preview_fingerprint'];
    $other = [...$current, 'attempt_reference' => (string) Str::uuid()];
    $oldCycle = $predecessor?->getAttributes();
    $oldTerms = $predecessor?->termsRevisions()->orderBy('id')->get()->map->getAttributes()->all();
    $oldSlots = $predecessor?->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $attemptCount = DB::table('plan_operation_attempts')->count();
    $results = Concurrency::driver('process')->run([
        distinctAgentPlanMysqlTask($agent->user_id, $customer->id, $old),
        distinctAgentPlanMysqlTask($replacement->user_id, $customer->id, $current),
        distinctAgentPlanMysqlTask($replacement->user_id, $customer->id, $other),
    ]);
    expect($results[0])->toBe('blocked');
    sort($results);
    expect($results)->toBe(['blocked', 'blocked', 'created']);
    $winner = ThriftPlan::query()->whereNotNull('open_customer_profile_id')->sole();
    expect($winner->created_by_user_id)->toBe($replacement->user_id);
    expect($winner->predecessor_plan_id)->toBe($predecessor?->id);
    expect(ThriftPlan::query()->count())->toBe($renewal ? 2 : 1);
    expect(DB::table('plan_operation_attempts')->count())->toBe($attemptCount + 1);
    expect($winner->slots()->count())->toBe(2);
    expect($winner->termsRevisions()->count())->toBe(1);
    expect(DB::table('fee_snapshots')->where('source_type', 'plan_terms_revision')
        ->where('source_id', $winner->plan_id.'-R1')->count())->toBe(1);
    expect(DB::table('plan_notification_intents')->where('thrift_plan_id', $winner->id)
        ->where('recipient_user_id', $agent->user_id)->count())->toBe(0);
    expect(DB::table('plan_notification_intents')->where('thrift_plan_id', $winner->id)
        ->where('recipient_user_id', $replacement->user_id)->count())->toBe(1);
    expect($customer->fresh()->currentAssignment->agent_profile_id)->toBe($replacement->id);
    expect($predecessor?->fresh()->getAttributes())->toBe($oldCycle);
    expect($predecessor?->termsRevisions()->orderBy('id')->get()->map->getAttributes()->all())->toBe($oldTerms);
    expect($predecessor?->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($oldSlots);
    expect(DB::table('ledger_posting_groups')->count())->toBe(0);
    expect(DB::table('collection_receipts')->count())->toBe(0);
    Queue::assertPushed(DeliverCustomerHandoverNotice::class);
})->with(['first creation' => false, 'cancelled-cycle renewal' => true]);

test('mysql participation suffix disagreement excludes uncertain residuals without losing posted funding', function (string $owner, bool $recordedActive): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    $month = now('Africa/Lagos')->startOfMonth()->toDateString();
    if (! FinancialPeriod::query()->where('month', $month)->exists()) {
        FinancialPeriod::factory()->create(['month' => $month]);
    }
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2500.00'];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $data);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Africa/Lagos'));
    if ($owner === 'customer') {
        $service = app(CustomerStatusManagementService::class);
        $customer = $service->transition($admin, $customer, CustomerStatus::Inactive, $customer->version,
            'Reviewed interruption.', 'Original dates remain.');
        if ($recordedActive) {
            $customer = $service->transition($admin, $customer, CustomerStatus::Active, $customer->version,
                'Reviewed restoration.', 'Original dates remain.');
        }
        DB::table('customer_profiles')->where('id', $customer->id)->update([
            'operational_status' => $recordedActive ? 'inactive' : 'active',
        ]);
    } else {
        foreach ($recordedActive ? ['pause', 'resume'] : ['pause'] as $action) {
            $plan->refresh();
            app(ThriftPlanService::class)->transition($agent->user, $plan, $action, (string) Str::uuid(), [
                'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
                'plan_version' => $plan->version, 'reason' => 'Reviewed participation change.',
                'customer_explanation' => 'Original dates remain.']);
        }
        DB::table('thrift_plans')->where('id', $plan->id)->update(['status' => $recordedActive ? 'paused' : 'active']);
    }
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'customer_status_histories',
        'plan_lifecycle_events', 'collection_receipts', 'collection_allocations', 'ledger_posting_groups',
        'ledger_entries', 'fee_obligations', 'fee_obligation_entries', 'collection_annotations'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Africa/Lagos'));
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'unavailable']);
    expect(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000]);
    $workspace = app(CollectionWorkspaceService::class);
    $work = $workspace->dueWork($agent->user, '2026-10-06', '2026-10-08', '', 'all');
    expect($work['slots']->items()[0]['status'])->toBe('unavailable');
    expect($work['totals']['covered_kobo'])->toBe(50000);
    expect($work['totals']['outstanding_kobo'])->toBe(0);
    expect($work['totals']['unavailable_target_kobo'])->toBe(200000);
    expect($workspace->dueWork($agent->user, '2026-10-06', '2026-10-08', '', 'missed')['slots']->total())->toBe(0);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    Queue::assertPushed(DeliverCollectionNotificationIntent::class);
})->with(['Customer recorded blocked' => ['customer', false], 'Customer recorded restored' => ['customer', true],
    'plan recorded paused' => ['plan', false], 'plan recorded resumed' => ['plan', true]]);

test('mysql first manual fee and monetary amendment preserve current terms across stale or concurrent owners', function (?bool $feeFirst): void {
    config()->set('fees.manual_charges_enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    Queue::fake();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $rule = FeeRule::create(['version' => 1, 'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Isolated agreement fixture.']);
    $terms = ['name' => 'Original unused agreement', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2, 'customer_visible_notes' => '',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1, 'customer_agreement_attested' => true];
    $service = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $service->preview($agent->user, $customer, $terms)['preview_fingerprint'];
    $plan = $service->create($agent->user, $customer, (string) Str::uuid(), $terms)['plan'];
    $revision = [...$terms, 'amount_ngn' => '2500.00', 'contribution_days' => 3,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'reason' => 'Reviewed changed agreement.',
        'customer_explanation' => 'Customer agreed to changed monetary and schedule terms.'];
    $revision['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $revision)['preview_fingerprint'];
    $category = app(ManualChargeService::class)->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'first-cycle-fee-lock', 'kind' => 'manual_fee', 'purpose' => 'Reviewed independent service fee',
        'customer_description' => 'Independent service fee', 'amount_kobo' => 10000], planChargeMysqlRequest());
    $charge = planManualChargeMysqlTask($admin->id, $customer->id, $plan->id, $category->id, (string) Str::uuid());
    $revise = planRevisionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $revision);
    $originalTerms = $plan->currentTermsRevision()->getAttributes();
    $originalSlots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    if ($feeFirst === null) {
        $outcomes = Concurrency::driver('process')->run([$charge, $revise]);
        expect($outcomes)->toBeIn([['charged', 'blocked'], ['blocked', 'revised']]);
        $feeFirst = $outcomes[0] === 'charged';
    } else {
        DB::beginTransaction();
        try {
            expect($service->hasCycleActivity($plan))->toBeFalse();
            expect(Concurrency::driver('process')->run([$feeFirst ? $charge : $revise]))->toBe([$feeFirst ? 'charged' : 'revised']);
            expect(($feeFirst ? $revise : $charge)())->toBe('blocked');
        } finally {
            DB::rollBack();
        }
    }
    expect($plan->fresh()->current_terms_revision)->toBe($feeFirst ? 1 : 2);
    expect(DB::table('manual_charges')->count())->toBe($feeFirst ? 1 : 0);
    expect(DB::table('fee_obligations')->count())->toBe($feeFirst ? 1 : 0);
    expect(DB::table('ledger_posting_groups')->count())->toBe(0);
    if ($feeFirst) {
        expect($plan->fresh()->currentTermsRevision()->getAttributes())->toBe($originalTerms);
        expect($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots);
        $descriptive = [...$terms, 'name' => 'Clarified original agreement', 'plan_version' => 1, 'terms_revision' => 1,
            'reason' => 'Clarify the retained agreement name.', 'customer_explanation' => 'Original money and dates retained.'];
        $descriptive['preview_fingerprint'] = $service->previewRevision($agent->user, $plan->fresh(), $descriptive)['preview_fingerprint'];
        $reference = (string) Str::uuid();
        $service->revise($agent->user, $plan->fresh(), $reference, $descriptive);
        expect($plan->fresh()->current_terms_revision)->toBe(2);
        expect($plan->fresh()->currentTermsRevision()->contribution_amount_kobo)->toBe(200000);
        expect($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots);
        $baseline = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
        expect($service->revise($agent->user, $plan->fresh(), $reference, $descriptive)->current_terms_revision)->toBe(2);
        expect($charge())->toBe('charged');
        expect(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($baseline);
        expect($plan->termsRevisions()->count())->toBe(2);
    }
})->with(['committed fee first' => true, 'committed amendment first' => false, 'concurrent owners' => null]);

test('mysql first receipt and monetary amendment preserve one agreed allocation source across current owners', function (?bool $receiptFirst): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    Queue::fake();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Receipt race no fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Isolated reviewed agreement fixture.']);
    $terms = ['name' => 'Original receipt agreement', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2, 'customer_visible_notes' => '',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1, 'customer_agreement_attested' => true];
    $service = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $service->preview($agent->user, $customer, $terms)['preview_fingerprint'];
    $plan = $service->create($agent->user, $customer, (string) Str::uuid(), $terms)['plan'];
    $revision = [...$terms, 'amount_ngn' => '2500.00', 'contribution_days' => 3,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'reason' => 'Reviewed changed agreement.',
        'customer_explanation' => 'Customer agreed to changed monetary and schedule terms.'];
    $revision['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $revision)['preview_fingerprint'];
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $receipt = receiptMysqlTask($agent->user_id, $customer->id, $payload['attempt_reference'], $payload);
    $revise = planRevisionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $revision);
    $originalTerms = $plan->currentTermsRevision()->getAttributes();
    $originalSlots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    if ($receiptFirst === null) {
        $outcomes = Concurrency::driver('process')->run([$receipt, $revise]);
        expect($outcomes)->toBeIn([['posted', 'blocked'], ['blocked', 'revised']]);
        $receiptFirst = $outcomes[0] === 'posted';
    } else {
        DB::beginTransaction();
        try {
            expect($service->hasCycleActivity($plan))->toBeFalse();
            expect(Concurrency::driver('process')->run([$receiptFirst ? $receipt : $revise]))->toBe([$receiptFirst ? 'posted' : 'revised']);
            expect(($receiptFirst ? $revise : $receipt)())->toBe('blocked');
        } finally {
            DB::rollBack();
        }
    }
    $plan->refresh();
    expect($plan->version)->toBe(2);
    expect($plan->current_terms_revision)->toBe($receiptFirst ? 1 : 2);
    expect($plan->termsRevisions()->where('revision', 1)->firstOrFail()->getAttributes())->toBe($originalTerms);
    expect(DB::table('collection_receipts')->count())->toBe($receiptFirst ? 1 : 0);
    expect(DB::table('collection_allocations')->count())->toBe($receiptFirst ? 1 : 0);
    expect(DB::table('ledger_posting_groups')->count())->toBe($receiptFirst ? 1 : 0);
    expect(DB::table('ledger_entries')->count())->toBe($receiptFirst ? 2 : 0);
    expect(DB::table('fee_obligations')->count())->toBe(0);
    expect(DB::table('withdrawal_reservations')->count())->toBe(0);
    if ($receiptFirst) {
        expect($plan->activity_started_at)->not->toBeNull();
        expect($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots);
        expect((int) DB::table('collection_receipts')->value('savings_amount_kobo'))->toBe(100000);
        expect((int) DB::table('collection_allocations')->value('contribution_slot_id'))->toBe($originalSlots[0]['id']);
        expect((int) DB::table('collection_allocations')->value('amount_kobo'))->toBe(100000);
        foreach (['debit', 'credit'] as $side) {
            expect((int) DB::table('ledger_entries')->where('side', $side)->sum('amount_kobo'))->toBe(100000);
        }
        expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(100000);
    } else {
        expect($plan->activity_started_at)->toBeNull();
        expect($plan->currentTermsRevision()->contribution_amount_kobo)->toBe(250000);
        expect($plan->currentTermsRevision()->expected_gross_kobo)->toBe(750000);
        expect($plan->slots()->whereNotNull('active_ordinal')->orderBy('active_ordinal')->pluck('expected_amount_kobo')->all())->toBe([250000, 250000, 250000]);
        foreach ($originalSlots as $originalSlot) {
            $retained = $plan->slots()->whereKey($originalSlot['id'])->firstOrFail();
            expect($retained->active_ordinal)->toBeNull();
            expect($retained->due_date)->toBe($originalSlot['due_date']);
            expect($retained->expected_amount_kobo)->toBe(200000);
            expect($retained->plan_terms_revision_id)->toBe($originalSlot['plan_terms_revision_id']);
        }
    }
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_operation_attempts', 'plan_lifecycle_events',
        'fee_snapshots', 'fee_obligations', 'collection_receipts', 'collection_allocations', 'collection_batches',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_reservations'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    expect(($receiptFirst ? $receipt : $revise)())->toBe($receiptFirst ? 'posted' : 'revised');
    expect(($receiptFirst ? $revise : $receipt)())->toBe('blocked');
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['committed receipt first' => true, 'committed amendment first' => false, 'concurrent owners' => null]);

/** @param array<string, mixed> $data */
function planReservationMysqlTask(int $agentId, int $customerId, array $data): Closure
{
    return static function () use ($agentId, $customerId, $data): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe plan reservation race database.');
        }
        config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
        Queue::fake();
        try {
            app(WithdrawalService::class)->submit(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $data);

            return 'reserved';
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        }
    };
}

test('mysql first reservation and descriptive revision retain the original monetary and fee agreement', function (?bool $reservationFirst): void {
    config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    Queue::fake();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Retained fixed withdrawal fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'fixed', 'timing' => 'withdrawal', 'basis' => 'none', 'settlement_source' => 'withdrawal_payout',
        'currency' => 'NGN', 'amount_kobo' => 600, 'customer_description' => 'Six naira once per cycle', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Isolated existing withdrawal fee agreement.']);
    $terms = ['name' => 'Original funded agreement', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2, 'customer_visible_notes' => '',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $plans->preview($agent->user, $customer, $terms)['preview_fingerprint'];
    $plan = $plans->create($agent->user, $customer, (string) Str::uuid(), $terms)['plan'];
    $collection = $this->lifecycleCollectionPayload($customer, $plan);
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $collection)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $collection);
    $plan->refresh();
    $originalTerms = $plan->currentTermsRevision()->getAttributes();
    $originalSnapshot = $plan->currentTermsRevision()->feeSnapshot->getAttributes();
    $originalSlots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $financialRows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
        'fee_snapshots', 'fee_obligations', 'fee_obligation_entries'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $revision = [...$terms, 'name' => 'Clarified funded agreement', 'customer_visible_notes' => 'Original monetary agreement remains.',
        'plan_version' => $plan->version, 'terms_revision' => 1, 'reason' => 'Clarify Customer-visible service details.',
        'customer_explanation' => 'Your original money, fee and schedule remain.'];
    $revision['preview_fingerprint'] = $plans->previewRevision($agent->user, $plan, $revision)['preview_fingerprint'];
    $revise = planRevisionMysqlTask($agent->user_id, $plan->id, (string) Str::uuid(), $revision);
    $instruction = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00', 'method' => 'cash',
        'destination_reference' => 'customer:'.$customer->id, 'reason' => 'Reviewed partial savings request.'];
    $withdrawals = app(WithdrawalService::class);
    $quote = $withdrawals->preview($agent->user, $customer, $instruction);
    expect($quote['gross_kobo'])->toBe(30000);
    expect($quote['fee_kobo'])->toBe(600);
    expect($quote['net_kobo'])->toBe(29400);
    $quotedFields = ['customer_version', 'assignment_version', 'plan_version', 'business_version', 'quote_expires_at', 'preview_fingerprint'];
    $payload = [...$instruction, ...array_intersect_key($quote, array_flip($quotedFields)), 'attempt_reference' => (string) Str::uuid()];
    $reserve = planReservationMysqlTask($agent->user_id, $customer->id, $payload);
    if ($reservationFirst === null) {
        $outcomes = Concurrency::driver('process')->run([$reserve, $revise]);
        expect($outcomes)->toBeIn([['reserved', 'revised'], ['blocked', 'revised']]);
        $reservationFirst = $outcomes[0] === 'reserved';
    } else {
        DB::beginTransaction();
        try {
            expect((int) DB::table('thrift_plans')->where('id', $plan->id)->value('version'))->toBe(2);
            expect(Concurrency::driver('process')->run([$reservationFirst ? $reserve : $revise]))->toBe([$reservationFirst ? 'reserved' : 'revised']);
            expect(($reservationFirst ? $revise : $reserve)())->toBe($reservationFirst ? 'revised' : 'blocked');
            if ($reservationFirst) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
    }
    if (! $reservationFirst) {
        expect(DB::table('withdrawal_requests')->count())->toBe(0);
        expect(DB::table('withdrawal_reservations')->count())->toBe(0);
        $freshQuote = $withdrawals->preview($agent->user, $customer, $instruction);
        expect($freshQuote['plan_version'])->toBe(3);
        expect($freshQuote['fee_snapshot_id'])->toBe($quote['fee_snapshot_id']);
        expect($freshQuote['gross_kobo'])->toBe(30000);
        expect($freshQuote['fee_kobo'])->toBe(600);
        expect($freshQuote['net_kobo'])->toBe(29400);
        $payload = [...$instruction, ...array_intersect_key($freshQuote, array_flip($quotedFields)), 'attempt_reference' => (string) Str::uuid()];
        $reserve = planReservationMysqlTask($agent->user_id, $customer->id, $payload);
        expect($reserve())->toBe('reserved');
    }
    $plan->refresh();
    expect($plan->version)->toBe(3);
    expect($plan->current_terms_revision)->toBe(2);
    expect($plan->currentTermsRevision()->name)->toBe('Clarified funded agreement');
    expect($plan->currentTermsRevision()->customer_visible_notes)->toBe('Original monetary agreement remains.');
    expect($plan->currentTermsRevision()->contribution_amount_kobo)->toBe(200000);
    expect($plan->currentTermsRevision()->contribution_days)->toBe(2);
    expect($plan->currentTermsRevision()->fee_snapshot_id)->toBe($originalSnapshot['id']);
    expect($plan->termsRevisions()->where('revision', 1)->firstOrFail()->getAttributes())->toBe($originalTerms);
    expect($plan->currentTermsRevision()->feeSnapshot->getAttributes())->toBe($originalSnapshot);
    expect($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots);
    $request = WithdrawalRequest::query()->sole();
    expect($request->gross_amount_kobo)->toBe(30000);
    expect($request->fee_amount_kobo)->toBe(600);
    expect($request->net_amount_kobo)->toBe(29400);
    expect($request->fee_snapshot_id)->toBe($originalSnapshot['id']);
    expect($request->plan_version)->toBe($reservationFirst ? 2 : 3);
    expect(DB::table('withdrawal_reservations')->where('status', 'live')->count())->toBe(1);
    expect((int) DB::table('withdrawal_reservations')->value('gross_amount_kobo'))->toBe(30000);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan))->toBe([
        'liability_kobo' => 100000, 'reservations_kobo' => 30000, 'available_kobo' => 70000,
        'cycle_liability_kobo' => 100000, 'cycle_reservations_kobo' => 30000, 'cycle_available_kobo' => 70000,
    ]);
    DB::transaction(function () use ($withdrawals, $request, $customer): void {
        $withdrawals->assertReservationAndBalance($request, $customer);
    });
    $replayRows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_operation_attempts', 'plan_lifecycle_events',
        'withdrawal_requests', 'withdrawal_reservations', 'withdrawal_attempts', 'withdrawal_events'] as $table) {
        $replayRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    expect($reserve())->toBe('reserved');
    expect($revise())->toBe('revised');
    foreach ($financialRows + $replayRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['committed reservation first' => true, 'committed descriptive revision first' => false, 'concurrent owners' => null]);
