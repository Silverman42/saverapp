<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Notifications\FeeSavingsApplicationMailNotification;
use App\Services\CustomerReassignmentService;
use App\Services\FeeSavingsApplicationService;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationPipeline;
use App\Services\WithdrawalBalanceService;
use App\Support\PlatformJobMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

function feeApplicationNoticeFixture(bool $post = true): array
{
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $request = Request::create('/admin/fees/application', 'POST');
    $session = new Store('fee-application-notice', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE independently reviewed payment reason.', 'customer_description' => 'Registration paid from your selected cycle.'];
    $quote = $owner->preview($admin, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $group = $post ? $owner->apply($admin, $fee->id, $payload, $request) : null;

    return [$admin, $customer, $agent, $plan, $fee, $payload, $request, $group];
}

function feeApplicationNoticeFinancialRows(): array
{
    $rows = [];
    foreach (['fee_savings_applications', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'collection_receipts', 'collection_allocations', 'withdrawal_reservations', 'thrift_plans'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function materializeApplicationNotices(): void
{
    foreach (DB::table('notification_inbox_intents')->where('template_id', 'fee_application.posted')->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
}

test('an explicit savings application retains one safe source audited notice per Customer and current Agent through delivery and replay', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    [$admin, $customer, $agent, , $fee, $payload, $request, $group] = feeApplicationNoticeFixture();
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    expect(DB::table('notification_events')->where('family', 'fee_application')->count())->toBe(1);
    expect(DB::table('fee_application_notification_intents')->where('channel', 'database')->orderBy('recipient_user_id')->pluck('recipient_user_id')->all())
        ->toBe([$agent->id, $customer->user_id]);
    $event = DB::table('notification_events')->where('family', 'fee_application')->sole();
    expect($event->audit_event_id)->toBe(DB::table('audit_events')->where('event_type', 'ledger.fee_posted')->value('id'));
    expect($event->timezone)->toBe($group->business_timezone);
    $financial = feeApplicationNoticeFinancialRows();

    materializeApplicationNotices();
    materializeApplicationNotices();
    expect(app(FeeSavingsApplicationService::class)->apply($admin, $fee->id, $payload, $request)->id)->toBe($group->id);

    $this->assertDatabaseCount('notifications', 2);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
    $data = DB::table('notifications')->pluck('data')->map(fn (string $data): string => json_decode($data, true, flags: JSON_THROW_ON_ERROR)['message'])->implode(' ');
    expect($data)->toContain('₦200.00', 'Registration paid from your selected cycle.', 'At posting: cycle savings ₦800.00', 'available cycle savings ₦800.00', 'unpaid fee ₦0.00')->not->toContain('PRIVATE', 'payment reason');
    $attempts = AuditEvent::query()->where('event_type', 'fee_application.delivery_attempt')->get();
    expect($attempts)->toHaveCount(2);
    foreach ($attempts as $attempt) {
        expect($attempt->target_id)->toBe($group->id)->and($attempt->target_reference)->toBe($group->posting_reference);
        expect($attempt->payload['source_audit_event_id'])->toBe($event->audit_event_id);
        expect(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $attempt->id)->value('outcome'))->toBe('Succeeded');
    }
    $notice = DB::table('fee_application_notification_intents')->where('channel', 'database')->where('recipient_user_id', $customer->user_id)->sole();
    $this->actingAs($customer->user)->get(route('notifications.open', $notice->notification_id))
        ->assertRedirect(route('customers.show', $customer->customer_id, absolute: false));
    $this->actingAs($admin)->get(route('notifications.show', $notice->notification_id))->assertNotFound();
});

test('actual reassignment suppresses or hides an original Agent application notice while preserving Customer delivery and money', function (bool $deliverFirst): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    [$admin, $customer, $agent] = feeApplicationNoticeFixture();
    $oldNotice = DB::table('fee_application_notification_intents')->where('recipient_user_id', $agent->id)->sole();
    if ($deliverFirst) {
        materializeApplicationNotices();
    }
    $this->travel(1)->seconds();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $owner = app(CustomerReassignmentService::class);
    $review = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $financial = feeApplicationNoticeFinancialRows();
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $review['version'],
        'assignment_version' => $review['assignment_version'], 'preview_token' => $review['preview_token'], 'target_agent_id' => $replacement->id,
        'confirmed' => true, 'reason' => 'Reviewed service transfer.', 'customer_explanation' => 'Your service contact changed.']);

    materializeApplicationNotices();

    expect(DB::table('fee_application_notification_intents')->where('id', $oldNotice->id)->value('status'))->toBe($deliverFirst ? 'delivered' : 'suppressed');
    expect(DB::table('fee_application_notification_intents')->where('channel', 'database')->where('recipient_user_id', $customer->user_id)->value('status'))->toBe('delivered');
    $this->actingAs($agent)->get(route('notifications.show', $oldNotice->notification_id))->assertNotFound();
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
})->with(['before delivery' => false, 'after delivery' => true]);

test('a damaged application source blocks pending success notices and omits unverified canonical source identities without posting money', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    [, , , , , , , $group] = feeApplicationNoticeFixture();
    DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'debit')->update(['amount_kobo' => 20001]);
    $financial = feeApplicationNoticeFinancialRows();

    materializeApplicationNotices();

    $this->assertDatabaseCount('notifications', 0);
    expect(DB::table('fee_application_notification_intents')->where('channel', 'database')->pluck('status')->all())->toBe(['failed', 'failed']);
    expect(DB::table('notification_inbox_intents')->where('template_id', 'fee_application.posted')->pluck('status')->all())->toBe(['blocked', 'blocked']);
    $attempts = AuditEvent::query()->where('event_type', 'fee_application.delivery_attempt')->get();
    expect($attempts)->toHaveCount(2);
    foreach ($attempts as $attempt) {
        expect($attempt->target_id)->toBeNull()->and($attempt->payload['application_id'])->toBeNull()
            ->and($attempt->payload['source_audit_event_id'])->toBeNull();
    }
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
});

