<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Jobs\DeliverReversalNotificationIntent;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalEvent;
use App\Models\ReversalNotificationIntent;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\ReversalCapabilityRegistry;
use App\Services\ReversalNoticeService;
use App\Services\ReversalOwnerContract;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function reversalFixture(): array
{
    $agent = User::factory()->agent()->create([
        'two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agentProfile->id,
        'assigned_by_user_id' => $agent->id,
        'status' => CustomerAssignmentStatus::Current,
    ]);
    $original = LedgerPostingGroup::create([
        'posting_reference' => 'COL-REV-001', 'idempotency_key' => 'reversal-fixture-'.Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'source_type' => 'collection_receipt',
        'source_id' => '1', 'event_type' => 'cash_savings', 'currency' => 'NGN',
        'actor_user_id' => $agent->id, 'customer_profile_id' => $customer->id,
        'occurred_at' => now(), 'committed_at' => now(),
    ]);

    return [$agent, $customer, $assignment, $original];
}

function pendingReversal(User $agent, CustomerProfile $customer, CustomerAssignment $assignment, LedgerPostingGroup $original): ReversalRequest
{
    return ReversalRequest::create([
        'reversal_id' => (string) Str::uuid(), 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $original->id, 'live_original_posting_group_id' => $original->id,
        'requested_by_user_id' => $agent->id, 'initiating_agent_profile_id' => $assignment->agent_profile_id,
        'assignment_id' => $assignment->id, 'state' => 'pending_review', 'version' => 1,
        'reason_category' => 'duplicate_posting', 'internal_reason' => 'Duplicate receipt investigation',
        'customer_explanation' => 'We are reviewing a receipt correction.',
        'evidence_text' => 'Cash register shows one tender.',
        'dependency_fingerprint' => str_repeat('b', 64),
        'dependency_snapshot' => ['summary' => [], 'dependencies' => []],
        'original_amount_kobo' => 200000, 'currency' => 'NGN',
    ]);
}

test('production owner gate rejects a new request without creating financial state', function (): void {
    [$agent, $customer, , $original] = reversalFixture();

    $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))
        ->assertServiceUnavailable();
    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => str_repeat('b', 64),
        'customer_version' => $customer->version, 'assignment_version' => 1,
        'reason_category' => 'duplicate_posting', 'internal_reason' => 'Duplicate receipt',
        'customer_explanation' => 'We are correcting a receipt.',
        'evidence_text' => 'Cash register shows one tender.', 'confirmed' => true,
    ])->assertServiceUnavailable();

    $this->assertDatabaseCount('reversal_requests', 0);
    $this->assertDatabaseCount('reversal_attempts', 0);
    $this->assertDatabaseCount('reversal_events', 0);
});

