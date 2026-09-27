<?php

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\FeeLedgerPostingType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Jobs\ProjectAuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionException;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusNotificationIntent;
use App\Models\FeeObligationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\PlanOperationAttempt;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AgentEligibilityService;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CustomerLifecycleEligibility;
use App\Services\CustomerLifecycleService;
use App\Services\FeeObligationService;
use App\Services\LedgerPostingService;
use App\Services\PlatformGuard;
use App\Services\ReversalService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

test('CAM-AC-030: archive preserves identity access assignment history and cancels name proposals with an unavailable Agent', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    Queue::fake([DeliverCustomerStatusNotificationIntent::class]);
    $agent->update(['operational_status' => 'inactive']);
    $original = $customer->getAttributes();
    $access = $customer->user->getAttributes();
    $assignment = $customer->currentAssignment->getAttributes();
    $proposal = CustomerNameCorrection::create(['customer_profile_id' => $customer->id, 'requested_by_user_id' => $agent->user_id,
        'current_name' => $customer->user->name, 'proposed_name' => 'Corrected Name', 'reason' => 'Correction',
        'profile_version' => 1, 'status' => 'pending', 'expires_at' => now()->addDay()]);
    $payload = $this->lifecyclePayload($customer);
    $result = $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)
        ->assertOk()->assertJsonPath('status', 'archived')->json();
    expect($customer->fresh()->version)->toBe(2)
        ->and(array_intersect_key($customer->fresh()->getAttributes(), array_flip(['customer_id', 'user_id', 'phone', 'internal_reference', 'created_at'])))->toBe(array_intersect_key($original, array_flip(['customer_id', 'user_id', 'phone', 'internal_reference', 'created_at'])))
        ->and($customer->user->fresh()->getAttributes())->toBe($access)
        ->and($customer->currentAssignment->fresh()->getAttributes())->toBe($assignment)
        ->and($proposal->fresh()->status)->toBe('cancelled');
    expect(DB::table('canonical_audit_events')->where('event_type', 'customer.status_changed')->count())->toBe(1);
    expect(CustomerStatusNotificationIntent::query()->count())->toBe(3);
    foreach (CustomerStatusNotificationIntent::query()->get() as $intent) {
        expect(json_encode($intent->payload))->not->toContain('Internal review');
    }
    Queue::assertPushed(DeliverCustomerStatusNotificationIntent::class);
    $this->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)->assertOk()->assertExactJson($result);
    $this->assertDatabaseCount('customer_lifecycle_operations', 1);
    $this->assertDatabaseCount('customer_status_histories', 1);
    $this->actingAs($customer->user)->get(route('customers.show', $customer->customer_id))->assertOk();
    $this->actingAs($admin)->get(route('customers.status.edit', $customer->customer_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/Status')->where('customer.operational_status', 'archived')->where('allowed_targets', []));
});

test('CAM-AC-031/032: restoration permits a discrepancy and repeated episodes retain original operation results', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $archive = $this->lifecyclePayload($customer);
    $service = app(CustomerLifecycleService::class);
    $original = $service->execute($admin, $customer, 'archive', $archive);
    LedgerAccount::query()->where('code', LedgerAccountCode::CustomerSavingsLiability->value)->update(['mapping_status' => 'unconfigured']);
    $restore = $this->lifecyclePayload($customer->fresh());
    $this->actingAs($admin)->postJson(route('customers.lifecycle.restore', $customer->customer_id), $restore)->assertOk()->assertJsonPath('status', 'inactive');
    expect($customer->fresh()->version)->toBe(3)->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Inactive);
    expect($service->execute($admin, $customer, 'archive', $archive))->toBe($original);
    $changed = $archive;
    $changed['reason'] = 'Different intent';
    $this->postJson(route('customers.lifecycle.archive', $customer->customer_id), $changed)->assertConflict();
    $this->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertConflict();
    LedgerAccount::query()->where('code', LedgerAccountCode::CustomerSavingsLiability->value)->update(['mapping_status' => 'mapped']);
    $service->execute($admin, $customer->fresh(), 'archive', $this->lifecyclePayload($customer->fresh()));
    expect($customer->statusHistories()->count())->toBe(3);
    $this->getJson(route('customers.lifecycle.operation', [$customer->customer_id, $archive['attempt_reference']]))->assertOk()->assertExactJson($original);
});

