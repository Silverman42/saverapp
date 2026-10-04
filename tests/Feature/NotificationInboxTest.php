<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AgentStatusHistory;
use App\Models\AgentStatusNotificationIntent;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\CustomerStatusNotificationIntent;
use App\Models\Permission;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanNotificationIntent;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\NotificationCatalogue;
use App\Services\NotificationInbox;
use App\Services\NotificationPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('notifications.enabled', true);
    Queue::fake();
});

/** @return array{0: CustomerStatusNotificationIntent, 1: int} */
function inboxStatusNotice(CustomerProfile $customer, ?User $recipient = null, string $audience = 'subject_customer', bool $deliver = true, bool $capture = true): array
{
    $history = CustomerStatusHistory::create([
        'customer_profile_id' => $customer->id, 'from_status' => CustomerStatus::Active, 'to_status' => CustomerStatus::Restricted,
        'reason' => 'Secret investigation reason', 'customer_facing_explanation' => 'A temporary review is in progress.',
        'changed_by_user_id' => $customer->user_id, 'created_at' => now(),
    ]);
    $owner = CustomerStatusNotificationIntent::create([
        'notification_id' => (string) Str::uuid(), 'customer_status_history_id' => $history->id,
        'recipient_user_id' => $recipient?->id ?? $customer->user_id, 'audience_type' => $audience,
        'channel' => 'database', 'purpose' => 'customer_status_changed', 'customer_profile_id' => $customer->id,
        'payload' => ['title' => '<script>secret</script>', 'message' => 'private password token bank details', 'url' => 'https://evil.test/?token=secret'],
        'status' => 'pending',
    ]);
    if (! $capture) {
        return [$owner, 0];
    }
    $id = app(NotificationPipeline::class)->capture('customer_status', $owner->id, false);
    if ($deliver) {
        app(NotificationPipeline::class)->materialize($id);
    }

    return [$owner, $id];
}

function inboxAssignedCustomer(AgentProfile $agent): CustomerProfile
{
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id,
        'assigned_by_user_id' => $agent->user_id, 'reason' => 'Assigned service',
        'status' => CustomerAssignmentStatus::Current, 'is_current' => 1, 'effective_at' => now(), 'version' => 1,
    ]);

    return $customer;
}

test('inbox and JSON endpoints require authentication and respect the feature gate', function (): void {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
    $this->getJson(route('notifications.sync'))->assertUnauthorized();
    $customer = CustomerProfile::factory()->create();
    config()->set('notifications.enabled', false);
    $this->actingAs($customer->user)->getJson(route('notifications.sync'))->assertNotFound();
});

test('safe committed notices are scoped and counted before search or serialization', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner] = inboxStatusNotice($customer);
    $other = CustomerProfile::factory()->create();
    [$otherOwner] = inboxStatusNotice($other);
    $this->actingAs($customer->user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->component('notifications/Index')->has('inbox.items', 1)
            ->where('inbox.unread_count', 1)->where('inbox.items.0.id', $owner->notification_id)
            ->where('inbox.items.0.title', 'Customer status updated'));
    $this->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $this->get(route('notifications.show', $otherOwner->notification_id))->assertNotFound();
    $this->patchJson(route('notifications.read', $otherOwner->notification_id), ['read' => true, 'version' => 1])->assertNotFound();
    $this->get(route('notifications.open', $otherOwner->notification_id))->assertNotFound();
    expect(DB::table('notifications')->where('id', $owner->notification_id)->value('data'))
        ->not->toContain('password', 'evil.test', '<script>', 'Secret investigation');
});

test('read state is idempotent versioned and independent from event and delivery state', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner, $intent] = inboxStatusNotice($customer);
    $event = DB::table('notification_events')->first();
    $this->actingAs($customer->user)->patchJson(route('notifications.read', $owner->notification_id), ['read' => true, 'version' => 1])
        ->assertOk()->assertJsonPath('version', 2);
    $this->patchJson(route('notifications.read', $owner->notification_id), ['read' => true, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
    $this->patchJson(route('notifications.read', $owner->notification_id), ['read' => false, 'version' => 1])->assertConflict();
    $this->patchJson(route('notifications.read', $owner->notification_id), ['read' => false, 'version' => 2])->assertOk()->assertJsonPath('version', 3);
    expect(DB::table('notification_events')->first())->toEqual($event);
    expect(DB::table('notification_inbox_intents')->where('id', $intent)->value('status'))->toBe('delivered');
    expect(DB::table('notification_inbox_attempts')->count())->toBe(1);
});

