<?php

use App\Enums\AdminPermission;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\User;
use App\Services\CustomerReassignmentService;
use App\Services\FeeObligationService;
use App\Services\NotificationPipeline;
use App\Support\MoneyFormatter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

/** @return array{User, CustomerProfile, User, FeeObligation, Request} */
function feeObligationNoticeFixture(): array
{
    [$agent, $customer] = collectionFixture();
    $fee = reportFeeObligation($agent, $customer, 50000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $request = Request::create('/admin/fees/obligation', 'POST');
    $session = new Store('fee-obligation-notice', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);

    return [$admin, $customer, $agent, $fee, $request];
}

function feeObligationNoticeAction(User $admin, FeeObligation $fee, Request $request, string $action, string $reference, int $amount = 10000): FeeObligationEntry
{
    $owner = app(FeeObligationService::class);
    $reason = 'SECRET reviewed original unpaid fee correction.';
    $description = 'Your original unpaid fee was reviewed.';
    if ($action === 'waiver') {
        return $owner->waive($admin, $fee->id, $amount, $reason, $description, $reference, $request);
    }

    return $owner->correctUnsettledAssessment($admin, $fee->id, $amount,
        $action === 'increase' ? FeeAssessmentCorrectionDirection::Increase : FeeAssessmentCorrectionDirection::Reduce,
        $reason, $description, $reference, $request);
}

/** @return array<string, array<int, object>> */
function feeObligationNoticeFinancialRows(): array
{
    $rows = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'fee_obligation_events', 'ledger_posting_groups',
        'ledger_entries', 'collection_receipts', 'collection_fee_components', 'collection_allocations',
        'withdrawal_requests', 'withdrawal_reservations', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function materializeFeeObligationNotices(): void
{
    foreach (DB::table('notification_inbox_intents')->whereIn('template_id', ['fee_obligation.waived', 'fee_obligation.assessment_corrected'])->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
}

test('actual unpaid waiver and corrections retain original audited snapshots for each authorized recipient without financial replay', function (string $action, int $after): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $customer, $agent, $fee, $request] = feeObligationNoticeFixture();
    $reference = (string) Str::uuid();
    $entry = feeObligationNoticeAction($admin, $fee, $request, $action, $reference);
    $event = DB::table('fee_obligation_events')->sole();
    $audit = AuditEvent::query()->where('event_type', $action === 'waiver' ? 'fee.obligation.waived' : 'fee.assessment.corrected')->sole();
    expect($event->fee_obligation_entry_id)->toBe($entry->id)->and($event->audit_event_id)->toBe($audit->id)
        ->and($event->operation_reference)->toBe($reference)->and($event->outstanding_before_kobo)->toBe(50000)
        ->and($event->outstanding_after_kobo)->toBe($after)->and($event->amount_kobo)->toBe(10000)
        ->and($event->customer_profile_id)->toBe($customer->id)->and($event->currency)->toBe('NGN');
    $this->assertDatabaseCount('fee_obligation_notification_intents', 3);
    expect(DB::table('fee_obligation_notification_intents')->orderBy('recipient_user_id')->pluck('recipient_user_id')->all())
        ->toBe(collect([$admin->id, $customer->user_id, $agent->id])->sort()->values()->all());
    expect(feeObligationNoticeAction($admin, $fee, $request, $action, $reference)->id)->toBe($entry->id);
    $this->assertDatabaseCount('fee_obligation_events', 1);
    $this->assertDatabaseCount('fee_obligation_notification_intents', 3);
    app(FeeObligationService::class)->waive($admin, $fee->id, 5000, 'SECRET later relief.',
        'A separate later relief was approved.', (string) Str::uuid(), $request);
    expect($fee->fresh()->outstandingAmountKobo())->toBe($after - 5000);
    $financial = feeObligationNoticeFinancialRows();
    materializeFeeObligationNotices();
    materializeFeeObligationNotices();
    expect(feeObligationNoticeFinancialRows())->toEqual($financial);
    $this->assertDatabaseCount('notifications', 6);
    $original = DB::table('notification_inbox_intents')->whereIn('id', DB::table('notification_inbox_aliases')
        ->where('family', 'fee_obligation')->whereIn('owner_intent_id', DB::table('fee_obligation_notification_intents')
        ->where('fee_obligation_event_id', $event->id)->select('id'))->select('intent_id'))->get();
    expect($original)->toHaveCount(3);
    foreach ($original as $notice) {
        expect($notice->summary)->toContain('Your original unpaid fee was reviewed.', '₦100.00', '₦500.00',
            MoneyFormatter::formatNaira($after), 'No money was moved.')->not->toContain('SECRET', 'later relief')
            ->and($notice->reference)->toBe($reference)->and($notice->status)->toBe('delivered');
    }
    $attempts = AuditEvent::query()->where('event_type', 'fee.delivery_attempt')->get();
    expect($attempts)->toHaveCount(6);
    foreach ($attempts as $attempt) {
        expect($attempt->target_id)->toBe($fee->id)->and($attempt->payload['source_event_id'])->not->toBeNull()
            ->and($attempt->payload['source_audit_event_id'])->not->toBeNull();
    }
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    expect(json_encode(DB::table('notification_events')->get(), JSON_THROW_ON_ERROR))->not->toContain('SECRET');
})->with(['unpaid waiver' => ['waiver', 40000], 'unpaid decrease' => ['decrease', 40000], 'unpaid increase' => ['increase', 60000]]);

test('same instant actual reassignment and current operator grant loss suppress only unauthorized fee change recipients', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $customer, $agent, $fee, $request] = feeObligationNoticeFixture();
    feeObligationNoticeAction($admin, $fee, $request, 'waiver', (string) Str::uuid());
    $oldAgent = DB::table('fee_obligation_notification_intents')->where('recipient_user_id', $agent->id)->sole();
    $operator = DB::table('fee_obligation_notification_intents')->where('recipient_user_id', $admin->id)->sole();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer->fresh(), $replacement->id);
    $handover->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $quote['version'],
        'assignment_version' => $quote['assignment_version'], 'preview_token' => $quote['preview_token'], 'target_agent_id' => $replacement->id,
        'confirmed' => true, 'reason' => 'Reviewed service reassignment.', 'customer_explanation' => 'Your service contact changed.']);
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $financial = feeObligationNoticeFinancialRows();
    materializeFeeObligationNotices();
    expect(DB::table('fee_obligation_notification_intents')->where('id', $oldAgent->id)->value('status'))->toBe('suppressed')
        ->and(DB::table('fee_obligation_notification_intents')->where('id', $operator->id)->value('status'))->toBe('suppressed')
        ->and(DB::table('fee_obligation_notification_intents')->where('recipient_user_id', $customer->user_id)->value('status'))->toBe('delivered');
    $this->actingAs($agent)->get(route('notifications.show', $oldAgent->notification_id))->assertNotFound();
    $this->actingAs($admin)->get(route('notifications.show', $operator->notification_id))->assertNotFound();
    expect(feeObligationNoticeFinancialRows())->toEqual($financial);
});

