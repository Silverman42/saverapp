<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\PlanNotificationIntent;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Notifications\ThriftPlanNotification;
use App\Services\AgentEligibilityService;
use App\Services\AuditProjection;
use App\Services\CustomerReassignmentService;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationInbox;
use App\Services\NotificationPipeline;
use App\Services\ThriftPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

function planNoticeRecoveryRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'plan_operation_attempts',
        'plan_lifecycle_events', 'plan_notification_intents', 'fee_obligations', 'fee_obligation_entries',
        'collection_receipts', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('reassigned plan forms and failed queued notices recheck current scope without repeating lifecycle or financial activity', function (bool $handover): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $plan = $this->createLifecyclePlan($customer, $actor);
    $service = app(ThriftPlanService::class);
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Private original operational explanation.', 'customer_explanation' => 'Your dated agreement remains unchanged.'];
    $this->actingAs($actor)->post(route('plans.pause', $plan->plan_id), $data)->assertRedirect();
    $plan->refresh();
    $terms = $plan->currentTermsRevision();
    $revision = ['attempt_reference' => (string) Str::uuid(), 'name' => 'Reviewed new plan name',
        'amount_ngn' => '2000.00', 'start_date' => $terms->start_date, 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $terms->feeSnapshot->fee_rule_id,
        'fee_rule_version' => $terms->feeSnapshot->fee_rule_version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'customer_agreement_attested' => true,
        'reason' => 'Private reviewed correction.', 'customer_explanation' => 'The plan name will be clearer.'];
    $revision['preview_fingerprint'] = $service->previewRevision($actor, $plan, $revision)['preview_fingerprint'];
    $oldAgentOwner = PlanNotificationIntent::query()->where('audience_type', 'current_agent')->sole();
    $customerOwner = PlanNotificationIntent::query()->where('audience_type', 'subject_customer')->where('channel', 'database')->sole();
    Queue::assertPushed(DeliverPlanNotificationIntent::class, fn (DeliverPlanNotificationIntent $job): bool => $job->intentId === $oldAgentOwner->id);
    $baseline = planNoticeRecoveryRows();
    DB::statement("CREATE TRIGGER fail_plan_notice BEFORE UPDATE ON notification_inbox_intents WHEN NEW.status = 'delivered' BEGIN SELECT RAISE(ABORT, 'simulated plan notification outage'); END");
    try {
        (new DeliverPlanNotificationIntent($oldAgentOwner->id))->handle(app(AgentEligibilityService::class));
        (new DeliverPlanNotificationIntent($customerOwner->id))->handle(app(AgentEligibilityService::class));
    } finally {
        DB::statement('DROP TRIGGER fail_plan_notice');
    }
    expect(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('notification_inbox_attempts')->count())->toBe(2)
        ->and($oldAgentOwner->fresh()->status)->toBe('pending')
        ->and($customerOwner->fresh()->status)->toBe('pending')
        ->and(planNoticeRecoveryRows())->toEqual($baseline);
    $failures = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->get();
    expect($failures)->toHaveCount(2);
    foreach ($failures as $failure) {
        $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $failure->id)->sole();
        expect($failure->target_reference)->toBe($plan->plan_id)->and($failure->actor_id)->toBeNull()
            ->and($failure->payload['attempt'])->toBe(1)->and($failure->payload['channel'])->toBe('database')
            ->and($canonical->outcome)->toBe('Failed')->and($canonical->content)->not->toContain('simulated plan notification outage');
    }
    $replacement = null;
    if ($handover) {
        $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        $admin->givePermissionTo(AdminPermission::CustomersReassign);
        $owner = app(CustomerReassignmentService::class);
        $preview = $owner->preview($admin, $customer->fresh(), $replacement->id);
        $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
            'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
            'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed current service transfer.',
            'customer_explanation' => 'Your service contact changed.']);
        $this->actingAs($actor)->get(route('plans.edit', $plan->plan_id))->assertNotFound();
        $this->patchJson(route('plans.update', $plan->plan_id), $revision)->assertNotFound();
        $this->postJson(route('plans.resume', $plan->plan_id), [...$data, 'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version])->assertNotFound();
        $this->actingAs($replacement->user)->get(route('plans.show', $plan->plan_id))
            ->assertInertia(fn (Assert $page) => $page->where('plan.status', 'paused')->has('plan.history', 1)
                ->where('plan.history.0.actor', $actor->name)->where('plan.history.0.explanation', $data['customer_explanation']));
        $this->patchJson(route('plans.update', $plan->plan_id), $revision)->assertConflict();
        $this->get(route('plans.edit', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('customer.assignment_version', 2)->where('plan.version', 2)->where('plan.terms_revision', 1));
    }
    $baseline = planNoticeRecoveryRows();
    $nextAttempt = DB::table('notification_inbox_intents')->whereIn('id', DB::table('notification_inbox_aliases')
        ->where('family', 'plan')->whereIn('owner_intent_id', [$oldAgentOwner->id, $customerOwner->id])->select('intent_id'))->max('next_attempt_at');
    expect($nextAttempt)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse($nextAttempt)->addSecond());
    foreach ([$oldAgentOwner, $customerOwner] as $notice) {
        (new DeliverPlanNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
        (new DeliverPlanNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
    }
    expect(AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->count())->toBe(4);
    foreach ([$oldAgentOwner, $customerOwner] as $notice) {
        $event = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')
            ->where('payload->notification_reference', $notice->notification_id)->orderByDesc('id')->firstOrFail();
        $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
        expect($event->target_reference)->toBe($plan->plan_id)->and($event->payload['attempt'])->toBe(2)
            ->and($canonical->outcome)->toBe($handover && $notice->id === $oldAgentOwner->id ? 'Denied' : 'Succeeded')
            ->and($canonical->content)->not->toContain('Private original operational explanation.');
    }
    expect($oldAgentOwner->fresh()->status)->toBe($handover ? 'suppressed' : 'delivered')
        ->and($customerOwner->fresh()->status)->toBe('delivered')
        ->and(DB::table('notifications')->where('id', $customerOwner->notification_id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('id', $oldAgentOwner->notification_id)->count())->toBe($handover ? 0 : 1)
        ->and(DB::table('notification_inbox_attempts')->count())->toBe(4);
    $notice = app(NotificationInbox::class)->detail($customer->user, $customerOwner->notification_id);
    expect($notice['summary'])->toBe('Your daily thrift plan was paused.')
        ->and(json_encode($notice))->not->toContain('Private original operational explanation.');
    if ($replacement !== null) {
        $this->actingAs($actor)->get(route('notifications.show', $oldAgentOwner->notification_id))->assertNotFound();
        $this->get(route('notifications.open', $oldAgentOwner->notification_id))->assertNotFound();
        $this->actingAs($replacement->user)->get(route('notifications.show', $oldAgentOwner->notification_id))->assertNotFound();
        expect(DB::table('plan_notification_intents')->where('recipient_user_id', $replacement->user_id)->count())->toBe(0);
    } else {
        $this->actingAs($actor)->post(route('plans.pause', $plan->plan_id), $data)->assertRedirect();
    }
    unset($baseline['plan_notification_intents']);
    $after = planNoticeRecoveryRows();
    unset($after['plan_notification_intents']);
    expect($after)->toEqual($baseline)
        ->and(ThriftPlan::query()->sole()->created_by_user_id)->toBe($actor->id)
        ->and(DB::table('plan_lifecycle_events')->count())->toBe(1)
        ->and(DB::table('plan_operation_attempts')->count())->toBe(1);
})->with(['same scope retry' => false, 'handover during delivery outage' => true]);

test('plan mail pre-send claim failure preserves one lifecycle commit and retries only for an authorized verified Customer', function (bool $revoked, bool $laterResume): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Private personnel detail.', 'customer_explanation' => 'Your original dated agreement remains.'];
    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), $data)->assertRedirect();
    $mail = PlanNotificationIntent::query()->where('channel', 'mail')->sole();
    Queue::assertPushed(DeliverPlanNotificationIntent::class, fn (DeliverPlanNotificationIntent $job): bool => $job->intentId === $mail->id);
    $baseline = planNoticeRecoveryRows();
    DB::statement("CREATE TRIGGER fail_plan_mail_claim BEFORE UPDATE ON plan_notification_intents WHEN NEW.status = 'sending' BEGIN SELECT RAISE(ABORT, 'simulated claim outage'); END");
    $job = new DeliverPlanNotificationIntent($mail->id);
    try {
        expect(fn () => $job->handle(app(AgentEligibilityService::class)))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_plan_mail_claim');
    }
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    expect($mail->fresh()->status)->toBe('pending')->and(planNoticeRecoveryRows())->toEqual($baseline);
    if ($laterResume) {
        $plan->refresh();
        $this->actingAs($agent->user)->post(route('plans.resume', $plan->plan_id), [...$data,
            'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version])->assertRedirect();
        expect($plan->fresh()->status->value)->toBe('active');
        $baseline = planNoticeRecoveryRows();
    }
    if ($revoked) {
        $customer->user->forceFill(['account_state' => AccountState::Suspended])->save();
    }
    $job->handle(app(AgentEligibilityService::class));
    $job->handle(app(AgentEligibilityService::class));
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount($revoked ? 0 : 1);
    expect($mail->fresh()->status)->toBe($revoked ? 'suppressed' : 'delivered');
    if (! $revoked) {
        $html = Crypt::decryptString($mail->fresh()->rendered_snapshot);
        expect($html)->toContain('Status at this update: Paused')->not->toContain('Private personnel detail.');
        expect($mail->fresh()->rendered_hash)->toBe(hash('sha256', $html))
            ->and($mail->fresh()->attempt_count)->toBe(1)->and($mail->fresh()->template_version)->toBe(1);
    }
    unset($baseline['plan_notification_intents']);
    $after = planNoticeRecoveryRows();
    unset($after['plan_notification_intents']);
    expect($after)->toEqual($baseline);
})->with(['authorized retry' => [false, false], 'access revoked before retry' => [true, false], 'later resume retains original mail status' => [false, true]]);

test('terminal mail failure captures one masked audit outcome atomically and replay preserves financial owners', function (string $fault): void {
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Private operational reason.', 'customer_explanation' => 'Your plan is paused.',
    ])->assertRedirect();
    $mail = PlanNotificationIntent::query()->where('channel', 'mail')->sole();
    $baseline = planNoticeRecoveryRows();
    $auditBaseline = DB::table('canonical_audit_events')->count();
    $error = new RuntimeException('SECRET transport credentials and recipient@example.test');
    if ($fault !== 'none') {
        DB::statement($fault === 'canonical'
            ? "CREATE TRIGGER fail_plan_terminal BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'thrift_plan.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'simulated canonical outage'); END"
            : "CREATE TRIGGER fail_plan_terminal BEFORE UPDATE ON plan_notification_intents WHEN NEW.status = 'failed' BEGIN SELECT RAISE(ABORT, 'simulated owner outage'); END");
        try {
            expect(fn () => (new DeliverPlanNotificationIntent($mail->id))->failed($error))->toThrow(QueryException::class);
        } finally {
            DB::statement('DROP TRIGGER fail_plan_terminal');
        }
        expect(planNoticeRecoveryRows())->toEqual($baseline)
            ->and(DB::table('canonical_audit_events')->count())->toBe($auditBaseline)
            ->and(AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->count())->toBe(0);
    }
    (new DeliverPlanNotificationIntent($mail->id))->failed($error);
    (new DeliverPlanNotificationIntent($mail->id))->failed(null);
    $event = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->sole();
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
    expect($mail->fresh()->status)->toBe('failed')->and($mail->fresh()->failure_reason)->toBe('Delivery failed after retrying.')
        ->and($event->actor_id)->toBeNull()->and($event->target_reference)->toBe($plan->plan_id)
        ->and($event->payload['notification_reference'])->toBe($mail->notification_id)
        ->and($event->payload['lifecycle_event_id'])->toBe($mail->plan_lifecycle_event_id)
        ->and($event->payload['category'])->toBe('retry_budget_exhausted')
        ->and($canonical->outcome)->toBe('Failed')->and($canonical->content)->not->toContain('SECRET')
        ->and($canonical->content)->not->toContain('recipient@example.test')
        ->and(DB::table('canonical_audit_events')->count())->toBe($auditBaseline + 1);
    unset($baseline['plan_notification_intents']);
    $after = planNoticeRecoveryRows();
    unset($after['plan_notification_intents']);
    expect($after)->toEqual($baseline);
    app(AuditProjection::class)->drain();
    expect(json_encode(DB::table('audit_search_documents')->get(), JSON_THROW_ON_ERROR))->not->toContain('SECRET');
})->with(['none', 'canonical', 'owner']);