test('duplicate capture and worker replay create one event item and attempt', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner, $id] = inboxStatusNotice($customer, deliver: false);
    expect(app(NotificationPipeline::class)->capture('customer_status', $owner->id, false))->toBe($id);
    app(NotificationPipeline::class)->materialize($id);
    app(NotificationPipeline::class)->deliverOwner('customer_status', $owner->id);
    expect(DB::table('notification_events')->count())->toBe(1);
    expect(DB::table('notification_inbox_intents')->count())->toBe(1);
    expect(DB::table('notifications')->count())->toBe(1);
    expect(DB::table('notification_inbox_attempts')->count())->toBe(1);
    expect($owner->fresh()->status)->toBe('delivered');
});

test('source rollback discards shared event and intent and leaves no queued delivery', function (): void {
    $customer = CustomerProfile::factory()->create();
    try {
        DB::transaction(function () use ($customer): void {
            inboxStatusNotice($customer, deliver: false);
            throw new RuntimeException('Abort source');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('notification_events')->count())->toBe(0);
    expect(DB::table('notification_inbox_intents')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('scheduled recovery dispatches durable due intents without owner replay', function (): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    $this->artisan('notifications:drain')->assertSuccessful();
    Queue::assertPushed(MaterializeNotificationIntent::class, fn (MaterializeNotificationIntent $job): bool => $job->intentId === $id);
    expect(CustomerStatusHistory::query()->count())->toBe(1);
    expect(DB::table('notifications')->count())->toBe(0);
    DB::table('notification_inbox_intents')->where('id', $id)->update(['next_attempt_at' => now()->addMinutes(5)]);
    Queue::fake();
    $this->artisan('notifications:drain')->assertSuccessful();
    Queue::assertNothingPushed();
});

test('invited and suspended account notices are retained but inaccessible until valid activation', function (): void {
    $customer = CustomerProfile::factory()->create(['user_id' => User::factory()->invited()]);
    [$owner] = inboxStatusNotice($customer);
    expect($owner->fresh()->status)->toBe('delivered');
    $this->actingAs($customer->user)->getJson(route('notifications.sync'))->assertRedirect(route('login'));
    $customer->user->forceFill(['account_state' => AccountState::Active])->save();
    $this->actingAs($customer->user->fresh())->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $customer->user->forceFill(['account_state' => AccountState::Suspended])->save();
    $this->actingAs($customer->user->fresh())->get(route('notifications.show', $owner->notification_id))->assertRedirect(route('login'));
});

test('Customer financial ownership is retained through operational restriction and archival', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner] = inboxStatusNotice($customer);
    foreach ([CustomerStatus::Inactive, CustomerStatus::Restricted, CustomerStatus::Archived] as $status) {
        $customer->forceFill(['operational_status' => $status])->save();
        $this->actingAs($customer->user)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
        $this->get(route('notifications.show', $owner->notification_id))->assertOk();
    }
});

test('reassignment removes former Agent notices counts search and links and invalidates cursors', function (): void {
    $agent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()]);
    $customer = inboxAssignedCustomer($agent);
    [$owner] = inboxStatusNotice($customer, $agent->user, 'current_agent');
    $before = app(NotificationInbox::class)->sync($agent->user);
    $this->actingAs($agent->user)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $customer->currentAssignment->forceFill(['is_current' => null, 'status' => CustomerAssignmentStatus::Ended])->save();
    $this->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
    $this->get(route('notifications.show', $owner->notification_id))->assertNotFound();
    $this->get(route('notifications.open', $owner->notification_id))->assertNotFound();
    $this->get(route('notifications.index', ['search' => $customer->customer_id]))->assertInertia(fn (Assert $page) => $page->has('inbox.items', 0));
    expect(app(NotificationInbox::class)->sync($agent->user)['scope'])->not->toBe($before['scope']);
});

test('inactive eligible Agents may read current scope but incomplete MFA and role drift remove content', function (): void {
    $agent = AgentProfile::factory()->inactive()->create(['user_id' => User::factory()->agent()->withTwoFactor()]);
    $customer = inboxAssignedCustomer($agent);
    inboxStatusNotice($customer, $agent->user, 'current_agent');
    $this->actingAs($agent->user)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $agent->user->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAs($agent->user->fresh())->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
});