test('CAM-AC-031: restore rechecks assigned Agent eligibility and assignment version', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    app(CustomerLifecycleService::class)->execute($admin, $customer, 'archive', $this->lifecyclePayload($customer));
    $payload = $this->lifecyclePayload($customer->fresh());
    $agent->update(['operational_status' => 'inactive']);
    $this->actingAs($admin)->postJson(route('customers.lifecycle.restore', $customer->customer_id), $payload)->assertUnprocessable()->assertJsonValidationErrors('target_status');
    $agent->update(['operational_status' => 'active']);
    $payload['assignment_version'] = 2;
    $this->postJson(route('customers.lifecycle.restore', $customer->customer_id), $payload)->assertConflict();
    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Archived);
});

test('CAM-AC-029: missing account mappings cannot certify zero savings or refunds', function (string $code, string $gate) {
    [$admin, $customer] = $this->createLifecycleFixture();
    LedgerAccount::query()->where('code', $code)->update(['mapping_status' => 'unconfigured']);
    $preview = $this->actingAs($admin)->postJson(route('customers.lifecycle.preview', $customer->customer_id))->assertOk()->json();
    expect(collect($preview['checks'])->firstWhere('key', $gate)['status'])->toBe('unavailable');
    $this->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertUnprocessable();
    $this->assertDatabaseCount('customer_lifecycle_operations', 0);
})->with([[LedgerAccountCode::CustomerSavingsLiability->value, 'savings'], [LedgerAccountCode::RefundPayable->value, 'fees']]);

test('CAM-AC-029: missing and unpaid registration fee assessments block archival', function (bool $assess) {
    [$admin, $customer, $agent, $snapshot] = $this->createLifecycleFixture(50000);
    if ($assess) {
        app(FeeObligationService::class)->assessSnapshot($snapshot, $admin);
    }
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer);
    expect(collect($preview['checks'])->firstWhere('key', 'fees')['status'])->toBe($assess ? 'blocked' : 'unavailable');
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertUnprocessable();
})->with([true, false]);

test('CAM-AC-029: an authoritative waiver clears the fee gate without archival posting money', function () {
    [$admin, $customer, $agent, $snapshot] = $this->createLifecycleFixture(50000);
    $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $admin);
    FeeObligationEntry::create(['fee_obligation_id' => $obligation->id, 'entry_type' => 'waiver', 'amount_kobo' => 50000,
        'currency' => 'NGN', 'source_type' => 'admin_waiver', 'source_id' => 'fixture-waiver', 'idempotency_key' => 'fixture-waiver',
        'actor_user_id' => $admin->id, 'reason' => 'Approved waiver', 'customer_description' => 'Waived']);
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertOk();
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('CAM-AC-029: open cycles block archival and unknown cycle state fails closed', function (string $status, string $expected) {
    [$admin, $customer] = $this->createLifecycleFixture();
    ThriftPlan::create(['plan_id' => 'PLN-GATE', 'customer_profile_id' => $customer->id, 'created_by_user_id' => $admin->id,
        'status' => 'active', 'open_customer_profile_id' => $customer->id, 'current_terms_revision' => 1, 'version' => 1]);
    DB::table('thrift_plans')->update(['status' => $status]);
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer);
    expect(collect($preview['checks'])->firstWhere('key', 'plans')['status'])->toBe($expected);
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertUnprocessable();
})->with([['active', 'blocked'], ['paused', 'blocked'], ['completed', 'blocked'], ['unknown', 'unavailable']]);

test('CAM-AC-029: queued notifications do not block archival but unknown jobs fail closed', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['data' => ['commandName' => ProjectAuditEvent::class]]),
        'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
    expect(app(CustomerLifecycleEligibility::class)->preview($admin, $customer)['eligible'])->toBeTrue();
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['data' => ['commandName' => 'UnknownFinancialWriter']]),
        'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer);
    expect(collect($preview['checks'])->firstWhere('key', 'financial_work')['status'])->toBe('unavailable');
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertUnprocessable();
});

