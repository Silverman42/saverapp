<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AuditEvent;
use App\Models\FeeRule;
use App\Models\User;
use App\Services\NotificationPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

function feeNoticeManager(): User
{
    $user = User::factory()->admin()->withTwoFactor()->create();
    $user->givePermissionTo(AdminPermission::FeesManage);

    return $user;
}

function publishNoticeRule(object $test, User $actor): FeeRule
{
    $payload = ['kind' => 'registration', 'name' => 'New onboarding terms', 'model' => 'fixed', 'amount_ngn' => '100.01',
        'customer_description' => 'Customer fee disclosure.', 'publication_reason' => 'PRIVATE internal pricing reason.'];
    $review = $test->actingAs($actor)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $test->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->postJson(route('admin.fees.registration.store'), [...$payload, 'confirmed' => true, 'preview_fingerprint' => $review])->assertRedirect();

    return FeeRule::query()->sole();
}

function materializeFeeNotices(): void
{
    foreach (DB::table('notification_inbox_intents')->orderBy('id')->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
}

test('reviewed publication and retirement retain unique safe notices for current fee managers and no other recipients', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    $other = feeNoticeManager();
    $settings = User::factory()->admin()->withTwoFactor()->create();
    $settings->givePermissionTo(AdminPermission::BusinessSettingsManage);
    $suspended = feeNoticeManager();
    $suspended->update(['account_state' => AccountState::Suspended]);
    User::factory()->agent()->create();
    $rule = publishNoticeRule($this, $actor);
    $this->assertDatabaseCount('fee_rule_events', 1);
    $this->assertDatabaseCount('fee_rule_notification_intents', 2);
    expect(DB::table('fee_rule_notification_intents')->orderBy('recipient_user_id')->pluck('recipient_user_id')->all())
        ->toBe([$actor->id, $other->id]);
    expect(DB::table('fee_rule_events')->value('audit_event_id'))->toBe(DB::table('audit_events')->where('event_type', 'fee_rule.published')->value('id'));
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    materializeFeeNotices();
    materializeFeeNotices();
    $this->assertDatabaseCount('notifications', 2);
    $notice = DB::table('fee_rule_notification_intents')->where('recipient_user_id', $other->id)->sole();
    $this->actingAs($other)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $this->get(route('notifications.open', $notice->notification_id))->assertRedirect(route('admin.fees.registration.index', absolute: false));
    expect(DB::table('notifications')->where('id', $notice->notification_id)->value('data'))
        ->toContain('version 1', 'Effective time (UTC)', 'existing agreements retain')->not->toContain('PRIVATE', 'internal pricing');
    $this->actingAs($settings)->get(route('notifications.show', $notice->notification_id))->assertNotFound();
    $this->travel(1)->seconds();
    $reason = 'PRIVATE retirement governance.';
    $review = $this->actingAs($actor)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => $reason])->assertOk()->json('preview_fingerprint');
    $this->postJson(route('admin.fees.registration.retire', $rule), ['reason' => $reason, 'confirmed' => true, 'preview_fingerprint' => $review])->assertRedirect();
    materializeFeeNotices();
    $this->assertDatabaseCount('fee_rule_events', 2);
    $this->assertDatabaseCount('fee_rule_notification_intents', 4);
    $this->assertDatabaseCount('notifications', 4);
    $this->assertDatabaseCount('ledger_entries', 0);
    expect(DB::table('fee_rule_events')->where('event_type', 'retired')->value('audit_event_id'))
        ->toBe(DB::table('audit_events')->where('event_type', 'fee_rule.retired')->value('id'));
    expect(DB::table('notifications')->pluck('data')->implode(' '))->not->toContain('PRIVATE');
    $attempts = AuditEvent::query()->where('event_type', 'fee_rule.delivery_attempt')->get();
    expect($attempts)->toHaveCount(4);
    foreach ($attempts as $attempt) {
        $source = DB::table('fee_rule_events')->where('id', $attempt->payload['fee_rule_event_id'])->sole();
        $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $attempt->id)->sole();
        expect($attempt->target_id)->toBe($rule->id)->and($attempt->target_reference)->toBe('registration:registration:v1')
            ->and($attempt->actor_id)->toBeNull()->and($attempt->payload['attempt'])->toBe(1)
            ->and($attempt->payload['source_audit_event_id'])->toBe($source->audit_event_id)
            ->and($canonical->outcome)->toBe('Succeeded')->and($canonical->content)->not->toContain('PRIVATE');
    }
    Queue::assertPushed(MaterializeNotificationIntent::class, 4);
});

