<?php

use App\Enums\AccountState;
use App\Enums\LedgerAccountCode;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AuditEvent;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Notifications\CollectionReceiptMailNotification;
use App\Services\CollectionReplacementService;
use App\Services\CollectionService;
use App\Support\PlatformJobMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

function receiptMailFixture(bool $withFee = false, bool $dispatchOutage = false): array
{
    if (! $dispatchOutage) {
        Queue::fake();
    }
    config()->set(['collections.enabled' => true, 'mail.default' => 'array']);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    if ($withFee) {
        $fee = reportFeeObligation($agent, $customer, 50000);
        $data['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']];
    }
    $data['notes'] = 'Private receipt investigation and cash attestation.';
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    if ($dispatchOutage) {
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Simulated receipt queue outage.'));
    }
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $intent = DB::table('collection_notification_intents')->where('channel', 'mail')->sole();

    return [$customer, $receipt, $intent];
}

function receiptMailFinancialRows(): array
{
    $rows = [];
    foreach (['collection_receipts', 'collection_batches', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_obligations', 'fee_obligation_entries'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('safe own receipt mail delivers once without granting account access or repeating financial posting', function (AccountState $state): void {
    [$customer, $receipt, $intent] = receiptMailFixture();
    $customer->user->forceFill(['account_state' => $state, 'email_verified_at' => null])->save();
    $baseline = receiptMailFinancialRows();
    $job = new DeliverCollectionNotificationIntent($intent->id);
    $job->handle();
    $job->handle();
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    $delivered = DB::table('collection_notification_intents')->where('id', $intent->id)->sole();
    $html = Crypt::decryptString($delivered->rendered_snapshot);
    expect($delivered->status)->toBe('delivered')->and($delivered->attempt_count)->toBe(1)
        ->and($delivered->rendered_hash)->toBe(hash('sha256', $html))->and($delivered->template_version)->toBe(1)
        ->and($html)->toContain($receipt->receipt_reference)->toContain('₦2,000.00')->toContain('Savings:')->toContain('Fees:')
        ->not->toContain('Private receipt investigation')->not->toContain('activation')->not->toContain('View receipt')
        ->and($customer->user->fresh()->account_state)->toBe($state)->and(receiptMailFinancialRows())->toEqual($baseline);
    $this->assertDatabaseCount('collection_notification_intents', 2);
    expect(AuditEvent::query()->where('event_type', 'collection.delivery_attempt')->count())->toBe(1);
})->with([AccountState::Active, AccountState::Invited, AccountState::Suspended, AccountState::Deactivated]);

test('a pre-send mail claim outage leaves a retryable intent and one financial receipt', function (): void {
    [, , $intent] = receiptMailFixture();
    $baseline = receiptMailFinancialRows();
    DB::statement("CREATE TRIGGER fail_receipt_mail_claim BEFORE UPDATE ON collection_notification_intents WHEN NEW.status = 'sending' BEGIN SELECT RAISE(ABORT, 'simulated claim outage'); END");
    try {
        expect(fn () => (new DeliverCollectionNotificationIntent($intent->id))->handle())->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_receipt_mail_claim');
    }
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('pending');
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('delivered')
        ->and(receiptMailFinancialRows())->toEqual($baseline);
});

test('mail transport uncertainty never automatically resends or repeats financial state', function (): void {
    [, , $intent] = receiptMailFixture();
    $baseline = receiptMailFinancialRows();
    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if ($event->notification instanceof CollectionReceiptMailNotification) {
            throw new RuntimeException('Private provider response after delivery was invoked.');
        }
    });
    $job = new DeliverCollectionNotificationIntent($intent->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    Notification::fake();
    $job->handle();
    $job->failed(new RuntimeException('Private terminal transport detail.'));
    Notification::assertNothingSent();
    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('uncertain')
        ->and(receiptMailFinancialRows())->toEqual($baseline);
    $audit = AuditEvent::query()->where('event_type', 'collection.delivery_attempt')->sole();
    expect($audit->payload['category'])->toBe('delivery_uncertain')
        ->and(DB::table('canonical_audit_events')->where('legacy_audit_event_id', $audit->id)->value('content'))->not->toContain('Private');
});

test('mixed receipt mail retains the actual fee and savings distinction without exposing private cash notes', function (): void {
    [$customer, $receipt, $intent] = receiptMailFixture(true);
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    $sent = DB::table('collection_notification_intents')->where('id', $intent->id)->sole();
    $html = Crypt::decryptString($sent->rendered_snapshot);
    expect($sent->status)->toBe('delivered')->and($html)->toContain('Total received: ₦2,500.00')
        ->toContain('Savings: ₦2,000.00')->toContain('Fees: ₦500.00')->not->toContain('Private receipt investigation');
    expect($receipt->fresh()->fee_amount_kobo)->toBe(50000);
});

test('unsafe recipient or damaged receipt prevents mail without changing financial owners', function (string $damage): void {
    [$customer, $receipt, $intent] = receiptMailFixture();
    if ($damage === 'destination') {
        $customer->user->forceFill(['email' => 'unsafe-address'])->save();
    } elseif ($damage === 'recipient') {
        DB::table('collection_notification_intents')->where('id', $intent->id)->update(['recipient_user_id' => $receipt->recorded_by_user_id]);
    } elseif ($damage === 'foreign_customer') {
        $foreign = User::factory()->customer()->create();
        DB::table('collection_notification_intents')->where('id', $intent->id)->update(['recipient_user_id' => $foreign->id]);
    } else {
        DB::table('collection_receipts')->where('id', $receipt->id)->update(['tender_amount_kobo' => 200001]);
    }
    $baseline = receiptMailFinancialRows();
    Notification::fake();
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    Notification::assertNothingSent();
    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('blocked')
        ->and(receiptMailFinancialRows())->toEqual($baseline);
})->with(['destination', 'recipient', 'foreign_customer', 'receipt']);

