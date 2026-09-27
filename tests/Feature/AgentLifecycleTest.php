<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountCode;
use App\Jobs\DeliverAgentLifecycleNotificationIntent;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AgentLifecycleHistory;
use App\Models\AgentLifecycleNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AgentTrustedDevice;
use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use App\Models\CustomerAssignment;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Notifications\AgentLifecycleNotification;
use App\Services\AgentLifecycleService;
use App\Services\AgentOffboardingEligibility;
use App\Services\AgentTrustedDeviceService;
use App\Services\AuthorizationService;
use App\Services\CollectionReadService;
use App\Services\NotificationCatalogue;
use App\Services\ResumeCookieService;
use App\Services\ReversalService;
use App\Services\SecurityCaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleAgents;

uses(CreatesLifecycleAgents::class);

test('suspension immediately revokes all access and preserves identity assignments credentials and finances', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create(['agent_profile_id' => $agent->id, 'customer_profile_id' => $customer->id]);
    $password = $agent->user->password;
    $secret = $agent->user->two_factor_secret;
    DB::table('sessions')->insert(['id' => 'departing-session', 'user_id' => $agent->user_id, 'payload' => '', 'last_activity' => now()->timestamp]);
    AgentTrustedDevice::create(['user_id' => $agent->user_id, 'device_token_hash' => hash('sha256', 'device'), 'device_name' => 'Departing device', 'trusted_until' => now()->addDays(30)]);
    $correction = CustomerNameCorrection::create(['customer_profile_id' => $customer->id, 'requested_by_user_id' => $agent->user_id,
        'current_name' => 'Old Name', 'proposed_name' => 'New Name', 'reason' => 'Correction', 'profile_version' => 1, 'status' => 'pending', 'expires_at' => now()->addDays(7)]);
    $this->actingAs($admin)->withSession($this->agentLifecycleFreshSession())
        ->postJson(route('agents.lifecycle.suspend', $agent->agent_id), $this->agentLifecyclePayload($agent))
        ->assertOk()->assertJsonPath('account_state', 'suspended')->assertJsonPath('operational_status', 'active');
    expect($agent->fresh()->version)->toBe(2);
    expect($agent->user->fresh()->password)->toBe($password);
    expect($agent->user->fresh()->two_factor_secret)->toBe($secret);
    expect($agent->user->fresh()->remember_token)->toBeNull();
    expect($agent->user->fresh()->lifecycle_access_version)->toBe(1);
    expect($assignment->fresh()->is_current)->toBe(1);
    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Active);
    expect($correction->fresh()->status)->toBe('invalidated');
    $this->assertDatabaseMissing('sessions', ['id' => 'departing-session']);
    $this->assertDatabaseMissing('agent_trusted_devices', ['user_id' => $agent->user_id]);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->actingAs($agent->user->fresh())->get(route('agents.show', $agent->agent_id))->assertRedirect(route('login'));
});

test('suspension is safe before activation and duplicate UUID submission returns the original result', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $agent->user->forceFill(['account_state' => AccountState::Invited, 'password' => null, 'email_verified_at' => null])->save();
    $payload = $this->agentLifecyclePayload($agent);
    $service = app(AgentLifecycleService::class);
    $request = $this->agentLifecycleRequest($admin);
    $result = $service->execute($admin, $agent, 'suspend', $payload, $request);
    expect($service->execute($admin, $agent, 'suspend', $payload, $request))->toBe($result);
    $this->assertDatabaseCount('agent_lifecycle_histories', 1);
    $this->assertDatabaseCount('agent_lifecycle_operations', 1);
    $again = $service->execute($admin, $agent->fresh(), 'suspend', $this->agentLifecyclePayload($agent), $request);
    expect($again['outcome'])->toBe('no_op');
    $this->assertDatabaseCount('agent_lifecycle_histories', 1);
});

test('lifecycle endpoint requires permission and fresh password plus authenticator verification', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $payload = $this->agentLifecyclePayload($agent);
    $this->postJson(route('agents.lifecycle.suspend', $agent->agent_id), $payload)->assertUnauthorized();
    $this->actingAs($admin)->postJson(route('agents.lifecycle.suspend', $agent->agent_id), $payload)->assertStatus(423);
    $this->withSession($this->agentLifecycleFreshSession())->postJson(route('agents.lifecycle.suspend', $agent->agent_id), [...$payload, 'confirmed' => false, 'reason' => ' ', 'agent_explanation' => str_repeat('x', 501)])
        ->assertUnprocessable()->assertJsonValidationErrors(['confirmed', 'reason', 'agent_explanation']);
    $admin->revokePermissionTo(AdminPermission::AgentsManage);
    $this->postJson(route('agents.lifecycle.suspend', $agent->agent_id), $payload)->assertForbidden();
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Active);
});

