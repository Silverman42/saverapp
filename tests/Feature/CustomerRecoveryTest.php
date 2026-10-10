<?php

use App\Enums\AdminPermission;
use App\Jobs\DeliverCustomerHandoverNotice;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\User;
use App\Services\CustomerReassignmentService;
use App\Services\EmailChangeService;
use App\Services\NotificationPipeline;
use App\Services\PlatformCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    [$this->admin, $this->customer, $this->agent] = $this->createLifecycleFixture();
    $this->admin->givePermissionTo([AdminPermission::SecurityOperationsManage, AdminPermission::CustomersReassign]);
    $this->freshSession = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
});

function recoveryVerification(CustomerProfile $customer): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'email' => 'recovered@example.test', 'in_person' => true,
        'record_compared' => true, 'verified_at' => now()->toIso8601String(), 'procedure_reference' => 'KYC-1', 'notes' => 'Private in-person evidence'];
}

function recoveryDecision(CustomerRecovery $recovery): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'recovery_version' => $recovery->version, 'reason' => 'Reviewed verification'];
}

function recoveryToken(CustomerRecovery $recovery): string
{
    $notice = DB::table('customer_handover_notices as n')->join('customer_handover_events as e', 'e.id', '=', 'n.customer_handover_event_id')
        ->where('e.customer_recovery_id', $recovery->id)->where('n.purpose', 'recovery_activation')->orderByDesc('n.id')->first();
    $payload = json_decode(Crypt::decryptString($notice->payload), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['url'])->not->toContain($payload['token'])->not->toContain($payload['token_hash']);

    return $payload['token'];
}

test('Customer recovery revokes only after fresh Admin approval and completes with a single-use email challenge', function () {
    $original = $this->customer->user->getAttributes();
    $payload = recoveryVerification($this->customer);
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), $payload)->assertOk()->assertJsonPath('state', 'awaiting_approval');
    $this->postJson(route('customers.recovery.store', $this->customer->customer_id), $payload)->assertOk();
    expect($this->customer->user->fresh()->getAttributes())->toBe($original);
    $recovery = CustomerRecovery::firstOrFail();
    $approval = recoveryDecision($recovery);
    $url = route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']);
    $this->postJson($url, $approval)->assertForbidden();
    $this->actingAs($this->admin)->postJson($url, $approval)->assertForbidden();
    $this->withSession($this->freshSession)->postJson($url, $approval)->assertOk()->assertJsonPath('state', 'awaiting_activation');
    $this->postJson($url, $approval)->assertOk();
    expect($this->customer->user->fresh()->password)->toBeNull()->and($this->customer->user->fresh()->recovery_pending)->toBeTrue();
    $recovery = $recovery->fresh();
    $token = recoveryToken($recovery);
    expect($recovery->activation_token_hash)->toBe(hash('sha256', $token));
    $activation = ['token' => $token, 'password' => 'a new private password', 'password_confirmation' => 'a new private password'];
    $this->post(route('customer-recovery.activate', $recovery->reference), $activation)->assertRedirect(route('login'));
    $user = $this->customer->user->fresh();
    expect($user->email)->toBe('recovered@example.test')->and($user->recovery_pending)->toBeFalse()
        ->and(Hash::check('a new private password', $user->password))->toBeTrue()->and($recovery->fresh()->state)->toBe('completed');
    $this->postJson(route('customer-recovery.activate', $recovery->reference), $activation)->assertUnprocessable();
    $safe = DB::table('canonical_audit_events')->where('event_type', 'like', 'auth.customer_recovery_%')->get()->toJson();
    expect($safe)->not->toContain($token)->not->toContain('Private in-person evidence')->not->toContain('recovered@example.test');
});