test('stale terminal callbacks preserve delivered suppressed and local recovery notices', function (string $state): void {
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Private operational reason.', 'customer_explanation' => 'Your plan is paused.',
    ])->assertRedirect();
    if ($state === 'local') {
        $intent = PlanNotificationIntent::query()->where('channel', 'database')->where('audience_type', 'subject_customer')->sole();
    } else {
        $intent = PlanNotificationIntent::query()->where('channel', 'mail')->sole();
        if ($state === 'suppressed') {
            $customer->user->forceFill(['account_state' => AccountState::Suspended])->save();
        }
        (new DeliverPlanNotificationIntent($intent->id))->handle(app(AgentEligibilityService::class));
        expect($intent->fresh()->status)->toBe($state);
    }
    $baseline = planNoticeRecoveryRows();
    $auditBaseline = DB::table('canonical_audit_events')->count();
    $deliveryAudits = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->count();
    (new DeliverPlanNotificationIntent($intent->id))->failed(new RuntimeException('SECRET stale failure'));
    (new DeliverPlanNotificationIntent(PHP_INT_MAX))->failed(null);
    expect(planNoticeRecoveryRows())->toEqual($baseline)
        ->and(DB::table('canonical_audit_events')->count())->toBe($auditBaseline)
        ->and(AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->count())->toBe($deliveryAudits);
})->with(['delivered', 'suppressed', 'local']);

