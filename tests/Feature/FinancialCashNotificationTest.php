<?php

use App\Enums\AdminPermission;
use App\Jobs\DeliverFinancialCashNotificationIntent;
use App\Models\AgentProfile;
use App\Models\CashDisbursement;
use App\Models\CollectionBatch;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Notifications\FinancialCashMailNotification;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    Queue::fake();
    LedgerAccount::query()->whereNotNull('effective_at')->update(['effective_at' => now()->subDay()]);
    DB::table('cash_method_versions')->update(['effective_at' => now()->subDay()]);
    config()->set(['collections.enabled' => true, 'fees.savings_applications_enabled' => true,
        'fees.refunds_enabled' => true, 'fees.cash_disbursements_enabled' => true, 'mail.default' => 'array']);
});

/** @return array{User, CustomerProfile, User, FeeObligation, array<string, mixed>} */
function financialCashRefundFixture(object $test, string $kind, bool $post = true): array
{
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 50000);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    if ($kind === 'external') {
        $payload['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']];
    }
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReconciliationManage);
    $batch = CollectionBatch::query()->findOrFail($receipt->collection_batch_id);
    $frozenAt = CarbonImmutable::now();
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->travelTo($frozenAt);
    $test->actingAs($reviewer)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'REFUND-NOTICE-'.$batch->id,
        'amount_ngn' => $kind === 'external' ? '2500.00' : '2000.00', 'handoff_date' => $date,
        'receiving_location' => 'Business till', 'source_attestation' => 'Counted original tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent custody reconciliation.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $test->actingAs($admin)->withSession(cashSession());
    if ($kind === 'savings') {
        $review = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed payment of the agreed fee.', 'customer_description' => 'Registration paid from savings.'];
        $quote = $test->postJson(route('admin.fees.obligations.savings-preview', $fee), $review)->assertOk()->json();
        $test->postJson(route('admin.fees.obligations.apply-savings', $fee), [...$review,
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
            'quote_expires_at' => $quote['quote_expires_at'], 'confirmed' => true])->assertOk();
    }
    $refund = ['refund_reference' => (string) Str::uuid(), 'kind' => $kind, 'amount_ngn' => '100.00',
        'reason' => 'PRIVATE independently approved concession.', 'confirmed' => true];
    if ($post) {
        $test->post(route('admin.fees.refunds.store', $fee), $refund)->assertRedirect()->assertSessionHasNoErrors();
    }

    return [$admin, $customer, $agent, $fee, $refund];
}

