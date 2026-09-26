<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Jobs\DeliverWithdrawalNotificationIntent;
use App\Models\AgentProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalNotificationIntent;
use App\Models\WithdrawalRequest;
use App\Services\AgentEligibilityService;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\NotificationPipeline;
use App\Services\PlatformState;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function withdrawalFixture(): array
{
    $agent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $agentProfile->id,
        'assigned_by_user_id' => $agent->id, 'status' => CustomerAssignmentStatus::Current,
    ]);
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Withdrawal fee', 'kind' => FeeRuleKind::Plan,
        'rule_key' => 'withdrawal-test', 'model' => FeeRuleModel::Percentage,
        'timing' => FeeRuleTiming::Withdrawal, 'basis' => FeeRuleBasis::GrossWithdrawalDebit,
        'settlement_source' => FeeSettlementSource::WithdrawalPayout,
        'currency' => 'NGN', 'amount_kobo' => 0, 'basis_points' => 200,
        'customer_description' => 'Two percent withdrawal fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test withdrawal terms.',
    ]);
    $plan = ThriftPlan::create([
        'plan_id' => 'PLN-WDL-001', 'customer_profile_id' => $customer->id,
        'created_by_user_id' => $agent->id, 'open_customer_profile_id' => $customer->id,
        'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1,
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'plan', 'source_id' => $plan->plan_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Withdrawal fee',
        'kind' => FeeRuleKind::Plan, 'model' => FeeRuleModel::Percentage,
        'timing' => FeeRuleTiming::Withdrawal, 'basis' => FeeRuleBasis::GrossWithdrawalDebit,
        'settlement_source' => FeeSettlementSource::WithdrawalPayout, 'currency' => 'NGN',
        'amount_kobo' => 0, 'basis_points' => 200, 'basis_amount_kobo' => 0,
        'customer_description' => 'Two percent withdrawal fee', 'acknowledged_at' => now(),
    ]);
    PlanTermsRevision::create([
        'thrift_plan_id' => $plan->id, 'revision' => 1, 'name' => 'Daily cycle',
        'contribution_amount_kobo' => 100000, 'currency' => 'NGN', 'start_date' => now()->toDateString(),
        'contribution_days' => 1, 'frequency' => 'daily', 'timezone' => 'Africa/Lagos',
        'business_version' => 1, 'expected_gross_kobo' => 100000,
        'fee_snapshot_id' => $snapshot->id, 'attested_by_user_id' => $agent->id, 'attested_at' => now(),
    ]);
    $batch = CollectionBatch::create([
        'agent_profile_id' => $agentProfile->id, 'received_date' => now()->toDateString(),
        'timezone' => 'Africa/Lagos', 'revision' => 1, 'status' => 'open', 'version' => 1,
    ]);
    $receipt = CollectionReceipt::create([
        'receipt_reference' => 'TXN-WDL-001', 'attempt_reference' => (string) Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'customer_profile_id' => $customer->id,
        'thrift_plan_id' => $plan->id, 'recording_agent_profile_id' => $agentProfile->id,
        'assignment_id' => $assignment->id, 'collection_batch_id' => $batch->id,
        'recorded_by_user_id' => $agent->id, 'received_date' => now()->toDateString(),
        'timezone' => 'Africa/Lagos', 'business_version' => 1,
        'tender_amount_kobo' => 100000, 'savings_amount_kobo' => 100000,
        'fee_amount_kobo' => 0, 'recorded_at' => now(),
    ]);
    DB::transaction(function () use ($receipt, $customer, $agentProfile, $agent): void {
        $group = app(CollectionLedgerService::class)->postCashSavings($receipt->id, $customer->id, $agentProfile->id, 100000, $agent);
        $receipt->update(['savings_posting_group_id' => $group->id]);
    });

    return [$agent, $customer, $assignment, $plan];
}

function withdrawalPayload(CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan): array
{
    return [
        'plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00',
        'method' => 'cash', 'destination_reference' => 'verified-customer-cash',
        'reason' => 'Customer requested a partial payout', 'internal_notes' => '',
    ];
}

function enableFixtureMethod(): void
{
    $registry = Mockery::mock(WithdrawalMethodRegistry::class);
    $registry->shouldReceive('resolve')->andReturn([
        'version' => 1, 'destination_reference' => 'verified-customer-cash',
        'destination_mask' => 'Customer cash pickup',
    ]);
    app()->instance(WithdrawalMethodRegistry::class, $registry);
}

