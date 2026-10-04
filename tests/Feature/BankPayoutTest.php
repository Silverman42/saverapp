<?php

use App\Enums\AdminPermission;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Services\FakePayoutProvider;
use App\Services\LedgerTransactionProjectionService;
use App\Services\UnavailablePayoutProvider;
use App\Support\PayoutProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../BankPayoutFixtures.php';

test('a confirmed bank transfer posts one balanced withdrawal and consumes the reservation', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '1');
    expect($withdrawal->state)->toBe('approved');
    $version = $withdrawal->version;
    $reference = (string) Str::uuid();
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => $reference, 'version' => $version, 'confirmed' => true])->assertRedirect();

    $attempt = bankAttempt($reference);
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->where('event_type', 'bank_withdrawal')->with('entries.account')->sole();
    expect($attempt->status)->toBe('succeeded')->and($attempt->provider_outcome)->toBe('succeeded')->and($attempt->ledger_posting_group_id)->toBe($group->id)
        ->and($withdrawal->fresh()->state)->toBe('posted')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('consumed')
        ->and(groupLines($group))->toBe(['customer_savings_liability_ngn:debit:30000', 'payout_clearing_ngn:credit:30000']);

    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => $reference, 'version' => $version, 'confirmed' => true])->assertRedirect();
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => $reference, 'version' => $version + 1, 'confirmed' => true])->assertStatus(409);
    expect(BankPayoutAttempt::query()->count())->toBe(1)->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(1);
});

test('a definitive provider failure keeps the reservation and permits a separately identified retry', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '2');
    $first = startBankPayout($this, $admin, $withdrawal);

    $attempt = bankAttempt($first);
    expect($attempt->status)->toBe('failed')->and($attempt->failure_code)->toBe('account_closed')->and($attempt->live_withdrawal_request_id)->toBeNull()
        ->and($withdrawal->fresh()->state)->toBe('payment_failed')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live')
        ->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(0)
        ->and($withdrawal->fresh()->deadline_at->greaterThan(now()->addDays(6)))->toBeTrue();

    startBankPayout($this, $admin, $withdrawal);
    expect(BankPayoutAttempt::query()->orderBy('id')->pluck('attempt_number')->all())->toBe([1, 2]);
});

test('a timeout whose transfer exists stays unknown until a same-key query proves success, then posts once', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '4');
    $reference = startBankPayout($this, $admin, $withdrawal);

    $attempt = bankAttempt($reference);
    expect($attempt->status)->toBe('unknown')->and($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(0);
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->fresh()->version, 'confirmed' => true])->assertStatus(409);
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown');

    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe('succeeded')->and($withdrawal->fresh()->state)->toBe('posted')
        ->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(1)
        ->and(BankPayoutAttempt::query()->count())->toBe(1);
});

test('a timeout with no transfer is only a failure after the not-found window', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '5');
    $reference = startBankPayout($this, $admin, $withdrawal);
    expect(bankAttempt($reference)->status)->toBe('unknown');

    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();
    expect(bankAttempt($reference)->status)->toBe('unknown')->and($withdrawal->fresh()->state)->toBe('outcome_unknown');

    $this->travel(31)->minutes();
    BankPayoutAttempt::query()->update(['next_check_at' => now()->subMinute()]);
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();

    expect(bankAttempt($reference)->status)->toBe('failed')->and(bankAttempt($reference)->failure_code)->toBe('not_found_after_window')
        ->and($withdrawal->fresh()->state)->toBe('payment_failed')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');
});

test('an accepted transfer is not final until a query confirms it', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '3');
    $reference = startBankPayout($this, $admin, $withdrawal);
    expect(bankAttempt($reference)->status)->toBe('submitted')->and($withdrawal->fresh()->state)->toBe('payout_processing');

    BankPayoutAttempt::query()->update(['next_check_at' => now()->subSecond()]);
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();

    expect(bankAttempt($reference)->status)->toBe('succeeded')->and($withdrawal->fresh()->state)->toBe('posted');
});

