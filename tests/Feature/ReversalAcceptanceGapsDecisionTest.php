<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\AgentProfile;
use App\Models\ReversalAttempt;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AgentOffboardingEligibility;
use App\Services\CollectionReadService;
use App\Services\ReversalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';

/** @return array<string, mixed> */
function revGapCancelPayload(ReversalRequest $request, array $overrides = []): array
{
    return [...['attempt_reference' => (string) Str::uuid(), 'version' => $request->fresh()->version,
        'decision_reason' => 'Customer confirmed the receipt was correct.', 'confirmed' => true], ...$overrides];
}

test('REV-AC-008: a successor Agent cannot cancel a request another Agent submitted, and the former Agent loses all access', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    [$successor] = revGapReassign($customer);
    $before = revGapEffectCounts();

    $this->actingAs($successor)->get(route('reversals.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_cancel', false));
    $this->postJson(route('reversals.cancel', $request), revGapCancelPayload($request))
        ->assertConflict()->assertJsonPath('message', 'Only the requesting Agent may cancel this request.');

    $this->actingAs($agent)->postJson(route('reversals.cancel', $request), revGapCancelPayload($request))->assertForbidden();
    $this->get(route('reversals.show', $request))->assertNotFound();
    $this->getJson(route('reversals.review-preview', $request))->assertNotFound();
    $this->postJson(route('reversals.evidence.store', $request), ['files' => []])->assertNotFound();
    $submitAttempt = ReversalAttempt::query()->where('operation', 'submit')->sole();
    $this->getJson(route('reversals.attempts.show', $submitAttempt->attempt_reference))->assertNotFound();

    expect(revGapEffectCounts())->toBe($before)->and($request->fresh()->state)->toBe('pending_review');
});

test('REV-AC-008: neither an Admin nor the Customer can cancel a pending request', function (string $role): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $actor = $role === 'Admin' ? revGapAdmin() : $customer->user;
    $before = revGapEffectCounts();

    $this->actingAs($actor)->withSession(revGapFreshSession())->postJson(route('reversals.cancel', $request), revGapCancelPayload($request))->assertForbidden();

    expect(revGapEffectCounts())->toBe($before)->and($request->fresh()->state)->toBe('pending_review');
})->with(['Admin', 'Customer']);

test('REV-AC-008: the requester cannot cancel once the request has been decided', function (string $decision): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    revGapDecision($this, revGapAdmin(), $request, $decision)->assertRedirect()->assertSessionHasNoErrors();
    $decided = $request->fresh();
    $before = revGapEffectCounts();

    $this->actingAs($agent)->postJson(route('reversals.cancel', $request), revGapCancelPayload($decided))->assertConflict();

    expect(revGapEffectCounts())->toBe($before)->and($request->fresh()->state)->toBe($decided->state)
        ->and($request->fresh()->version)->toBe($decided->version);
})->with(['reject' => 'reject', 'approve' => 'approve']);

test('REV-AC-008: the requester cancels a pending request once, leaving the original and its money untouched', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $liability = app(CollectionReadService::class)->position($customer)['liability_kobo'];
    $groups = DB::table('ledger_posting_groups')->count();

    $this->actingAs($agent)->postJson(route('reversals.cancel', $request), revGapCancelPayload($request))->assertRedirect();
    $this->postJson(route('reversals.cancel', $request), revGapCancelPayload($request))->assertConflict();

    expect($request->fresh()->state)->toBe('cancelled')->and($request->fresh()->reviewed_by_user_id)->toBeNull()
        ->and(DB::table('ledger_posting_groups')->count())->toBe($groups)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($liability);
});

test('REV-AC-010: an Archived Customer blocks approval until restored to Inactive', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $admin = revGapAdmin();
    revGapSetCustomerStatus($customer, CustomerStatus::Archived->value);
    $before = revGapEffectCounts();

    revGapDecision($this, $admin, $request, 'approve')->assertConflict()
        ->assertJsonPath('message', 'Restore the Archived Customer before correction.');
    $after = revGapEffectCounts();
    expect($request->fresh()->state)->toBe('pending_review')
        ->and(collect($after)->except('canonical_audit_events')->all())->toBe(collect($before)->except('canonical_audit_events')->all())
        ->and($after['canonical_audit_events'])->toBe($before['canonical_audit_events'] + 1);

    revGapSetCustomerStatus($customer, CustomerStatus::Inactive->value);
    revGapDecision($this, $admin, $request, 'approve')->assertRedirect();

    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(app(CollectionReadService::class)->position($customer->fresh())['liability_kobo'])->toBe(0);
});

test('REV-AC-010: a Restricted Customer is corrected without enabling payout or new transactions', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    revGapSetCustomerStatus($customer, CustomerStatus::Restricted->value);

    revGapDecision($this, revGapAdmin(), $request, 'approve')->assertRedirect();

    $customer = $customer->fresh();
    expect($request->fresh()->state)->toBe('approved_posted')->and($customer->operational_status)->toBe(CustomerStatus::Restricted)
        ->and(Gate::forUser($agent)->allows('initiateWithdrawal', $customer))->toBeFalse()
        ->and($customer->operational_status->canTransact())->toBeFalse();
});