test('fee manager permission or active account loss suppresses pending notice without changing the published rule', function (string $loss): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    $recipient = feeNoticeManager();
    $rule = publishNoticeRule($this, $actor);
    $original = $rule->getAttributes();
    if ($loss === 'grant') {
        $recipient->revokePermissionTo(AdminPermission::FeesManage);
    } else {
        $recipient->update(['account_state' => AccountState::Suspended]);
    }
    materializeFeeNotices();
    $notice = DB::table('fee_rule_notification_intents')->where('recipient_user_id', $recipient->id)->sole();
    expect($notice->status)->toBe('suppressed');
    $attempt = AuditEvent::query()->where('event_type', 'fee_rule.delivery_attempt')->where('payload->notification_reference', $notice->notification_id)->sole();
    expect($attempt->payload['category'])->toBe('scope_or_expiry');
    expect(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $attempt->id)->value('outcome'))->toBe('Denied');
    $this->assertDatabaseMissing('notifications', ['id' => $notice->notification_id]);
    expect($rule->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('fee_rule_events', 1);
    $this->assertDatabaseCount('fee_obligations', 0);
    Queue::assertPushed(MaterializeNotificationIntent::class, 2);
})->with(['grant', 'account']);

test('delivered fee notice reads and destination disappear after matching grant revocation', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    publishNoticeRule($this, $actor);
    materializeFeeNotices();
    $notice = DB::table('fee_rule_notification_intents')->sole();
    $this->actingAs($actor)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $actor->revokePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($actor->fresh())->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
    $this->get(route('notifications.show', $notice->notification_id))->assertNotFound();
    $this->get(route('notifications.open', $notice->notification_id))->assertNotFound();
    $this->patchJson(route('notifications.read', $notice->notification_id), ['read' => true, 'version' => 1])->assertNotFound();
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertPushed(MaterializeNotificationIntent::class, 1);
});

test('repeated owner capture and delivery recover the same fee notification identity', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    publishNoticeRule($this, $actor);
    $owner = DB::table('fee_rule_notification_intents')->sole();
    $pipeline = app(NotificationPipeline::class);
    $first = $pipeline->capture('fee_rule', $owner->id, false);
    expect($pipeline->capture('fee_rule', $owner->id, false))->toBe($first);
    $pipeline->deliverOwner('fee_rule', $owner->id);
    $pipeline->deliverOwner('fee_rule', $owner->id);
    $this->assertDatabaseCount('notification_events', 1);
    $this->assertDatabaseCount('notification_inbox_intents', 1);
    $this->assertDatabaseCount('notification_inbox_attempts', 1);
    $this->assertDatabaseCount('notifications', 1);
    expect(DB::table('notifications')->value('id'))->toBe($owner->notification_id);
    Queue::assertPushed(MaterializeNotificationIntent::class, 1);
});

test('fee notice capture failure rolls back publication and its audit and delivery sources', function (): void {
    $this->freezeTime();
    $actor = feeNoticeManager();
    $payload = ['model' => 'fixed', 'name' => 'Rollback terms', 'amount_ngn' => '100.01',
        'customer_description' => 'Original fee disclosure.', 'publication_reason' => 'Reviewed governance.'];
    $review = $this->actingAs($actor)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $this->partialMock(NotificationPipeline::class, function (MockInterface $mock): void {
        $mock->shouldReceive('capture')->once()->with('fee_rule', Mockery::type('int'))
            ->andThrow(new RuntimeException('Injected durable capture failure.'));
    });
    $this->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->postJson(route('admin.fees.registration.store'), [...$payload, 'confirmed' => true, 'preview_fingerprint' => $review])->assertInternalServerError();
    foreach (['fee_rules', 'fee_rule_events', 'fee_rule_notification_intents', 'notification_events', 'notification_inbox_intents', 'notifications'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'fee_rule.published']);
});

test('a mismatched fee notice source is blocked before delivery', function (string $damage): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    publishNoticeRule($this, $actor);
    if ($damage === 'version') {
        DB::table('fee_rule_events')->update(['version' => 2]);
    } else {
        DB::table('fee_rule_events')->update(['actor_user_id' => User::factory()->admin()->create()->id]);
    }
    materializeFeeNotices();
    expect(DB::table('notification_inbox_intents')->value('status'))->toBe('blocked');
    expect(DB::table('notification_inbox_intents')->value('failure_category'))->toBe('unsupported_contract');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('fee_rules', 1);
    $attempt = AuditEvent::query()->where('event_type', 'fee_rule.delivery_attempt')->sole();
    expect($attempt->target_id)->toBeNull()->and($attempt->target_reference)->toBeNull()
        ->and($attempt->payload['fee_rule_event_id'])->toBeNull()->and($attempt->payload['source_audit_event_id'])->toBeNull();
    expect(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $attempt->id)->value('outcome'))->toBe('Failed');
    Queue::assertPushed(MaterializeNotificationIntent::class, 1);
})->with(['version', 'parent actor']);