test('reassignment before materialization suppresses a historical Agent receipt without redistributing it', function (): void {
    $agent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()]);
    $customer = inboxAssignedCustomer($agent);
    [$owner, $id] = inboxStatusNotice($customer, $agent->user, 'current_agent', false);
    $customer->currentAssignment->forceFill(['is_current' => null, 'status' => CustomerAssignmentStatus::Ended])->save();
    app(NotificationPipeline::class)->materialize($id);
    expect($owner->fresh()->status)->toBe('suppressed');
    expect(DB::table('notifications')->count())->toBe(0);
});

test('Admin delivery and retrieval require the exact current owner permission', function (): void {
    Permission::firstOrCreate(['name' => AdminPermission::AgentsManage->value, 'guard_name' => 'web'], ['status' => 'active']);
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);
    $agent = AgentProfile::factory()->create();
    $history = AgentStatusHistory::create(['agent_profile_id' => $agent->id, 'from_status' => 'active', 'to_status' => 'inactive',
        'reason' => 'Private staffing matter', 'changed_by_user_id' => $admin->id]);
    $owner = AgentStatusNotificationIntent::create(['notification_id' => (string) Str::uuid(), 'agent_status_history_id' => $history->id,
        'recipient_user_id' => $admin->id, 'agent_profile_id' => $agent->id, 'audience_type' => 'managing_admin', 'channel' => 'database',
        'purpose' => 'agent_status_changed', 'payload' => [], 'status' => 'pending']);
    $id = app(NotificationPipeline::class)->capture('agent_status', $owner->id, false);
    app(NotificationPipeline::class)->materialize($id);
    $this->actingAs($admin)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 1);
    $admin->revokePermissionTo(AdminPermission::AgentsManage->value);
    $this->actingAs($admin->fresh())->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
    $this->get(route('notifications.show', $owner->notification_id))->assertNotFound();
});

test('unknown event contracts and invalid source relationships never produce raw payload fallbacks', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner] = inboxStatusNotice($customer, deliver: false);
    $owner->forceFill(['customer_profile_id' => CustomerProfile::factory()->create()->id])->save();
    expect(fn () => app(NotificationPipeline::class)->capture('customer_status', $owner->id, false))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(NotificationPipeline::class)->capture('unregistered', $owner->id, false))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(NotificationCatalogue::class)->describe('customer_status', (object) [...$owner->getAttributes(), 'audience_type' => 'broadcast']))->toThrow(InvalidArgumentException::class);
});

test('unsupported template versions block delivery with safe durable failure history', function (): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    DB::table('notification_inbox_intents')->where('id', $id)->update(['template_version' => 999]);
    app(NotificationPipeline::class)->materialize($id);
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('blocked');
    expect(DB::table('notification_inbox_attempts')->value('failure_category'))->toBe('unsupported_contract');
    expect(DB::table('notifications')->count())->toBe(0);
});

test('expired inbox visibility hides content without deleting sources or audit', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner, $id] = inboxStatusNotice($customer);
    DB::table('notification_inbox_intents')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
    $this->actingAs($customer->user)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
    $this->get(route('notifications.show', $owner->notification_id))->assertNotFound();
    expect(CustomerStatusHistory::query()->count())->toBe(1);
    expect(DB::table('notifications')->count())->toBe(1);
});

