<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Models\AgentProfile;
use App\Models\BankPayoutAttempt;
use App\Models\CustomerAssignment;
use App\Models\CustomerPayoutDestination;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Services\FakePayoutProvider;
use App\Services\WithdrawalMethodRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../BankPayoutFixtures.php';

function registerDestination(object $test, User $agent, CustomerProfile $customer, string $account, ?string $reference = null): TestResponse
{
    return $test->actingAs($agent)->post(route('customers.payout-destinations.store', $customer->customer_id), [
        'bank_code' => '058', 'account_number' => $account, 'attestation' => 'The Customer gave this account in person.',
        'registration_reference' => $reference ?? (string) Str::uuid()]);
}

test('a provider name that does not match the Customer cannot be verified', function (): void {
    [$admin, $agent, $customer] = bankPayoutFixture($this, '1', approve: false);
    CustomerPayoutDestination::query()->update(['status' => 'superseded', 'active_customer_profile_id' => null]);
    FakePayoutProvider::nameAccount('058', '5555555551', 'COMPLETELY DIFFERENT PERSON');

    registerDestination($this, $agent, $customer, '5555555551')->assertRedirect();
    $destination = CustomerPayoutDestination::query()->latest('id')->firstOrFail();
    expect($destination->name_match)->toBe('mismatch')->and($destination->status)->toBe('pending_verification');

    $this->actingAs($admin)->withSession(bankSession())->post(route('payout-destinations.verify', $destination), ['note' => 'Looks fine.', 'confirmed' => true])->assertStatus(409);
    $this->post(route('payout-destinations.reject', $destination), ['note' => 'The payee is another person.', 'confirmed' => true])->assertRedirect();
    expect($destination->fresh()->status)->toBe('rejected');
});

test('one account cannot be registered for two Customers and the account number is never stored', function (): void {
    [, $agent, $customer] = bankPayoutFixture($this, '1', approve: false);
    $account = '1234567891';
    $otherAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $otherProfile = AgentProfile::factory()->active()->create(['user_id' => $otherAgent->id]);
    $otherCustomer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $otherCustomer->id, 'agent_profile_id' => $otherProfile->id,
        'assigned_by_user_id' => $otherAgent->id, 'status' => CustomerAssignmentStatus::Current]);
    FakePayoutProvider::nameAccount('058', $account, (string) $otherCustomer->user->name);

    registerDestination($this, $otherAgent, $otherCustomer, $account)->assertStatus(409);

    $destination = CustomerPayoutDestination::query()->where('customer_profile_id', $customer->id)->sole();
    $stored = json_encode(DB::table('customer_payout_destinations')->get()).json_encode(DB::table('audit_events')->get());
    expect($stored)->not->toContain($account)->and($destination->account_mask)->toBe('******7891')
        ->and(CustomerPayoutDestination::query()->where('customer_profile_id', $otherCustomer->id)->count())->toBe(0);
});

test('registration is replay-safe and a changed payload under the same reference conflicts', function (): void {
    [, $agent, $customer] = bankPayoutFixture($this, '1', approve: false);
    $reference = (string) Str::uuid();
    FakePayoutProvider::nameAccount('058', '7777777771', (string) $customer->user->name);
    CustomerPayoutDestination::query()->update(['status' => 'superseded', 'active_customer_profile_id' => null]);

    registerDestination($this, $agent, $customer, '7777777771', $reference)->assertRedirect();
    registerDestination($this, $agent, $customer, '7777777771', $reference)->assertRedirect();
    registerDestination($this, $agent, $customer, '7777777772', $reference)->assertStatus(409);

    expect(CustomerPayoutDestination::query()->where('registration_reference', $reference)->count())->toBe(1);
});

test('only the current assigned Agent registers and only an Admin with Customer management verifies', function (): void {
    [$admin, $agent, $customer] = bankPayoutFixture($this, '1', approve: false);
    CustomerPayoutDestination::query()->update(['status' => 'superseded', 'active_customer_profile_id' => null]);
    FakePayoutProvider::nameAccount('058', '6666666661', (string) $customer->user->name);
    $other = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    AgentProfile::factory()->active()->create(['user_id' => $other->id]);

    registerDestination($this, $other, $customer, '6666666661')->assertForbidden();
    registerDestination($this, $admin, $customer, '6666666661')->assertForbidden();
    registerDestination($this, $customer->user, $customer, '6666666661')->assertForbidden();
    registerDestination($this, $agent, $customer, '6666666661')->assertRedirect();
    $destination = CustomerPayoutDestination::query()->latest('id')->firstOrFail();

    $limited = User::factory()->admin()->withTwoFactor()->create();
    $limited->givePermissionTo(AdminPermission::WithdrawalsReview);
    $this->actingAs($limited)->withSession(bankSession())->post(route('payout-destinations.verify', $destination), ['note' => 'Fine.', 'confirmed' => true])->assertForbidden();
    $this->actingAs($agent)->post(route('payout-destinations.verify', $destination), ['note' => 'Fine.', 'confirmed' => true])->assertForbidden();
    expect($destination->fresh()->status)->toBe('pending_verification');
});