test('local plan audit failure rolls back delivery and the same queued owner can retry once', function (): void {
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'SECRET local private reason.', 'customer_explanation' => 'Your plan is paused.',
    ])->assertRedirect();
    $notice = PlanNotificationIntent::query()->where('channel', 'database')->where('audience_type', 'subject_customer')->sole();
    $intentId = DB::table('notification_inbox_aliases')->where('family', 'plan')->where('owner_intent_id', $notice->id)->sole()->intent_id;
    $baseline = planNoticeRecoveryRows();
    $inbox = DB::table('notification_inbox_intents')->where('id', $intentId)->sole();
    $attempts = DB::table('notification_inbox_attempts')->count();
    $audits = DB::table('canonical_audit_events')->count();
    DB::statement("CREATE TRIGGER fail_plan_local_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'thrift_plan.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'simulated local audit outage'); END");
    try {
        expect(fn () => app(NotificationPipeline::class)->materializeOwned($intentId))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_plan_local_audit');
    }
    expect(planNoticeRecoveryRows())->toEqual($baseline)
        ->and(DB::table('notification_inbox_intents')->where('id', $intentId)->sole())->toEqual($inbox)
        ->and(DB::table('notification_inbox_attempts')->count())->toBe($attempts)
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('canonical_audit_events')->count())->toBe($audits);
    (new DeliverPlanNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
    (new DeliverPlanNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
    expect($notice->fresh()->status)->toBe('delivered')->and(DB::table('notifications')->count())->toBe(1)
        ->and(DB::table('notification_inbox_attempts')->count())->toBe($attempts + 1)
        ->and(DB::table('canonical_audit_events')->count())->toBe($audits + 1);
    $event = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->sole();
    expect($event->target_reference)->toBe($plan->plan_id)->and($event->payload['attempt'])->toBe(1);
});