test('durable fee change capture failure rolls back its entry audit and snapshots before the original retry', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, , , $fee, $request] = feeObligationNoticeFixture();
    $reference = (string) Str::uuid();
    $financial = feeObligationNoticeFinancialRows();
    $audits = DB::table('canonical_audit_events')->orderBy('id')->get()->all();
    DB::statement("CREATE TRIGGER fail_fee_change_capture BEFORE INSERT ON fee_obligation_notification_intents BEGIN SELECT RAISE(ABORT, 'notice capture outage'); END");
    try {
        expect(fn () => feeObligationNoticeAction($admin, $fee, $request, 'waiver', $reference))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_change_capture');
    }
    expect(feeObligationNoticeFinancialRows())->toEqual($financial)
        ->and(DB::table('canonical_audit_events')->orderBy('id')->get()->all())->toEqual($audits);
    $this->assertDatabaseCount('fee_obligation_notification_intents', 0);
    expect(DB::table('notification_events')->where('family', 'fee_obligation')->count())->toBe(0);
    feeObligationNoticeAction($admin, $fee, $request, 'waiver', $reference);
    $this->assertDatabaseCount('fee_obligation_events', 1);
    $this->assertDatabaseCount('fee_obligation_notification_intents', 3);
});

test('fee change canonical delivery failure leaves financial sources committed and original delivery retry inserts one notice', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, , , $fee, $request] = feeObligationNoticeFixture();
    feeObligationNoticeAction($admin, $fee, $request, 'waiver', (string) Str::uuid());
    $intent = DB::table('notification_inbox_intents')->where('template_id', 'fee_obligation.waived')->firstOrFail();
    $financial = feeObligationNoticeFinancialRows();
    DB::statement("CREATE TRIGGER fail_fee_change_delivery BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'fee.delivery_attempt' BEGIN SELECT RAISE(ABORT, 'delivery audit outage'); END");
    try {
        expect(fn () => app(NotificationPipeline::class)->materializeOwned($intent->id))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_change_delivery');
    }
    $this->assertDatabaseCount('notifications', 0);
    expect(DB::table('notification_inbox_intents')->where('id', $intent->id)->value('status'))->toBe('pending')
        ->and(feeObligationNoticeFinancialRows())->toEqual($financial);
    app(NotificationPipeline::class)->materialize($intent->id);
    app(NotificationPipeline::class)->materialize($intent->id);
    $this->assertDatabaseCount('notifications', 1);
    expect(AuditEvent::query()->where('event_type', 'fee.delivery_attempt')->count())->toBe(1)
        ->and(feeObligationNoticeFinancialRows())->toEqual($financial);
});