function submittedWithdrawal(User $agent, CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan): WithdrawalRequest
{
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $payload);

    return app(WithdrawalService::class)->submit($agent, $customer, [
        ...$payload, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true,
    ]);
}

test('production method gate prevents submission and reservations', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertStatus(503);
    expect(WithdrawalRequest::query()->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
});

test('preview rejects client-supplied financial terms', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [
        ...withdrawalPayload($customer, $assignment, $plan), 'fee_kobo' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('fee_kobo');
});

test('staged request reserves gross savings and replays one submit attempt', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $quote = $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    expect($quote['gross_kobo'])->toBe(30000)->and($quote['fee_kobo'])->toBe(600)
        ->and($quote['net_kobo'])->toBe(29400);
    $submission = [...$payload, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true];
    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), $submission)->assertRedirect();
    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), $submission)->assertRedirect();
    expect(WithdrawalRequest::query()->count())->toBe(1);
    $withdrawal = WithdrawalRequest::query()->firstOrFail();
    expect($withdrawal->state)->toBe('pending_review')
        ->and(DB::table('withdrawal_reservations')->where('status', 'live')->value('gross_amount_kobo'))->toBe(30000)
        ->and(DB::table('withdrawal_notification_intents')->count())->toBe(3)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});

test('one fresh authorized Admin decision preserves a live reservation', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview->value);
    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'confirmed' => true, 'decision_note' => 'Evidence reviewed'];
    $now = now()->timestamp;
    $this->actingAs($admin)->withSession(['auth.fresh_until' => $now + 600,
        'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now])
        ->post(route('withdrawals.approve', $withdrawal), $decision)->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('approved')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');
});

test('pending cancellation releases the reservation without reducing liability', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true,
        'internal_reason' => 'Customer changed their instruction',
    ])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('cancelled')
        ->and($withdrawal->fresh()->live_thrift_plan_id)->toBeNull()
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('released')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});

test('restriction holds a request and pauses safe expiry until lifted', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    DB::transaction(function () use ($customer): void {
        $customer->forceFill(['operational_status' => CustomerStatus::Restricted])->save();
        app(WithdrawalService::class)->applyCustomerStatus($customer, CustomerStatus::Restricted);
    });
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->held)->toBeTrue();
    DB::transaction(function () use ($customer): void {
        $customer->forceFill(['operational_status' => CustomerStatus::Active])->save();
        app(WithdrawalService::class)->applyCustomerStatus($customer, CustomerStatus::Active);
    });
    expect($withdrawal->fresh()->held)->toBeFalse()
        ->and($withdrawal->fresh()->deadline_at->isFuture())->toBeTrue()
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');
});

test('expired unheld request releases funds exactly once', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('expired')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('released')
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'expired')->count())->toBe(1);
});

test('roles and current assignment protect withdrawal preview and detail', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $otherAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    AgentProfile::factory()->active()->create(['user_id' => $otherAgent->id]);
    $this->actingAs($otherAgent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertForbidden();
    $this->actingAs($otherAgent)->get(route('withdrawals.show', $withdrawal))->assertForbidden();
    $this->actingAs($customer->user)->get(route('withdrawals.show', $withdrawal))->assertOk();
});

test('an Admin without the direct review grant cannot approve a request', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $now = now()->timestamp;
    $this->actingAs($admin)->withSession(['auth.fresh_until' => $now + 600,
        'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now])
        ->post(route('withdrawals.approve', $withdrawal), [
            'attempt_reference' => (string) Str::uuid(), 'version' => 1,
            'confirmed' => true, 'decision_note' => 'Reviewed',
        ])->assertForbidden();
    expect($withdrawal->fresh()->state)->toBe('pending_review');
});

test('a stale plan version cannot reserve Customer savings', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $payload);
    $plan->increment('version');
    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), [
        ...$payload, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true,
    ])->assertStatus(409);
    expect(WithdrawalRequest::query()->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
});

test('unattributed savings make the source balance unavailable', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $group = LedgerPostingGroup::create([
        'posting_reference' => 'TEST-UNATTRIBUTED', 'idempotency_key' => 'test-unattributed',
        'payload_hash' => str_repeat('b', 64), 'source_type' => 'unknown_source', 'source_id' => '1',
        'event_type' => 'unknown_source', 'currency' => 'NGN', 'actor_user_id' => $agent->id,
        'customer_profile_id' => $customer->id, 'occurred_at' => now(), 'committed_at' => now(),
    ]);
    $account = LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->firstOrFail();
    LedgerEntry::create([
        'ledger_posting_group_id' => $group->id, 'line_number' => 1,
        'ledger_account_id' => $account->id, 'side' => 'credit', 'amount_kobo' => 100,
        'customer_profile_id' => $customer->id,
    ]);
    expect(fn () => app(WithdrawalService::class)->preview($agent, $customer->fresh(),
        withdrawalPayload($customer, $assignment, $plan)))->toThrow(RuntimeException::class,
            'A savings entry has no authoritative source cycle.');
    expect(DB::table('withdrawal_reservations')->count())->toBe(0);
});