test('invalid local plan source evidence blocks delivery and audit omits an unverified plan identity', function (string $damage): void {
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'SECRET source detail.', 'customer_explanation' => 'Your plan is paused.',
    ])->assertRedirect();
    $notice = PlanNotificationIntent::query()->where('channel', 'database')->where('audience_type', 'subject_customer')->sole();
    $intentId = DB::table('notification_inbox_aliases')->where('family', 'plan')->where('owner_intent_id', $notice->id)->sole()->intent_id;
    $intent = DB::table('notification_inbox_intents')->where('id', $intentId)->sole();
    $foreign = null;
    if ($damage === 'foreign') {
        [, $otherCustomer, $otherAgent] = $this->createLifecycleFixture();
        $foreign = $this->createLifecyclePlan($otherCustomer, $otherAgent->user);
        $this->actingAs($otherAgent->user)->post(route('plans.pause', $foreign->plan_id), [
            'attempt_reference' => (string) Str::uuid(), 'customer_version' => $otherCustomer->version,
            'assignment_version' => $otherCustomer->currentAssignment->version, 'plan_version' => $foreign->version,
            'reason' => 'SECRET foreign detail.', 'customer_explanation' => 'Your other plan is paused.',
        ])->assertRedirect();
        $foreign->refresh();
        $this->actingAs($otherAgent->user)->post(route('plans.resume', $foreign->plan_id), [
            'attempt_reference' => (string) Str::uuid(), 'customer_version' => $otherCustomer->version,
            'assignment_version' => $otherCustomer->currentAssignment->version, 'plan_version' => $foreign->version,
            'reason' => 'SECRET foreign resume.', 'customer_explanation' => 'Your other plan resumed.',
        ])->assertRedirect();
        DB::table('notification_events')->where('id', $intent->event_id)->update([
            'source_id' => $foreign->lifecycleEvents()->where('event_type', 'resume')->sole()->id,
        ]);
    } else {
        DB::table('notification_events')->where('id', $intent->event_id)->update($damage === 'missing'
            ? ['source_id' => PHP_INT_MAX] : ['source_version' => 999]);
    }
    $baseline = planNoticeRecoveryRows();
    (new DeliverPlanNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
    (new DeliverPlanNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
    expect($notice->fresh()->status)->toBe('failed')->and(DB::table('notifications')->count())->toBe(0);
    $event = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->sole();
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
    expect($event->target_reference)->toBeNull()->and($event->target_id)->toBeNull()
        ->and($event->payload['customer_profile_id'])->toBeNull()->and($event->payload['lifecycle_event_id'])->toBeNull()
        ->and($canonical->outcome)->toBe('Failed')->and($canonical->content)->not->toContain('SECRET');
    if ($foreign !== null) {
        expect($canonical->content)->not->toContain($foreign->plan_id);
    }
    unset($baseline['plan_notification_intents']);
    $after = planNoticeRecoveryRows();
    unset($after['plan_notification_intents']);
    expect($after)->toEqual($baseline);
})->with(['foreign', 'missing', 'version']);

