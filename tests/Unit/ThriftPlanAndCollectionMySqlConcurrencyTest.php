<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerStatusManagementService;
use App\Services\FinancialPeriodService;
use App\Services\ThriftPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;
use Tests\TestCase;

uses(TestCase::class, CreatesLifecycleCustomers::class);

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
