<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Jobs\DeliverCustomerInvitationJob;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../ReversalAcceptanceGapFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    Storage::fake('local');
    $business = BusinessProfile::current();
    $business->invitation_sender_email = 'invitations@saverapp.ng';
    $business->invitation_sender_name = 'SaverApp Security';
    $business->is_invitation_sender_verified = true;
    $business->save();
});

/** @return array<string, int> */
function camFreshSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

function camPublishRegistrationRule(object $test): FeeRule
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $terms = ['kind' => 'registration', 'name' => 'Registration terms', 'model' => 'fixed', 'amount_ngn' => '500.00',
        'customer_description' => 'Agreed account registration fee.', 'publication_reason' => 'Approved registration terms.'];
    $fingerprint = $test->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $terms)->assertOk()->json('preview_fingerprint');
    $test->withSession(camFreshSession())->postJson(route('admin.fees.registration.store'), [...$terms, 'confirmed' => true, 'preview_fingerprint' => $fingerprint])->assertRedirect();

    return FeeRule::query()->latest('version')->firstOrFail();
}

/** @return array<string, int> */
function camIdentityCounts(): array
{
    return collect(['users', 'customer_profiles', 'customer_assignments', 'fee_snapshots', 'fee_obligations', 'invitations', 'creation_attempts'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
}

test('CAM-AC-001: an Agent registers a Customer with a Unicode name and only required fields', function (): void {
    Queue::fake([DeliverCustomerInvitationJob::class]);
    $rule = camPublishRegistrationRule($this);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    $this->actingAs($agent)->post(route('customers.store'), ['attempt_reference' => (string) Str::uuid(), 'fee_rule_version' => $rule->version,
        'name' => 'Ọláyínká Àdìsá', 'email' => 'olayinka@saverapp.test', 'phone' => '+2348012345670'])->assertRedirect();

    $customer = CustomerProfile::query()->sole();
    expect($customer->user->name)->toBe('Ọláyínká Àdìsá')
        ->and($customer->customer_id)->toMatch('/\ACUS-\d{6}\z/')
        ->and($customer->operational_status)->toBe(CustomerStatus::Active)
        ->and($customer->user->account_state)->toBe(AccountState::Invited)
        ->and($customer->currentAssignment->agent_profile_id)->toBe($agent->agentProfile->id)
        ->and([$customer->address, $customer->gender, $customer->occupation, $customer->internal_reference])->toBe([null, null, null, null]);
});

test('CAM-AC-008: Agent registration creates no Customer fee or assignment', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage);

    $this->actingAs($admin)->post(route('agents.store'), ['attempt_reference' => (string) Str::uuid(), 'name' => 'Required Only Agent',
        'email' => 'required.agent@saverapp.test', 'phone' => '+2348022334400'])->assertRedirect();

    $profile = AgentProfile::query()->sole();
    expect($profile->operational_status)->toBe(AgentStatus::Inactive)
        ->and($profile->user->account_state)->toBe(AccountState::Invited)
        ->and(DB::table('fee_snapshots')->count())->toBe(0)
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and(DB::table('customer_assignments')->count())->toBe(0);
});

test('CAM-AC-015: a creator replaying a registration after reassignment learns nothing and creates nothing', function (): void {
    Queue::fake([DeliverCustomerInvitationJob::class]);
    $rule = camPublishRegistrationRule($this);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'fee_rule_version' => $rule->version,
        'name' => 'Replay Customer', 'email' => 'replay@saverapp.test', 'phone' => '+2348012345671'];
    $this->actingAs($agent)->post(route('customers.store'), $payload)->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    revGapReassign($customer);
    $before = camIdentityCounts();

    $this->actingAs($agent->fresh())->postJson(route('customers.store'), $payload)->assertNotFound();
    $this->getJson(route('customers.attempts.show', $payload['attempt_reference']))->assertNotFound();

    expect(camIdentityCounts())->toBe($before);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
});

test('CAM-AC-020: staff cannot correct the email of a Customer who has already activated', function (): void {
    [$agent, $customer] = collectionFixture();
    $email = $customer->user->email;
    expect($customer->user->account_state)->toBe(AccountState::Active);

    $this->actingAs($agent)->postJson(route('customers.invitations.correct-email', $customer->customer_id), [
        'email' => 'overwrite@saverapp.test', 'reason' => 'Customer asked by phone',
    ])->assertConflict();

    expect($customer->user->fresh()->email)->toBe($email);
});

test('CAM-AC-025: an Inactive Customer cannot save more but can still request a withdrawal', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $customer->update(['operational_status' => CustomerStatus::Inactive]);

    $withdrawal = submittedWithdrawal($agent, $customer->fresh(), $assignment, $plan);

    expect($withdrawal->state)->toBe('pending_review')
        ->and(WithdrawalRequest::query()->count())->toBe(1)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(1);
});

test('CAM-AC-034: an Inactive Agent keeps reads but cannot edit Customers or manage their invitations', function (): void {
    [$agent, $customer] = collectionFixture();
    $agent->agentProfile->update(['operational_status' => AgentStatus::Inactive]);
    $customer->user->forceFill(['account_state' => AccountState::Invited])->save();
    $address = $customer->address;

    $this->actingAs($agent->fresh())->get(route('customers.show', $customer->customer_id))->assertOk();
    $this->patchJson(route('customers.update', $customer->customer_id), ['version' => $customer->version, 'address' => '9 New Road'])->assertForbidden();
    $this->postJson(route('customers.invitations.resend', $customer->customer_id))->assertForbidden();

    expect($customer->fresh()->address)->toBe($address);
});

test('CAM-AC-036: reactivating an Agent never brings back Customers reassigned while they were Inactive', function (): void {
    [$agent, $customer] = collectionFixture();
    $agent->agentProfile->update(['operational_status' => AgentStatus::Inactive]);
    [$successor] = revGapReassign($customer);
    $agent->agentProfile->update(['operational_status' => AgentStatus::Active]);

    $this->actingAs($agent->fresh())->get(route('customers.show', $customer->customer_id))->assertNotFound();
    $this->get(route('customers.index'))->assertInertia(fn (Assert $page) => $page->where('customers.total', 0));
    $this->actingAs($successor)->get(route('customers.show', $customer->customer_id))->assertOk();
});

test('CAM-AC-051: the profile balance matches the authoritative ledger balance after a collection', function (): void {
    Queue::fake();
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '3000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();

    $balance = $this->actingAs($agent)->getJson(route('customers.ledger-balance', $customer->customer_id))->assertOk()->json();
    expect($balance['status'])->toBe('ready')->and($balance['liability_kobo'])->toBe(300000);

    $this->get(route('customers.show', $customer->customer_id))->assertInertia(fn (Assert $page) => $page
        ->where('customer.financial_summary.status', 'ready')
        ->where('customer.financial_summary.liability', MoneyFormatter::formatNaira($balance['liability_kobo']))
        ->where('customer.financial_summary.available', MoneyFormatter::formatNaira($balance['available_kobo'])));
});
