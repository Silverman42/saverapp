<?php

use App\Enums\AdminPermission;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AgentProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\LedgerAccount;
use App\Models\ManualCharge;
use App\Models\User;
use App\Notifications\CollectionReceiptMailNotification;
use App\Services\AgentLifecycleService;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\FeeObligationService;
use App\Services\NotificationPipeline;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\CreatesLifecycleAgents;

uses(CreatesLifecycleAgents::class);

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../ManualChargeFixtures.php';

/** @return array{User, CustomerProfile, FeeObligation, array<string, mixed>, CollectionReceipt} */
function externalFeeReceiptFixture(): array
{
    config()->set(['collections.enabled' => true, 'mail.default' => 'array']);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer);
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '200.00']],
        'notes' => 'SECRET original collection note.'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];

    return [$agent, $customer, $fee, $payload, app(CollectionService::class)->record($agent, $customer, $payload)];
}

/** @return array<string, array<int, object>> */
function externalFeeReceiptFinancialRows(): array
{
    $rows = [];
    foreach (['customer_profiles', 'customer_assignments', 'fee_rules', 'fee_snapshots', 'fee_obligations',
        'fee_obligation_entries', 'fee_obligation_events', 'manual_charges', 'charge_category_versions',
        'ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_fee_components',
        'collection_allocations', 'collection_batches', 'cash_remittances', 'thrift_plans', 'plan_terms_revisions',
        'contribution_slots', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function deliverExternalFeeReceipt(CollectionReceipt $receipt): void
{
    foreach (DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->pluck('id') as $id) {
        (new DeliverCollectionNotificationIntent((int) $id))->handle();
    }
}

/** @return array<string, mixed> */
function externalFeeReceiptContext(CollectionReceipt $receipt): array
{
    $ciphertext = DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('channel', 'mail')->sole()->context_ciphertext;

    return json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
}

function externalFeeReceiptRenderedMail(CollectionReceipt $receipt): string
{
    return Crypt::decryptString(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('channel', 'mail')->sole()->rendered_snapshot);
}

test('actual partial external fee receipt reaches Customer and receiving Agent with original balance and replay deduplication', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, $fee, $payload, $receipt] = externalFeeReceiptFixture();
    $owners = DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->get();
    expect($owners)->toHaveCount(3)->and($owners->where('channel', 'mail')->pluck('recipient_user_id')->all())->toBe([$customer->user_id])
        ->and($owners->where('channel', 'database')->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect([$customer->user_id, $agent->id])->sort()->values()->all());
    $context = externalFeeReceiptContext($receipt);
    expect($context['fees'])->toHaveCount(1)->and($context['fees'][0]['fee_obligation_id'])->toBe($fee->id)
        ->and($context['fees'][0]['amount_kobo'])->toBe(20000)->and($context['fees'][0]['remaining_kobo'])->toBe(30000)
        ->and($context['fee_amount_kobo'])->toBe(20000)->and($context['savings_amount_kobo'])->toBe(0);
    $financial = externalFeeReceiptFinancialRows();
    expect(app(CollectionService::class)->record($agent, $customer, $payload)->id)->toBe($receipt->id);
    deliverExternalFeeReceipt($receipt);
    deliverExternalFeeReceipt($receipt);
    expect(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->count())->toBe(3)
        ->and(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('status', 'delivered')->count())->toBe(3)
        ->and(externalFeeReceiptFinancialRows())->toEqual($financial);
    foreach ($owners->where('channel', 'database') as $owner) {
        $id = DB::table('notification_inbox_aliases')->where('family', 'collection')->where('owner_intent_id', $owner->id)->value('intent_id');
        $intent = DB::table('notification_inbox_intents')->find($id);
        expect($intent->summary)->toContain('200.00', '300.00')->not->toContain('SECRET')
            ->and(app(NotificationPipeline::class)->recipientScope(User::findOrFail($owner->recipient_user_id))->where('i.id', $id)->exists())->toBeTrue();
    }
    expect(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('channel', 'mail')->value('attempt_count'))->toBe(1)
        ->and(externalFeeReceiptRenderedMail($receipt))->toContain('200.00', '300.00')->not->toContain('SECRET');
});

test('actual later waiver preserves original external fee receipt balance through delayed inbox and mail delivery', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, , $fee, , $receipt] = externalFeeReceiptFixture();
    $original = externalFeeReceiptContext($receipt);
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::FeesManage);
    app(FeeObligationService::class)->waive($manager, $fee->id, 30000,
        'SECRET approved relief.', 'Your unpaid fee balance was waived.', (string) Str::uuid(), $this->agentLifecycleRequest($manager));
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    $financial = externalFeeReceiptFinancialRows();
    deliverExternalFeeReceipt($receipt);
    expect(externalFeeReceiptContext($receipt))->toBe($original)
        ->and(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('status', 'delivered')->count())->toBe(3)
        ->and(externalFeeReceiptRenderedMail($receipt))->toContain('300.00')->not->toContain('SECRET')
        ->and(externalFeeReceiptFinancialRows())->toEqual($financial);
});