test('lifecycle authorization is current and original result lookup is actor and Customer bound', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $payload = $this->lifecyclePayload($customer);
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)->assertForbidden();
    $this->actingAs($customer->user)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)->assertForbidden();
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)->assertOk();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::CustomersManage);
    $this->actingAs($other)->getJson(route('customers.lifecycle.operation', [$customer->customer_id, $payload['attempt_reference']]))->assertNotFound();
    $admin->revokePermissionTo(AdminPermission::CustomersManage);
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)->assertForbidden();
    $this->getJson(route('customers.lifecycle.operation', [$customer->customer_id, $payload['attempt_reference']]))->assertForbidden();
});

test('restricted and stale lifecycle forms cannot archive and the generic endpoint cannot bypass checks', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $customer->update(['operational_status' => 'restricted']);
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertConflict();
    $customer->update(['operational_status' => 'active']);
    $payload = $this->lifecyclePayload($customer);
    $payload['version'] = 2;
    $this->postJson(route('customers.lifecycle.archive', $customer->customer_id), $payload)->assertConflict();
    $this->patchJson(route('customers.status.update', $customer->customer_id), [...$payload, 'target_status' => 'archived'])->assertUnprocessable();
    $this->assertDatabaseCount('customer_status_histories', 0);
});

test('required confirmation and reasons are enforced for direct lifecycle service calls', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $payload = [...$this->lifecyclePayload($customer), 'confirmed' => false, 'reason' => ''];
    expect(fn () => app(CustomerLifecycleService::class)->execute($admin, $customer, 'archive', $payload))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('customer_lifecycle_operations', 0);
});

test('audit failure rolls back lifecycle status operation and proposal cancellation together', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $proposal = CustomerNameCorrection::create(['customer_profile_id' => $customer->id, 'requested_by_user_id' => $admin->id,
        'current_name' => $customer->user->name, 'proposed_name' => 'Corrected Name', 'reason' => 'Correction',
        'profile_version' => 1, 'status' => 'pending', 'expires_at' => now()->addDay()]);
    DB::statement("CREATE TRIGGER lifecycle_audit_outage BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
    try {
        expect(fn () => app(CustomerLifecycleService::class)->execute($admin, $customer, 'archive', $this->lifecyclePayload($customer)))->toThrow(QueryException::class);
        expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Active)->and($proposal->fresh()->status)->toBe('pending');
        $this->assertDatabaseCount('customer_status_histories', 0);
        $this->assertDatabaseCount('customer_lifecycle_operations', 0);
        $this->assertDatabaseCount('customer_status_notification_intents', 0);
    } finally {
        DB::statement('DROP TRIGGER lifecycle_audit_outage');
    }
});

test('archived Customer fee assessment and operational edits require restoration', function () {
    [$admin, $customer, $agent, $snapshot] = $this->createLifecycleFixture(100);
    $customer->update(['operational_status' => CustomerStatus::Archived]);
    expect(fn () => app(FeeObligationService::class)->assessSnapshot($snapshot, $admin))->toThrow(ConflictHttpException::class);
    $this->actingAs($admin)->get(route('customers.edit', $customer->customer_id))->assertForbidden();
    $this->assertDatabaseCount('fee_obligations', 0);
});

test('CAM-AC-029: actual collection posting blocks savings and reconciliation while unrelated Agent batches do not', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    config()->set('collections.enabled', true);
    LedgerAccount::query()->whereIn('code', [LedgerAccountCode::AgentReceivable->value, LedgerAccountCode::BusinessCash->value])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = $this->lifecycleCollectionPayload($customer, $plan);
    $collections = app(CollectionService::class);
    $data['preview_fingerprint'] = $collections->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $receipt = $collections->record($agent->user, $customer, $data);
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer);
    expect(collect($preview['checks'])->firstWhere('key', 'savings')['status'])->toBe('blocked')
        ->and(collect($preview['checks'])->firstWhere('key', 'collections')['status'])->toBe('blocked');
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertUnprocessable();
    $unrelated = CustomerProfile::factory()->create();
    expect(app(CollectionReadService::class)->archivalStatus($unrelated))->toBe('passed');
    $case = CollectionException::create(['collection_batch_id' => $receipt->collection_batch_id,
        'opened_by_user_id' => $admin->id, 'kind' => 'cash_shortage', 'status' => 'open', 'amount_kobo' => 100000, 'reason' => 'Investigate custody']);
    expect(app(CollectionReadService::class)->archivalStatus($customer))->toBe('unavailable');
    $case->update(['status' => 'resolved']);
    DB::table('collection_allocations')->where('collection_receipt_id', $receipt->id)->update(['amount_kobo' => 1]);
    expect(app(CollectionReadService::class)->archivalStatus($customer))->toBe('unavailable');
});

