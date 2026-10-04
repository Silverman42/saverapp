<?php

use App\Enums\AdminPermission;
use App\Models\User;
use App\Models\WithdrawalAttempt;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use App\Services\CollectionReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';

function replayReviewer(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);

    return $admin;
}

test('WDL-AC-019 each terminal release frees gross exactly once and keeps liability and history', function (string $release): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $explained = ['internal_reason' => 'Release', 'customer_explanation' => 'Released'];
    $decide = function (User $actor, string $route, array $fields, int $version) use ($withdrawal): void {
        $this->actingAs($actor)->withSession(cashSession())->post(route($route, $withdrawal),
            ['attempt_reference' => (string) Str::uuid(), 'version' => $version, 'confirmed' => true, ...$fields]);
    };
    $admin = replayReviewer();
    match ($release) {
        'reject' => $decide($admin, 'withdrawals.reject', $explained, 1),
        'cancel' => $decide($agent, 'withdrawals.cancel', ['internal_reason' => 'Release'], 1),
        'revoke' => [$decide($admin, 'withdrawals.approve', ['decision_note' => 'Reviewed'], 1), $decide($admin, 'withdrawals.revoke', $explained, 2)],
        'expire' => [$this->travel(8)->days(), $this->artisan('withdrawals:expire')],
    };
    $this->artisan('withdrawals:expire')->assertSuccessful();

    $fresh = $withdrawal->fresh();
    expect($fresh->state)->toBeIn(['rejected', 'cancelled', 'expired'])
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('released')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'submitted')->count())->toBe(1)
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)
            ->whereIn('event_type', ['reject', 'cancel', 'revoke', 'expired'])->count())->toBe(1);
})->with(['reject', 'cancel', 'revoke', 'expire']);

test('WDL-AC-031 a duplicate decision resolves once and a changed payload conflicts', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = replayReviewer();
    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'decision_note' => 'Reviewed'];

    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), $decision)->assertRedirect();
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), $decision)->assertRedirect();
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal),
        [...$decision, 'decision_note' => 'Different note'])->assertConflict();
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.reject', $withdrawal),
        [...$decision, 'internal_reason' => 'No', 'customer_explanation' => 'No'])->assertConflict();

    expect($withdrawal->fresh()->state)->toBe('approved')->and($withdrawal->fresh()->version)->toBe(2)
        ->and(WithdrawalAttempt::query()->where('withdrawal_request_id', $withdrawal->id)->where('operation', 'approve')->count())->toBe(1)
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'approve')->count())->toBe(1);
});

test('WDL-AC-031 another actor cannot reuse a submit attempt reference', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $reference = WithdrawalAttempt::query()->where('withdrawal_request_id', $withdrawal->id)->value('attempt_reference');
    $admin = replayReviewer();

    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.reject', $withdrawal), [
        'attempt_reference' => $reference, 'version' => 1, 'confirmed' => true,
        'internal_reason' => 'Reuse', 'customer_explanation' => 'Reuse',
    ])->assertConflict();
    expect($withdrawal->fresh()->state)->toBe('pending_review')->and(WithdrawalRequest::count())->toBe(1);
});