test('an unavailable original fee change audit blocks source success and masks unverified source identities', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, , , $fee, $request] = feeObligationNoticeFixture();
    feeObligationNoticeAction($admin, $fee, $request, 'waiver', (string) Str::uuid());
    DB::table('fee_obligation_events')->update(['audit_event_id' => AuditEvent::query()->where('event_type', 'fee.obligation.assessed')->value('id')]);
    $financial = feeObligationNoticeFinancialRows();
    materializeFeeObligationNotices();
    $this->assertDatabaseCount('notifications', 0);
    expect(DB::table('notification_inbox_intents')->where('template_id', 'fee_obligation.waived')->pluck('status')->all())->toBe(['blocked', 'blocked', 'blocked']);
    $attempts = AuditEvent::query()->where('event_type', 'fee.delivery_attempt')->get();
    expect($attempts)->toHaveCount(3);
    foreach ($attempts as $attempt) {
        expect($attempt->target_id)->toBeNull()->and($attempt->payload['source_event_id'])->toBeNull()
            ->and($attempt->payload['source_audit_event_id'])->toBeNull();
    }
    expect(feeObligationNoticeFinancialRows())->toEqual($financial);
});

test('fee change queue dispatch outage recovers the retained database intents without repeating its original action', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$admin, , , $fee, $request] = feeObligationNoticeFixture();
    $reference = (string) Str::uuid();
    $previousQueue = Queue::getFacadeRoot();
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('SECRET queue unavailable.'));
    try {
        $entry = feeObligationNoticeAction($admin, $fee, $request, 'waiver', $reference);
    } finally {
        Queue::swap($previousQueue);
    }
    expect(DB::table('platform_recovery_work')->where('owner', 'notification_inbox')->count())->toBe(3);
    $financial = feeObligationNoticeFinancialRows();
    materializeFeeObligationNotices();
    materializeFeeObligationNotices();
    expect(feeObligationNoticeAction($admin, $fee, $request, 'waiver', $reference)->id)->toBe($entry->id)
        ->and(feeObligationNoticeFinancialRows())->toEqual($financial);
    $this->assertDatabaseCount('notifications', 3);
    $this->assertDatabaseCount('fee_obligation_events', 1);
});