test('actual split receipt retains distinct registration and manual fee component totals without private reasons', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set(['collections.enabled' => true, 'fees.manual_charges_enabled' => true, 'mail.default' => 'array']);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $registration = reportFeeObligation($agent, $customer);
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($manager)->withSession($this->agentLifecycleFreshSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => 'receipt-service-fee', 'kind' => 'manual_fee',
        'purpose' => 'SECRET management service purpose.', 'customer_description' => 'Agreed service fee.', 'amount_ngn' => '500.00', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->sole();
    $reviewed = reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'SECRET reviewed assessment.', 'confirmed' => true]);
    $this->post(route('admin.charges.assess'), $reviewed)->assertRedirect()->assertSessionHasNoErrors();
    $manual = FeeObligation::query()->findOrFail(ManualCharge::query()->sole()->fee_obligation_id);
    $payload = [...collectionPayload($customer->fresh(), $assignment, $plan->fresh(), $date, '2000.00'),
        'fees' => [['obligation_id' => $registration->id, 'amount_ngn' => '200.00'], ['obligation_id' => $manual->id, 'amount_ngn' => '300.00']], 'notes' => 'SECRET received note.'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer->fresh(), $payload);
    $context = externalFeeReceiptContext($receipt);
    expect($context['fees'])->toHaveCount(2)->and($context['savings_amount_kobo'])->toBe(200000)
        ->and($context['fee_amount_kobo'])->toBe(50000)->and($context['tender_amount_kobo'])->toBe(250000);
    $components = collect($context['fees'])->keyBy('fee_obligation_id');
    expect($components[$registration->id]['amount_kobo'])->toBe(20000)->and($components[$registration->id]['remaining_kobo'])->toBe(30000)
        ->and($components[$manual->id]['amount_kobo'])->toBe(30000)->and($components[$manual->id]['remaining_kobo'])->toBe(20000);
    $financial = externalFeeReceiptFinancialRows();
    deliverExternalFeeReceipt($receipt);
    expect(externalFeeReceiptRenderedMail($receipt))->toContain('2,000.00', '500.00', '2,500.00', '300.00', '200.00')->not->toContain('SECRET')
        ->and(externalFeeReceiptFinancialRows())->toEqual($financial);
});

test('actual Agent eligibility changes suppress historical fee receipt Agent delivery while preserving Customer receipt', function (string $change): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, , , $receipt] = externalFeeReceiptFixture();
    $manager = User::factory()->admin()->withTwoFactor()->create();
    if ($change === 'handover') {
        $manager->givePermissionTo(AdminPermission::CustomersReassign);
        $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        $service = app(CustomerReassignmentService::class);
        $quote = $service->preview($manager, $customer->fresh(), $replacement->id);
        $service->execute($manager, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $quote['version'],
            'assignment_version' => $quote['assignment_version'], 'preview_token' => $quote['preview_token'], 'target_agent_id' => $replacement->id,
            'confirmed' => true, 'reason' => 'SECRET management transfer.', 'customer_explanation' => 'Your service Agent changed.']);
        expect(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('recipient_user_id', $replacement->user_id)->count())->toBe(0);
    } else {
        $manager->givePermissionTo(AdminPermission::AgentsManage);
        $profile = $agent->agentProfile;
        app(AgentLifecycleService::class)->execute($manager, $profile, 'suspend', $this->agentLifecyclePayload($profile), $this->agentLifecycleRequest($manager));
    }
    $financial = externalFeeReceiptFinancialRows();
    deliverExternalFeeReceipt($receipt);
    expect(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('recipient_user_id', $agent->id)->value('status'))->toBe('suppressed')
        ->and(DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('recipient_user_id', $customer->user_id)->where('status', 'delivered')->count())->toBe(2)
        ->and(externalFeeReceiptFinancialRows())->toEqual($financial);
})->with(['same-instant handover' => 'handover', 'owner-backed suspension' => 'suspend']);

test('actual uncertain external fee receipt email alerts original receiving Agent without resend or false Customer issue', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, , $payload, $receipt] = externalFeeReceiptFixture();
    $financial = externalFeeReceiptFinancialRows();
    $attempts = 0;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$attempts): void {
        if ($event->notification instanceof CollectionReceiptMailNotification) {
            $attempts++;
            throw new RuntimeException('SECRET external acceptance unverified.');
        }
    });
    $owner = DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)->where('channel', 'mail')->sole();
    $job = new DeliverCollectionNotificationIntent($owner->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->handle();
    expect($attempts)->toBe(1)->and(DB::table('collection_notification_intents')->where('id', $owner->id)->value('status'))->toBe('uncertain');
    $issue = DB::table('fee_operational_issues')->where('issue_kind', 'delivery_issue')->sole();
    expect($issue->state)->toBe('acceptance_unknown')
        ->and(DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->pluck('recipient_user_id')->all())->toBe([$agent->id]);
    foreach (DB::table('notification_inbox_intents')->where('template_id', 'fee_issue.delivery_issue')->pluck('id') as $id) {
        app(NotificationPipeline::class)->materialize((int) $id);
    }
    expect(DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->where('status', 'delivered')->count())->toBe(1)
        ->and(DB::table('fee_issue_notification_intents')->where('recipient_user_id', $customer->user_id)->count())->toBe(0)
        ->and(app(CollectionService::class)->record($agent, $customer, $payload)->id)->toBe($receipt->id)
        ->and(externalFeeReceiptFinancialRows())->toEqual($financial);
});