test('an unknowable provider outcome keeps the reservation and never posts', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '6');
    $reference = startBankPayout($this, $admin, $withdrawal);
    $this->travel(2)->hours();
    BankPayoutAttempt::query()->update(['next_check_at' => now()->subSecond()]);
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();

    expect(bankAttempt($reference)->status)->toBe('unknown')->and($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live')
        ->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(0);
});

test('a recorded success that fails to post is posted later without sending again', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '3');
    $reference = startBankPayout($this, $admin, $withdrawal);
    expect(bankAttempt($reference)->status)->toBe('submitted');
    DB::table('financial_periods')->update(['status' => 'closed']);
    BankPayoutAttempt::query()->update(['next_check_at' => now()->subSecond()]);
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();
    $attempt = bankAttempt($reference);
    expect($attempt->status)->toBe('succeeded')->and($attempt->ledger_posting_group_id)->toBeNull()->and($withdrawal->fresh()->state)->toBe('payout_processing')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');

    DB::table('financial_periods')->update(['status' => 'open']);
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();

    expect(bankAttempt($reference)->ledger_posting_group_id)->not->toBeNull()->and($withdrawal->fresh()->state)->toBe('posted')
        ->and(BankPayoutAttempt::query()->count())->toBe(1);
});

test('callbacks are authenticated, replay-safe and never finalize an outcome by themselves', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '3');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    $event = callbackEvent($attempt, 'succeeded', 'evt-1');

    sendPayoutCallback($this, $event, tamper: [...$event, 'amount_kobo' => 1])->assertStatus(400);
    sendPayoutCallback($this, $event, now()->subHour()->timestamp)->assertStatus(400);
    expect($attempt->fresh()->status)->toBe('submitted');

    sendPayoutCallback($this, $event)->assertOk()->assertJson(['disposition' => 'applied']);
    expect($attempt->fresh()->status)->toBe('succeeded')->and($withdrawal->fresh()->state)->toBe('posted');
    sendPayoutCallback($this, $event)->assertOk()->assertJson(['disposition' => 'duplicate']);
    sendPayoutCallback($this, [...$event, 'amount_kobo' => 31000])->assertStatus(409)->assertJson(['disposition' => 'conflict']);
    sendPayoutCallback($this, callbackEvent($attempt, 'succeeded', 'evt-unmatched', ['idempotency_key' => str_repeat('a', 64)]))->assertOk()->assertJson(['disposition' => 'unmatched']);
    expect(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(1)
        ->and(DB::table('bank_payout_callbacks')->count())->toBe(2);
});

test('a callback that claims success is ignored when the provider query does not confirm it', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '9');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    expect($attempt->status)->toBe('submitted');

    sendPayoutCallback($this, callbackEvent($attempt, 'succeeded'))->assertOk();

    expect($attempt->fresh()->status)->toBe('submitted')->and($withdrawal->fresh()->state)->toBe('payout_processing')
        ->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(0);
});

test('settlement clears the payout payable against bank funding without touching liability', function (): void {
    [$admin, , $customer, , $withdrawal] = bankPayoutFixture($this, '1');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    $liabilityBefore = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
        ->where('ledger_accounts.code', 'customer_savings_liability_ngn')->selectRaw("SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END) as v")->value('v');

    $event = callbackEvent($attempt, 'settled', 'evt-settle');
    sendPayoutCallback($this, $event)->assertOk();
    sendPayoutCallback($this, [...$event, 'event_id' => 'evt-settle-2'])->assertOk();

    $group = LedgerPostingGroup::query()->where('event_type', 'bank_payout_settlement')->with('entries.account')->sole();
    $liabilityAfter = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
        ->where('ledger_accounts.code', 'customer_savings_liability_ngn')->selectRaw("SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END) as v")->value('v');
    expect(groupLines($group))->toBe(['business_bank_ngn:credit:30000', 'payout_clearing_ngn:debit:30000'])
        ->and($attempt->fresh()->settlement_posting_group_id)->toBe($group->id)->and($attempt->fresh()->settled_at)->not->toBeNull()
        ->and((int) $liabilityAfter)->toBe((int) $liabilityBefore);
});

