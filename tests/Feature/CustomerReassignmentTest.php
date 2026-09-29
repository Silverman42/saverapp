<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\Invitation;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CollectionService;
use App\Services\CustomerInvitationManagementService;
use App\Services\CustomerReassignmentService;
use App\Services\ReversalService;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

beforeEach(function () {
    Queue::fake();
    [$this->admin, $this->customer, $this->agent] = $this->createLifecycleFixture();
    $this->admin->givePermissionTo(AdminPermission::CustomersReassign);
    $this->replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
});

function reassignmentInput(CustomerProfile $customer, User $admin, AgentProfile $replacement): array
{
    $preview = app(CustomerReassignmentService::class)->preview($admin, $customer, $replacement->id);

    return ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'preview_token' => $preview['preview_token'], 'target_agent_id' => $replacement->id, 'confirmed' => true,
        'reason' => 'Internal personnel details', 'customer_explanation' => 'Your service contact is changing.'];
}

test('CAM-AC-043/048 reassignment preserves all Customer and account states with no gap or overlap', function (CustomerStatus $status) {
    $this->customer->forceFill(['operational_status' => $status])->save();
    $original = $this->customer->user->getAttributes();
    $this->agent->forceFill(['operational_status' => 'inactive'])->save();
    $old = $this->customer->currentAssignment;
    $payload = reassignmentInput($this->customer, $this->admin, $this->replacement);
    $result = $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertOk()->assertJsonPath('status', 'committed')->json();
    $current = $this->customer->fresh()->currentAssignment;
    expect($current->agent_profile_id)->toBe($this->replacement->id)->and($current->version)->toBe(2)
        ->and($old->fresh()->ended_at->equalTo($current->effective_at))->toBeTrue()
        ->and($this->customer->fresh()->operational_status)->toBe($status)
        ->and($this->customer->user->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    expect(CustomerAssignment::query()->where('customer_profile_id', $this->customer->id)->where('is_current', 1)->count())->toBe(1);
    $this->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertOk()->assertExactJson($result);
    $this->getJson(route('customers.reassignment.operation', [$this->customer->customer_id, $payload['attempt_reference']]))->assertOk()->assertExactJson($result);
    $payload['reason'] = 'Changed';
    $this->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertConflict();
    $this->assertDatabaseCount('customer_handover_events', 1);
})->with(CustomerStatus::cases());

test('reassignment requires its own grant and eligible replacement while the source may be suspended', function () {
    $payload = reassignmentInput($this->customer, $this->admin, $this->replacement);
    $this->admin->revokePermissionTo(AdminPermission::CustomersReassign);
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertForbidden();
    $this->actingAs($this->agent->user)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertForbidden();
    $this->admin->givePermissionTo(AdminPermission::CustomersReassign);
    $this->agent->user->forceFill(['account_state' => 'suspended'])->save();
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertOk();
});

test('stale recipient eligibility rejects reassignment atomically', function () {
    $payload = reassignmentInput($this->customer, $this->admin, $this->replacement);
    $this->replacement->forceFill(['operational_status' => 'inactive'])->save();
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertConflict();
    expect($this->customer->fresh()->currentAssignment->agent_profile_id)->toBe($this->agent->id);
    $this->assertDatabaseCount('customer_handover_events', 0);
});

test('choosing the current Agent is a no-op with no notifications or assignment episode', function () {
    $payload = reassignmentInput($this->customer, $this->admin, $this->agent);
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertOk()->assertJsonPath('status', 'unchanged');
    $this->assertDatabaseCount('customer_assignments', 1);
    $this->assertDatabaseCount('customer_handover_notices', 0);
    expect($this->customer->fresh()->version)->toBe(1);
});

test('CAM-AC-046 cancels old name proposals and immediately removes all former-Agent scope', function () {
    $proposal = CustomerNameCorrection::create(['customer_profile_id' => $this->customer->id, 'requested_by_user_id' => $this->agent->user_id,
        'current_name' => $this->customer->user->name, 'proposed_name' => 'New Name', 'reason' => 'Correction', 'profile_version' => 1, 'status' => 'pending', 'expires_at' => now()->addDay()]);
    $payload = reassignmentInput($this->customer, $this->admin, $this->replacement);
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertOk();
    expect($proposal->fresh()->status)->toBe('cancelled');
    $this->actingAs($this->agent->user)->get(route('customers.show', $this->customer->customer_id))->assertNotFound();
    $this->get(route('customers.recovery.show', $this->customer->customer_id))->assertNotFound();
    $this->actingAs($this->replacement->user)->get(route('customers.show', $this->customer->customer_id))->assertOk();
    $removal = DB::table('notification_inbox_intents')->where('recipient_user_id', $this->agent->user_id)->first();
    expect($removal->customer_profile_id)->toBeNull()->and($removal->summary)->not->toContain($this->customer->customer_id)
        ->and($removal->summary)->not->toContain($this->customer->user->name)->and($removal->summary)->not->toContain('Internal personnel');
});

test('a pending work change invalidates the preview and audit failure rolls back the whole handover', function () {
    $payload = reassignmentInput($this->customer, $this->admin, $this->replacement);
    $this->createLifecyclePlan($this->customer, $this->agent->user);
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.store', $this->customer->customer_id), $payload)->assertConflict();
    $payload = reassignmentInput($this->customer->fresh(), $this->admin, $this->replacement);
    $this->mock(AuditCapture::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
    $this->withoutExceptionHandling();
    expect(fn () => app(CustomerReassignmentService::class)->execute($this->admin, $this->customer->fresh(), $payload))->toThrow(RuntimeException::class);
    expect($this->customer->fresh()->currentAssignment->agent_profile_id)->toBe($this->agent->id);
    $this->assertDatabaseCount('customer_handover_notices', 0);
});

test('reassignment screens expose current identity and eligible targets', function () {
    $this->actingAs($this->admin)->get(route('customers.reassignment.edit', $this->customer->customer_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/Reassign')->where('customer.reference', $this->customer->customer_id)->has('agents', 2));
});

test('handover preserves pending withdrawal reservation and recording-Agent cash responsibility', function () {
    FinancialPeriod::factory()->create();
    LedgerAccount::whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($this->customer, $this->agent->user);
    $collection = $this->lifecycleCollectionPayload($this->customer, $plan);
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($this->agent->user, $this->customer, $collection)['preview_fingerprint'];
    app(CollectionService::class)->record($this->agent->user, $this->customer, $collection);
    $registry = Mockery::mock(WithdrawalMethodRegistry::class);
    $registry->shouldReceive('resolve')->andReturn(['version' => 1, 'destination_reference' => 'fixture-cash', 'destination_mask' => 'Fixture cash']);
    app()->instance(WithdrawalMethodRegistry::class, $registry);
    $input = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00', 'method' => 'cash', 'destination_reference' => 'fixture-cash', 'reason' => 'Customer instruction'];
    $quote = app(WithdrawalService::class)->preview($this->agent->user, $this->customer, $input);
    $request = app(WithdrawalService::class)->submit($this->agent->user, $this->customer, [...$input,
        ...Arr::only($quote, ['customer_version', 'assignment_version', 'plan_version', 'business_version', 'quote_expires_at', 'preview_fingerprint']),
        'attempt_reference' => (string) Str::uuid(), 'instruction_attested' => true, 'confirmed' => true]);
    $before = $request->fresh()->getAttributes();
    $reservation = DB::table('withdrawal_reservations')->first();
    $receipts = DB::table('collection_receipts')->get()->all();
    $ledger = DB::table('ledger_entries')->get()->all();
    app(CustomerReassignmentService::class)->execute($this->admin, $this->customer, reassignmentInput($this->customer, $this->admin, $this->replacement));
    expect($request->fresh()->getAttributes())->toBe($before)->and(DB::table('withdrawal_reservations')->first())->toEqual($reservation)
        ->and(DB::table('collection_receipts')->get()->all())->toEqual($receipts)->and(DB::table('ledger_entries')->get()->all())->toEqual($ledger)
        ->and(app(WithdrawalService::class)->agentOffboardingStatus($this->agent, false))->toBe('passed');
    $this->actingAs($this->agent->user)->getJson(route('customers.access', $this->customer->customer_id))->assertNotFound();
    $this->actingAs($this->replacement->user)->getJson(route('customers.access', $this->customer->customer_id))->assertOk();
});

test('invitation authority follows handover without changing its token expiry or fee snapshot', function () {
    $this->customer->user->forceFill(['account_state' => 'invited', 'email_verified_at' => null])->save();
    $invitation = Invitation::create(['user_id' => $this->customer->user_id, 'target_email' => $this->customer->user->email,
        'target_email_normalized' => $this->customer->user->email_normalized, 'role' => 'customer', 'token_hash' => hash('sha256', 'retained-token'),
        'generation' => 1, 'status' => 'sent', 'delivery_status' => 'sent', 'sent_at' => now(), 'expires_at' => now()->addDays(2), 'invited_by_user_id' => $this->agent->user_id]);
    $before = $invitation->fresh()->getAttributes();
    $fees = DB::table('fee_snapshots')->where('customer_profile_id', $this->customer->id)->get()->all();
    app(CustomerReassignmentService::class)->execute($this->admin, $this->customer, reassignmentInput($this->customer, $this->admin, $this->replacement));
    expect($invitation->fresh()->getAttributes())->toBe($before)->and(DB::table('fee_snapshots')->where('customer_profile_id', $this->customer->id)->get()->all())->toEqual($fees);
    app(CustomerInvitationManagementService::class)->verifyAuthority($this->replacement->user, $this->customer->fresh());
    expect(fn () => app(CustomerInvitationManagementService::class)->verifyAuthority($this->agent->user, $this->customer->fresh()))->toThrow(ConflictHttpException::class);
});

test('pending reversal keeps its initiator evidence amount and assignment after handover', function () {
    FinancialPeriod::factory()->create();
    LedgerAccount::whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($this->customer, $this->agent->user);
    $input = $this->lifecycleCollectionPayload($this->customer, $plan);
    $input['preview_fingerprint'] = app(CollectionService::class)->preview($this->agent->user, $this->customer, $input)['preview_fingerprint'];
    app(CollectionService::class)->record($this->agent->user, $this->customer, $input);
    $original = LedgerPostingGroup::firstOrFail();
    $reversal = ReversalRequest::create([
        'reversal_id' => (string) Str::uuid(), 'customer_profile_id' => $this->customer->id,
        'original_posting_group_id' => $original->id, 'live_original_posting_group_id' => $original->id,
        'requested_by_user_id' => $this->agent->user_id, 'initiating_agent_profile_id' => $this->agent->id,
        'assignment_id' => $this->customer->currentAssignment->id, 'state' => 'pending_review', 'version' => 1,
        'reason_category' => 'recording_error', 'internal_reason' => 'Review requested', 'customer_explanation' => 'Your receipt requires review',
        'evidence_text' => 'Original evidence', 'dependency_fingerprint' => str_repeat('a', 64),
        'dependency_snapshot' => ['summary' => [], 'dependencies' => []], 'original_amount_kobo' => 100000, 'currency' => 'NGN',
    ]);
    $before = $reversal->fresh()->getAttributes();
    $ledger = DB::table('ledger_entries')->get()->all();
    app(CustomerReassignmentService::class)->execute($this->admin, $this->customer, reassignmentInput($this->customer, $this->admin, $this->replacement));
    expect($reversal->fresh()->getAttributes())->toBe($before)->and(DB::table('ledger_entries')->get()->all())->toEqual($ledger)
        ->and(app(ReversalService::class)->agentOffboardingStatus($this->agent, false))->toBe('passed');
});

test('handover HTTP preview is registered as a platform read and returns a bound confirmation', function () {
    $this->actingAs($this->admin)->postJson(route('customers.reassignment.preview', $this->customer->customer_id), ['target_agent_id' => $this->replacement->id])->assertOk()->assertJsonPath('version', 1)->assertJsonStructure(['preview_token', 'pending_withdrawals', 'pending_reversals']);
});
