<?php

use App\Enums\AdminPermission;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Notifications\FeeSavingsApplicationMailNotification;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\FeeObligationService;
use App\Services\FeeOperationalIssueNotificationSource;
use App\Services\FeeSavingsApplicationService;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationPipeline;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';

/** @return array{User, CustomerProfile, ThriftPlan, User, User, Request, array<string, mixed>, CollectionReceipt} */
function feeIssueCollectionFixture(FeeRuleTiming $timing = FeeRuleTiming::FirstContribution, bool $affordable = false): array
{
    config()->set(['collections.enabled' => true, 'fees.savings_applications_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture($timing === FeeRuleTiming::CycleCompletion ? 2 : 3,
        feeAmountKobo: $affordable ? 50000 : ($timing === FeeRuleTiming::CycleCompletion ? 500000 : 300000),
        feeTiming: $timing, feeSource: FeeSettlementSource::SavingsApplication);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::FeesManage);
    $secondManager = User::factory()->admin()->withTwoFactor()->create();
    $secondManager->givePermissionTo(AdminPermission::FeesManage);
    $request = Request::create('/admin/fees/application', 'POST');
    $session = new Store('fee-issue-operator', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);
    $payload = collectionPayload($customer, $assignment, $plan, $date, $timing === FeeRuleTiming::CycleCompletion ? '4000.00' : '2000.00');
    $payload['notes'] = 'SECRET original collection note.';
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);

    return [$agent, $customer, $plan, $manager, $secondManager, $request, $payload, $receipt];
}