test('original lookup is scoped and an Admin cannot initiate', function (): void {
    [, , , $original] = reversalFixture();
    $otherAgent = User::factory()->agent()->create([
        'two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    AgentProfile::factory()->active()->create(['user_id' => $otherAgent->id]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($otherAgent)->postJson(route('reversals.preview', $original->posting_reference))
        ->assertNotFound();
    $this->actingAs($admin)->postJson(route('reversals.preview', $original->posting_reference))
        ->assertForbidden();
});

test('fresh authorized Admin rejection is idempotent and does not post money', function (): void {
    [$agent, $customer, $assignment, $original] = reversalFixture();
    $reversal = pendingReversal($agent, $customer, $assignment, $original);
    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    $admin->givePermissionTo(AdminPermission::ReversalsReview->value);
    $data = [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'decision_reason' => 'Evidence does not prove duplication.', 'confirmed' => true,
    ];
    $now = now()->timestamp;

    $this->actingAs($admin)->withSession([
        'auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now,
        'auth.mfa_confirmed_at' => $now,
    ])->post(route('reversals.reject', $reversal), $data)->assertRedirect();
    $this->actingAs($admin)->post(route('reversals.reject', $reversal), $data)->assertRedirect();

    expect($reversal->fresh()->state)->toBe('rejected');
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('reversal_attempts', 1);
    $this->assertDatabaseCount('reversal_events', 1);
});

test('approval remains blocked without an owner compensation contract', function (): void {
    [$agent, $customer, $assignment, $original] = reversalFixture();
    $reversal = pendingReversal($agent, $customer, $assignment, $original);
    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    $admin->givePermissionTo(AdminPermission::ReversalsReview->value);
    $now = now()->timestamp;

    $this->actingAs($admin)->withSession([
        'auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now,
        'auth.mfa_confirmed_at' => $now,
    ])->postJson(route('reversals.approve', $reversal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'preview_fingerprint' => str_repeat('c', 64), 'decision_reason' => 'Reviewed',
        'confirmed' => true,
    ])->assertServiceUnavailable();

    expect($reversal->fresh()->state)->toBe('pending_review');
    $this->assertDatabaseCount('reversal_attempts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
});

test('a contract-backed request replays once and requester cancellation preserves the original', function (): void {
    [$agent, $customer, $assignment, $original] = reversalFixture();
    $owner = Mockery::mock(ReversalOwnerContract::class);
    $owner->shouldReceive('preview')->andReturn([
        'fingerprint' => str_repeat('d', 64), 'gross_kobo' => 200000,
        'summary' => ['original_reference' => $original->posting_reference],
        'dependencies' => [],
    ]);
    $registry = Mockery::mock(ReversalCapabilityRegistry::class);
    $registry->shouldReceive('resolve')->andReturn($owner);
    app()->instance(ReversalCapabilityRegistry::class, $registry);
    $quote = $this->actingAs($agent)
        ->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
    $submission = [
        'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'],
        'reason_category' => 'duplicate_posting',
        'internal_reason' => 'Duplicate receipt investigation',
        'customer_explanation' => 'We are reviewing a receipt correction.',
        'evidence_text' => 'Cash register shows one tender.', 'confirmed' => true,
    ];

    $this->actingAs($agent)->post(route('reversals.store', $original->posting_reference), $submission)->assertRedirect();
    $this->actingAs($agent)->post(route('reversals.store', $original->posting_reference), $submission)->assertRedirect();
    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), [
        ...$submission, 'internal_reason' => 'Changed investigation',
    ])->assertConflict();
    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), [
        ...$submission, 'attempt_reference' => (string) Str::uuid(),
    ])->assertConflict();
    $reversal = ReversalRequest::query()->firstOrFail();
    $this->actingAs($agent)->post(route('reversals.cancel', $reversal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'decision_reason' => 'Customer confirmed the receipt was correct.', 'confirmed' => true,
    ])->assertRedirect();

    expect($reversal->fresh()->state)->toBe('cancelled');
    $this->assertDatabaseCount('reversal_requests', 1);
    $this->assertDatabaseCount('reversal_attempts', 2);
    $this->assertDatabaseCount('reversal_events', 2);
    $this->assertDatabaseCount('reversal_notification_intents', 2);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
});

test('review permission and Customer presentation keep internal evidence private', function (): void {
    [$agent, $customer, $assignment, $original] = reversalFixture();
    $reversal = pendingReversal($agent, $customer, $assignment, $original);
    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    $now = now()->timestamp;

    $this->actingAs($admin)->withSession([
        'auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now,
        'auth.mfa_confirmed_at' => $now,
    ])->postJson(route('reversals.reject', $reversal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'decision_reason' => 'No authority', 'confirmed' => true,
    ])->assertForbidden();
    $this->actingAs($customer->user)->get(route('reversals.show', $reversal))
        ->assertInertia(fn ($page) => $page->component('reversals/Show')
            ->where('reversal.internal_reason', null)
            ->where('reversal.evidence_text', null)
            ->where('reversal.customer_explanation', null));

    expect($reversal->fresh()->state)->toBe('pending_review');
    $this->assertDatabaseCount('reversal_attempts', 0);
});

test('delivery suppresses a former Agent notification after assignment ends', function (): void {
    [$agent, $customer, $assignment, $original] = reversalFixture();
    $reversal = pendingReversal($agent, $customer, $assignment, $original);
    $event = ReversalEvent::create([
        'reversal_request_id' => $reversal->id, 'actor_user_id' => $agent->id,
        'event_type' => 'submitted', 'effective_at' => now(),
    ]);
    Queue::fake();
    app(ReversalNoticeService::class)->queue($reversal, $event);
    $intent = ReversalNotificationIntent::query()->firstOrFail();
    $assignment->status = CustomerAssignmentStatus::Ended;
    $assignment->save();

    app(DeliverReversalNotificationIntent::class, ['intentId' => $intent->id])
        ->handle(app(AgentEligibilityService::class));

    expect($intent->fresh()->status)->toBe('suppressed');
    $this->assertDatabaseCount('notifications', 0);
});