test('CAM-AC-029: pending withdrawal and reversal owner records cannot be hidden by zero-looking balances', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $reservationId = DB::table('withdrawal_reservations')->insertGetId(['customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id,
        'owner_reference' => 'WDL-LIFECYCLE', 'gross_amount_kobo' => 100, 'status' => 'live', 'version' => 1,
        'created_at' => now(), 'updated_at' => now()]);
    $withdrawal = WithdrawalRequest::create(['withdrawal_id' => 'WDL-LIFECYCLE', 'customer_profile_id' => $customer->id,
        'thrift_plan_id' => $plan->id, 'live_thrift_plan_id' => $plan->id, 'initiating_agent_profile_id' => $agent->id,
        'assignment_id' => $customer->currentAssignment->id, 'submitted_by_user_id' => $agent->user_id,
        'fee_snapshot_id' => $plan->currentTermsRevision()->fee_snapshot_id, 'withdrawal_reservation_id' => $reservationId,
        'type' => 'early', 'state' => 'pending_review', 'gross_amount_kobo' => 100, 'fee_amount_kobo' => 0, 'net_amount_kobo' => 100,
        'currency' => 'NGN', 'method' => 'cash', 'destination_reference' => 'test', 'destination_mask' => 'Cash', 'reason' => 'Request',
        'customer_version' => 1, 'assignment_version' => 1, 'plan_version' => 1, 'business_version' => 1, 'method_version' => 1,
        'version' => 1, 'submitted_at' => now(), 'deadline_at' => now()->addDay()]);
    expect(app(WithdrawalService::class)->archivalStatus($customer))->toBe('blocked');
    DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['state' => 'rejected', 'live_thrift_plan_id' => null, 'terminal_at' => now()]);
    expect(app(WithdrawalService::class)->archivalStatus($customer))->toBe('unavailable');
    DB::table('withdrawal_reservations')->where('id', $reservationId)->update(['status' => 'released']);
    expect(app(WithdrawalService::class)->archivalStatus($customer))->toBe('passed');
    $original = LedgerPostingGroup::create(['posting_reference' => 'REV-ORIGINAL', 'idempotency_key' => 'rev-original',
        'payload_hash' => str_repeat('a', 64), 'source_type' => 'collection_receipt', 'source_id' => '1', 'event_type' => 'cash_contribution',
        'currency' => 'NGN', 'customer_profile_id' => $customer->id, 'actor_user_id' => $agent->user_id, 'occurred_at' => now(), 'committed_at' => now()]);
    ReversalRequest::create(['reversal_id' => 'REV-LIFECYCLE', 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $original->id, 'live_original_posting_group_id' => $original->id,
        'requested_by_user_id' => $agent->user_id, 'initiating_agent_profile_id' => $agent->id, 'assignment_id' => $customer->currentAssignment->id,
        'state' => 'pending_review', 'version' => 1, 'reason_category' => 'incorrect_amount', 'internal_reason' => 'Review',
        'customer_explanation' => 'Correction review', 'evidence_text' => 'Receipt', 'dependency_fingerprint' => str_repeat('a', 64),
        'dependency_snapshot' => [], 'original_amount_kobo' => 100, 'currency' => 'NGN']);
    expect(app(ReversalService::class)->archivalStatus($customer))->toBe('blocked');
});