test('cursor pagination is stable and bound to user filters and current scope', function (): void {
    $this->freezeTime();
    $customer = CustomerProfile::factory()->create();
    for ($index = 0; $index < 26; $index++) {
        inboxStatusNotice($customer);
    }
    $service = app(NotificationInbox::class);
    $first = $service->read($customer->user, ['page_size' => 25]);
    $second = $service->read($customer->user, ['page_size' => 25, 'cursor' => $first['next_cursor']]);
    expect($first['items'])->toHaveCount(25);
    expect($second['items'])->toHaveCount(1);
    expect(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')))->toBe([]);
    $this->actingAs($customer->user)->getJson(route('notifications.index', ['page_size' => 25, 'search' => 'changed', 'cursor' => $first['next_cursor']]))->assertUnprocessable();
    $other = CustomerProfile::factory()->create();
    $this->actingAs($other->user)->getJson(route('notifications.index', ['page_size' => 25, 'cursor' => $first['next_cursor']]))->assertUnprocessable();
});

test('new arrivals stay outside continuation until explicit refresh', function (): void {
    $customer = CustomerProfile::factory()->create();
    for ($index = 0; $index < 26; $index++) {
        inboxStatusNotice($customer);
    }
    $service = app(NotificationInbox::class);
    $first = $service->read($customer->user, []);
    $this->travel(2)->seconds();
    [$new] = inboxStatusNotice($customer);
    $next = $service->read($customer->user, ['cursor' => $first['next_cursor']]);
    expect(array_column($next['items'], 'id'))->not->toContain($new->notification_id);
    expect($service->sync($customer->user)['unread_count'])->toBe(27);
});

test('page-read changes only its signed displayed page and rejects altered tokens', function (): void {
    $customer = CustomerProfile::factory()->create();
    for ($index = 0; $index < 26; $index++) {
        inboxStatusNotice($customer);
    }
    $page = app(NotificationInbox::class)->read($customer->user, []);
    $this->actingAs($customer->user)->postJson(route('notifications.page-read'), ['page_token' => $page['page_token']])->assertOk()->assertJsonPath('unread_count', 1);
    $this->postJson(route('notifications.page-read'), ['page_token' => $page['page_token']])->assertOk()->assertJsonPath('unread_count', 1);
    $this->postJson(route('notifications.page-read'), ['page_token' => 'forged'])->assertUnprocessable();
    $other = CustomerProfile::factory()->create();
    $this->actingAs($other->user)->postJson(route('notifications.page-read'), ['page_token' => $page['page_token']])->assertUnprocessable();
});

test('bulk read rolls back entirely when one displayed row has a conflicting read version', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$first] = inboxStatusNotice($customer);
    [$second] = inboxStatusNotice($customer);
    $page = app(NotificationInbox::class)->read($customer->user, []);
    DB::table('notifications')->where('id', $second->notification_id)->update(['read_version' => 5]);
    $this->actingAs($customer->user)->postJson(route('notifications.page-read'), ['page_token' => $page['page_token']])->assertConflict();
    expect(DB::table('notifications')->where('id', $first->notification_id)->value('read_at'))->toBeNull();
});

test('inbox validates bounded dates filters and page sizes', function (array $filters): void {
    $customer = CustomerProfile::factory()->create();
    $this->actingAs($customer->user)->getJson(route('notifications.index', $filters))->assertUnprocessable();
})->with([
    [['page_size' => 26]], [['category' => 'marketing']], [['read' => 'read']], [['from' => '2026-01-01']],
    [['from' => '2020-01-01', 'to' => '2021-01-01']], [['from' => '2999-01-01', 'to' => '2999-01-02']],
    [['recipient_user_id' => 2]], [['cursor' => 'forged']], [['search' => str_repeat('x', 161)]],
]);

test('verified historical import preserves ID timestamps and read state without email or raw payloads', function (): void {
    Notification::fake();
    $customer = CustomerProfile::factory()->create();
    [$owner, $id] = inboxStatusNotice($customer, deliver: false);
    DB::table('notifications')->insert(['id' => $owner->notification_id, 'type' => 'legacy', 'notifiable_type' => User::class,
        'notifiable_id' => $customer->user_id, 'data' => json_encode(['secret' => 'never expose']),
        'read_at' => now()->subDay(), 'created_at' => $owner->created_at, 'updated_at' => now()]);
    $before = DB::table('notifications')->where('id', $owner->notification_id)->first();
    $this->artisan('notifications:import --dry-run')->assertSuccessful();
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('pending');
    $this->artisan('notifications:import')->assertSuccessful();
    $this->artisan('notifications:import')->assertSuccessful();
    $after = DB::table('notifications')->where('id', $owner->notification_id)->first();
    expect($after->id)->toBe($before->id);
    expect($after->created_at)->toBe($before->created_at);
    expect($after->read_at)->toBe($before->read_at);
    expect($after->data)->not->toContain('never expose');
    expect(DB::table('notification_inbox_attempts')->count())->toBe(1);
    Notification::assertNothingSent();
});

test('historical notices without a source-linked safe contract are excluded', function (): void {
    $customer = CustomerProfile::factory()->create();
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'unregistered', 'notifiable_type' => User::class,
        'notifiable_id' => $customer->user_id, 'data' => '{"secret":"unknown"}', 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('notifications:import')->assertSuccessful();
    $this->actingAs($customer->user)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
});

test('owner link is internal and destination performs its own authorization', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner] = inboxStatusNotice($customer);
    $this->actingAs($customer->user)->get(route('notifications.open', $owner->notification_id))->assertRedirect(route('customers.show', $customer->customer_id));
    expect(CustomerStatusHistory::query()->count())->toBe(1);
});