test('REV-AC-024: a pending correction blocks Customer archival until it reaches a terminal outcome', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    $request = revGapSubmit($this, $agent, $original);
    $check = fn (): string => collect($this->actingAs($admin)->postJson(route('customers.lifecycle.preview', $customer->customer_id))
        ->assertOk()->json('checks'))->firstWhere('key', 'reversals')['status'];

    expect($check())->toBe('blocked')
        ->and(app(ReversalService::class)->archivalStatus($customer))->toBe('blocked');

    revGapDecision($this, revGapAdmin(), $request, 'reject')->assertRedirect();

    expect($check())->toBe('passed');
});

test('REV-AC-024: the Agent offboarding inventory does not pass while a pending request lacks a verified handover', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $profile = AgentProfile::query()->where('user_id', $agent->id)->sole();
    $request = revGapSubmit($this, $agent, $original);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $inventory = fn (): string => collect(app(AgentOffboardingEligibility::class)->preview($admin, $profile->fresh(), null)['checks'])
        ->firstWhere('key', 'financial_requests')['status'];

    expect($inventory())->not->toBe('passed')
        ->and(app(ReversalService::class)->agentOffboardingStatus($profile))->not->toBe('passed');

    revGapReassign($customer);

    expect(app(ReversalService::class)->agentOffboardingStatus($profile->fresh()))->toBe('passed')
        ->and($request->fresh()->state)->toBe('pending_review');
});

test('REV-AC-024: a suspended initiating Agent leaves the request reviewable with the original actors unchanged', function (): void {
    ['agent' => $agent, 'original' => $original, 'assignment' => $assignment] = revGapReceipt();
    $profile = AgentProfile::query()->where('user_id', $agent->id)->sole();
    $request = revGapSubmit($this, $agent, $original);
    $agent->forceFill(['account_state' => AccountState::Suspended])->save();
    $admin = revGapAdmin();

    $this->actingAs($admin)->withSession(revGapFreshSession())->get(route('reversals.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_approve', true));
    revGapDecision($this, $admin, $request, 'approve')->assertRedirect();

    $approved = $request->fresh();
    expect($approved->state)->toBe('approved_posted')->and($approved->requested_by_user_id)->toBe($agent->id)
        ->and($approved->initiating_agent_profile_id)->toBe($profile->id)->and($approved->assignment_id)->toBe($assignment->id)
        ->and($approved->reviewed_by_user_id)->toBe($admin->id);
});

test('REV-AC-026: the attempt lookup returns the operation result to its owner and 404 to everyone else', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $reference = ReversalAttempt::query()->where('operation', 'submit')->sole()->attempt_reference;
    [$foreignAgent] = revGapAgentWithCustomer();
    $admin = revGapAdmin();

    $this->actingAs($agent)->getJson(route('reversals.attempts.show', $reference))->assertOk()
        ->assertExactJson(['reversal_id' => $request->reversal_id, 'state' => 'pending_review']);
    foreach ([$foreignAgent, $customer->user, $admin] as $other) {
        $this->actingAs($other)->getJson(route('reversals.attempts.show', $reference))->assertNotFound();
    }
    $this->actingAs($agent)->getJson(route('reversals.attempts.show', (string) Str::uuid()))->assertNotFound();

    revGapDecision($this, $admin, $request, 'approve', ['attempt_reference' => $decisionReference = (string) Str::uuid()])->assertRedirect();
    $this->actingAs($admin)->getJson(route('reversals.attempts.show', $decisionReference))->assertOk()
        ->assertExactJson(['reversal_id' => $request->reversal_id, 'state' => 'approved_posted']);
    $this->actingAs($agent)->getJson(route('reversals.attempts.show', $decisionReference))->assertNotFound();
});

test('REV-AC-026: reusing an operation reference with a changed payload or action conflicts without a second effect', function (): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);
    $submission = revGapSubmission($quote);
    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), $submission)->assertRedirect()->assertSessionHasNoErrors();
    $request = ReversalRequest::query()->sole();
    $admin = revGapAdmin();
    $approval = ['attempt_reference' => (string) Str::uuid(), 'version' => 1, 'decision_reason' => 'Reviewed original custody evidence.',
        'preview_fingerprint' => app(ReversalService::class)->reviewPreview($admin, $request)['preview_fingerprint'], 'confirmed' => true];
    $after = null;

    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), $submission)->assertRedirect();
    $this->postJson(route('reversals.store', $original->posting_reference), [...$submission, 'customer_explanation' => 'A different explanation.'])->assertConflict();
    $this->postJson(route('reversals.cancel', $request), revGapCancelPayload($request, ['attempt_reference' => $submission['attempt_reference']]))->assertConflict();
    $this->actingAs($admin)->withSession(revGapFreshSession())->postJson(route('reversals.approve', $request), $approval)->assertRedirect();
    $after = revGapEffectCounts();
    $this->postJson(route('reversals.approve', $request), $approval)->assertRedirect();
    $this->postJson(route('reversals.approve', $request), [...$approval, 'decision_reason' => 'A different reason.'])->assertConflict();
    $this->postJson(route('reversals.reject', $request), [...$approval, 'preview_fingerprint' => null])->assertConflict();

    expect(revGapEffectCounts())->toBe($after)->and(ReversalRequest::query()->count())->toBe(1)
        ->and($request->fresh()->state)->toBe('approved_posted')
        ->and(DB::table('ledger_posting_groups')->where('source_type', 'reversal_request')->count())->toBe(1);
});