/** @return array{ThriftPlan, PlanNotificationIntent} */
function planMailRecoveryFixture(bool $queueOutage = false): array
{
    if (! $queueOutage) {
        Queue::fake();
    }
    [, $customer, $agent] = test()->createLifecycleFixture();
    $plan = test()->createLifecyclePlan($customer, $agent->user);
    if ($queueOutage) {
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('SECRET queue dispatch outage.'));
    }
    test()->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'SECRET private operational reason.', 'customer_explanation' => 'Your dated terms remain unchanged.',
    ])->assertRedirect();

    return [$plan->fresh(), PlanNotificationIntent::query()->where('channel', 'mail')->sole()];
}

function planMailFinancialRows(): array
{
    $rows = planNoticeRecoveryRows();
    unset($rows['plan_notification_intents']);

    return $rows;
}

test('cancelled or uncertain plan mail never reports delivery or automatically resends', function (bool $cancelled): void {
    [, $intent] = planMailRecoveryFixture();
    $baseline = planMailFinancialRows();
    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($cancelled): ?bool {
        if ($event->notification instanceof ThriftPlanNotification && $event->channel === 'mail') {
            if ($cancelled) {
                return false;
            }
            throw new RuntimeException('SECRET provider connection outcome is unknown.');
        }

        return null;
    });
    $job = new DeliverPlanNotificationIntent($intent->id);
    if ($cancelled) {
        $job->handle(app(AgentEligibilityService::class));
    } else {
        expect(fn () => $job->handle(app(AgentEligibilityService::class)))->toThrow(RuntimeException::class);
    }
    $retained = $intent->fresh()->toArray();
    $job->handle(app(AgentEligibilityService::class));
    $job->failed(new RuntimeException('SECRET stale terminal callback'));
    app(ManagementMailDelivery::class)->drain(20);
    expect($intent->fresh()->status)->toBe('uncertain')->and($intent->fresh()->attempt_count)->toBe(1)
        ->and($intent->fresh()->toArray())->toEqual($retained)
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(planMailFinancialRows())->toEqual($baseline);
    $audit = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->sole();
    expect($audit->payload['category'])->toBe($cancelled ? 'acceptance_unconfirmed' : 'delivery_uncertain');
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $audit->id)->sole();
    expect($canonical->outcome)->toBe('Failed')->and($canonical->content)->not->toContain('SECRET');
})->with(['cancelled before transport' => true, 'unknown transport outcome' => false]);

