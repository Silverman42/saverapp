<?php

use App\Enums\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\CustomerStatusNotificationIntent;
use App\Services\CollectionService;
use App\Services\NotificationPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set('notifications.enabled', true);
    config()->set('collections.enabled', true);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-16 23:30:00', 'Africa/Lagos'));
});

/** @return array{0: CustomerProfile, 1: int} */
function ntfClosureCollectionNotice(string $amount): array
{
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(1, slotAmountKobo: 999999999);
    $payload = collectionPayload($customer, $assignment, $plan, $today, $amount);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    $ownerId = (int) DB::table('collection_notification_intents')->where('recipient_user_id', $customer->user_id)->value('id');
    $id = DB::table('notification_inbox_intents')->where('source_owner_id', $ownerId)->value('id')
        ?? app(NotificationPipeline::class)->capture('collection', $ownerId, false);

    return [$customer, (int) $id];
}

test('NTF-AC-021: collection notices render exact en-NG kobo amounts without rounding', function (string $amount, string $rendered): void {
    [$customer, $id] = ntfClosureCollectionNotice($amount);
    app(NotificationPipeline::class)->materialize($id);

    $this->actingAs($customer->user)->get(route('notifications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('timezone', 'Africa/Lagos')
        ->where('inbox.items.0.title', 'Collection recorded')
        ->where('inbox.items.0.summary', "Savings: {$rendered}; external fee: ₦0.00; total tender: {$rendered}."));
})->with([
    'one kobo' => ['0.01', '₦0.01'],
    'ten kobo' => ['0.10', '₦0.10'],
    'odd kobo' => ['12345.67', '₦12,345.67'],
    'millions' => ['9999999.99', '₦9,999,999.99'],
]);

test('NTF-AC-021: late-evening Lagos events carry an exact UTC instant for business-timezone display', function (): void {
    [$customer, $id] = ntfClosureCollectionNotice('2000.00');
    app(NotificationPipeline::class)->materialize($id);

    $item = $this->actingAs($customer->user)->get(route('notifications.index'))->viewData('page')['props']['inbox']['items'][0];

    expect(CarbonImmutable::parse($item['effective_at'])->setTimezone('Africa/Lagos')->toDateString())->toBe('2026-09-16')
        ->and(CarbonImmutable::parse($item['effective_at'])->utcOffset())->toBe(0)
        ->and($item['effective_at'])->toStartWith('2026-09-16T22:30');
});

test('NTF-AC-021: Unicode and markup in a customer-facing explanation stay literal text', function (): void {
    $customer = CustomerProfile::factory()->create();
    $explanation = 'Ọláyínká, ẹ jọ̀ọ́ <b>wait</b> & call — ₦ review 🙏';
    $history = CustomerStatusHistory::create([
        'customer_profile_id' => $customer->id, 'from_status' => CustomerStatus::Active, 'to_status' => CustomerStatus::Restricted,
        'reason' => 'Private reason', 'customer_facing_explanation' => $explanation,
        'changed_by_user_id' => $customer->user_id, 'created_at' => now(),
    ]);
    $owner = CustomerStatusNotificationIntent::create([
        'notification_id' => (string) Str::uuid(), 'customer_status_history_id' => $history->id,
        'recipient_user_id' => $customer->user_id, 'audience_type' => 'subject_customer', 'channel' => 'database',
        'purpose' => 'customer_status_changed', 'customer_profile_id' => $customer->id, 'payload' => [], 'status' => 'pending',
    ]);
    $id = app(NotificationPipeline::class)->capture('customer_status', $owner->id, false);
    app(NotificationPipeline::class)->materialize($id);

    $summary = $this->actingAs($customer->user)->get(route('notifications.index'))->viewData('page')['props']['inbox']['items'][0]['summary'];

    expect($summary)->toEndWith(' '.$explanation)
        ->and($summary)->not->toContain('Private reason')
        ->and($summary)->not->toContain('&lt;');
});

test('NTF-AC-023: tampered financial facts or snapshots block a collection notice instead of rendering it', function (string $table, Closure $changes): void {
    [, $id] = ntfClosureCollectionNotice('2000.00');
    $eventId = DB::table('notification_inbox_intents')->where('id', $id)->value('event_id');
    $facts = json_decode((string) DB::table('notification_events')->where('id', $eventId)->value('facts'), true);
    DB::table($table)->where('id', $table === 'notification_events' ? $eventId : $id)->update($changes($facts));

    app(NotificationPipeline::class)->materialize($id);

    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('blocked')
        ->and(DB::table('notifications')->count())->toBe(0);
})->with([
    'secret fact' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'password' => 'hunter2'])]],
    'other Customer fact' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'customer_id' => 'CUS-999999'])]],
    'evidence fact' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'evidence' => 'raw evidence'])]],
    'inconsistent tender' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'tender_amount_kobo' => $facts['tender_amount_kobo'] + 1])]],
    'negative amount' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'savings_amount_kobo' => -1, 'tender_amount_kobo' => -1 + $facts['fee_amount_kobo']])]],
    'fractional amount' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'fee_amount_kobo' => 0.5])]],
    'string amount' => ['notification_events', fn (array $facts): array => ['facts' => json_encode([...$facts, 'fee_amount_kobo' => '0'])]],
    'missing component' => ['notification_events', fn (array $facts): array => ['facts' => json_encode(array_diff_key($facts, ['fee_amount_kobo' => true]))]],
    'redirected destination' => ['notification_inbox_intents', fn (): array => ['destination' => json_encode(['route' => 'admin.dashboard', 'parameters' => []])]],
    'changed reference' => ['notification_inbox_intents', fn (): array => ['reference' => 'RCP-OTHER']],
    'edited title' => ['notification_inbox_intents', fn (): array => ['title' => 'Urgent: confirm your PIN']],
]);