/** @return array<string, array<int, object>> */
function feeIssueFinancialRows(): array
{
    $rows = [];
    foreach (['customer_profiles', 'customer_assignments', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'fee_savings_applications', 'fee_obligation_events', 'ledger_posting_groups', 'ledger_entries', 'collection_receipts',
        'collection_fee_components', 'collection_allocations', 'collection_batches', 'cash_remittances',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function materializeFeeIssues(): void
{
    foreach (DB::table('notification_inbox_intents')->whereIn('template_id', ['fee_issue.trigger_unapplied', 'fee_issue.delivery_issue'])->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
}

function fundActualIssueFee(CustomerProfile $customer, ThriftPlan $plan): void
{
    $agent = $customer->currentAssignment->agentProfile->user;
    $later = collectionPayload($customer->fresh(), $customer->currentAssignment, $plan->fresh(), now('Africa/Lagos')->toDateString(), '2000.00');
    $collections = app(CollectionService::class);
    $later['preview_fingerprint'] = $collections->preview($agent, $customer, $later)['preview_fingerprint'];
    $collections->record($agent, $customer, $later);
}

/** @return array{array<string, mixed>, int} */
function applyActualIssueFee(User $manager, CustomerProfile $customer, ThriftPlan $plan, Request $request): array
{
    $fee = FeeObligation::query()->sole();
    $owner = app(FeeSavingsApplicationService::class);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'SECRET approved original fee application.',
        'customer_description' => 'Your original assessed cycle fee was paid from savings.'];
    $quote = $owner->preview($manager, $fee->id, $data);
    $payload = [...$data, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $group = $owner->apply($manager, $fee->id, $payload, $request);

    return [$payload, $group->id];
}

test('actual assessed unpaid contribution and completion fees alert only current Agent and fee managers through receipt replay', function (FeeRuleTiming $timing): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, , $manager, $secondManager, , $payload, $receipt] = feeIssueCollectionFixture($timing);
    $issue = DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->sole();
    expect($issue->state)->toBe('insufficient_funds')->and($issue->fee_obligation_id)->toBe(FeeObligation::query()->sole()->id)
        ->and($issue->customer_profile_id)->toBe($customer->id)
        ->and($issue->audit_event_id)->toBe(AuditEvent::query()->where('event_type', 'collection.receipt_posted')->sole()->id)
        ->and(strlen($issue->state_fingerprint))->toBe(64);
    $recipients = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->orderBy('recipient_user_id')->pluck('recipient_user_id')->all();
    expect($recipients)->toBe(collect([$agent->id, $manager->id, $secondManager->id])->sort()->values()->all())
        ->and($recipients)->not->toContain($customer->user_id);
    $financial = feeIssueFinancialRows();
    expect(app(CollectionService::class)->record($agent, $customer, $payload)->id)->toBe($receipt->id);
    materializeFeeIssues();
    materializeFeeIssues();
    expect(DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->count())->toBe(1)
        ->and(DB::table('fee_issue_notification_intents')->count())->toBe(3)
        ->and(feeIssueFinancialRows())->toEqual($financial);
    $intents = DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.trigger_unapplied')->get();
    expect($intents)->toHaveCount(3);
    foreach ($intents as $intent) {
        expect($intent->status)->toBe('delivered')->and((bool) $intent->action_required)->toBeTrue()
            ->and($intent->summary)->not->toContain('SECRET')
            ->and(app(NotificationPipeline::class)->recipientScope(User::findOrFail($intent->recipient_user_id))->where('i.id', $intent->id)->exists())->toBeTrue();
    }
    expect(app(FeeOperationalIssueNotificationSource::class)->isActionable($issue->id))->toBeTrue();
})->with(['first contribution' => FeeRuleTiming::FirstContribution, 'cycle completion' => FeeRuleTiming::CycleCompletion]);

test('meaningful unpaid availability refresh changes the actionable issue and actual application resolves it without duplicating the original assessment', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $customer, $plan, $manager, , $request] = feeIssueCollectionFixture();
    $first = DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->sole();
    $assessment = FeeObligation::query()->sole()->entries()->sole()->getAttributes();
    fundActualIssueFee($customer, $plan);
    $issues = DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->orderBy('id')->get();
    expect($issues)->toHaveCount(2)->and($issues[1]->state)->toBe('reviewed_application_required')
        ->and($issues[1]->state_fingerprint)->not->toBe($first->state_fingerprint)
        ->and(FeeObligation::query()->sole()->entries()->where('entry_type', 'assessment')->sole()->getAttributes())->toBe($assessment)
        ->and(app(FeeOperationalIssueNotificationSource::class)->isActionable($first->id))->toBeFalse()
        ->and(app(FeeOperationalIssueNotificationSource::class)->isActionable($issues[1]->id))->toBeTrue();
    [$payload, $groupId] = applyActualIssueFee($manager, $customer, $plan, $request);
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(0);
    foreach ($issues as $issue) {
        expect(app(FeeOperationalIssueNotificationSource::class)->isActionable($issue->id))->toBeFalse();
    }
    $financial = feeIssueFinancialRows();
    expect(app(FeeSavingsApplicationService::class)->apply($manager, FeeObligation::query()->sole()->id, $payload, $request)->id)->toBe($groupId);
    materializeFeeIssues();
    foreach (DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.trigger_unapplied')->get() as $intent) {
        expect(app(NotificationPipeline::class)->recipientScope(User::findOrFail($intent->recipient_user_id))->where('i.id', $intent->id)->exists())->toBeFalse();
    }
    expect(feeIssueFinancialRows())->toEqual($financial);
});

test('actual waiver resolves an assessed unpaid trigger without moving savings or posting a fee receipt', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, , , $manager, , $request] = feeIssueCollectionFixture();
    $issue = DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->sole();
    $groups = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();
    $entries = DB::table('ledger_entries')->orderBy('id')->get()->all();
    app(FeeObligationService::class)->waive($manager, FeeObligation::query()->sole()->id, 300000,
        'SECRET approved full relief.', 'Your original unpaid fee was waived.', (string) Str::uuid(), $request);
    expect(app(FeeOperationalIssueNotificationSource::class)->isActionable($issue->id))->toBeFalse()
        ->and(DB::table('ledger_posting_groups')->orderBy('id')->get()->all())->toEqual($groups)
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($entries);
    $financial = feeIssueFinancialRows();
    materializeFeeIssues();
    expect(feeIssueFinancialRows())->toEqual($financial);
});

test('affordable actual automatic application leaves no assessed unpaid trigger issue', function (FeeRuleTiming $timing): void {
    $this->freezeTime();
    Queue::fake();
    feeIssueCollectionFixture($timing, true);
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(0)
        ->and(DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->count())->toBe(0)
        ->and(DB::table('fee_issue_notification_intents')->count())->toBe(0);
})->with(['first contribution' => FeeRuleTiming::FirstContribution, 'cycle completion' => FeeRuleTiming::CycleCompletion]);

