<?php

use App\Enums\AdminPermission;
use App\Enums\ThriftPlanStatus;
use App\Models\AuditEvent;
use App\Models\LedgerPostingGroup;
use App\Models\OfflinePayout;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalNotificationIntent;
use App\Services\WithdrawalMethodRegistry;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../OfflinePayoutFixtures.php';

test('recording an offline payout posts the withdrawal to the matching business account', function (string $channel, string $account, string $reference): void {
    [$admin, , $customer, $plan, $withdrawal] = offlinePayoutFixture($this);

    $this->actingAs($admin)->withSession(offlineSession())
        ->post(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal, ['channel' => $channel, 'external_reference' => $reference]))
        ->assertRedirect(route('withdrawals.show', $withdrawal))->assertSessionHasNoErrors();

    $withdrawal->refresh();
    $payout = OfflinePayout::query()->sole();
    $group = LedgerPostingGroup::query()->where('event_type', 'offline_withdrawal')->sole();
    $credit = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
        ->where('ledger_posting_group_id', $group->id)->where('ledger_accounts.code', $account)->where('side', 'credit')->sole();
    expect($withdrawal->state)->toBe('posted')->and($withdrawal->terminal_at)->not->toBeNull()
        ->and($withdrawal->live_thrift_plan_id)->toBeNull()
        ->and($payout->channel)->toBe($channel)->and($payout->amount_kobo)->toBe(29400)
        ->and($payout->ledger_posting_group_id)->toBe($group->id)->and($payout->recorded_by_user_id)->toBe($admin->id)
        ->and((int) $credit->amount_kobo)->toBe(29400)
        ->and($group->source_id)->toBe((string) $withdrawal->id)
        ->and(DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->value('status'))->toBe('consumed')
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'offline_posted')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'withdrawal.offline_posted')->count())->toBe(1)
        ->and(WithdrawalNotificationIntent::query()->where('recipient_user_id', $customer->user_id)->where('channel', 'database')
            ->where('payload->message', 'Your withdrawal was paid outside the platform and posted.')->exists())->toBeTrue()
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active)
        ->and($plan->fresh()->closure_pending_at)->toBeNull();
    $this->actingAs($admin)->get(route('withdrawals.show', $withdrawal))->assertOk()
        ->assertInertia(fn ($page) => $page->where('offline_payout.channel', $channel)->where('offline_payout.amount_kobo', 29400)
            ->where('can_record_offline', true));
})->with([
    'cash' => ['cash', 'business_cash_ngn', ''],
    'bank transfer' => ['bank_transfer', 'business_bank_ngn', 'TRF-2026-0042'],
]);

test('recording an offline payout needs withdrawal review authority and fresh authentication', function (bool $permitted, bool $fresh): void {
    [, , , , $withdrawal] = offlinePayoutFixture($this);
    $actor = User::factory()->admin()->withTwoFactor()->create();
    if ($permitted) {
        $actor->givePermissionTo(AdminPermission::WithdrawalsReview);
    }

    $this->flushSession();
    $response = $this->actingAs($actor)->withSession($fresh ? offlineSession() : [])
        ->post(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal));

    $fresh ? $response->assertForbidden() : $response->assertRedirect(route('fresh-authentication'));
    expect(OfflinePayout::query()->count())->toBe(0)->and($withdrawal->fresh()->state)->toBe('approved')
        ->and(LedgerPostingGroup::query()->where('event_type', 'offline_withdrawal')->count())->toBe(0);
})->with(['no permission' => [false, true], 'stale session' => [true, false]]);

test('replaying an offline payout returns the first record and a changed replay conflicts', function (): void {
    [$admin, , , , $withdrawal] = offlinePayoutFixture($this);
    $payload = offlinePayload($withdrawal);
    $this->actingAs($admin)->withSession(offlineSession())
        ->post(route('withdrawals.offline-payout.store', $withdrawal), $payload)->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('withdrawals.offline-payout.store', $withdrawal), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(route('withdrawals.offline-payout.store', $withdrawal), [...$payload, 'note' => 'A different story.'])->assertConflict();
    $this->postJson(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal->fresh()))->assertConflict();

    expect(OfflinePayout::query()->count())->toBe(1)
        ->and(LedgerPostingGroup::query()->where('event_type', 'offline_withdrawal')->count())->toBe(1);
});

test('an offline payout is refused for a request that is not approved or is held', function (string $case): void {
    [$admin, , , , $withdrawal] = offlinePayoutFixture($this, approve: $case !== 'pending review');
    if ($case === 'held') {
        $withdrawal->forceFill(['held' => true, 'hold_reason' => 'customer_restricted', 'held_at' => now()])->save();
    }
    $before = DB::table('ledger_entries')->orderBy('id')->get()->all();

    $this->actingAs($admin)->withSession(offlineSession())
        ->postJson(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal->fresh()))->assertConflict();

    expect(OfflinePayout::query()->count())->toBe(0)
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->value('status'))->toBe('live');
})->with(['pending review', 'held']);

test('a bank transfer payout needs its reference and a payment date no later than today', function (array $overrides, string $field): void {
    [$admin, , , , $withdrawal] = offlinePayoutFixture($this);

    $this->actingAs($admin)->withSession(offlineSession())
        ->post(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal, $overrides))->assertSessionHasErrors($field);

    expect(OfflinePayout::query()->count())->toBe(0)->and($withdrawal->fresh()->state)->toBe('approved');
})->with([
    'missing bank reference' => [['channel' => 'bank_transfer', 'external_reference' => ''], 'external_reference'],
    'future payment date' => [['paid_on' => now('Africa/Lagos')->addDay()->toDateString()], 'paid_on'],
]);

test('the offline method is unavailable while its switch is off', function (): void {
    [$admin, $agent, $customer, $plan, $withdrawal] = offlinePayoutFixture($this);
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toContain('offline');

    config()->set('withdrawals.offline_enabled', false);

    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->not->toContain('offline');
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [
        'plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '100.00', 'method' => 'offline',
        'destination_reference' => 'customer:'.$customer->id, 'reason' => 'Customer asked', 'internal_notes' => '',
    ])->assertStatus(503);
    $this->actingAs($admin)->withSession(offlineSession())
        ->postJson(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal))->assertStatus(503);
    expect(OfflinePayout::query()->count())->toBe(0);
});