test('commit-time authority and freshness cannot be bypassed by direct service calls', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $request = $this->agentLifecycleRequest($admin);
    $payload = $this->agentLifecyclePayload($agent);
    $request->session()->put('auth.password_confirmed_at', 0);
    expect(fn () => app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $payload, $request))->toThrow(ValidationException::class);
    $request->session()->put($this->agentLifecycleFreshSession());
    $admin->revokePermissionTo(AdminPermission::AgentsManage);
    expect(fn () => app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $payload, $request))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('agent_lifecycle_histories', 0);
});

test('restoration derives onboarding progress while keeping sessions revoked and locks intact', function (string $state): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $attributes = ['account_state' => AccountState::Suspended];
    if ($state === 'invited') {
        $attributes += ['password' => null, 'email_verified_at' => null];
    } elseif ($state === 'mfa_setup_required') {
        $attributes += ['two_factor_confirmed_at' => null];
    }
    $agent->user->forceFill($attributes)->save();
    $agent->user->lockTemporarily(20, 'password', 'Existing lock');
    $this->actingAs($admin)->withSession($this->agentLifecycleFreshSession())
        ->postJson(route('agents.lifecycle.restore', $agent->agent_id), $this->agentLifecyclePayload($agent))->assertOk()->assertJsonPath('account_state', $state);
    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Active);
    expect($agent->user->fresh()->isTemporarilyLocked())->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['user_id' => $agent->user_id]);
})->with(['invited', 'mfa_setup_required', 'active']);

test('offboarding starts atomically with one accountable case and blocks restoration and activation', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $payload = $this->agentLifecyclePayload($agent);
    $this->actingAs($admin)->withSession($this->agentLifecycleFreshSession())->postJson(route('agents.lifecycle.start-offboarding', $agent->agent_id), $payload)
        ->assertOk()->assertJsonPath('account_state', 'suspended')->assertJsonPath('operational_status', 'inactive');
    $case = $agent->offboardingCases()->firstOrFail();
    expect($case->owner_user_id)->toBe($admin->id);
    expect($case->original_account_state)->toBe('active');
    expect($case->original_operational_status)->toBe('active');
    expect($case->reason)->toBe('Private management review.');
    expect(DB::table('agent_offboarding_cases')->where('id', $case->id)->value('reason'))->not->toBe($case->reason);
    $this->postJson(route('agents.lifecycle.start-offboarding', $agent->agent_id), $payload)->assertOk();
    $this->postJson(route('agents.lifecycle.start-offboarding', $agent->agent_id), $this->agentLifecyclePayload($agent))->assertConflict();
    $this->postJson(route('agents.lifecycle.restore', $agent->agent_id), $this->agentLifecyclePayload($agent))->assertConflict();
    $this->patchJson(route('agents.status.update', $agent->agent_id), ['target_status' => 'active', 'version' => $agent->fresh()->version,
        'reason' => 'Ready', 'agent_explanation' => 'Resume', 'confirmed' => true])->assertUnprocessable();
    $this->assertDatabaseCount('agent_offboarding_cases', 1);
});

test('case cancellation retains history and access restrictions then supports separate restoration and a new episode', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $request = $this->agentLifecycleRequest($admin);
    $service = app(AgentLifecycleService::class);
    $service->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $request);
    $old = $this->agentLifecyclePayload($agent);
    $cancelled = $service->execute($admin, $agent, 'cancel-offboarding', $old, $request);
    expect($cancelled['case_status'])->toBe('cancelled');
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Inactive);
    $service->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $request);
    $service->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $request);
    expect($service->execute($admin, $agent, 'cancel-offboarding', $old, $request))->toBe($cancelled);
    $this->assertDatabaseCount('agent_offboarding_cases', 2);
    expect($agent->offboardingCases()->where('is_open', 1)->count())->toBe(1);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
});