test('actual handover and fee grant revocation deny stale trigger recipients without changing financial owners', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, $plan, $manager, $secondManager] = feeIssueCollectionFixture();
    $old = DB::table('fee_issue_notification_intents')->where('recipient_user_id', $agent->id)->sole();
    $revoked = DB::table('fee_issue_notification_intents')->where('recipient_user_id', $manager->id)->sole();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $manager->givePermissionTo(AdminPermission::CustomersReassign);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($manager, $customer->fresh(), $replacement->id);
    $handover->execute($manager, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $quote['version'],
        'assignment_version' => $quote['assignment_version'], 'preview_token' => $quote['preview_token'], 'target_agent_id' => $replacement->id,
        'confirmed' => true, 'reason' => 'Reviewed service transfer.', 'customer_explanation' => 'Your service Agent changed.']);
    $manager->revokePermissionTo(AdminPermission::FeesManage);
    $financial = feeIssueFinancialRows();
    materializeFeeIssues();
    expect(DB::table('fee_issue_notification_intents')->where('id', $old->id)->value('status'))->toBe('suppressed')
        ->and(DB::table('fee_issue_notification_intents')->where('id', $revoked->id)->value('status'))->toBe('suppressed')
        ->and(DB::table('fee_issue_notification_intents')->where('recipient_user_id', $secondManager->id)->value('status'))->toBe('delivered');
    $this->actingAs($agent)->get(route('notifications.show', $old->notification_id))->assertNotFound();
    $this->actingAs($manager)->get(route('notifications.show', $revoked->notification_id))->assertNotFound();
    expect(feeIssueFinancialRows())->toEqual($financial);
    $customer = $customer->fresh();
    $later = collectionPayload($customer, $customer->currentAssignment, $plan->fresh(),
        now('Africa/Lagos')->toDateString(), '2000.00');
    $later['preview_fingerprint'] = app(CollectionService::class)->preview($replacement->user, $customer, $later)['preview_fingerprint'];
    app(CollectionService::class)->record($replacement->user, $customer, $later);
    $currentIssue = DB::table('fee_operational_issues')->where('issue_kind', 'trigger_unapplied')->orderByDesc('id')->first();
    $currentRecipients = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $currentIssue->id)->orderBy('recipient_user_id')->pluck('recipient_user_id')->all();
    expect($currentRecipients)->toBe(collect([$replacement->user_id, $secondManager->id])->sort()->values()->all())
        ->and(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(300000);
    $financial = feeIssueFinancialRows();
    materializeFeeIssues();
    $replacementIntent = DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.trigger_unapplied')
        ->where('recipient_user_id', $replacement->user_id)->sole();
    expect($replacementIntent->status)->toBe('delivered')
        ->and(app(NotificationPipeline::class)->recipientScope($replacement->user)->where('i.id', $replacementIntent->id)->exists())->toBeTrue()
        ->and(feeIssueFinancialRows())->toEqual($financial);
});