test('durable application notice capture failure rolls back the complete financial owner and permits one original retry', function (string $boundary): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    [$admin, , , , $fee, $payload, $request] = feeApplicationNoticeFixture(false);
    $financial = feeApplicationNoticeFinancialRows();
    DB::statement('CREATE TRIGGER fail_app_notice BEFORE INSERT ON '.$boundary." BEGIN SELECT RAISE(ABORT, 'notice capture outage'); END");
    try {
        expect(fn () => app(FeeSavingsApplicationService::class)->apply($admin, $fee->id, $payload, $request))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_app_notice');
    }
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
    $this->assertDatabaseCount('fee_application_notification_intents', 0);
    expect(DB::table('notification_events')->where('family', 'fee_application')->count())->toBe(0);

    app(FeeSavingsApplicationService::class)->apply($admin, $fee->id, $payload, $request);

    $this->assertDatabaseCount('fee_savings_applications', 1);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    expect(DB::table('management_mail_dispatches')->where('owner_family', 'fee_application')->count())->toBe(1);
})->with(['in-app capture' => 'fee_application_notification_intents', 'mail dispatch capture' => 'management_mail_dispatches']);

test('canonical application delivery capture failure rolls back inbox delivery while the paid fee remains unchanged and retry delivers once', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    feeApplicationNoticeFixture();
    $intent = DB::table('notification_inbox_intents')->where('template_id', 'fee_application.posted')->first();
    $financial = feeApplicationNoticeFinancialRows();
    DB::statement("CREATE TRIGGER fail_app_notice_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'fee_application.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'canonical delivery outage'); END");
    try {
        expect(fn () => app(NotificationPipeline::class)->materializeOwned($intent->id))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_app_notice_audit');
    }
    $this->assertDatabaseCount('notifications', 0);
    expect(DB::table('notification_inbox_intents')->where('id', $intent->id)->value('status'))->toBe('pending');
    expect(AuditEvent::query()->where('event_type', 'fee_application.delivery_attempt')->count())->toBe(0);
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);

    app(NotificationPipeline::class)->materialize($intent->id);
    app(NotificationPipeline::class)->materialize($intent->id);

    $this->assertDatabaseCount('notifications', 1);
    expect(AuditEvent::query()->where('event_type', 'fee_application.delivery_attempt')->count())->toBe(1);
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
});