test('a provider return after posting records custody movement and leaves the withdrawal posted', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '7');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    $event = callbackEvent($attempt, 'returned', 'evt-return', ['return_reference' => 'RET-1']);

    sendPayoutCallback($this, $event)->assertOk()->assertJson(['disposition' => 'applied']);
    sendPayoutCallback($this, [...$event, 'event_id' => 'evt-return-again'])->assertOk();

    $return = BankPayoutReturn::query()->sole();
    $group = LedgerPostingGroup::query()->where('event_type', 'bank_payout_return')->with('entries.account')->sole();
    expect($return->status)->toBe('posted')->and($return->return_posting_group_id)->toBe($group->id)->and($withdrawal->fresh()->state)->toBe('posted')
        ->and(groupLines($group))->toBe(['cash_recovery_clearing_ngn:credit:30000', 'payout_clearing_ngn:debit:30000']);
    $attempt->refresh();
    sendPayoutCallback($this, callbackEvent($attempt, 'settled', 'evt-settle-late'))->assertOk();
    sendPayoutCallback($this, callbackEvent($attempt, 'returned', 'evt-return-2', ['return_reference' => 'RET-2']))->assertOk();
    expect(BankPayoutReturn::query()->where('status', 'exception')->count())->toBe(1);
});

test('a late success after a definitive failure becomes a held exception and posts nothing', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '8');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    expect($attempt->status)->toBe('failed')->and($withdrawal->fresh()->state)->toBe('payment_failed');
    FakePayoutProvider::forceQuery($attempt->idempotency_key, 'succeeded');

    sendPayoutCallback($this, callbackEvent($attempt, 'succeeded'))->assertOk();

    $withdrawal->refresh();
    expect($attempt->fresh()->status)->toBe('failed')->and($withdrawal->held)->toBeTrue()->and($withdrawal->hold_reason)->toBe('provider_conflict')
        ->and(BankPayoutReturn::query()->where('status', 'exception')->count())->toBe(1)
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'provider_conflict')->count())->toBe(1)
        ->and(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count())->toBe(0);
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true])->assertStatus(409);
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('payment_failed');
});

test('the fake provider is never bound outside local and testing environments', function (): void {
    config()->set(['withdrawals.bank.provider' => 'fake']);
    expect(app(PayoutProvider::class))->toBeInstanceOf(FakePayoutProvider::class);
    app()->detectEnvironment(fn (): string => 'production');
    expect(app(PayoutProvider::class))->toBeInstanceOf(UnavailablePayoutProvider::class);
    app()->detectEnvironment(fn (): string => 'testing');
});

test('the local fake-event command drives the real callback path and refuses other environments', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '3');
    $reference = startBankPayout($this, $admin, $withdrawal);
    expect(bankAttempt($reference)->status)->toBe('submitted');

    $this->artisan('payouts:fake-event', ['attempt' => $reference, 'event' => 'succeeded'])->expectsOutput('Callback applied.')->assertSuccessful();
    expect(bankAttempt($reference)->status)->toBe('succeeded')->and($withdrawal->fresh()->state)->toBe('posted');

    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('payouts:fake-event', ['attempt' => $reference, 'event' => 'settled'])->assertFailed();
    app()->detectEnvironment(fn (): string => 'testing');
    expect(LedgerPostingGroup::query()->where('event_type', 'bank_payout_settlement')->count())->toBe(0);
});

