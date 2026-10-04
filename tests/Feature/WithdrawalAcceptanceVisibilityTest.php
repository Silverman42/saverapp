<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Jobs\DeliverWithdrawalNotificationIntent;
use App\Models\AgentProfile;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalNotificationIntent;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalStatusNotification;
use App\Services\AgentEligibilityService;
use App\Services\CollectionReadService;
use App\Services\ReversalCapabilityRegistry;
use App\Services\StatementPreviewService;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalReversalOwner;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CashExecutionFixtures.php';

function visibilityReviewer(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);

    return $admin;
}

test('WDL-AC-029 the Show page renders submission approval and posting dates distinctly', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $this->travel(1)->hours();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $posted = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();

    $this->actingAs($admin)->get(route('withdrawals.show', $withdrawal))->assertInertia(fn (Assert $page) => $page
        ->component('withdrawals/Show')
        ->where('withdrawal.submitted_at', $withdrawal->fresh()->submitted_at->toIso8601String())
        ->where('withdrawal.approved_at', $withdrawal->fresh()->approved_at->toIso8601String())
        ->where('withdrawal.state', 'posted'));
    expect($withdrawal->fresh()->submitted_at->lte($withdrawal->fresh()->approved_at))->toBeTrue()
        ->and($withdrawal->fresh()->approved_at->lt($posted->committed_at))->toBeTrue();
});

test('WDL-AC-035 list results counts and filters stay within each role scope', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $otherAgent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $otherAgent->id]);
    $admin = visibilityReviewer();

    app()->forgetInstance(WithdrawalMethodRegistry::class);
    $this->actingAs($admin)->get(route('withdrawals.index', ['state' => 'pending_review']))
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('requests.data.0.id', $withdrawal->withdrawal_id)->where('state_filter', 'pending_review'));
    $this->actingAs($admin)->get(route('withdrawals.index', ['state' => 'approved']))
        ->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    $this->actingAs($agent)->get(route('withdrawals.index'))->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('can_review', false));
    $this->actingAs($customer->user)->get(route('withdrawals.index'))->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
    $this->actingAs($otherAgent)->get(route('withdrawals.index'))->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    $this->actingAs(User::factory()->customer()->create())->get(route('withdrawals.index'))->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));

    $this->actingAs($customer->user)->get(route('withdrawals.show', $withdrawal))
        ->assertInertia(fn (Assert $page) => $page->where('withdrawal.internal_notes', null)->where('bank_attempts', []));
    $assignment->forceFill(['status' => CustomerAssignmentStatus::Ended])->save();
    $this->actingAs($agent)->get(route('withdrawals.index'))->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    $this->actingAs($agent)->get(route('withdrawals.show', $withdrawal))->assertForbidden();
});

test('WDL-AC-037 each notice family reaches only current safe recipients with accurate state', function (string $family): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    Queue::fake();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = visibilityReviewer();
    $request = request();
    $request->setLaravelSession(app('session.store'));
    $request->session()->put(cashSession());
    $request->setUserResolver(fn () => $admin);
    $decide = fn (User $actor, string $action, int $version) => app(WithdrawalService::class)->decide($actor, $withdrawal, $action, [
        'attempt_reference' => (string) Str::uuid(), 'version' => $version, 'confirmed' => true,
        'decision_note' => 'Reviewed', 'internal_reason' => 'Reviewed', 'customer_explanation' => 'Reviewed',
    ], $request);
    match ($family) {
        'submitted' => null,
        'approve' => $decide($admin, 'approve', 1),
        'reject' => $decide($admin, 'reject', 1),
        'cancel' => $decide($agent, 'cancel', 1),
        'expired' => [$this->travel(8)->days(), app(WithdrawalService::class)->expireDue()],
    };

    $intents = WithdrawalNotificationIntent::query()->whereIn('withdrawal_event_id',
        DB::table('withdrawal_events')->where('event_type', $family)->select('id'))->get();
    expect($intents)->toHaveCount(3)
        ->and($intents->pluck('payload.state')->unique()->all())->toBe([$withdrawal->fresh()->state])
        ->and($intents->pluck('recipient_user_id')->unique()->sort()->values()->all())->toBe(collect([$customer->user_id, $agent->id])->sort()->values()->all());
})->with(['submitted', 'approve', 'reject', 'cancel', 'expired']);