test('archival notices deduplicate delivery reauthorize recipients and retain failed delivery without repeating status', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    Queue::fake([DeliverCustomerStatusNotificationIntent::class]);
    $payload = $this->lifecyclePayload($customer);
    app(CustomerLifecycleService::class)->execute($admin, $customer, 'archive', $payload);
    $inbox = CustomerStatusNotificationIntent::query()->where('audience_type', 'subject_customer')->where('channel', 'database')->firstOrFail();
    $job = new DeliverCustomerStatusNotificationIntent($inbox->id);
    $job->handle(app(AgentEligibilityService::class));
    $job->handle(app(AgentEligibilityService::class));
    expect($inbox->fresh()->status)->toBe('delivered');
    expect($customer->user->notifications()->count())->toBe(1);
    $agent->user->update(['account_state' => 'suspended']);
    $agentNotice = CustomerStatusNotificationIntent::query()->where('audience_type', 'current_agent')->firstOrFail();
    (new DeliverCustomerStatusNotificationIntent($agentNotice->id))->handle(app(AgentEligibilityService::class));
    expect($agentNotice->fresh()->status)->toBe('suppressed');
    $mail = CustomerStatusNotificationIntent::query()->where('channel', 'mail')->firstOrFail();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Mail transport unavailable.'));
    $delivery = new DeliverCustomerStatusNotificationIntent($mail->id);
    try {
        $delivery->handle(app(AgentEligibilityService::class));
        $this->fail('Expected mail delivery failure.');
    } catch (RuntimeException $exception) {
        $delivery->failed($exception);
    }
    expect($mail->fresh()->status)->toBe('failed');
    app(CustomerLifecycleService::class)->execute($admin, $customer, 'archive', $payload);
    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Archived);
    $this->assertDatabaseCount('customer_status_histories', 1);
    $this->assertDatabaseCount('customer_status_notification_intents', 3);
});

test('direct contribution and fee posting boundaries deny new effects for Archived Customers', function () {
    [$admin, $customer, $agent, $snapshot] = $this->createLifecycleFixture(100);
    $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $admin);
    $customer->update(['operational_status' => CustomerStatus::Archived]);
    expect(fn () => app(PlatformGuard::class)->transaction('financial', fn () => app(CollectionLedgerService::class)
        ->postCashSavings(999, $customer->id, $agent->id, 100, $admin)))->toThrow(ConflictHttpException::class);
    $command = new LedgerPostingCommand(FeeLedgerPostingType::ExternalFeeReceipt,
        'archived-fee-attempt', 'collection_receipt', '999-'.$obligation->id, 'NGN', $admin, $customer->id,
        CarbonImmutable::now(), [
            new LedgerPostingLine(LedgerAccountCode::AgentReceivable, LedgerEntrySide::Debit, 100, $customer->id, $agent->id, $obligation->id),
            new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, 100, $customer->id, null, $obligation->id),
        ], 'Fee receipt');
    expect(fn () => app(LedgerPostingService::class)->postFee($command))->toThrow(ConflictHttpException::class);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('a formally cancelled unused cycle clears the plan and fee owners before archival', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    app(ThriftPlanService::class)->transition($agent->user, $plan, 'cancel', (string) Str::uuid(), [
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Unused cycle', 'customer_explanation' => 'Your unused plan was cancelled.',
    ]);
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer->fresh());
    expect($preview['eligible'])->toBeTrue();
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer->fresh()))->assertOk();
    expect($plan->fresh()->status->value)->toBe('cancelled');
});

test('unfinished owning plan operations block archival and unclassified operation outcomes remain unavailable', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $attempt = PlanOperationAttempt::create(['attempt_reference' => (string) Str::uuid(),
        'user_id' => $admin->id, 'business_id' => BusinessProfile::current()->business_id,
        'customer_profile_id' => $customer->id, 'operation_type' => 'plan_create', 'payload_fingerprint' => str_repeat('a', 64), 'status' => 'in_progress']);
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer);
    expect(collect($preview['checks'])->firstWhere('key', 'financial_work')['status'])->toBe('blocked');
    $attempt->update(['status' => 'unknown']);
    $preview = app(CustomerLifecycleEligibility::class)->preview($admin, $customer);
    expect(collect($preview['checks'])->firstWhere('key', 'financial_work')['status'])->toBe('unavailable');
    $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id), $this->lifecyclePayload($customer))->assertUnprocessable();
});