test('a destination cannot be replaced while a live request uses it', function (): void {
    [$admin, $agent, $customer] = bankPayoutFixture($this, '1');
    FakePayoutProvider::nameAccount('058', '4444444441', (string) $customer->user->name);
    registerDestination($this, $agent, $customer, '4444444441')->assertRedirect();
    $replacement = CustomerPayoutDestination::query()->latest('id')->firstOrFail();

    $this->actingAs($admin)->withSession(bankSession())->post(route('payout-destinations.verify', $replacement), ['note' => 'Replace.', 'confirmed' => true])->assertStatus(409);

    expect($replacement->fresh()->status)->toBe('pending_verification')->and(CustomerPayoutDestination::query()->where('status', 'verified')->count())->toBe(1);
});

test('revoking a destination holds live requests without pausing their expiry and blocks the transfer', function (): void {
    [$admin, , , , $withdrawal, $destination] = bankPayoutFixture($this, '1');

    $this->actingAs($admin)->withSession(bankSession())->post(route('payout-destinations.revoke', $destination), ['note' => 'Account reported closed.', 'confirmed' => true])->assertRedirect();

    $withdrawal->refresh();
    expect($destination->fresh()->status)->toBe('revoked')->and($withdrawal->held)->toBeTrue()->and($withdrawal->hold_reason)->toBe('destination_invalid')
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'hold_applied')->count())->toBe(1);
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true])->assertStatus(409);
    expect(BankPayoutAttempt::query()->count())->toBe(0);
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('expired')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('released');
});

test('a destination used by an in-flight transfer cannot be revoked', function (): void {
    [$admin, , , , $withdrawal, $destination] = bankPayoutFixture($this, '3');
    startBankPayout($this, $admin, $withdrawal);
    expect($withdrawal->fresh()->state)->toBe('payout_processing');

    $this->actingAs($admin)->withSession(bankSession())->post(route('payout-destinations.revoke', $destination), ['note' => 'Report.', 'confirmed' => true])->assertStatus(409);

    expect($destination->fresh()->status)->toBe('verified');
});

test('bank transfer is offered only when its flags, settings and provider are all available', function (): void {
    [$agent, $customer] = withdrawalFixture();
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toBe([]);
    config()->set(['withdrawals.bank_enabled' => true, 'withdrawals.bank_certified' => true]);
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toBe([]);
    config()->set(['withdrawals.bank.provider' => 'fake']);
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toBe(['bank_transfer']);
    config()->set(['withdrawals.bank_certified' => false]);
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toBe([]);
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [
        'plan_id' => 'PLN-WDL-001', 'type' => 'partial', 'gross_ngn' => '300.00', 'method' => 'bank_transfer',
        'destination_reference' => (string) Str::uuid(), 'reason' => 'Customer requested a transfer'])->assertStatus(503);
});

test('the Customer sees only masked destinations and the Agent sees the verified payee name', function (): void {
    [$admin, $agent, $customer, , , $destination] = bankPayoutFixture($this, '1', approve: false);

    $this->actingAs($customer->user)->get(route('customers.payout-destinations.index', $customer->customer_id))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('customers/PayoutDestinations')
        ->where('destinations.0.reference', $destination->destination_reference)->where('destinations.0.account_mask', '******7891')
        ->where('destinations.0.payee_name', null)->where('can_register', false)->where('can_review', false)->missing('destinations.0.account_token'));
    $this->actingAs($agent)->get(route('customers.payout-destinations.index', $customer->customer_id))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('destinations.0.payee_name', (string) $customer->user->name)
        ->where('can_register', true)->where('bank_available', true));
    $this->actingAs($admin)->get(route('customers.payout-destinations.index', $customer->customer_id))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_review', true));
});

test('the withdrawal pages offer the bank method and keep provider evidence from the Customer', function (): void {
    [$admin, $agent, $customer, , $withdrawal] = bankPayoutFixture($this, '3');
    $this->actingAs($agent)->get(route('customers.withdrawals.create', $customer->customer_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('methods', ['bank_transfer'])->has('bank_destinations', 1)
            ->where('bank_destinations.0.label', 'Fake Bank 058 ******7893'));
    startBankPayout($this, $admin, $withdrawal);

    $this->actingAs($admin)->get(route('withdrawals.show', $withdrawal))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('bank_attempts.0.status', 'submitted')->where('can_execute_bank', true)->where('can_execute', false)
            ->where('withdrawal.deduction_kobo', 0));
    $this->actingAs($customer->user)->get(route('withdrawals.show', $withdrawal))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('bank_attempts', [])->where('bank_returns', [])->where('can_execute_bank', false));
    $this->actingAs($admin)->get(route('withdrawals.index', ['state' => 'payout_processing']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('new_requests_available', true));
});
