<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\FinancialArtifact;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';
require_once __DIR__.'/../LedgerAcceptanceGapFixtures.php';

/** @return array{0: User, 1: mixed, 2: mixed, 3: mixed, 4: LedgerPostingGroup} */
function ledgerPostedCashWithdrawal(object $test): array
{
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($test, $admin, $withdrawal);
    $test->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    $test->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$admin, $customer, $plan, $withdrawal->fresh(), LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole()];
}

test('LED-AC-003: renaming an account changes only its display, never stored references or statement lines', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $today = now('Africa/Lagos')->toDateString();
    $statement = fn (): array => collect(app(StatementPreviewService::class)->preview($agent, $customer, $today, $today, 'Africa/Lagos'))
        ->except(['cutoff_at', 'preview_fingerprint', 'projection_version'])->all();
    $before = [DB::table('ledger_entries')->orderBy('id')->get()->all(), DB::table('ledger_posting_groups')->orderBy('id')->get()->all(),
        DB::table('ledger_transaction_references')->orderBy('id')->get()->all(), $statement()];
    $account = LedgerAccount::query()->where('code', LedgerAccountCode::CustomerSavingsLiability->value)->sole();

    DB::table('ledger_accounts')->where('id', $account->id)->update(['display_name' => 'Member savings owed']);
    app(LedgerTransactionProjectionService::class)->rebuild();

    expect($account->fresh()->code)->toBe(LedgerAccountCode::CustomerSavingsLiability)
        ->and([DB::table('ledger_entries')->orderBy('id')->get()->all(), DB::table('ledger_posting_groups')->orderBy('id')->get()->all(),
            DB::table('ledger_transaction_references')->orderBy('id')->get()->all(), $statement()])->toEqual($before);
});

test('LED-AC-004: approved postings retain their independent approver and source after later lifecycle changes', function (): void {
    [$admin, $customer, $plan, $withdrawal, $payout] = ledgerPostedCashWithdrawal($this);
    expect($payout->approver_user_id)->toBe($withdrawal->reviewed_by_user_id)
        ->and($payout->approver_user_id)->not->toBeNull()
        ->and($payout->source_type)->toBe('withdrawal')->and($payout->source_id)->toBe((string) $withdrawal->id)
        ->and($payout->occurred_on)->not->toBeNull()->and($payout->committed_at)->not->toBeNull()
        ->and($payout->schema_version)->toBe(1);
});

test('LED-AC-004: a correction retains its approver after the approver loses the grant and is suspended', function (): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $reviewer = revGapAdmin();
    revGapDecision($this, $reviewer, $request, 'approve')->assertRedirect();
    $compensation = LedgerPostingGroup::findOrFail($request->fresh()->compensation_posting_group_id);
    $reviewer->revokePermissionTo(AdminPermission::ReversalsReview);
    $reviewer->forceFill(['account_state' => AccountState::Suspended])->save();

    expect($compensation->fresh()->approver_user_id)->toBe($reviewer->id)
        ->and($original->fresh()->approver_user_id)->toBeNull()
        ->and($compensation->fresh()->getAttributes())->toBe($compensation->getAttributes());
});

test('LED-AC-021: approvals read the authoritative ledger while the transaction cache lags', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    ledgerGapUnsupportedGroup($agent);
    $reads = app(LedgerTransactionReadService::class);
    expect($reads->state()['status'])->toBe('stale')
        ->and($reads->balance($agent, $customer))->toBe(['status' => 'unavailable']);
    DB::table('ledger_transaction_projections')->delete();

    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'decision_note' => 'Reviewed',
    ])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('approved');

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '800.00'])->assertUnprocessable()->assertJsonValidationErrors('gross_ngn');
});

test('LED-AC-031: withdrawal detail shows signed effect, G P F D components, dated timeline and staff-only actors', function (): void {
    [$admin, $customer, $plan, $withdrawal] = ledgerPostedCashWithdrawal($this);
    $reads = app(LedgerTransactionReadService::class);
    $reference = collect($reads->search($admin, [])['data'])->firstWhere('type', 'withdrawal')['reference'];

    $detail = $reads->detail($admin, $reference);
    expect($detail['components'])->toBe(['gross_kobo' => 30000, 'net_kobo' => 29400, 'fee_kobo' => 600, 'deduction_kobo' => 0])
        ->and($detail['components']['gross_kobo'])->toBe($detail['components']['net_kobo'] + $detail['components']['fee_kobo'] + $detail['components']['deduction_kobo'])
        ->and(array_column($detail['timeline'], 'label'))->toBe(['Requested', 'Approved', 'Occurred', 'Committed'])
        ->and($detail['timeline'][1]['at'])->toBe($withdrawal->approved_at->toIso8601String())
        ->and($detail['actors']['reviewed_by'])->toBe(User::query()->whereKey($withdrawal->reviewed_by_user_id)->value('name'))
        ->and($detail['actors']['requested_by'])->not->toBeNull();
    $customerDetail = $reads->detail($customer->user, $reference);
    expect($customerDetail['actors'])->toBe([])->and($customerDetail['components'])->toBe($detail['components']);
});