test('CAM-AC-045 unapproved recovery requires replacement verification and preserves the original request and evidence', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::firstOrFail();
    $target = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $preview = app(CustomerReassignmentService::class)->preview($this->admin, $this->customer, $target->id);
    app(CustomerReassignmentService::class)->execute($this->admin, $this->customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => 1, 'assignment_version' => 1, 'target_agent_id' => $target->id, 'preview_token' => $preview['preview_token'],
        'confirmed' => true, 'reason' => 'Handover', 'customer_explanation' => 'New service contact']);
    $recovery = $recovery->fresh();
    expect($recovery->state)->toBe('verification_required')->and($recovery->verified_assignment_id)->toBeNull()
        ->and($recovery->requested_by_user_id)->toBe($this->agent->user_id);
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertConflict();
    $verification = [...recoveryVerification($this->customer->fresh()), 'recovery_version' => $recovery->version, 'reason' => 'Repeated verification'];
    $this->actingAs($target->user)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'verify']), $verification)->assertOk();
    $this->actingAs($this->agent->user)->get(route('customers.recovery.show', $this->customer->customer_id))->assertNotFound();
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery->fresh()))->assertOk();
});

test('approval blocks password reset and old sessions while preserving operational status and assignment', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::firstOrFail();
    DB::table('password_reset_tokens')->insert(['email' => $this->customer->user->email, 'token' => 'old', 'created_at' => now()]);
    $assignmentId = $this->customer->currentAssignment->id;
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    expect($this->customer->fresh()->currentAssignment->id)->toBe($assignmentId)->and($this->customer->fresh()->operational_status->value)->toBe('active');
    $this->assertDatabaseCount('password_reset_tokens', 0);
    $this->actingAs($this->customer->user->fresh())->get(route('customers.show', $this->customer->customer_id))->assertRedirect(route('login'));
    $this->post('/forgot-password', ['email' => $this->customer->user->email])->assertRedirect();
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

test('recovery reservations conflict with registration and self-service email changes', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    expect(fn () => User::factory()->create(['email' => 'RECOVERED@example.test']))->toThrow(ValidationException::class);
    $other = User::factory()->customer()->create();
    expect(fn () => app(EmailChangeService::class)->begin($other, 'recovered@example.test'))->toThrow(ValidationException::class);
});

test('unapproved request expiry releases the email and approved expiry retains its reservation and access block', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $this->travel(8)->days();
    $this->artisan('customers:expire-recovery')->assertSuccessful();
    expect(CustomerRecovery::first()->state)->toBe('expired')->and(CustomerRecovery::first()->proposed_email_normalized)->toBeNull();
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::latest('id')->first();
    $fresh = ['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp, 'auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $this->actingAs($this->admin)->withSession($fresh)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    $this->travel(31)->minutes();
    $this->artisan('customers:expire-recovery')->assertSuccessful();
    expect($recovery->fresh()->state)->toBe('activation_expired')->and($recovery->fresh()->proposed_email_normalized)->toBe('recovered@example.test')
        ->and($this->customer->user->fresh()->recovery_pending)->toBeTrue();
});

test('expired activation can be reissued only by fresh security Admin and rotates its token without restoring credentials', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::first();
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    $oldToken = recoveryToken($recovery);
    $this->travel(31)->minutes();
    $fresh = ['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp, 'auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $url = route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'reissue']);
    $this->actingAs($this->admin)->withSession($fresh)->postJson($url, recoveryDecision($recovery->fresh()))->assertOk();
    expect(recoveryToken($recovery))->not->toBe($oldToken)->and($this->customer->user->fresh()->password)->toBeNull();
    $this->postJson($url, recoveryDecision($recovery->fresh()))->assertConflict();
    $this->postJson(route('customer-recovery.activate', $recovery->reference), ['token' => $oldToken, 'password' => 'a very safe new password', 'password_confirmation' => 'a very safe new password'])->assertUnprocessable();
});