/** @return array<string, array<int, object>> */
function financialCashNoticeMoneyRows(): array
{
    $rows = [];
    foreach (['fee_refunds', 'fee_obligations', 'fee_obligation_entries', 'fee_snapshots', 'fee_savings_applications',
        'collection_receipts', 'collection_allocations', 'collection_fee_components', 'cash_remittances',
        'ledger_posting_groups', 'ledger_entries', 'cash_disbursements', 'cash_recoveries', 'thrift_plans', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function materializeFinancialCashNotices(): void
{
    foreach (DB::table('notification_inbox_intents')->join('notification_events', 'notification_events.id', '=', 'notification_inbox_intents.event_id')
        ->where('notification_events.family', 'financial_cash')->pluck('notification_inbox_intents.id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
}

function assertFinancialCashDeliveryIssue(object $test, User $operator, User $customer, object $mail, string $audience): object
{
    $issue = DB::table('fee_operational_issues')->where('source_family', 'financial_cash')->where('source_owner_id', $mail->id)->sole();
    $audit = DB::table('audit_events')->where('id', $issue->audit_event_id)->sole();
    $payload = json_decode($audit->payload, true, flags: JSON_THROW_ON_ERROR);
    $source = DB::table('financial_cash_events')->where('id', $mail->financial_cash_event_id)->sole();
    expect($issue->state)->toBe('acceptance_unknown')->and($issue->customer_profile_id)->toBe($mail->customer_profile_id)
        ->and($audit->event_type)->toBe(in_array($audience, ['refund_cash_operator'], true) ? 'cash_disbursement.delivery_state_recorded' : 'fee.delivery_state_recorded')
        ->and($payload['notification_reference'])->toBe($mail->notification_id)
        ->and($payload['source_event_id'])->toBe($source->id)->and($payload['source_audit_event_id'])->toBe($source->audit_event_id)
        ->and($payload['outcome'])->toBe('acceptance_unknown');
    $intents = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->get();
    expect($intents)->toHaveCount(1)->and($intents->sole()->recipient_user_id)->toBe($operator->id)
        ->and($intents->sole()->audience_type)->toBe($audience)->and($intents->sole()->channel)->toBe('database')
        ->and(DB::table('fee_issue_notification_intents')->where('recipient_user_id', $customer->id)->count())->toBe(0);
    $notice = $intents->sole();
    foreach (DB::table('notification_inbox_intents')->where('notification_id', $notice->notification_id)->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
    $test->actingAs($operator)->get(route('notifications.show', $notice->notification_id))->assertOk();
    $test->actingAs($customer)->get(route('notifications.show', $notice->notification_id))->assertNotFound();

    return $notice;
}

test('actual fee refund has distinct source-bound Customer Agent manager and material mail notices through replay', function (string $kind): void {
    [$admin, $customer, $agent, $fee, $payload] = financialCashRefundFixture($this, $kind);
    $event = DB::table('financial_cash_events')->where('event_type', 'refund_authorized')->sole();
    $owners = DB::table('financial_cash_notification_intents')->where('financial_cash_event_id', $event->id)->get();
    expect($owners)->toHaveCount(4)->and($owners->where('channel', 'database')->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect([$admin->id, $customer->user_id, $agent->id])->sort()->values()->all());
    $notice = DB::table('notification_events')->where('family', 'financial_cash')->sole();
    $summary = DB::table('notification_inbox_intents')->where('event_id', $notice->id)->where('recipient_user_id', $customer->user_id)->value('summary');
    expect($notice->audit_event_id)->toBe($event->audit_event_id)->and($notice->timezone)->toBe('Africa/Lagos')
        ->and($summary)->toContain($kind === 'savings' ? 'restored your savings' : 'refund is payable')
        ->not->toContain('PRIVATE')->not->toContain('was paid');
    $money = financialCashNoticeMoneyRows();
    materializeFinancialCashNotices();
    materializeFinancialCashNotices();
    $mail = $owners->where('channel', 'mail')->sole();
    app(ManagementMailDelivery::class)->drain(100);
    $job = new DeliverFinancialCashNotificationIntent($mail->id);
    $job->handle();
    $job->handle();
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('financial_cash_events')->count())->toBe(1)
        ->and(DB::table('financial_cash_notification_intents')->count())->toBe(4)
        ->and(DB::table('financial_cash_notification_intents')->where('id', $mail->id)->value('status'))->toBe('delivered')
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(financialCashNoticeMoneyRows())->toEqual($money);
    foreach ($owners->where('channel', 'database') as $owner) {
        $recipient = User::query()->findOrFail($owner->recipient_user_id);
        $this->actingAs($recipient)->get(route('notifications.show', $owner->notification_id))->assertOk();
    }
})->with(['savings', 'external']);

test('actual external refund payment keeps cash operator authority separate and announces paid only after receipt', function (): void {
    [$manager, $customer] = financialCashRefundFixture($this, 'external');
    enableFixtureMethod();
    $operator = User::factory()->admin()->withTwoFactor()->create();
    $operator->givePermissionTo(AdminPermission::CashExecute);
    $refund = FeeRefund::query()->sole();
    $payment = ['execution_reference' => (string) Str::uuid(), 'evidence' => 'PRIVATE verified refund custody.', 'confirmed' => true];
    $this->actingAs($operator)->withSession(cashSession())->post(route('fee-refunds.cash', $refund), $payment)->assertRedirect()->assertSessionHasNoErrors();
    $execution = CashDisbursement::query()->sole();
    $this->post(route('cash-disbursements.handoff', $execution), ['evidence' => 'PRIVATE personally handed exact cash.', 'delivered' => true, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('financial_cash_notification_intents')->where('channel', 'mail')->count())->toBe(1);
    $this->actingAs($customer->user)->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $posted = DB::table('financial_cash_events')->where('event_type', 'posted')->sole();
    $operatorNotice = DB::table('financial_cash_notification_intents')->where('financial_cash_event_id', $posted->id)->where('audience_type', 'refund_cash_operator')->sole();
    expect($operatorNotice->recipient_user_id)->toBe($operator->id)
        ->and(DB::table('financial_cash_notification_intents')->where('channel', 'mail')->count())->toBe(2)
        ->and(DB::table('notification_inbox_intents')->join('notification_events', 'notification_events.id', '=', 'notification_inbox_intents.event_id')->where('notification_events.family', 'financial_cash')->where('notification_events.event_type', 'posted')->value('notification_inbox_intents.summary'))->toContain('refund was paid');
    $money = financialCashNoticeMoneyRows();
    materializeFinancialCashNotices();
    $this->actingAs($operator)->get(route('notifications.show', $operatorNotice->notification_id))->assertOk();
    $this->get(route('notifications.open', $operatorNotice->notification_id))->assertRedirect(route('cash-disbursements.index'));
    $postedMail = DB::table('financial_cash_notification_intents')->where('financial_cash_event_id', $posted->id)->where('channel', 'mail')->sole();
    Event::listen(NotificationSending::class, fn (NotificationSending $event): ?bool => $event->notification instanceof FinancialCashMailNotification ? false : null);
    $job = new DeliverFinancialCashNotificationIntent($postedMail->id);
    $job->handle();
    $job->handle();
    $issueNotice = assertFinancialCashDeliveryIssue($this, $operator, $customer->user, $postedMail, 'refund_cash_operator');
    expect(DB::table('fee_operational_issues')->where('source_family', 'financial_cash')->count())->toBe(1);
    $operator->revokePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($operator)->get(route('notifications.show', $operatorNotice->notification_id))->assertNotFound();
    $this->get(route('notifications.show', $issueNotice->notification_id))->assertNotFound();
    expect(financialCashNoticeMoneyRows())->toEqual($money);
});

test('same-instant actual reassignment and manager grant loss suppress original refund audiences without changing money', function (): void {
    [$admin, $customer, $agent] = financialCashRefundFixture($this, 'savings');
    $oldAgent = DB::table('financial_cash_notification_intents')->where('audience_type', 'current_agent')->sole();
    $oldManager = DB::table('financial_cash_notification_intents')->where('audience_type', 'fee_manager')->sole();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $owner = app(CustomerReassignmentService::class);
    $review = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $money = financialCashNoticeMoneyRows();
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $review['version'],
        'assignment_version' => $review['assignment_version'], 'preview_token' => $review['preview_token'], 'target_agent_id' => $replacement->id,
        'confirmed' => true, 'reason' => 'Reviewed service transfer.', 'customer_explanation' => 'Your service contact changed.']);
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    materializeFinancialCashNotices();
    expect(DB::table('financial_cash_notification_intents')->where('id', $oldAgent->id)->value('status'))->toBe('suppressed')
        ->and(DB::table('financial_cash_notification_intents')->where('id', $oldManager->id)->value('status'))->toBe('suppressed');
    $this->actingAs($agent)->get(route('notifications.show', $oldAgent->notification_id))->assertNotFound();
    $this->actingAs($admin)->get(route('notifications.show', $oldManager->notification_id))->assertNotFound();
    expect(financialCashNoticeMoneyRows())->toEqual($money);
});

test('required refund notice capture rolls back the actual concession and same original retry succeeds once', function (string $table): void {
    [$admin, , , $fee, $payload] = financialCashRefundFixture($this, 'savings', false);
    $money = financialCashNoticeMoneyRows();
    DB::statement('CREATE TRIGGER fail_refund_notice BEFORE INSERT ON '.$table." BEGIN SELECT RAISE(ABORT, 'refund notice outage'); END");
    $this->postJson(route('admin.fees.refunds.store', $fee), $payload)->assertServerError();
    expect(financialCashNoticeMoneyRows())->toEqual($money)->and(DB::table('financial_cash_events')->count())->toBe(0);
    DB::statement('DROP TRIGGER fail_refund_notice');
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('financial_cash_events')->count())->toBe(1)->and(DB::table('financial_cash_notification_intents')->count())->toBe(4);
})->with(['financial_cash_notification_intents', 'management_mail_dispatches']);

test('optional refund mail uncertainty never repeats the accepted financial source or transport attempt', function (): void {
    [$admin, $customer, , $fee, $refund] = financialCashRefundFixture($this, 'savings');
    $mail = DB::table('financial_cash_notification_intents')->where('channel', 'mail')->sole();
    $money = financialCashNoticeMoneyRows();
    Event::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
        return $event->notification instanceof FinancialCashMailNotification ? false : null;
    });
    $job = new DeliverFinancialCashNotificationIntent($mail->id);
    $job->handle();
    $job->handle();
    expect(DB::table('financial_cash_notification_intents')->where('id', $mail->id)->value('status'))->toBe('unknown')
        ->and(DB::table('management_delivery_attempts')->where('owner_family', 'financial_cash')->count())->toBe(1)
        ->and(financialCashNoticeMoneyRows())->toEqual($money);
    $issueNotice = assertFinancialCashDeliveryIssue($this, $admin, $customer->user, $mail, 'fee_manager');
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), $refund)->assertRedirect()->assertSessionHasNoErrors();
    $job->handle();
    expect(DB::table('fee_operational_issues')->where('source_family', 'financial_cash')->count())->toBe(1)
        ->and(financialCashNoticeMoneyRows())->toEqual($money);
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->get(route('notifications.show', $issueNotice->notification_id))->assertNotFound();
});