/** Issues and renders one statement for the period through the real owner, optionally superseding an earlier one. */
function ledgerIssuedStatement(User $actor, $customer, string $from, string $to, ?FinancialArtifact $supersedes = null): FinancialArtifact
{
    $fingerprint = app(StatementPreviewService::class)->preview($actor, $customer, $from, $to, 'Africa/Lagos')['preview_fingerprint'];
    $artifact = app(FinancialArtifactService::class)->issueStatement($actor, $customer, (string) Str::uuid(), $from, $to, $fingerprint, $supersedes);
    app(FinancialArtifactService::class)->render($artifact->id);

    return $artifact->fresh();
}

test('LED-AC-039: a late pre-period posting changes only the new opening and the superseded statement stays reproducible', function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'Africa/Lagos'));
    [$agent, $customer, $assignment, , $plan] = ledgerGapReceipt(200000, '2000.00', 2, -1);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $first = ledgerIssuedStatement($agent, $customer, '2026-10-03', '2026-10-03');
    $bytes = app(FinancialArtifactService::class)->download($agent, $first);

    $this->travel(1)->hours();
    $late = ledgerGapRecordReceipt($agent, $customer, $assignment, $plan, '2026-10-02', '2000.00', 'Cash received yesterday, recorded late.');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $second = ledgerIssuedStatement($agent, $customer, '2026-10-03', '2026-10-03', $first);

    expect(LedgerPostingGroup::findOrFail($late->savings_posting_group_id)->committed_at->gt(CarbonImmutable::parse($first->snapshot['cutoff_at'])))->toBeTrue()
        ->and($first->fresh()->snapshot['opening_kobo'])->toBe(0)
        ->and($second->snapshot['opening_kobo'])->toBe(200000)
        ->and($second->snapshot['activity_kobo'])->toBe($first->snapshot['activity_kobo'])
        ->and($second->snapshot['closing_kobo'])->toBe($first->snapshot['closing_kobo'] + 200000)
        ->and($second->supersedes_artifact_id)->toBe($first->id)
        ->and($first->fresh()->snapshot_hash)->toBe($first->snapshot_hash)
        ->and(app(FinancialArtifactService::class)->download($agent, $first->fresh()))->toBe($bytes);
});

test('LED-AC-040: an in-period correction changes activity and closing, notifies the Customer and labels nothing Final', function (): void {
    Queue::fake();
    Storage::fake('local');
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $today = now('Africa/Lagos')->toDateString();
    $first = ledgerIssuedStatement($agent, $customer, $today, $today);

    $request = revGapSubmit($this, $agent, $original);
    revGapDecision($this, revGapAdmin(), $request, 'approve')->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $second = ledgerIssuedStatement($agent, $customer, $today, $today, $first);

    $intents = DB::table('financial_artifact_notification_intents')->whereIn('financial_artifact_event_id',
        DB::table('financial_artifact_events')->where('financial_artifact_id', $second->id)->select('id'))->get();
    expect($first->fresh()->snapshot['closing_kobo'])->toBe(200000)
        ->and($second->snapshot['activity_kobo'])->toBe(0)->and($second->snapshot['closing_kobo'])->toBe(0)
        ->and(LedgerPostingGroup::findOrFail($original->id)->getAttributes())->toBe($original->getAttributes())
        ->and(json_encode([$first->fresh()->snapshot, $second->snapshot, $second->manifest]))->not->toContain('Final')
        ->and($intents->pluck('audience_type')->sort()->values()->all())->toBe(['artifact_requester', 'subject_customer'])
        ->and($intents->firstWhere('audience_type', 'subject_customer')->recipient_user_id)->toBe($customer->user_id);
});

test('LED-AC-049: statement notices are deduplicated on retry, scoped to their recipients and carry no internal ledger data', function (): void {
    Queue::fake();
    Storage::fake('local');
    ['agent' => $agent, 'customer' => $customer] = revGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $today = now('Africa/Lagos')->toDateString();
    $first = ledgerIssuedStatement($agent, $customer, $today, $today);
    $customer->update(['version' => $customer->version + 1]);
    $second = ledgerIssuedStatement($agent, $customer, $today, $today, $first);
    $service = app(FinancialArtifactService::class);
    $service->render($second->id);
    $service->render($second->id);
    $events = DB::table('financial_artifact_events')->where('financial_artifact_id', $second->id)->select('id');

    expect(DB::table('financial_artifact_notification_intents')->whereIn('financial_artifact_event_id', $events)->count())->toBe(2)
        ->and(FinancialArtifact::query()->count())->toBe(2)
        ->and(DB::table('financial_artifact_notification_intents')->whereIn('financial_artifact_event_id',
            DB::table('financial_artifact_events')->where('financial_artifact_id', $first->id)->select('id'))->pluck('audience_type')->all())->toBe(['artifact_requester']);
    $inbox = DB::table('notification_inbox_intents')->get();
    $notices = DB::table('notification_events')->get();
    expect(json_encode([$inbox, $notices]))->not->toContain('ledger_account')->not->toContain('evidence')->not->toContain('agent_receivable');
    [$other] = revGapAgentWithCustomer();
    expect(DB::table('notification_inbox_intents')->where('recipient_user_id', $other->id)->count())->toBe(0);
});