test('a cancelled mail notification is never falsely reported delivered', function (): void {
    [, , $intent] = receiptMailFixture();
    Event::listen(NotificationSending::class, fn (NotificationSending $event): ?bool => $event->notification instanceof CollectionReceiptMailNotification ? false : null);
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('uncertain');
});

test('audit failure after transport acceptance keeps an uncertain outcome and never resends the receipt', function (): void {
    [, , $intent] = receiptMailFixture();
    $baseline = receiptMailFinancialRows();
    DB::statement("CREATE TRIGGER fail_receipt_mail_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'collection.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'private final audit outage'); END");
    try {
        expect(fn () => (new DeliverCollectionNotificationIntent($intent->id))->handle())->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_receipt_mail_audit');
    }
    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('sending');
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    expect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('uncertain')
        ->and(receiptMailFinancialRows())->toEqual($baseline);
});

test('terminal mail failure is atomic with its safe audit and repeated callback changes nothing', function (string $fault): void {
    [, , $intent] = receiptMailFixture();
    $baseline = receiptMailFinancialRows();
    $trigger = $fault === 'audit'
        ? "CREATE TRIGGER fail_receipt_mail_terminal BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'collection.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'private canonical outage'); END"
        : "CREATE TRIGGER fail_receipt_mail_terminal BEFORE UPDATE ON collection_notification_intents WHEN NEW.status = 'failed' BEGIN SELECT RAISE(ABORT, 'private owner outage'); END";
    DB::statement($trigger);
    try {
        expect(fn () => (new DeliverCollectionNotificationIntent($intent->id))->failed(new RuntimeException('private transport details')))
            ->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_receipt_mail_terminal');
    }
    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('pending')
        ->and(AuditEvent::query()->where('event_type', 'collection.delivery_attempt')->count())->toBe(0);
    (new DeliverCollectionNotificationIntent($intent->id))->failed(new RuntimeException('private transport details'));
    $failed = DB::table('collection_notification_intents')->where('id', $intent->id)->sole();
    (new DeliverCollectionNotificationIntent($intent->id))->failed(new RuntimeException('private callback retry'));
    expect($failed->status)->toBe('failed')
        ->and(DB::table('collection_notification_intents')->where('id', $intent->id)->sole())->toEqual($failed)
        ->and(AuditEvent::query()->where('event_type', 'collection.delivery_attempt')->count())->toBe(1)
        ->and(receiptMailFinancialRows())->toEqual($baseline);
})->with(['audit', 'owner']);

test('receipt mail middleware sends only after its durable claim transaction finishes', function (): void {
    [, , $intent] = receiptMailFixture();
    $baseLevel = DB::transactionLevel();
    $observed = [];
    Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$observed): void {
        if ($event->notification instanceof CollectionReceiptMailNotification) {
            $observed[] = DB::transactionLevel();
        }
    });
    $job = new DeliverCollectionNotificationIntent($intent->id);
    app(PlatformJobMiddleware::class)->handle($job, fn (DeliverCollectionNotificationIntent $job) => $job->handle());
    expect($observed)->toBe([$baseLevel])
        ->and(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('delivered');
});

test('scheduled mail drain recovers a committed receipt after queue outage and retires completed dispatch work', function (): void {
    [, , $intent] = receiptMailFixture(dispatchOutage: true);
    $baseline = receiptMailFinancialRows();
    $this->assertDatabaseHas('management_mail_dispatches', ['owner_family' => 'collection', 'owner_id' => $intent->id, 'status' => 'pending']);
    Queue::fake();
    $this->artisan('notifications:drain', ['--limit' => 10])->assertSuccessful();
    Queue::assertPushed(DeliverCollectionNotificationIntent::class, fn (DeliverCollectionNotificationIntent $job): bool => $job->intentId === $intent->id);
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    $this->artisan('notifications:drain', ['--limit' => 10])->assertSuccessful();
    Queue::assertPushed(DeliverCollectionNotificationIntent::class, 1);
    $this->assertDatabaseHas('management_mail_dispatches', ['owner_family' => 'collection', 'owner_id' => $intent->id, 'status' => 'complete']);
    expect(receiptMailFinancialRows())->toEqual($baseline);
});

test('replacement receipt mail identifies controlled funds allocation without claiming another cash receipt', function (): void {
    [$customer, $receipt] = receiptMailFixture();
    config()->set('collections.receipt_corrections_enabled', true);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds)->update(['mapping_status' => 'mapped']);
    $assignment = $customer->currentAssignment;
    $agent = $assignment->agentProfile->user;
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $data = collectionPayload($customer->fresh(), $assignment->fresh(), $receipt->plan->fresh(), $receipt->received_date, '2000.00');
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $reversal, $data);
    $replacement = $service->record($agent, $reversal, [...$data, 'preview_fingerprint' => $quote['preview_fingerprint'],
        'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    $intent = DB::table('collection_notification_intents')->where('collection_receipt_id', $replacement->id)->where('channel', 'mail')->sole();
    $baseline = receiptMailFinancialRows();
    (new DeliverCollectionNotificationIntent($intent->id))->handle();
    $sent = DB::table('collection_notification_intents')->where('id', $intent->id)->sole();
    $html = Crypt::decryptString($sent->rendered_snapshot);
    expect($sent->status)->toBe('delivered')->and($html)->toContain('Funds reallocated: ₦2,000.00')
        ->toContain('No additional money was received.')->not->toContain('Total received:')
        ->and(receiptMailFinancialRows())->toEqual($baseline);
});