test('notification delivery suppresses an Agent who loses current eligibility', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    Queue::fake();
    submittedWithdrawal($agent, $customer, $assignment, $plan);
    $intent = WithdrawalNotificationIntent::query()->where('audience_type', 'current_agent')->firstOrFail();
    $agent->forceFill(['account_state' => AccountState::Suspended])->save();
    (new DeliverWithdrawalNotificationIntent($intent->id))->handle(app(AgentEligibilityService::class));
    expect($intent->fresh()->status)->toBe('suppressed');
});

test('reassignment moves pending cancellation authority without changing request attribution', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $replacement = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $replacementProfile = AgentProfile::factory()->active()->create(['user_id' => $replacement->id]);
    $assignment->forceFill(['status' => CustomerAssignmentStatus::Ended, 'reason' => 'Customer reassigned'])->save();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $replacementProfile->id,
        'assigned_by_user_id' => $replacement->id, 'status' => CustomerAssignmentStatus::Current,
        'version' => 2,
    ]);
    $this->actingAs($agent)->get(route('withdrawals.show', $withdrawal))->assertForbidden();
    $this->actingAs($replacement)->post(route('withdrawals.cancel', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'confirmed' => true, 'internal_reason' => 'Customer changed instruction',
    ])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('cancelled')
        ->and($withdrawal->fresh()->submitted_by_user_id)->toBe($agent->id)
        ->and($withdrawal->fresh()->initiating_agent_profile_id)->toBe($assignment->agent_profile_id);
});

test('pending withdrawal inbox work reroutes once to the currently assigned replacement Agent', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    Queue::fake();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $original = WithdrawalNotificationIntent::query()->where('audience_type', 'current_agent')->firstOrFail();
    $replacement = User::factory()->agent()->withTwoFactor()->create();
    $replacementProfile = AgentProfile::factory()->active()->create(['user_id' => $replacement->id]);
    $assignment->forceFill(['status' => CustomerAssignmentStatus::Ended])->save();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $replacementProfile->id,
        'assigned_by_user_id' => $replacement->id, 'version' => 2,
    ]);
    $pipeline = app(NotificationPipeline::class);
    $pipeline->deliverOwner('withdrawal', $original->id);
    $pipeline->deliverOwner('withdrawal', $original->id);
    expect($original->fresh()->status)->toBe('suppressed');
    $new = DB::table('notification_inbox_intents')->where('recipient_user_id', $replacement->id)->sole();
    $pipeline->materialize((int) $new->id);
    config()->set('notifications.enabled', true);
    $this->actingAs($replacement)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $this->actingAs($agent)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
    expect($withdrawal->fresh()->state)->toBe('pending_review');
    expect($withdrawal->fresh()->submitted_by_user_id)->toBe($agent->id);
    expect(DB::table('notification_inbox_intents')->where('recipient_user_id', $replacement->id)->count())->toBe(1);
});

test('platform freeze retains an expired reservation and permits authorized original outcome lookup', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $withdrawal->update(['deadline_at' => now()->subMinute()]);
    app(PlatformState::class)->transition([
        'mode' => 'financial_freeze', 'expected_version' => 1, 'operation_id' => (string) Str::uuid(),
        'operator' => 'operations-service', 'reason' => 'Contain incident', 'incident' => 'INC-200', 'expires_at' => null,
    ]);
    $attempt = DB::table('withdrawal_attempts')->where('withdrawal_request_id', $withdrawal->id)->value('attempt_reference');

    $this->artisan('withdrawals:expire')->assertSuccessful();
    $this->actingAs($agent)->get(route('withdrawals.attempts.show', $attempt))->assertOk();
    $this->assertDatabaseHas('withdrawal_reservations', ['owner_reference' => $withdrawal->withdrawal_id, 'status' => 'live', 'gross_amount_kobo' => 30000]);
    expect($withdrawal->fresh()->state)->toBe('pending_review');
    $this->actingAs(User::factory()->customer()->create())->get(route('withdrawals.attempts.show', $attempt))->assertForbidden();
    $this->assertDatabaseCount('withdrawal_requests', 1);
});