test('actual failed fee notice alerts authorized operators once and its own delivery cannot recursively create issues', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, $plan, $manager, $secondManager, $request] = feeIssueCollectionFixture();
    fundActualIssueFee($customer, $plan);
    [$payload, $groupId] = applyActualIssueFee($manager, $customer, $plan, $request);
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'database')->where('recipient_user_id', $customer->user_id)->sole();
    $intentId = DB::table('notification_inbox_aliases')->where('family', 'fee_application')->where('owner_intent_id', $owner->id)->value('intent_id');
    $financial = feeIssueFinancialRows();
    DB::statement("CREATE TRIGGER fail_fee_issue_delivery BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'SECRET local notification outage'); END");
    try {
        app(NotificationPipeline::class)->materialize((int) $intentId);
        $issue = DB::table('fee_operational_issues')->where('issue_kind', 'delivery_issue')->sole();
        expect($issue->state)->toBe('local_failure')->and($issue->source_family)->toBe('fee_application')
            ->and($issue->source_owner_id)->toBe($owner->id)
            ->and(DB::table('notification_inbox_attempts')->where('intent_id', $intentId)->whereNotNull('failure_category')->count())->toBe(1);
        $recipients = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->orderBy('recipient_user_id')->pluck('recipient_user_id')->all();
        expect($recipients)->toBe([$manager->id])
            ->and($recipients)->not->toContain($customer->user_id, $agent->id, $secondManager->id);
        $issueIntent = DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.delivery_issue')->first();
        app(NotificationPipeline::class)->materialize($issueIntent->id);
        $this->travel(3)->minutes();
        app(NotificationPipeline::class)->materialize((int) $intentId);
        expect(DB::table('fee_operational_issues')->where('issue_kind', 'delivery_issue')->count())->toBe(1)
            ->and(DB::table('notification_inbox_attempts')->where('intent_id', $intentId)->whereNotNull('failure_category')->count())->toBe(2);
    } finally {
        DB::statement('DROP TRIGGER fail_fee_issue_delivery');
    }
    $this->travel(10)->minutes();
    materializeFeeIssues();
    foreach (DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.delivery_issue')->get() as $intent) {
        expect($intent->status)->toBe('delivered')->and($intent->summary)->not->toContain('SECRET')
            ->and(app(NotificationPipeline::class)->recipientScope(User::findOrFail($intent->recipient_user_id))->where('i.id', $intent->id)->exists())->toBeTrue();
    }
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    expect(app(FeeSavingsApplicationService::class)->apply($manager, FeeObligation::query()->sole()->id, $payload, $request)->id)->toBe($groupId)
        ->and(feeIssueFinancialRows())->toEqual($financial);
    app(NotificationPipeline::class)->materialize((int) $intentId);
    expect(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('delivered')
        ->and(app(FeeOperationalIssueNotificationSource::class)->isActionable($issue->id))->toBeFalse()
        ->and(feeIssueFinancialRows())->toEqual($financial);
});

test('actual fee email uncertainty alerts only authorized operators without resend false success or repeated money', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('mail.default', 'array');
    [$agent, $customer, $plan, $manager, $secondManager, $request] = feeIssueCollectionFixture();
    fundActualIssueFee($customer, $plan);
    [$payload, $groupId] = applyActualIssueFee($manager, $customer, $plan, $request);
    $owner = DB::table('fee_application_notification_intents')->where('channel', 'mail')->sole();
    $financial = feeIssueFinancialRows();
    $transportAttempts = 0;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$transportAttempts): void {
        if ($event->notification instanceof FeeSavingsApplicationMailNotification) {
            $transportAttempts++;
            throw new RuntimeException('SECRET acceptance could not be verified.');
        }
    });
    $job = new DeliverFeeApplicationNotificationIntent($owner->id);
    $job->handle();
    $issue = DB::table('fee_operational_issues')->where('issue_kind', 'delivery_issue')->sole();
    expect($issue->state)->toBe('acceptance_unknown')->and($issue->source_family)->toBe('fee_application')
        ->and($issue->source_owner_id)->toBe($owner->id)
        ->and(DB::table('fee_application_notification_intents')->where('id', $owner->id)->value('status'))->toBe('unknown');
    $recipients = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->orderBy('recipient_user_id')->pluck('recipient_user_id')->all();
    expect($recipients)->toBe([$manager->id])
        ->and($recipients)->not->toContain($customer->user_id, $agent->id, $secondManager->id);
    $this->travel(6)->minutes();
    $job->handle();
    app(ManagementMailDelivery::class)->drain(100);
    materializeFeeIssues();
    materializeFeeIssues();
    expect($transportAttempts)->toBe(1)
        ->and(DB::table('management_delivery_attempts')->count())->toBe(1)
        ->and(DB::table('management_delivery_attempts')->value('outcome'))->toBe('acceptance_unknown')
        ->and(DB::table('fee_operational_issues')->where('issue_kind', 'delivery_issue')->count())->toBe(1)
        ->and(app(FeeSavingsApplicationService::class)->apply($manager, FeeObligation::query()->sole()->id, $payload, $request)->id)->toBe($groupId)
        ->and(feeIssueFinancialRows())->toEqual($financial);
    foreach (DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.delivery_issue')->get() as $intent) {
        expect($intent->status)->toBe('delivered')->and($intent->summary)->not->toContain('SECRET')
            ->and(app(NotificationPipeline::class)->recipientScope(User::findOrFail($intent->recipient_user_id))->where('i.id', $intent->id)->exists())->toBeTrue();
    }
});