test('post-send plan finalization fault retains one transport claim and scheduled recovery never resends', function (string $fault): void {
    $this->freezeTime();
    [, $intent] = planMailRecoveryFixture();
    $baseline = planMailFinancialRows();
    DB::statement($fault === 'audit'
        ? "CREATE TRIGGER fail_plan_mail_final BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'thrift_plan.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'SECRET final audit outage'); END"
        : "CREATE TRIGGER fail_plan_mail_final BEFORE UPDATE ON plan_notification_intents WHEN NEW.status IN ('delivered', 'uncertain') BEGIN SELECT RAISE(ABORT, 'SECRET final owner outage'); END");
    $job = new DeliverPlanNotificationIntent($intent->id);
    try {
        expect(fn () => $job->handle(app(AgentEligibilityService::class)))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_plan_mail_final');
    }
    expect($intent->fresh()->status)->toBe('sending')->and($intent->fresh()->attempt_count)->toBe(1)
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    Queue::fake();
    app(ManagementMailDelivery::class)->drain(20);
    Queue::assertNothingPushed();
    $job->handle(app(AgentEligibilityService::class));
    expect($intent->fresh()->status)->toBe('sending');
    $this->travel(6)->minutes();
    app(ManagementMailDelivery::class)->drain(20);
    Queue::assertPushed(DeliverPlanNotificationIntent::class, fn (DeliverPlanNotificationIntent $work): bool => $work->intentId === $intent->id);
    $job->handle(app(AgentEligibilityService::class));
    $job->handle(app(AgentEligibilityService::class));
    $job->failed(null);
    app(ManagementMailDelivery::class)->drain(20);
    expect($intent->fresh()->status)->toBe('uncertain')
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(DB::table('management_mail_dispatches')->where('owner_family', 'plan')->where('owner_id', $intent->id)->value('status'))->toBe('complete')
        ->and(planMailFinancialRows())->toEqual($baseline);
    $audit = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->sole();
    expect($audit->payload['category'])->toBe('delivery_uncertain');
})->with(['audit', 'owner']);