test('detached actual refund posting blocks captured success notices and suppresses material mail without financial changes', function (): void {
    financialCashRefundFixture($this, 'savings');
    $refund = FeeRefund::query()->sole();
    DB::table('ledger_posting_groups')->where('id', $refund->ledger_posting_group_id)->update(['source_id' => (string) Str::uuid()]);
    $money = financialCashNoticeMoneyRows();
    materializeFinancialCashNotices();
    expect(DB::table('notification_inbox_intents')->join('notification_events', 'notification_events.id', '=', 'notification_inbox_intents.event_id')->where('notification_events.family', 'financial_cash')->pluck('notification_inbox_intents.status')->unique()->all())->toBe(['blocked']);
    $mail = DB::table('financial_cash_notification_intents')->where('channel', 'mail')->sole();
    (new DeliverFinancialCashNotificationIntent($mail->id))->handle();
    expect(DB::table('financial_cash_notification_intents')->where('channel', 'database')->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(DB::table('financial_cash_notification_intents')->where('id', $mail->id)->value('status'))->toBe('suppressed')
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(financialCashNoticeMoneyRows())->toEqual($money);
});

test('refund mail finalization outage retains transport uncertainty and recovers without a second send', function (): void {
    [$admin, $customer, , $fee, $refund] = financialCashRefundFixture($this, 'savings');
    $mail = DB::table('financial_cash_notification_intents')->where('channel', 'mail')->sole();
    $money = financialCashNoticeMoneyRows();
    DB::statement("CREATE TRIGGER fail_refund_mail_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'fee.delivery_state_recorded' BEGIN SELECT RAISE(ABORT, 'canonical mail outage'); END");
    $job = new DeliverFinancialCashNotificationIntent($mail->id);
    expect(fn () => $job->handle())->toThrow(QueryException::class);
    DB::statement('DROP TRIGGER fail_refund_mail_audit');
    expect(DB::table('financial_cash_notification_intents')->where('id', $mail->id)->value('status'))->toBe('sending')
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    $job->handle();
    $this->travel(6)->minutes();
    app(ManagementMailDelivery::class)->drain(100);
    $job->handle();
    $job->handle();
    expect(DB::table('financial_cash_notification_intents')->where('id', $mail->id)->value('status'))->toBe('unknown')
        ->and(DB::table('management_delivery_attempts')->where('owner_family', 'financial_cash')->count())->toBe(1)
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(financialCashNoticeMoneyRows())->toEqual($money);
    $issueNotice = assertFinancialCashDeliveryIssue($this, $admin, $customer->user, $mail, 'fee_manager');
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), $refund)->assertRedirect()->assertSessionHasNoErrors();
    $job->handle();
    expect(DB::table('fee_operational_issues')->where('source_family', 'financial_cash')->count())->toBe(1)
        ->and(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(financialCashNoticeMoneyRows())->toEqual($money);
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->get(route('notifications.show', $issueNotice->notification_id))->assertNotFound();
});