test('ownership transfer requires a currently eligible management Admin and a current case version', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $request = $this->agentLifecycleRequest($admin);
    $service = app(AgentLifecycleService::class);
    $service->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $request);
    $newOwner = User::factory()->admin()->withTwoFactor()->create();
    $payload = [...$this->agentLifecyclePayload($agent), 'owner_user_id' => $newOwner->id];
    expect(fn () => $service->execute($admin, $agent, 'transfer-owner', $payload, $request))->toThrow(ValidationException::class);
    $newOwner->givePermissionTo(AdminPermission::AgentsManage);
    $service->execute($admin, $agent, 'transfer-owner', $payload, $request);
    expect($agent->offboardingCases()->first()->owner_user_id)->toBe($newOwner->id);
    expect(fn () => $service->execute($admin, $agent, 'cancel-offboarding', [...$this->agentLifecyclePayload($agent), 'case_version' => 1], $request))->toThrow(ConflictHttpException::class);
});

test('completion fails closed on missing cash mapping recovery handover and queued-work contracts', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $service = app(AgentLifecycleService::class);
    $request = $this->agentLifecycleRequest($admin);
    $service->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $request);
    LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable->value)->update(['mapping_status' => 'unmapped']);
    $gates = app(AgentOffboardingEligibility::class)->preview($admin, $agent->fresh(), $agent->offboardingCases()->first());
    $checks = collect($gates['checks'])->keyBy('key');
    expect($checks['cash']['status'])->toBe('unavailable');
    expect($checks['recovery_invitations']['status'])->toBe('unavailable');
    expect($checks['queued_work']['status'])->toBe('unavailable');
    expect(fn () => $service->execute($admin, $agent, 'complete-offboarding', $this->agentLifecyclePayload($agent), $request))->toThrow(ValidationException::class);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
    expect($agent->offboardingCases()->first()->status)->toBe('in_progress');
    expect(AuditEvent::query()->where('event_type', 'agent.lifecycle_denied')->exists())->toBeTrue();
});

test('continuity blocks every non-archived status while archived assignments may remain', function (string $status, string $expected): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $customer = CustomerProfile::factory()->create(['operational_status' => $status]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id]);
    $checks = app(AgentOffboardingEligibility::class)->preview($admin, $agent, null)['checks'];
    expect(collect($checks)->keyBy('key')['customers']['status'])->toBe($expected);
})->with([['active', 'blocked'], ['inactive', 'blocked'], ['restricted', 'blocked'], ['archived', 'passed']]);

test('a former Customer replacement becoming ineligible blocks continuity', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->ended()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id]);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $replacement->id, 'version' => 2]);
    $service = app(AgentOffboardingEligibility::class);
    expect(collect($service->preview($admin, $agent, null)['checks'])->keyBy('key')['customers']['status'])->toBe('passed');
    $replacement->user->forceFill(['account_state' => AccountState::Suspended])->save();
    expect(collect($service->preview($admin, $agent, null)['checks'])->keyBy('key')['customers']['status'])->toBe('blocked');
});

test('completion rechecks gates under lock and returning Agent retains completed case and identity', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $service = app(AgentLifecycleService::class);
    $request = $this->agentLifecycleRequest($admin);
    $service->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $request);
    $this->mock(AgentOffboardingEligibility::class)->shouldReceive('preview')->once()->withArgs(fn ($actor, $profile, $case, $locked) => $locked === true)
        ->andReturn(['eligible' => true, 'checks' => []]);
    $service->execute($admin, $agent, 'complete-offboarding', $this->agentLifecyclePayload($agent), $request);
    $case = $agent->offboardingCases()->firstOrFail();
    expect($case->status)->toBe('completed');
    expect($case->completed_at)->not->toBeNull();
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Deactivated);
    $publicId = $agent->agent_id;
    $service->execute($admin, $agent, 'return', $this->agentLifecyclePayload($agent), $request);
    expect($agent->fresh()->agent_id)->toBe($publicId);
    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Inactive);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Active);
    expect($case->fresh()->status)->toBe('completed');
    expect(fn () => $service->execute($admin, $agent, 'cancel-offboarding', $this->agentLifecyclePayload($agent), $request))->toThrow(ConflictHttpException::class);
});