test('Customer application email retains posting balances after a later reservation and delivers once through drain and financial replay', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('mail.default', 'array');
    [$admin, $customer, $agent, $plan, $fee, $payload, $request, $group] = feeApplicationNoticeFixture();
    enableFixtureMethod();
    submittedWithdrawal($agent, $customer, $customer->currentAssignment, $plan);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(50000);
    $financial = feeApplicationNoticeFinancialRows();
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'mail')->sole();
    $source = DB::table('fee_savings_applications')->sole();
    expect($source->remaining_cycle_savings_kobo)->toBe(80000)->and($source->remaining_available_kobo)->toBe(80000)
        ->and($source->remaining_fee_kobo)->toBe(0);

    app(ManagementMailDelivery::class)->drain(100);
    $job = new DeliverFeeApplicationNotificationIntent($owner->id);
    $job->handle();
    $job->handle();
    app(ManagementMailDelivery::class)->drain(100);
    expect(app(FeeSavingsApplicationService::class)->apply($admin, $fee->id, $payload, $request)->id)->toBe($group->id);

    $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $html = $messages[0]->getOriginalMessage()->getHtmlBody();
    expect($messages[0]->getOriginalMessage()->getSubject())->toBe('Fee paid from savings '.$group->posting_reference);
    expect($html)->toContain('Registration paid from your selected cycle.', '₦200.00', 'At posting: cycle savings ₦800.00',
        'available cycle savings ₦800.00', 'unpaid fee ₦0.00', $group->posting_reference, route('customers.show', $customer->customer_id))
        ->not->toContain('PRIVATE', 'payment reason', '₦500.00');
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('delivered');
    $this->assertDatabaseHas('management_mail_dispatches', ['owner_family' => 'fee_application', 'owner_id' => $owner->id, 'status' => 'complete']);
    $this->assertDatabaseCount('management_delivery_attempts', 1);
    $audit = AuditEvent::query()->where('event_type', 'fee_application.delivery_state_recorded')->sole();
    expect($audit->target_id)->toBe($group->id)->and($audit->payload['outcome'])->toBe('transport_accepted');
    expect(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $audit->id)->value('outcome'))->toBe('Succeeded');
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
});

test('unsafe Customer mail identity or damaged financial source suppresses application email without exposing unverified source evidence', function (string $damage): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    [, $customer, , , , , , $group] = feeApplicationNoticeFixture();
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'mail')->sole();
    if ($damage === 'source') {
        DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'debit')->update(['amount_kobo' => 20001]);
    } elseif ($damage === 'account') {
        $customer->user->forceFill(['account_state' => AccountState::Deactivated])->save();
    } elseif ($damage === 'email') {
        $customer->user->forceFill(['email' => 'unsafe-address'])->save();
    } else {
        DB::table('fee_application_notification_intents')->where('id', $owner->id)->update(['recipient_user_id' => User::factory()->customer()->create()->id]);
    }
    $financial = feeApplicationNoticeFinancialRows();
    Notification::fake();

    (new DeliverFeeApplicationNotificationIntent($owner->id))->handle();
    (new DeliverFeeApplicationNotificationIntent($owner->id))->handle();

    Notification::assertNothingSent();
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('suppressed');
    $audit = AuditEvent::query()->where('event_type', 'fee_application.delivery_state_recorded')->sole();
    expect($audit->payload['outcome'])->toBe('suppressed');
    if (in_array($damage, ['source', 'recipient'], true)) {
        expect($audit->target_id)->toBeNull()->and($audit->payload['source_audit_event_id'])->toBeNull()->and($audit->payload['application_id'])->toBeNull();
    }
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
})->with(['source', 'account', 'email', 'recipient']);