test('retirement notice capture failure rolls back applicability and retains the original publication notice', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    $rule = publishNoticeRule($this, $actor);
    $original = $rule->getAttributes();
    $this->travel(1)->seconds();
    $reason = 'End new selection.';
    $review = $this->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => $reason])->assertOk()->json('preview_fingerprint');
    $this->partialMock(NotificationPipeline::class, function (MockInterface $mock): void {
        $mock->shouldReceive('capture')->once()->with('fee_rule', Mockery::type('int'))
            ->andThrow(new RuntimeException('Injected retirement notice failure.'));
    });
    $this->postJson(route('admin.fees.registration.retire', $rule), ['reason' => $reason, 'confirmed' => true,
        'preview_fingerprint' => $review])->assertInternalServerError();
    expect($rule->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('fee_rule_events', 1);
    $this->assertDatabaseCount('fee_rule_notification_intents', 1);
    $this->assertDatabaseCount('notification_inbox_intents', 1);
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'fee_rule.retired']);
    Queue::assertPushed(MaterializeNotificationIntent::class, 1);
});

test('local fee notice delivery failure retains the published rule and recovers the same intent without reassessment', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    $rule = publishNoticeRule($this, $actor);
    $original = $rule->getAttributes();
    $owner = DB::table('fee_rule_notification_intents')->sole();
    $intent = DB::table('notification_inbox_intents')->sole();
    DB::statement("CREATE TRIGGER fail_fee_notice BEFORE UPDATE ON notification_inbox_intents WHEN NEW.status = 'delivered' BEGIN SELECT RAISE(ABORT, 'simulated local notice outage'); END");
    try {
        app(NotificationPipeline::class)->materialize($intent->id);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_notice');
    }
    $failed = DB::table('notification_inbox_intents')->where('id', $intent->id)->sole();
    expect($failed->status)->toBe('pending');
    expect($failed->next_attempt_at)->not->toBeNull();
    expect($rule->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->travelTo(CarbonImmutable::parse($failed->next_attempt_at)->addSecond());
    app(NotificationPipeline::class)->materialize($intent->id);
    app(NotificationPipeline::class)->materialize($intent->id);
    expect(DB::table('fee_rule_notification_intents')->value('status'))->toBe('delivered');
    expect(DB::table('notifications')->value('notifiable_id'))->toBe($actor->id);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseCount('fee_rule_events', 1);
    $this->assertDatabaseCount('fee_rule_notification_intents', 1);
    $this->assertDatabaseCount('ledger_entries', 0);
    expect($rule->fresh()->getAttributes())->toBe($original);
    $attempts = AuditEvent::query()->where('event_type', 'fee_rule.delivery_attempt')->orderBy('id')->get();
    expect($attempts)->toHaveCount(2);
    foreach ($attempts as $index => $attempt) {
        $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $attempt->id)->sole();
        expect($attempt->target_id)->toBe($rule->id)->and($attempt->payload['attempt'])->toBe($index + 1)
            ->and($canonical->outcome)->toBe($index === 0 ? 'Failed' : 'Succeeded')
            ->and($canonical->content)->not->toContain('simulated local notice outage', 'PRIVATE');
    }
    expect(Queue::pushed(MaterializeNotificationIntent::class)->where('intentId', $intent->id))->toHaveCount(1);
});

test('fee notice canonical audit failure rolls back local delivery before a same-intent retry', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    $actor = feeNoticeManager();
    $rule = publishNoticeRule($this, $actor);
    $original = $rule->getAttributes();
    $intent = DB::table('notification_inbox_intents')->sole();
    $originalAudits = DB::table('canonical_audit_events')->count();
    DB::statement("CREATE TRIGGER fail_fee_notice_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'fee_rule.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'simulated canonical audit outage'); END");
    try {
        expect(fn () => app(NotificationPipeline::class)->materializeOwned($intent->id))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_notice_audit');
    }
    expect(DB::table('notification_inbox_intents')->sole())->toEqual($intent);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('notification_inbox_attempts', 0);
    $this->assertDatabaseCount('canonical_audit_events', $originalAudits);
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'fee_rule.delivery_attempt']);
    expect($rule->fresh()->getAttributes())->toBe($original);
    app(NotificationPipeline::class)->materialize($intent->id);
    app(NotificationPipeline::class)->materialize($intent->id);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseCount('notification_inbox_attempts', 1);
    $this->assertDatabaseCount('canonical_audit_events', $originalAudits + 1);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.delivery_attempt')->sole()->payload['attempt'])->toBe(1);
    Queue::assertPushed(MaterializeNotificationIntent::class, 1);
});