test('transient local delivery failures preserve atomicity and stop after three recorded attempts', function (): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    DB::statement("CREATE TRIGGER fail_inbox_completion BEFORE UPDATE ON notification_inbox_intents WHEN NEW.status = 'delivered' BEGIN SELECT RAISE(ABORT, 'simulated local outage'); END");
    $pipeline = app(NotificationPipeline::class);
    $pipeline->materialize($id);
    expect(DB::table('notifications')->count())->toBe(0);
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('pending');
    expect(DB::table('notification_inbox_attempts')->count())->toBe(1);
    $pipeline->materialize($id);
    expect(DB::table('notification_inbox_attempts')->count())->toBe(1);
    $this->travelTo(CarbonImmutable::parse(DB::table('notification_inbox_intents')->where('id', $id)->value('next_attempt_at')));
    $pipeline->materialize($id);
    $this->travelTo(CarbonImmutable::parse(DB::table('notification_inbox_intents')->where('id', $id)->value('next_attempt_at')));
    $pipeline->materialize($id);
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('dead_letter');
    expect(DB::table('notification_inbox_attempts')->count())->toBe(3);
    expect(DB::table('notifications')->count())->toBe(0);
    expect(CustomerStatusHistory::query()->count())->toBe(1);
    DB::statement('DROP TRIGGER fail_inbox_completion');
});

test('queue dispatch outage leaves the committed owner operation recoverable', function (): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    app(NotificationPipeline::class)->dispatchRecoverably(static function (): never {
        throw new RuntimeException('Provider address and secret must not be logged');
    });
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('pending');
    $this->artisan('notifications:drain')->assertSuccessful();
    Queue::assertPushed(MaterializeNotificationIntent::class);
});

test('legacy pending intents are captured by import without immediate historical dispatch', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner] = inboxStatusNotice($customer, deliver: false, capture: false);
    $this->artisan('notifications:import')->assertSuccessful();
    expect(DB::table('notification_inbox_intents')->count())->toBe(1);
    expect(DB::table('notification_inbox_intents')->value('status'))->toBe('pending');
    expect($owner->fresh()->status)->toBe('pending');
    Queue::assertNothingPushed();
});

test('historical duplicate aliases coalesce into one inbox item and preserve a read state', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$owner] = inboxStatusNotice($customer, deliver: false);
    $second = $owner->replicate();
    $second->notification_id = (string) Str::uuid();
    $second->purpose = 'customer_status_changed_receipt';
    $second->save();
    foreach ([$owner, $second] as $index => $notice) {
        DB::table('notifications')->insert(['id' => $notice->notification_id, 'type' => 'legacy', 'notifiable_type' => User::class,
            'notifiable_id' => $customer->user_id, 'data' => '{}', 'read_at' => $index === 1 ? now() : null,
            'created_at' => $notice->created_at, 'updated_at' => now()]);
    }
    $this->artisan('notifications:import')->assertSuccessful();
    expect(DB::table('notification_inbox_intents')->count())->toBe(1);
    expect(DB::table('notification_inbox_aliases')->count())->toBe(2);
    $this->actingAs($customer->user)->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 0);
    $this->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->has('inbox.items', 1));
});

test('proposals become expired or superseded without editing their rendered snapshot', function (): void {
    $agent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()]);
    $customer = inboxAssignedCustomer($agent);
    $this->actingAs($agent->user)->post(route('customers.name-corrections.store', $customer->customer_id), [
        'name' => 'Updated Name', 'reason' => 'Customer requested correction', 'version' => $customer->version,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $intent = DB::table('notification_inbox_intents')->where('action_required', true)->sole();
    app(NotificationPipeline::class)->materialize((int) $intent->id);
    $snapshot = $intent->snapshot_hash;
    $service = app(NotificationInbox::class);
    expect($service->detail($customer->user, $intent->notification_id)['action_required'])->toBeTrue();
    $this->travel(8)->days();
    $expired = $service->detail($customer->user, $intent->notification_id);
    expect($expired['visibility'])->toBe('expired');
    expect($expired['action_required'])->toBeFalse();
    expect($service->read($customer->user, ['status' => 'expired'])['items'])->toHaveCount(1);
    DB::table('customer_name_corrections')->where('id', $intent->action_correction_id)->update(['status' => 'replaced']);
    expect($service->read($customer->user, ['status' => 'superseded'])['items'])->toHaveCount(1);
    expect(DB::table('notification_inbox_intents')->where('id', $intent->id)->value('snapshot_hash'))->toBe($snapshot);
});

