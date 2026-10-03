<?php

use App\Enums\AdminPermission;
use App\Jobs\DeliverChargeNotificationIntent;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\LedgerAccount;
use App\Models\ManualCharge;
use App\Models\User;
use App\Services\CustomerReassignmentService;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationPipeline;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ManualChargeFixtures.php';

function chargeNoticeFixture(object $test, string $kind = 'deduction', string $mode = 'deduction', bool $post = true): array
{
    $test->freezeTime();
    Queue::fake();
    config()->set(['collections.enabled' => true, 'fees.manual_charges_enabled' => true, 'fees.savings_applications_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $receipt = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
    $receipt['preview_fingerprint'] = $test->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)
        ->assertOk()->json('preview_fingerprint');
    $test->post(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo($kind === 'deduction' ? AdminPermission::DeductionsManage : AdminPermission::FeesManage);
    $test->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])->post(route('admin.charges.publish'), [
            'publication_reference' => (string) Str::uuid(), 'category_key' => 'notice-'.$kind, 'kind' => $kind,
            'purpose' => 'PRIVATE approved service purpose.', 'customer_description' => 'Agreed service charge', 'amount_ngn' => '100.01', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->sole();
    $payload = reviewManualCharge($test, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'PRIVATE independent charge reason.', 'mode' => $mode, 'confirmed' => true]);
    if ($post) {
        $test->postJson(route('admin.charges.assess'), $payload)->assertOk();
    }

    return [$admin, $customer, $agent, $plan, $post ? ManualCharge::query()->sole() : null, $payload];
}

test('actual manual deduction captures Customer and current Agent notices plus one material debit email', function (): void {
    [$admin, $customer, $agent, , $charge, $payload] = chargeNoticeFixture($this);
    $recipients = DB::table('manual_charge_notification_intents')->where('manual_charge_id', $charge->id)->where('channel', 'database')
        ->orderBy('recipient_user_id')->pluck('recipient_user_id')->all();
    expect($recipients)->toBe([$agent->id, $customer->user_id]);
    expect(DB::table('manual_charge_notification_intents')->where('manual_charge_id', $charge->id)->where('channel', 'mail')->count())->toBe(1);
    $mail = DB::table('manual_charge_notification_intents')->where('channel', 'mail')->sole();
    $context = json_decode(Crypt::decryptString(json_decode($mail->payload, true)['context_ciphertext']), true, flags: JSON_THROW_ON_ERROR);
    expect($context['manual_charge_id'])->toBe($charge->id)->and($context['posting_group_id'])->toBe($charge->ledger_posting_group_id)
        ->and($context['cycle_liability_kobo'])->toBe(89999)->and($context['cycle_available_kobo'])->toBe(89999)
        ->and($context['outstanding_fee_kobo'])->toBe(0)->and($context['timezone'])->toBe('Africa/Lagos');
    $financial = chargeNoticeFinancialRows();
    BusinessProfile::current()->update(['timezone' => 'UTC', 'version' => BusinessProfile::current()->version + 1]);
    materializeChargeNotices();
    materializeChargeNotices();
    expect(DB::table('notification_events')->where('family', 'charge')->sole()->timezone)->toBe('Africa/Lagos');
    $this->actingAs($admin)->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(DB::table('manual_charge_notification_intents')->count())->toBe(3)
        ->and(DB::table('notifications')->count())->toBe(2)->and(chargeNoticeFinancialRows())->toEqual($financial);
    $messages = DB::table('notifications')->pluck('data')->implode(' ');
    expect($messages)->toContain('100.01', '899.99', 'Agreed service charge')->not->toContain('PRIVATE');
});

function chargeNoticeFinancialRows(): array
{
    $rows = [];
    foreach (['manual_charges', 'charge_category_versions', 'fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'fee_savings_applications', 'ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_allocations',
        'contribution_slots', 'plan_terms_revisions', 'thrift_plans', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function materializeChargeNotices(): void
{
    foreach (DB::table('notification_inbox_intents')->where('template_id', 'charge.assessed')->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
}

test('manual assessment and combined fee payment retain Agent follow-up without duplicate debit mail', function (string $mode): void {
    [, $customer, $agent, , $charge] = chargeNoticeFixture($this, 'manual_fee', $mode);
    expect(DB::table('manual_charge_notification_intents')->where('channel', 'database')->orderBy('recipient_user_id')->pluck('recipient_user_id')->all())
        ->toBe([$agent->id, $customer->user_id]);
    expect(DB::table('manual_charge_notification_intents')->where('channel', 'mail')->count())->toBe(0)
        ->and(DB::table('fee_application_notification_intents')->where('channel', 'mail')->count())->toBe($mode === 'assess_and_apply' ? 1 : 0);
    $owner = DB::table('manual_charge_notification_intents')->where('recipient_user_id', $customer->user_id)->sole();
    $context = json_decode(Crypt::decryptString(json_decode($owner->payload, true)['context_ciphertext']), true, flags: JSON_THROW_ON_ERROR);
    expect($context['cycle_liability_kobo'])->toBeNull()->and($context['cycle_available_kobo'])->toBeNull()
        ->and($context['outstanding_fee_kobo'])->toBe($mode === 'assess_and_apply' ? 0 : $charge->amount_kobo);
    $financial = chargeNoticeFinancialRows();
    materializeChargeNotices();
    materializeChargeNotices();
    expect(DB::table('notifications')->count())->toBe(2)->and(chargeNoticeFinancialRows())->toEqual($financial);
})->with(['assessment_only', 'assess_and_apply']);

test('actual handover suppresses or hides original Agent charge notice while preserving Customer delivery', function (bool $deliverFirst): void {
    [$admin, $customer, $agent] = chargeNoticeFixture($this);
    $oldNotice = DB::table('manual_charge_notification_intents')->where('recipient_user_id', $agent->id)->sole();
    if ($deliverFirst) {
        materializeChargeNotices();
    }
    $this->travel(1)->seconds();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $owner = app(CustomerReassignmentService::class);
    $review = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $financial = chargeNoticeFinancialRows();
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $review['version'],
        'assignment_version' => $review['assignment_version'], 'target_agent_id' => $replacement->id, 'preview_token' => $review['preview_token'],
        'reason' => 'Current service contact changed.', 'customer_explanation' => 'Your service contact changed.', 'confirmed' => true]);
    materializeChargeNotices();
    $this->actingAs($agent)->get(route('notifications.show', $oldNotice->notification_id))->assertNotFound();
    if (! $deliverFirst) {
        expect(DB::table('manual_charge_notification_intents')->where('id', $oldNotice->id)->value('status'))->toBe('suppressed');
    }
    $customerNotice = DB::table('manual_charge_notification_intents')->where('channel', 'database')->where('recipient_user_id', $customer->user_id)->sole();
    $this->actingAs($customer->user)->get(route('notifications.show', $customerNotice->notification_id))->assertOk();
    expect(chargeNoticeFinancialRows())->toEqual($financial);
})->with(['handover before delivery' => false, 'handover after delivery' => true]);

test('actual deduction mail drains once with safe captured values and financial replay retains one delivery', function (): void {
    config()->set('mail.default', 'array');
    [$admin, $customer, , , $charge, $payload] = chargeNoticeFixture($this);
    $owner = DB::table('manual_charge_notification_intents')->where('channel', 'mail')->sole();
    $financial = chargeNoticeFinancialRows();
    app(ManagementMailDelivery::class)->drain(100);
    (new DeliverChargeNotificationIntent($owner->id))->handle();
    (new DeliverChargeNotificationIntent($owner->id))->handle();
    app(ManagementMailDelivery::class)->drain(100);
    $this->actingAs($admin)->postJson(route('admin.charges.assess'), $payload)->assertOk();
    $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $message = $messages[0]->getOriginalMessage();
    expect($message->getSubject())->toBe('Savings deduction posted '.$charge->operation_reference);
    expect($message->getHtmlBody())->toContain('100.01', '899.99', 'Agreed service charge', route('customers.show', $customer->customer_id))
        ->not->toContain('PRIVATE');
    expect(DB::table('manual_charge_notification_intents')->where('id', $owner->id)->value('status'))->toBe('delivered')
        ->and(DB::table('management_delivery_attempts')->where('owner_family', 'charge')->count())->toBe(1)
        ->and(chargeNoticeFinancialRows())->toEqual($financial);
});

test('changed deduction journal or encrypted notice context suppresses mail without owner effects', function (bool $damageContext): void {
    [, , , , $charge] = chargeNoticeFixture($this);
    $owner = DB::table('manual_charge_notification_intents')->where('channel', 'mail')->sole();
    if ($damageContext) {
        DB::table('manual_charge_notification_intents')->where('id', $owner->id)->update(['payload' => json_encode(['context_ciphertext' => 'corrupted'], JSON_THROW_ON_ERROR)]);
    } else {
        DB::table('ledger_entries')->where('ledger_posting_group_id', $charge->ledger_posting_group_id)->where('side', 'debit')->update(['amount_kobo' => 10002]);
    }
    $financial = chargeNoticeFinancialRows();
    Notification::fake();
    (new DeliverChargeNotificationIntent($owner->id))->handle();
    (new DeliverChargeNotificationIntent($owner->id))->handle();
    Notification::assertNothingSent();
    expect(DB::table('manual_charge_notification_intents')->where('id', $owner->id)->value('status'))->toBe('suppressed')
        ->and(chargeNoticeFinancialRows())->toEqual($financial);
})->with(['changed source' => false, 'changed ciphertext' => true]);

test('charge mail uncertainty retains one attempted outcome without resend or financial replay', function (): void {
    config()->set('mail.default', 'array');
    [$admin, , , , , $payload] = chargeNoticeFixture($this);
    $owner = DB::table('manual_charge_notification_intents')->where('channel', 'mail')->sole();
    $financial = chargeNoticeFinancialRows();
    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if ($event->channel === 'mail') {
            throw new RuntimeException('Transport acceptance is unverified.');
        }
    });
    (new DeliverChargeNotificationIntent($owner->id))->handle();
    (new DeliverChargeNotificationIntent($owner->id))->handle();
    app(ManagementMailDelivery::class)->drain(100);
    $this->actingAs($admin)->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(DB::table('manual_charge_notification_intents')->where('id', $owner->id)->value('status'))->toBe('unknown')
        ->and(DB::table('management_delivery_attempts')->where('owner_family', 'charge')->count())->toBe(1)
        ->and(DB::table('management_delivery_attempts')->where('owner_family', 'charge')->value('outcome'))->toBe('acceptance_unknown')
        ->and(chargeNoticeFinancialRows())->toEqual($financial);
});

test('durable charge notice capture failure rolls back the debit and same instruction retries once', function (string $boundary): void {
    [$admin, , , , , $payload] = chargeNoticeFixture($this, post: false);
    $financial = chargeNoticeFinancialRows();
    DB::statement('CREATE TRIGGER fail_charge_notice BEFORE INSERT ON '.$boundary." BEGIN SELECT RAISE(ABORT, 'notice capture outage'); END");
    try {
        $this->actingAs($admin)->postJson(route('admin.charges.assess'), $payload)->assertServerError();
    } finally {
        DB::statement('DROP TRIGGER fail_charge_notice');
    }
    expect(chargeNoticeFinancialRows())->toEqual($financial)
        ->and(DB::table('manual_charge_notification_intents')->count())->toBe(0)
        ->and(DB::table('notification_events')->where('family', 'charge')->count())->toBe(0);
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    expect(DB::table('manual_charges')->count())->toBe(1)->and(DB::table('manual_charge_notification_intents')->count())->toBe(3)
        ->and(DB::table('management_mail_dispatches')->where('owner_family', 'charge')->count())->toBe(1);
})->with(['in-app capture' => 'manual_charge_notification_intents', 'mail dispatch capture' => 'management_mail_dispatches']);

test('charge inbox delivery failure retains its original pending work and financial source for one retry', function (): void {
    chargeNoticeFixture($this);
    $intent = DB::table('notification_inbox_intents')->where('template_id', 'charge.assessed')->first();
    $financial = chargeNoticeFinancialRows();
    DB::statement("CREATE TRIGGER fail_charge_delivery BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'charge.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'delivery audit unavailable'); END");
    try {
        expect(fn () => app(NotificationPipeline::class)->materializeOwned($intent->id))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_charge_delivery');
    }
    expect(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('notification_inbox_intents')->where('id', $intent->id)->value('status'))->toBe('pending')
        ->and(chargeNoticeFinancialRows())->toEqual($financial);
    app(NotificationPipeline::class)->materialize($intent->id);
    app(NotificationPipeline::class)->materialize($intent->id);
    expect(DB::table('notifications')->count())->toBe(1)->and(chargeNoticeFinancialRows())->toEqual($financial);
});