test('WDL-AC-037 duplicate retry and failed mail delivery never resend or replay money', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    Queue::fake();
    Notification::fake();
    $customer->user->forceFill(['email_verified_at' => now()])->save();
    submittedWithdrawal($agent, $customer, $assignment, $plan);
    $mail = WithdrawalNotificationIntent::query()->where('channel', 'mail')->sole();
    $liability = app(CollectionReadService::class)->position($customer)['liability_kobo'];

    (new DeliverWithdrawalNotificationIntent($mail->id))->handle(app(AgentEligibilityService::class));
    (new DeliverWithdrawalNotificationIntent($mail->id))->handle(app(AgentEligibilityService::class));
    Notification::assertSentToTimes($customer->user, WithdrawalStatusNotification::class, 1);
    expect($mail->fresh()->status)->toBe('delivered');

    $withdrawal = WithdrawalRequest::query()->sole();
    app(WithdrawalService::class)->decide($agent, $withdrawal, 'cancel', ['attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'confirmed' => true, 'internal_reason' => 'Changed instruction'], request());
    $failing = WithdrawalNotificationIntent::query()->where('channel', 'mail')->whereKeyNot($mail->id)->sole();
    (new DeliverWithdrawalNotificationIntent($failing->id))->failed(new RuntimeException('Mailer down'));
    (new DeliverWithdrawalNotificationIntent($failing->id))->handle(app(AgentEligibilityService::class));
    Notification::assertSentToTimes($customer->user, WithdrawalStatusNotification::class, 1);
    expect($failing->fresh()->status)->toBe('failed')
        ->and(WithdrawalRequest::count())->toBe(1)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($liability);
});

test('WDL-AC-038 denied and conflicting attempts are audited without changing the request', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $wrongGrant = User::factory()->admin()->withTwoFactor()->create();
    $reviewer = visibilityReviewer();
    $decision = fn (int $version): array => ['attempt_reference' => (string) Str::uuid(), 'version' => $version,
        'confirmed' => true, 'decision_note' => 'Reviewed'];

    $this->actingAs($wrongGrant)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), $decision(1))->assertForbidden();
    $this->actingAs($reviewer)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), $decision(9))->assertConflict();

    $audits = DB::table('audit_events')->where('event_type', 'withdrawal.decision_denied')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('actor_id')->all())->toBe([$wrongGrant->id, $reviewer->id])
        ->and(collect($audits)->map(fn ($row) => json_decode($row->payload, true)['outcome'])->all())->toBe(['denied', 'conflict'])
        ->and(collect($audits)->pluck('payload')->implode(''))->not->toContain('verified-customer-cash')
        ->and($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->version)->toBe(1);
});

test('WDL-AC-040 statements include only the posted withdrawal and never the pending request', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $preview = fn (): array => app(StatementPreviewService::class)->preview($admin, $customer, now()->subMonth()->toDateString(), now()->addDay()->toDateString(), 'Africa/Lagos');
    $withdrawalRows = fn (array $statement): int => collect($statement['lines'])->filter(fn (array $line): bool => str_contains((string) $line['type'], 'withdrawal'))->count();
    expect($withdrawalRows($preview()))->toBe(0);

    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    expect($withdrawalRows($preview()))->toBe(0);
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($withdrawalRows($preview()))->toBe(1);
});

test('WDL-AC-042 withdrawal compensation stays unavailable until its flag is enabled', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $posted = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();

    config()->set(['withdrawals.cash_compensation_enabled' => false, 'withdrawals.bank_compensation_enabled' => true]);
    expect(app(ReversalCapabilityRegistry::class)->resolve($posted))->toBeNull()
        ->and(config('withdrawals.cash_enabled'))->toBeFalse()->and(config('withdrawals.bank_enabled'))->toBeFalse();
    config()->set('withdrawals.cash_compensation_enabled', true);
    expect(app(ReversalCapabilityRegistry::class)->resolve($posted))->toBeInstanceOf(WithdrawalReversalOwner::class);
});