test('unknown payouts, unmatched callbacks and ledger incidents surface as permission-scoped incident queues', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '4');
    $reference = startBankPayout($this, $admin, $withdrawal);
    $attempt = bankAttempt($reference);
    sendPayoutCallback($this, callbackEvent($attempt, 'succeeded', 'evt-orphan', ['idempotency_key' => str_repeat('b', 64)]))->assertJson(['disposition' => 'unmatched']);
    $incident = (string) Str::uuid();
    DB::table('ledger_integrity_incidents')->insert(['incident_reference' => $incident, 'category' => 'projection_mismatch', 'status' => 'open',
        'summary' => 'Detected in test.', 'projection_version' => 1, 'ledger_group_watermark' => 1, 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
    $admin = $admin->fresh();
    $value = fn (array $metrics, string $code): ?int => collect($metrics)->firstWhere('code', $code)['value'] ?? null;

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.incidents.status', 'Current')
        ->where('dashboard.sections.incidents.metrics', fn ($metrics) => $value($metrics->all(), 'bank_outcome_unknown') === 1
            && $value($metrics->all(), 'unmatched_bank_callbacks') === 1 && $value($metrics->all(), 'open_ledger_incidents') === null)
        ->where('dashboard.sections.incidents.rows', fn ($rows) => collect($rows)->firstWhere('reference', $reference)['href'] === route('withdrawals.show', $withdrawal->withdrawal_id)));

    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin->fresh())->get(route('reports.show', 'exceptions'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.payout_incidents.status', 'Current')
        ->where('report.sections.payout_incidents.total', 3)
        ->where('report.sections.payout_incidents.metrics', fn ($metrics) => $value($metrics->all(), 'open_ledger_incidents') === 1));
    $this->get(route('reports.show', ['report' => 'exceptions', 'customer_status' => 'active']))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.payout_incidents.status', 'Unavailable'));

    $reader = User::factory()->admin()->create();
    $this->actingAs($reader)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->missing('dashboard.sections.incidents'));
});

test('the withdrawals report lists each posted payout with its gross, paid, fee and deduction components', function (): void {
    [$admin, , $customer, , $withdrawal] = bankPayoutFixture($this, '1');
    startBankPayout($this, $admin, $withdrawal);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $value = fn ($metrics, string $code): ?int => collect($metrics)->firstWhere('code', $code)['value'] ?? null;

    $this->actingAs($admin)->get(route('reports.show', 'withdrawals'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.posted_payouts.total', 1)
        ->where('report.sections.posted_payouts.rows.0.reference', $withdrawal->withdrawal_id)
        ->where('report.sections.posted_payouts.rows.0.rail', 'bank_withdrawal')
        ->where('report.sections.posted_payouts.rows.0.compensation', 'None')
        ->where('report.sections.posted_payouts.rows.0.href', route('withdrawals.show', $withdrawal->withdrawal_id))
        ->where('report.sections.posted_payouts.metrics', fn ($metrics) => $value($metrics, 'gross_withdrawal') === 30000
            && $value($metrics, 'amount_paid') === 30000 && $value($metrics, 'withdrawal_fee') === 0));

    $this->actingAs($customer->user)->get(route('reports.show', 'withdrawals'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.posted_payouts.total', 1));
    $this->actingAs(CustomerProfile::factory()->create()->user)->get(route('reports.show', 'withdrawals'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.posted_payouts.total', 0));
    $this->actingAs($admin)->get(route('reports.show', ['report' => 'withdrawals', 'state' => 'pending_review']))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.posted_payouts.status', 'Unavailable'));
});

test('a posted bank payout notifies the Customer and current Agent once, never other Customers', function (): void {
    [$admin, $agent, $customer, , $withdrawal] = bankPayoutFixture($this, '1');
    $other = CustomerProfile::factory()->create();
    $version = $withdrawal->version;
    $reference = startBankPayout($this, $admin, $withdrawal);
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => $reference, 'version' => $version, 'confirmed' => true])->assertRedirect();
    $posted = fn (int $userId): int => DB::table('notification_inbox_intents')->where('recipient_user_id', $userId)
        ->where('template_id', 'withdrawal.bank_posted')->count();

    expect($posted($agent->id))->toBe(1)->and($posted($customer->user_id))->toBe(1)
        ->and($posted($other->user_id))->toBe(0);
});