test('plan mail derives trusted event content and rejects unsafe current recipients or crossed lifecycle sources', function (string $damage): void {
    [$plan, $intent] = planMailRecoveryFixture();
    $recipient = User::query()->findOrFail($intent->recipient_user_id);
    if ($damage === 'email') {
        $recipient->forceFill(['email' => 'unsafe-address'])->save();
    } elseif ($damage === 'unverified') {
        $recipient->forceFill(['email_verified_at' => null])->save();
    } elseif ($damage === 'role') {
        $recipient->assignRole(UserType::Agent->value);
    } elseif ($damage === 'foreign') {
        [, $otherCustomer, $otherAgent] = $this->createLifecycleFixture();
        $otherPlan = $this->createLifecyclePlan($otherCustomer, $otherAgent->user);
        $this->actingAs($otherAgent->user)->post(route('plans.pause', $otherPlan->plan_id), [
            'attempt_reference' => (string) Str::uuid(), 'customer_version' => $otherCustomer->version,
            'assignment_version' => $otherCustomer->currentAssignment->version, 'plan_version' => $otherPlan->version,
            'reason' => 'SECRET foreign event.', 'customer_explanation' => 'Your other plan is paused.',
        ])->assertRedirect();
        $foreignEvent = $otherPlan->lifecycleEvents()->firstOrFail();
        DB::table('plan_notification_intents')->where('id', $intent->id)->update(['plan_lifecycle_event_id' => $foreignEvent->id]);
    } else {
        DB::table('plan_notification_intents')->where('id', $intent->id)->update(['payload' => json_encode([
            'title' => 'SECRET fabricated title', 'message' => 'SECRET fabricated disclosure', 'plan_id' => 'FOREIGN',
            'status' => 'Closed', 'url' => 'https://unsafe.example.test/SECRET',
        ], JSON_THROW_ON_ERROR)]);
    }
    $baseline = planMailFinancialRows();
    $job = new DeliverPlanNotificationIntent($intent->id);
    $job->handle(app(AgentEligibilityService::class));
    $job->handle(app(AgentEligibilityService::class));
    expect($intent->fresh()->status)->toBe($damage === 'payload' ? 'delivered' : ($damage === 'foreign' ? 'blocked' : 'suppressed'))
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount($damage === 'payload' ? 1 : 0)
        ->and(planMailFinancialRows())->toEqual($baseline);
    if ($damage === 'payload') {
        $html = Crypt::decryptString($intent->fresh()->rendered_snapshot);
        expect($html)->toContain('Status at this update: Paused')->toContain($plan->plan_id)
            ->not->toContain('SECRET')->not->toContain('unsafe.example.test');
    } elseif ($damage === 'foreign') {
        $audit = AuditEvent::query()->where('event_type', 'thrift_plan.delivery_attempt')->sole();
        expect($audit->target_id)->toBeNull()->and($audit->target_reference)->toBeNull()
            ->and($audit->payload['lifecycle_event_id'])->toBeNull();
    }
})->with(['email', 'unverified', 'role', 'foreign', 'payload']);

test('registered plan mail survives commit queue outage and scheduled drain delivers once', function (): void {
    [, $intent] = planMailRecoveryFixture(true);
    $baseline = planMailFinancialRows();
    expect($intent->status)->toBe('pending')
        ->and(DB::table('management_mail_dispatches')->where('owner_family', 'plan')->where('owner_id', $intent->id)->count())->toBe(1)
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    Queue::fake();
    app(ManagementMailDelivery::class)->drain(20);
    Queue::assertPushed(DeliverPlanNotificationIntent::class, fn (DeliverPlanNotificationIntent $job): bool => $job->intentId === $intent->id);
    $job = new DeliverPlanNotificationIntent($intent->id);
    $job->handle(app(AgentEligibilityService::class));
    $job->handle(app(AgentEligibilityService::class));
    app(ManagementMailDelivery::class)->drain(20);
    expect($intent->fresh()->status)->toBe('delivered')
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(DB::table('management_mail_dispatches')->where('owner_family', 'plan')->where('owner_id', $intent->id)->value('status'))->toBe('complete')
        ->and(planMailFinancialRows())->toEqual($baseline);
});