test('deactivated accounts cannot be suspended or returned without completed-case evidence', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $agent->user->forceFill(['account_state' => AccountState::Deactivated])->save();
    foreach (['suspend', 'start-offboarding', 'return'] as $action) {
        expect(fn () => app(AgentLifecycleService::class)->execute($admin, $agent, $action, $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin)))
            ->toThrow(ConflictHttpException::class);
    }
});

test('security owner blocks restoration on unresolved and unrecognized evidence', function (string $status): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $agent->user->forceFill(['account_state' => AccountState::Suspended])->save();
    DB::table('security_cases')->insert(['case_reference' => (string) Str::ulid(), 'source_key' => str_repeat('a', 64), 'source_event_id' => 1,
        'affected_user_id' => $agent->user_id, 'state' => $status, 'severity' => 'High', 'version' => 1, 'episode' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(AgentLifecycleService::class)->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin)))
        ->toThrow(ValidationException::class);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
})->with(['Open', 'Investigating', 'Unsupported', 'Resolved']);

test('committed UUID cannot be reused with a different payload and lookup is actor scoped', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $payload = $this->agentLifecyclePayload($agent);
    $this->actingAs($admin)->withSession($this->agentLifecycleFreshSession())->postJson(route('agents.lifecycle.suspend', $agent->agent_id), $payload)->assertOk();
    $this->postJson(route('agents.lifecycle.suspend', $agent->agent_id), [...$payload, 'reason' => 'Different reason'])->assertConflict();
    $this->getJson(route('agents.lifecycle.operation', [$agent->agent_id, $payload['attempt_reference']]))->assertOk()->assertJsonPath('version', 2);
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::AgentsManage);
    $this->actingAs($other)->getJson(route('agents.lifecycle.operation', [$agent->agent_id, $payload['attempt_reference']]))->assertNotFound();
});

test('audit or notification capture failure rolls back revocations case history and proposal invalidation', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    DB::table('sessions')->insert(['id' => 'retained-session', 'user_id' => $agent->user_id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->mock(NotificationCatalogue::class)->shouldReceive('describe')->andThrow(new RuntimeException('Capture unavailable'));
    expect(fn () => app(AgentLifecycleService::class)->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin)))
        ->toThrow(RuntimeException::class, 'Capture unavailable');
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Active);
    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Active);
    $this->assertDatabaseHas('sessions', ['id' => 'retained-session']);
    $this->assertDatabaseCount('agent_offboarding_cases', 0);
    $this->assertDatabaseCount('agent_lifecycle_histories', 0);
    $this->assertDatabaseCount('agent_lifecycle_operations', 0);
});

test('management UI exposes only authorized lifecycle history and cannot grant financial authority', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $this->actingAs($admin)->get(route('agents.lifecycle.show', $agent->agent_id))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('agents/Lifecycle')->where('fresh_authentication', false)->has('completion.checks', 7)->where('completion.checks.2.url', null));
    $this->actingAs($agent->user)->get(route('agents.lifecycle.show', $agent->agent_id))->assertForbidden();
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->get(route('agents.lifecycle.show', $agent->agent_id))->assertNotFound();
});

test('lifecycle notices mask internal reasons and delivery rechecks scope and management permission', function (): void {
    Queue::fake([DeliverAgentLifecycleNotificationIntent::class, MaterializeNotificationIntent::class]);
    Notification::fake();
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['agent_profile_id' => $agent->id, 'customer_profile_id' => $customer->id]);
    app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $intents = AgentLifecycleNotificationIntent::all();
    expect($intents->pluck('payload')->map(fn ($payload) => $payload['message'])->implode(' '))->not->toContain('Private management review');
    $agentMail = $intents->where('audience_type', 'subject_agent')->where('channel', 'mail')->first();
    (new DeliverAgentLifecycleNotificationIntent($agentMail->id))->handle(app(AuthorizationService::class));
    (new DeliverAgentLifecycleNotificationIntent($agentMail->id))->handle(app(AuthorizationService::class));
    Notification::assertSentTo($agent->user, AgentLifecycleNotification::class);
    Notification::assertCount(1);
    $adminIntent = $intents->where('audience_type', 'managing_admin')->first();
    $admin->revokePermissionTo(AdminPermission::AgentsManage);
    (new DeliverAgentLifecycleNotificationIntent($adminIntent->id))->handle(app(AuthorizationService::class));
    expect($adminIntent->fresh()->status)->toBe('suppressed');
    $customerIntent = $intents->where('audience_type', 'assigned_customer')->where('channel', 'mail')->first();
    $customer->currentAssignment->forceFill(['is_current' => null, 'status' => 'ended', 'ended_at' => now()])->save();
    (new DeliverAgentLifecycleNotificationIntent($customerIntent->id))->handle(app(AuthorizationService::class));
    expect($customerIntent->fresh()->status)->toBe('suppressed');
});