test('suspended Customer cannot activate approved recovery and public GET reveals no request state', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::first();
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    $this->customer->user->forceFill(['account_state' => 'suspended'])->save();
    $this->postJson(route('customer-recovery.activate', $recovery->reference), ['token' => recoveryToken($recovery), 'password' => 'a very safe new password', 'password_confirmation' => 'a very safe new password'])->assertUnprocessable();
    $this->get(route('customer-recovery.activation', $recovery->reference))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/CustomerRecoveryActivation')->missing('state')->missing('email')->missing('token'));
    $this->get(route('customer-recovery.activation', (string) Str::uuid()))->assertOk();
});

test('recovery notes and operations are unavailable to baseline Admins and other Agents', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $baseline = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($baseline)->get(route('customers.recovery.show', $this->customer->customer_id))->assertNotFound();
    $this->get(route('customer-recovery.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('customer-recovery.index'))->assertOk();
    $this->actingAs($this->agent->user)->get(route('customers.recovery.show', $this->customer->customer_id))->assertOk();
});

test('approved challenge survives reassignment and cancellation never restores revoked credentials', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::firstOrFail();
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    $token = recoveryToken($recovery);
    $challenge = $recovery->fresh()->getAttributes();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $preview = app(CustomerReassignmentService::class)->preview($this->admin, $this->customer, $replacement->id);
    app(CustomerReassignmentService::class)->execute($this->admin, $this->customer, [...$preview, 'target_agent_id' => $replacement->id,
        'attempt_reference' => (string) Str::uuid(), 'reason' => 'Staff change', 'customer_explanation' => 'Your contact changed', 'confirmed' => true]);
    expect($recovery->fresh()->getAttributes())->toBe($challenge);
    $this->customer->user->forceFill(['account_state' => 'suspended'])->save();
    $this->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'cancel']), recoveryDecision($recovery->fresh()))->assertOk();
    expect($this->customer->user->fresh()->password)->toBeNull()->and($this->customer->user->fresh()->recovery_pending)->toBeTrue()
        ->and($this->customer->user->fresh()->account_state->value)->toBe('suspended');
    $this->postJson(route('customer-recovery.activate', $recovery->reference), ['token' => $token, 'password' => 'a very safe new password', 'password_confirmation' => 'a very safe new password'])->assertUnprocessable();
});

test('recovery rejects invited accounts incomplete attestation and weak activation passwords', function () {
    $input = recoveryVerification($this->customer);
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), [...$input, 'record_compared' => false])->assertUnprocessable();
    $this->customer->user->forceFill(['account_state' => 'invited'])->save();
    $this->postJson(route('customers.recovery.store', $this->customer->customer_id), $input)->assertConflict();
    $this->customer->user->forceFill(['account_state' => 'active'])->save();
    $this->postJson(route('customers.recovery.store', $this->customer->customer_id), $input)->assertOk();
    $recovery = CustomerRecovery::firstOrFail();
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    $this->postJson(route('customer-recovery.activate', $recovery->reference), ['token' => recoveryToken($recovery), 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect($recovery->fresh()->state)->toBe('awaiting_activation');
});

test('obsolete or suspended activation notices are suppressed and delivery failure leaves approval committed', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::firstOrFail();
    $approval = recoveryDecision($recovery);
    $url = route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']);
    $result = $this->actingAs($this->admin)->withSession($this->freshSession)->postJson($url, $approval)->assertOk()->json();
    $notice = DB::table('customer_handover_notices')->where('purpose', 'recovery_activation')->first();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Provider failed with protected details'));
    (new DeliverCustomerHandoverNotice($notice->id))->handle(app(NotificationPipeline::class));
    expect(DB::table('customer_handover_notices')->where('id', $notice->id)->value('status'))->toBe('unknown');
    $this->postJson($url, $approval)->assertOk()->assertExactJson($result);
    expect($recovery->fresh()->state)->toBe('awaiting_activation')->and($this->customer->user->fresh()->password)->toBeNull();
    $this->customer->user->forceFill(['account_state' => 'suspended'])->save();
    DB::table('customer_handover_notices')->where('id', $notice->id)->update(['status' => 'pending']);
    (new DeliverCustomerHandoverNotice($notice->id))->handle(app(NotificationPipeline::class));
    expect(DB::table('customer_handover_notices')->where('id', $notice->id)->value('status'))->toBe('suppressed');
});