test('application email transport uncertainty remains unknown without a resend or repeated financial effect', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    config()->set('mail.default', 'array');
    feeApplicationNoticeFixture();
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'mail')->sole();
    $financial = feeApplicationNoticeFinancialRows();
    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if ($event->notification instanceof FeeSavingsApplicationMailNotification) {
            throw new RuntimeException('PRIVATE provider response after send began.');
        }
    });
    $job = new DeliverFeeApplicationNotificationIntent($owner->id);

    $job->handle();
    Notification::fake();
    $this->travel(6)->minutes();
    $job->handle();
    app(ManagementMailDelivery::class)->drain(100);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('management_delivery_attempts', 1);
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('unknown');
    expect(DB::table('management_delivery_attempts')->value('outcome'))->toBe('acceptance_unknown');
    $audit = AuditEvent::query()->where('event_type', 'fee_application.delivery_state_recorded')->sole();
    expect($audit->payload['outcome'])->toBe('acceptance_unknown');
    expect(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $audit->id)->value('content'))->not->toContain('PRIVATE', 'provider response');
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
});

test('application mail canonical finalization failure retains uncertain acceptance and recovers without a second send', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    config()->set('mail.default', 'array');
    feeApplicationNoticeFixture();
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'mail')->sole();
    $financial = feeApplicationNoticeFinancialRows();
    $job = new DeliverFeeApplicationNotificationIntent($owner->id);
    DB::statement("CREATE TRIGGER fail_app_mail_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'fee_application.delivery_state_recorded' BEGIN SELECT RAISE(ABORT, 'canonical mail outage'); END");
    try {
        expect(fn () => app(PlatformJobMiddleware::class)->handle($job, fn (DeliverFeeApplicationNotificationIntent $queued) => $queued->handle()))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_app_mail_audit');
    }
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('sending');
    expect(DB::table('management_delivery_attempts')->value('outcome'))->toBe('acceptance_unknown');
    $job->handle();
    app(ManagementMailDelivery::class)->drain(100);
    $this->assertDatabaseHas('management_mail_dispatches', ['owner_family' => 'fee_application', 'owner_id' => $owner->id, 'status' => 'pending', 'dispatch_count' => 0]);
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('sending');

    $this->travel(6)->minutes();
    app(ManagementMailDelivery::class)->drain(100);
    Queue::assertPushed(DeliverFeeApplicationNotificationIntent::class, fn (DeliverFeeApplicationNotificationIntent $queued): bool => $queued->intentId === $owner->id);
    $job->handle();
    $job->handle();

    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('unknown');
    $this->assertDatabaseCount('management_delivery_attempts', 1);
    expect(AuditEvent::query()->where('event_type', 'fee_application.delivery_state_recorded')->count())->toBe(1);
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
});

test('application mail cannot claim accepted delivery after a silent veto or an error following transport acceptance', function (bool $veto): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class, DeliverFeeApplicationNotificationIntent::class]);
    config()->set('mail.default', 'array');
    feeApplicationNoticeFixture();
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'mail')->sole();
    $financial = feeApplicationNoticeFinancialRows();
    if ($veto) {
        Event::listen(NotificationSending::class, fn (NotificationSending $event): ?bool => $event->notification instanceof FeeSavingsApplicationMailNotification ? false : null);
    } else {
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->notification instanceof FeeSavingsApplicationMailNotification) {
                throw new RuntimeException('PRIVATE failure following provider acceptance.');
            }
        });
    }
    $job = new DeliverFeeApplicationNotificationIntent($owner->id);

    $job->handle();
    $job->handle();
    $this->travel(6)->minutes();
    $job->handle();

    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount($veto ? 0 : 1);
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('unknown');
    $this->assertDatabaseCount('management_delivery_attempts', 1);
    expect(DB::table('management_delivery_attempts')->value('outcome'))->toBe('acceptance_unknown');
    $audit = AuditEvent::query()->where('event_type', 'fee_application.delivery_state_recorded')->sole();
    expect($audit->payload['outcome'])->toBe('acceptance_unknown');
    expect(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $audit->id)->value('outcome'))->toBe('Failed');
    expect(feeApplicationNoticeFinancialRows())->toEqual($financial);
})->with(['silent veto' => true, 'failure after acceptance' => false]);