test('resume cookies issued before suspension remain invalid after restoration', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $service = app(ResumeCookieService::class);
    $request = Request::create('/dashboard', 'GET');
    $cookie = $service->recordResumeDestination($agent->user, $request);
    $request->cookies->set(ResumeCookieService::COOKIE_NAME, $cookie->getValue());
    expect($service->consumeResumeDestination($agent->user, $request))->toBe('/dashboard');
    app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    app(AgentLifecycleService::class)->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    expect($service->consumeResumeDestination($agent->user->fresh(), $request))->toBeNull();
});

test('Agent lifecycle history cannot be edited or deleted', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $history = AgentLifecycleHistory::firstOrFail();
    expect(fn () => $history->forceFill(['reason' => 'Rewritten'])->save())->toThrow(RuntimeException::class);
    expect(fn () => $history->delete())->toThrow(RuntimeException::class);
});

test('revoked session and pending login challenges cannot revive after restoration', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $service = app(AgentLifecycleService::class);
    $request = $this->agentLifecycleRequest($admin);
    $service->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $request);
    $service->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $request);
    $user = $agent->user->fresh();
    $this->actingAs($user)->withSession(['auth.lifecycle_access_version' => 0])
        ->get(route('agent.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
    $this->withSession(['login.id' => $user->id, 'login.lifecycle_access_version' => 0])
        ->post(route('two-factor.login.store'), ['code' => '123456'])->assertRedirect(route('login'));
    $this->assertGuest();
    $this->withSession(['login.pending_eviction' => ['user_id' => $user->id, 'access_version' => 0]])
        ->get(route('device-eviction'))->assertRedirect(route('login'));
    $this->assertGuest();
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
});

test('future authenticator timestamps cannot authorize lifecycle actions', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $request = $this->agentLifecycleRequest($admin);
    $request->session()->put('auth.mfa_confirmed_at', now()->addMinute()->timestamp);
    expect(fn () => app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $request))
        ->toThrow(ValidationException::class);
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Active);
    $this->assertDatabaseHas('audit_events', ['event_type' => 'agent.lifecycle_denied']);
});

test('revoked case owner authority blocks access readiness completion evidence', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    app(AgentLifecycleService::class)->execute($admin, $agent, 'start-offboarding', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $this->assertDatabaseHas('agent_status_histories', ['agent_profile_id' => $agent->id, 'from_status' => 'active', 'to_status' => 'inactive']);
    $admin->revokePermissionTo(AdminPermission::AgentsManage);
    $checks = collect(app(AgentOffboardingEligibility::class)->preview($admin, $agent->fresh(), $agent->offboardingCases()->first())['checks'])->keyBy('key');
    expect($checks['access']['status'])->toBe('blocked');
});

test('authoritative empty financial inventory passes while ambiguous reconciliation remains unavailable', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable->value)->update(['mapping_status' => 'mapped', 'currency' => 'NGN', 'normal_balance' => 'debit']);
    $read = app(CollectionReadService::class);
    expect($read->agentOffboardingStatus($agent))->toBe('passed');
    $checks = collect(app(AgentOffboardingEligibility::class)->preview($admin, $agent, null)['checks'])->keyBy('key');
    expect($checks['financial_requests']['status'])->toBe('passed');
    expect($checks['obligations']['status'])->toBe('unavailable');
    $batch = CollectionBatch::create(['business_version' => 1, 'agent_profile_id' => $agent->id,
        'received_date' => now()->toDateString(), 'timezone' => 'Africa/Lagos', 'revision' => 1, 'status' => 'open', 'version' => 1]);
    expect($read->agentOffboardingStatus($agent))->toBe('blocked');
    app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
    expect($batch->fresh()->status)->toBe('open');
    $batch->update(['status' => 'reconciled']);
    expect($read->agentOffboardingStatus($agent))->toBe('unavailable');
    $batch->update(['status' => 'unsupported']);
    expect($read->agentOffboardingStatus($agent))->toBe('unavailable');
});