test('handover jobs and expiry processing declare their platform operation contracts', function () {
    $catalogue = app(PlatformCatalogue::class);
    expect($catalogue->jobClass(new DeliverCustomerHandoverNotice(1)))->toBe('external')
        ->and(PlatformCatalogue::COMMANDS['customers:expire-recovery'])->toBe('mutation');
});

test('original request UUID remains replayable after its attestation freshness window', function () {
    $input = recoveryVerification($this->customer);
    $url = route('customers.recovery.store', $this->customer->customer_id);
    $result = $this->actingAs($this->agent->user)->postJson($url, $input)->assertOk()->json();
    $this->travel(2)->days();
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])->postJson($url, $input)->assertOk()->assertExactJson($result);
    $this->assertDatabaseCount('customer_recoveries', 1);
    $this->postJson($url, [...$input, 'attempt_reference' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('verified_at');
});

test('approved recovery blocks login without account failure counters that could obstruct activation', function () {
    $this->actingAs($this->agent->user)->postJson(route('customers.recovery.store', $this->customer->customer_id), recoveryVerification($this->customer))->assertOk();
    $recovery = CustomerRecovery::firstOrFail();
    $this->actingAs($this->admin)->withSession($this->freshSession)->postJson(route('customers.recovery.update', [$this->customer->customer_id, $recovery->reference, 'approve']), recoveryDecision($recovery))->assertOk();
    auth()->logout();
    $this->post(route('login.store'), ['email' => $this->customer->user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    expect(Cache::get('auth:password:failures:'.$this->customer->user->email_normalized))->toBeNull()
        ->and($this->customer->user->fresh()->account_state->value)->toBe('active');
});

test('AUTH-AC-023: Customer recovery rejection, approval and completion are audited without the token or evidence', function () {
    $store = route('customers.recovery.store', $this->customer->customer_id);
    $this->actingAs($this->agent->user)->postJson($store, recoveryVerification($this->customer))->assertOk();
    $rejected = CustomerRecovery::firstOrFail();
    $this->actingAs($this->admin)->withSession($this->freshSession)
        ->postJson(route('customers.recovery.update', [$this->customer->customer_id, $rejected->reference, 'reject']), recoveryDecision($rejected))->assertOk();
    $this->travel(2)->minutes();
    $this->actingAs($this->agent->user)->postJson($store, recoveryVerification($this->customer->fresh()))->assertOk();
    $approved = CustomerRecovery::query()->whereKeyNot($rejected->id)->firstOrFail();
    $this->actingAs($this->admin)->withSession($this->freshSession)
        ->postJson(route('customers.recovery.update', [$this->customer->customer_id, $approved->reference, 'approve']), recoveryDecision($approved))->assertOk();
    $token = recoveryToken($approved->fresh());

    $this->post(route('customer-recovery.activate', $approved->reference), ['token' => $token, 'password' => 'a new private password',
        'password_confirmation' => 'a new private password'])->assertRedirect(route('login'));

    $events = DB::table('audit_events')->whereIn('event_type', ['auth.customer_recovery_rejected', 'auth.customer_recovery_approved', 'auth.customer_recovery_completed'])
        ->orderBy('id')->get(['event_type', 'target_reference']);
    expect($events->map(fn (object $event): array => (array) $event)->all())->toBe([
        ['event_type' => 'auth.customer_recovery_rejected', 'target_reference' => $this->customer->customer_id],
        ['event_type' => 'auth.customer_recovery_approved', 'target_reference' => $this->customer->customer_id],
        ['event_type' => 'auth.customer_recovery_completed', 'target_reference' => $this->customer->customer_id],
    ])->and(DB::table('audit_events')->where('event_type', 'like', 'auth.customer_recovery_%')->get()->toJson())
        ->not->toContain($token)->not->toContain('Private in-person evidence')->not->toContain('a new private password');
});
