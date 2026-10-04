<?php

use App\Enums\AdminPermission;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';

/** @return array<string, mixed> */
function revGapWithdrawalDecision(object $withdrawal, array $overrides = []): array
{
    return [...['attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->fresh()->version, 'confirmed' => true,
        'decision_note' => 'Reviewed.', 'internal_reason' => 'Customer changed the instruction.',
        'customer_explanation' => 'The payout instruction was withdrawn.'], ...$overrides];
}

test('REV-AC-020: a withdrawal that has not been paid has no posting to reverse and is routed to the withdrawal path', function (string $state): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);
    $session = revGapFreshSession();
    match ($state) {
        'rejected' => test()->actingAs($admin)->withSession($session)->post(route('withdrawals.reject', $withdrawal),
            revGapWithdrawalDecision($withdrawal))->assertRedirect(),
        'cancelled' => test()->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal),
            revGapWithdrawalDecision($withdrawal))->assertRedirect(),
        'approved' => test()->actingAs($admin)->withSession($session)->post(route('withdrawals.approve', $withdrawal),
            revGapWithdrawalDecision($withdrawal))->assertRedirect(),
        default => null,
    };
    $before = revGapEffectCounts();

    expect($withdrawal->fresh()->state)->toBe($state)
        ->and(DB::table('ledger_posting_groups')->where('source_type', 'withdrawal')->count())->toBe(0);
    $this->actingAs($agent)->postJson(route('reversals.preview', $withdrawal->withdrawal_id))->assertNotFound();
    $this->postJson(route('reversals.store', $withdrawal->withdrawal_id), revGapSubmission(
        ['preview_fingerprint' => str_repeat('a', 64), 'customer_version' => 1, 'assignment_version' => 1]))->assertNotFound();

    expect(revGapEffectCounts())->toBe($before)->and(ReversalRequest::query()->count())->toBe(0);
    $this->get(route('withdrawals.show', $withdrawal))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_cancel', $state === 'pending_review'));
})->with(['pending_review' => 'pending_review', 'rejected' => 'rejected', 'cancelled' => 'cancelled', 'approved but unpaid' => 'approved']);

test('REV-AC-020: an approved but unpaid withdrawal is revoked through the withdrawal flow and still creates no reversal', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);
    $this->actingAs($admin)->withSession(revGapFreshSession())->post(route('withdrawals.approve', $withdrawal), revGapWithdrawalDecision($withdrawal))->assertRedirect();

    $this->post(route('withdrawals.revoke', $withdrawal), revGapWithdrawalDecision($withdrawal))->assertRedirect();

    expect($withdrawal->fresh()->state)->toBe('cancelled')->and(ReversalRequest::query()->count())->toBe(0)
        ->and(DB::table('reversal_attempts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->where('source_type', 'withdrawal')->count())->toBe(0);
});

/** Remits and reviews the receipt's batch through the Admin reconciliation flow. */
function revGapReconcileBatch(object $test, object $receipt): object
{
    $batch = $receipt->batch;
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::ReconciliationManage, AdminPermission::CashExecute]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $date = now('Africa/Lagos')->toDateString();
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'REV-GAP-'.$batch->id, 'amount_ngn' => '2000.00',
        'handoff_date' => $date, 'receiving_location' => 'Business till', 'source_attestation' => 'Counted original Customer tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent review matches posted original cash custody.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');

    return $batch;
}

test('REV-AC-021: a receipt correction in a reconciled batch leaves its review rows immutable and appends a linked supplement', function (): void {
    $this->freezeTime();
    ['agent' => $agent, 'receipt' => $receipt, 'original' => $original] = revGapReceipt();
    $batch = revGapReconcileBatch($this, $receipt);
    $reviews = DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->orderBy('id')->get()->all();
    $remittances = DB::table('cash_remittances')->where('collection_batch_id', $batch->id)->orderBy('id')->get()->all();
    expect($reviews)->toHaveCount(1)->and($remittances)->toHaveCount(1)
        ->and(FinancialWorkflowSupplement::query()->where('kind', 'receipt_compensated')->count())->toBe(0);

    $request = revGapSubmit($this, $agent, $original);
    revGapDecision($this, revGapAdmin(), $request, 'approve')->assertRedirect()->assertSessionHasNoErrors();

    $supplement = FinancialWorkflowSupplement::query()->where('kind', 'receipt_compensated')->sole();
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->orderBy('id')->get()->all())->toEqual($reviews)
        ->and(DB::table('cash_remittances')->where('collection_batch_id', $batch->id)->orderBy('id')->get()->all())->toEqual($remittances)
        ->and($supplement->collection_batch_id)->toBe($batch->id)->and($supplement->reversal_request_id)->toBe($request->id)
        ->and($supplement->customer_profile_id)->toBe($original->customer_profile_id)
        ->and($supplement->actor_user_id)->toBe($request->fresh()->reviewed_by_user_id);
});