test('notification transport retries preserve lifecycle history and deduplicate successful redelivery', function (): void {
    Queue::fake([DeliverAgentLifecycleNotificationIntent::class, MaterializeNotificationIntent::class]);
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    app(AgentLifecycleService::class)->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $intent = AgentLifecycleNotificationIntent::query()->where('audience_type', 'subject_agent')->where('channel', 'mail')->firstOrFail();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Transport unavailable'));
    $job = new DeliverAgentLifecycleNotificationIntent($intent->id);
    expect(fn () => $job->handle(app(AuthorizationService::class)))->toThrow(RuntimeException::class);
    expect($intent->fresh()->status)->toBe('pending');
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Suspended);
    $this->assertDatabaseCount('agent_lifecycle_histories', 1);
    $this->assertDatabaseHas('audit_events', ['event_type' => 'agent.suspend']);
    Notification::fake();
    $job->handle(app(AuthorizationService::class));
    $job->handle(app(AuthorizationService::class));
    Notification::assertSentTo($agent->user, AgentLifecycleNotification::class);
    Notification::assertCount(1);
    expect($intent->fresh()->status)->toBe('delivered');
});

test('authoritative security closure permits restoration but never clears a temporary authentication lock', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $securityAdmin = User::factory()->admin()->withTwoFactor()->create();
    $securityAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage);
    $event = AuditEvent::record('auth.lock_created', User::class, $agent->user_id, null, ['category' => 'password', 'lock_id' => 1]);
    $security = app(SecurityCaseService::class);
    $case = $security->signal($event->id, $agent->user_id);
    $security->change($securityAdmin, $case, ['expected_version' => 1, 'action' => 'state', 'state' => 'ClosedNoAction', 'note' => 'Security review completed.']);
    $agent->user->forceFill(['account_state' => AccountState::Suspended, 'locked_until' => now()->addHour()])->save();
    app(AgentLifecycleService::class)->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    expect($agent->user->fresh()->account_state)->toBe(AccountState::Active);
    expect($agent->user->fresh()->isTemporarilyLocked())->toBeTrue();
});

test('trusted-device authority cannot be issued from an earlier access generation', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $oldUser = $agent->user;
    $service = app(AgentLifecycleService::class);
    $service->execute($admin, $agent, 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $service->execute($admin, $agent, 'restore', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    app(AgentTrustedDeviceService::class)->createTrustedDevice($oldUser, Request::create('/login', 'POST'));
    $this->assertDatabaseMissing('agent_trusted_devices', ['user_id' => $agent->user_id]);
});

test('pending or ambiguously closed financial requests never count as an empty inventory', function (): void {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id]);
    $group = LedgerPostingGroup::create(['posting_reference' => 'COL-OFFBOARD-001', 'idempotency_key' => (string) Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'source_type' => 'collection_receipt', 'source_id' => '1', 'event_type' => 'cash_savings',
        'currency' => 'NGN', 'actor_user_id' => $agent->user_id, 'customer_profile_id' => $customer->id, 'occurred_at' => now(), 'committed_at' => now()]);
    $reversal = ReversalRequest::create(['reversal_id' => (string) Str::uuid(), 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $group->id, 'live_original_posting_group_id' => $group->id,
        'requested_by_user_id' => $agent->user_id, 'initiating_agent_profile_id' => $agent->id, 'assignment_id' => $assignment->id,
        'state' => 'pending_review', 'version' => 1, 'reason_category' => 'duplicate_posting', 'internal_reason' => 'Private evidence',
        'customer_explanation' => 'A receipt is under review.', 'evidence_text' => 'Private evidence',
        'dependency_fingerprint' => str_repeat('b', 64), 'dependency_snapshot' => [], 'original_amount_kobo' => 100, 'currency' => 'NGN']);
    $read = app(ReversalService::class);
    expect($read->agentOffboardingStatus($agent))->toBe('unavailable');
    $reversal->update(['state' => 'rejected', 'reviewed_at' => now(), 'live_original_posting_group_id' => null]);
    expect($read->agentOffboardingStatus($agent))->toBe('unavailable');
    $checks = collect(app(AgentOffboardingEligibility::class)->preview($admin, $agent, null)['checks'])->keyBy('key');
    expect($checks['financial_requests']['status'])->toBe('unavailable');
});