test('unknown stored variables or changed rendered snapshots block delivery rather than leak content', function (string $mutation): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    if ($mutation === 'variables') {
        DB::table('notification_events')->update(['facts' => json_encode(['status' => 'restricted', 'token' => 'must never reach inbox'])]);
    } else {
        DB::table('notification_inbox_intents')->where('id', $id)->update(['summary' => 'Unexpected sensitive text']);
    }
    app(NotificationPipeline::class)->materialize($id);
    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('blocked');
    expect(DB::table('notifications')->count())->toBe(0);
})->with(['variables', 'snapshot']);

test('plan notices use their immutable lifecycle event and retain Invited Customer ownership', function (): void {
    $agent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()]);
    $customer = inboxAssignedCustomer($agent);
    $customer->user->forceFill(['account_state' => AccountState::Invited])->save();
    $plan = ThriftPlan::create(['plan_id' => 'PLN-INBOX-001', 'customer_profile_id' => $customer->id,
        'created_by_user_id' => $agent->user_id, 'open_customer_profile_id' => $customer->id,
        'status' => ThriftPlanStatus::Paused, 'current_terms_revision' => 1, 'version' => 2]);
    $event = PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => 'pause',
        'from_status' => ThriftPlanStatus::Active, 'to_status' => ThriftPlanStatus::Paused,
        'actor_user_id' => $agent->user_id, 'assignment_version' => 1, 'plan_version' => 2,
        'reason' => 'Private operational reason', 'payload' => [], 'effective_at' => now()]);
    $owner = PlanNotificationIntent::create(['notification_id' => (string) Str::uuid(),
        'plan_lifecycle_event_id' => $event->id, 'thrift_plan_id' => $plan->id, 'customer_profile_id' => $customer->id,
        'recipient_user_id' => $customer->user_id, 'audience_type' => 'subject_customer', 'channel' => 'database',
        'purpose' => 'plan_lifecycle_changed', 'payload' => ['title' => 'forged', 'message' => 'Paid out'], 'status' => 'pending']);
    $pipeline = app(NotificationPipeline::class);
    $id = $pipeline->capture('plan', $owner->id, false);
    $pipeline->materialize($id);
    expect($owner->fresh()->status)->toBe('delivered');
    $customer->user->forceFill(['account_state' => AccountState::Active])->save();
    $notice = app(NotificationInbox::class)->detail($customer->user->fresh(), $owner->notification_id);
    expect($notice['category'])->toBe('plan');
    expect($notice['summary'])->toBe('Your daily thrift plan was paused.');
    expect(DB::table('notification_events')->where('family', 'plan')->value('source_version'))->toBe(2);
});

test('any changed stored contract blocks delivery instead of rendering', function (string $table, array $changes): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    $eventId = DB::table('notification_inbox_intents')->where('id', $id)->value('event_id');
    DB::table($table)->where('id', $table === 'notification_events' ? $eventId : $id)->update($changes);

    app(NotificationPipeline::class)->materialize($id);

    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe('blocked')
        ->and(DB::table('notifications')->count())->toBe(0);
})->with([
    'unknown event type' => ['notification_events', ['event_type' => 'made_up']],
    'unknown family' => ['notification_events', ['family' => 'unregistered']],
    'future schema' => ['notification_events', ['schema_version' => 2]],
    'zero source version' => ['notification_events', ['source_version' => 0]],
    'unexpected facts' => ['notification_events', ['facts' => json_encode(['status' => 'restricted', 'reason' => 'leak'])]],
    'mismatched template' => ['notification_inbox_intents', ['template_id' => 'customer_status.other']],
    'changed locale' => ['notification_inbox_intents', ['locale' => 'fr-FR']],
    'optional notice' => ['notification_inbox_intents', ['mandatory' => false]],
    'broadened audience' => ['notification_inbox_intents', ['audiences' => json_encode(['subject_customer', 'security_operations_admin'])]],
    'edited summary' => ['notification_inbox_intents', ['summary' => 'Click https://evil.test now']],
]);

test('only the supported template version renders', function (int $version, string $status): void {
    $customer = CustomerProfile::factory()->create();
    [, $id] = inboxStatusNotice($customer, deliver: false);
    DB::table('notification_inbox_intents')->where('id', $id)->update(['template_version' => $version]);

    app(NotificationPipeline::class)->materialize($id);

    expect(DB::table('notification_inbox_intents')->where('id', $id)->value('status'))->toBe($status);
})->with([
    'current' => [1, 'delivered'],
    'older' => [0, 'blocked'],
    'next' => [2, 'blocked'],
]);
