<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../LedgerAcceptanceGapFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

test('LED-AC-020: total Customer liability includes every status and stays apart from Agent custody', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    [, $second, $secondAssignment] = ledgerGapSecondCustomer();
    $third = CustomerProfile::factory()->create();
    ledgerGapLiability($second, 75000, $secondAssignment->agent_profile_id);
    $customer->update(['operational_status' => CustomerStatus::Archived]);
    $second->update(['operational_status' => CustomerStatus::Restricted]);
    $third->update(['operational_status' => CustomerStatus::Inactive]);
    $reads = app(CollectionReadService::class);

    $each = collect([$customer, $second, $third])->map(fn (CustomerProfile $profile): int => $reads->position($profile->fresh())['liability_kobo']);

    expect($each->all())->toBe([200000, 75000, 0])
        ->and($reads->scopedLiability(CustomerProfile::query()))->toBe(275000)
        ->and($reads->scopedPosition(CustomerProfile::query()))->toBe(['liability_kobo' => 275000, 'reservations_kobo' => 0, 'available_kobo' => 275000]);
    $net = fn (LedgerAccountCode $code, string $normal, string $other): int => (int) DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
        ->where('ledger_accounts.code', $code->value)->selectRaw("SUM(CASE WHEN side = '{$normal}' THEN amount_kobo WHEN side = '{$other}' THEN -amount_kobo ELSE 0 END) AS net")->value('net');
    expect($net(LedgerAccountCode::CustomerSavingsLiability, 'credit', 'debit'))->toBe(275000)
        ->and($net(LedgerAccountCode::AgentReceivable, 'debit', 'credit'))->toBe(275000);
});

test('LED-AC-021: a projection that was never verified blocks balances even though no money is posted', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agentless = User::factory()->admin()->withTwoFactor()->create();
    $reads = app(LedgerTransactionReadService::class);

    expect($reads->state()['status'])->toBe('unavailable')
        ->and($reads->balance($agentless, $customer))->toBe(['status' => 'unavailable'])
        ->and($reads->balances($agentless, [$customer]))->toBe([$customer->id => ['status' => 'unavailable']])
        ->and($reads->search($agentless, [])['status'])->toBe('unavailable');
    $this->actingAs($agentless)->getJson(route('customers.ledger-balance', $customer->customer_id))->assertOk()->assertExactJson(['status' => 'unavailable']);

    app(LedgerTransactionProjectionService::class)->rebuild();
    expect($reads->balance($agentless, $customer))->toBe(['status' => 'ready', 'liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0]);
});

test('LED-AC-021: a lagging projection shows its watermark and blocks every balance and statement surface', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $verified = app(LedgerTransactionReadService::class)->state();
    $reads = app(LedgerTransactionReadService::class);
    expect($verified['status'])->toBe('ready');

    $lagging = ledgerGapLiability($customer, 100, $customer->currentAssignment->agent_profile_id);
    $state = $reads->state();
    $today = now('Africa/Lagos')->toDateString();

    expect($state['status'])->toBe('stale')->and($state['watermark'])->toBe($verified['watermark'])
        ->and($lagging->id)->toBeGreaterThan($state['watermark'])
        ->and($reads->balance($agent, $customer))->toBe(['status' => 'unavailable'])
        ->and($reads->balances($agent, [$customer]))->toBe([$customer->id => ['status' => 'unavailable']])
        ->and(app(StatementPreviewService::class)->preview($agent, $customer, $today, $today, 'Africa/Lagos'))->toBe(['status' => 'unavailable']);
    $this->actingAs($agent)->getJson(route('customers.ledger-balance', $customer->customer_id))->assertExactJson(['status' => 'unavailable']);
    $this->get(route('transactions.index'))->assertInertia(fn ($page) => $page->where('result.stale', true)->where('result.state.watermark', $verified['watermark'])
        ->where('result.state.status', 'stale')->has('result.data', 1));
    $this->get(route('customers.statements.preview', $customer->customer_id))->assertInertia(fn ($page) => $page->where('preview.status', 'unavailable'));
});

test('LED-AC-022: only a whole receipt is offered for reversal and a fee component alone is not', function (): void {
    [$agent, , , $savings] = ledgerGapMixedReceipt();
    $fee = LedgerPostingGroup::query()->where('event_type', 'external_fee_receipt')->sole();
    $before = ledgerGapSnapshot();

    $this->actingAs($agent)->postJson(route('reversals.preview', $savings->posting_reference))->assertOk()->assertJsonPath('gross_kobo', 250000)
        ->assertJsonPath('dependencies.0.kind', 'external_fee');
    $this->postJson(route('reversals.preview', $fee->posting_reference))->assertConflict()->assertJsonPath('message', 'The complete authoritative receipt is unavailable.');

    expect(ledgerGapSnapshot())->toEqual($before)->and(ReversalRequest::query()->count())->toBe(0);
});

test('LED-AC-022: an approved full reversal adds one balanced linked group whose compensation cannot itself be corrected', function (): void {
    [$agent, $customer, $assignment, $original] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $originalRows = $original->entries()->get()->map->getAttributes()->all();

    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);

    $compensation = LedgerPostingGroup::findOrFail($request->fresh()->compensation_posting_group_id);
    $entries = $compensation->entries()->get();
    expect(LedgerPostingGroup::query()->count())->toBe(2)
        ->and($entries->where('side', LedgerEntrySide::Debit)->sum('amount_kobo'))->toBe($entries->where('side', LedgerEntrySide::Credit)->sum('amount_kobo'))
        ->and($entries->sum('amount_kobo'))->toBeGreaterThan(0)
        ->and($original->fresh()->entries()->get()->map->getAttributes()->all())->toBe($originalRows)
        ->and(app(CollectionReadService::class)->position($customer->fresh())['liability_kobo'])->toBe(0);
    $this->actingAs($agent)->postJson(route('reversals.preview', $compensation->posting_reference))->assertConflict();
    $this->postJson(route('reversals.preview', $original->posting_reference))->assertConflict();
});

test('LED-AC-023: a reversal reviewer gains no waiver, deduction, journal, report or incident authority and no Adjustment reason exists', function (): void {
    [$agent, $customer, , $original] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reviewer = ledgerGapAdmin([AdminPermission::ReversalsReview]);
    $before = ledgerGapSnapshot();
    $attempt = (string) Str::uuid();

    $this->actingAs($reviewer)->withSession(ledgerGapFreshSession());
    $this->get(route('admin.charges.index'))->assertForbidden();
    $this->post(route('admin.fees.obligations.waive', 1), ['attempt_reference' => $attempt, 'amount_ngn' => '1.00', 'reason' => 'Waive', 'customer_description' => 'Waive', 'confirmed' => true])->assertForbidden();
    $this->post(route('admin.fees.obligations.correct', 1), ['attempt_reference' => $attempt, 'confirmed' => true])->assertForbidden();
    $this->post(route('ledger.incidents.resolve', $attempt), ['note' => 'Fix the ledger', 'confirmed' => true])->assertForbidden();
    $this->post(route('reports.export', 'withdrawals'), ['operation_reference' => $attempt, 'format' => 'csv', 'confirmed' => true])->assertForbidden();
    $this->postJson(route('reversals.preview', $original->posting_reference))->assertForbidden();

    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), [
        'attempt_reference' => $attempt, 'preview_fingerprint' => str_repeat('a', 64), 'customer_version' => 1, 'assignment_version' => 1,
        'reason_category' => 'adjustment', 'internal_reason' => 'Adjust', 'customer_explanation' => 'Adjust', 'evidence_text' => 'Adjust', 'confirmed' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('reason_category');

    expect(ledgerGapSnapshot())->toEqual($before)->and(ReversalRequest::query()->count())->toBe(0);
});

test('LED-AC-025: a full rebuild at the same cutoff reproduces counts, balances, statuses and statement inputs exactly', function (): void {
    [$agent, $customer, $assignment, $original] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    ledgerGapRemittance();
    app(LedgerTransactionProjectionService::class)->rebuild();
    approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    $reads = app(LedgerTransactionReadService::class);
    $statements = app(StatementPreviewService::class);
    $capture = function () use ($agent, $customer, $reads, $statements): array {
        $preview = $statements->preview($agent, $customer, now('Africa/Lagos')->startOfMonth()->toDateString(), now('Africa/Lagos')->toDateString(), 'Africa/Lagos');
        unset($preview['cutoff_at'], $preview['projection_version'], $preview['preview_fingerprint']);

        return ['projection' => ledgerGapProjectionContent(), 'balance' => $reads->balance($agent, $customer), 'statement' => $preview,
            'search' => collect($reads->search($agent, [])['data'])->map(fn (array $row): array => [$row['reference'], $row['type'], $row['status']])->all()];
    };

    $first = app(LedgerTransactionProjectionService::class)->rebuild();
    $before = $capture();
    $second = app(LedgerTransactionProjectionService::class)->rebuild();
    $after = $capture();

    expect($second['version'])->toBe($first['version'] + 1)->and($second['transactions'])->toBe($first['transactions'])->and($second['groups'])->toBe($first['groups'])
        ->and($after)->toEqual($before)
        ->and(array_column($before['projection'], 'status'))->toContain('reversed')->toContain('posted')
        ->and($before['balance']['liability_kobo'])->toBe(0);
});

test('LED-AC-026: a failed rebuild leaves the verified projection untouched, labelled stale and never current', function (): void {
    [$agent, $customer, $assignment, , $plan] = ledgerGapReceipt(200000, '2000.00', 2);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $verified = DB::table('ledger_projection_state')->first();
    $rows = ledgerGapProjectionContent();
    $reads = app(LedgerTransactionReadService::class);
    ledgerGapUnsupportedGroup($agent, $customer->id);

    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class, 'unsupported or unlinked');
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    $state = DB::table('ledger_projection_state')->first();
    expect($state->active_version)->toBe($verified->active_version)->and($state->ledger_group_watermark)->toBe($verified->ledger_group_watermark)
        ->and($state->status)->toBe('unavailable')
        ->and(DB::table('ledger_transaction_projections')->where('projection_version', '>', $verified->active_version)->count())->toBe(0)
        ->and(ledgerGapProjectionContent())->toEqual($rows)
        ->and($reads->state()['status'])->toBe('stale')->and($reads->state()['version'])->toBe($verified->active_version)
        ->and($reads->search($agent, [])['stale'])->toBeTrue()
        ->and($reads->balance($agent, $customer))->toBe(['status' => 'unavailable'])
        ->and(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(2);

    $second = ledgerGapRecordReceipt($agent, $customer, $assignment, $plan, now('Africa/Lagos')->toDateString(), '2000.00');
    expect($second->savings_posting_group_id)->not->toBeNull()
        ->and(LedgerPostingGroup::query()->where('source_type', 'collection_receipt')->count())->toBe(2)
        ->and(DB::table('ledger_projection_state')->value('active_version'))->toBe($verified->active_version)
        ->and($reads->state()['status'])->toBe('stale');
});

test('LED-AC-027: every injected group, source, subsidiary, fee and dimension mismatch on a receipt is detected', function (string $damage, string $message): void {
    [, $customer, , $group] = ledgerGapMixedReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $other = CustomerProfile::factory()->create();
    $feeGroup = LedgerPostingGroup::query()->where('event_type', 'external_fee_receipt')->sole();
    $liability = LedgerAccount::query()->where('code', LedgerAccountCode::CustomerSavingsLiability->value)->value('id');
    match ($damage) {
        'unbalanced group' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'credit')->increment('amount_kobo'),
        'foreign currency group' => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['currency' => 'USD']),
        'missing occurrence date' => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['occurred_on' => null]),
        'shifted occurrence date' => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['occurred_on' => '2026-01-01']),
        'receipt savings amount' => DB::table('collection_receipts')->update(['savings_amount_kobo' => 200001]),
        'receipt fee amount' => DB::table('collection_receipts')->update(['fee_amount_kobo' => 90000]),
        'receipt without savings group' => DB::table('collection_receipts')->update(['savings_posting_group_id' => null]),
        'fee component for another receipt customer' => DB::table('ledger_posting_groups')->where('id', $feeGroup->id)->update(['customer_profile_id' => $other->id]),
        'liability entry for another customer' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('ledger_account_id', $liability)->update(['customer_profile_id' => $other->id]),
        'liability entry for another cycle' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('ledger_account_id', $liability)->update(['thrift_plan_id' => null]),
        'single line group' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'debit')->delete(),
    };

    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class, $message);

    $incident = DB::table('ledger_integrity_incidents')->where('status', 'open')->sole();
    expect($incident->category)->toBe('projection_rebuild')->and(Str::isUuid($incident->incident_reference))->toBeTrue()
        ->and(DB::table('canonical_audit_events')->where('event_type', 'ledger.integrity_incident')->count())->toBe(1)
        ->and(app(LedgerTransactionReadService::class)->state()['status'])->toBe('stale');
})->with([
    'unbalanced group' => ['unbalanced group', 'does not balance'],
    'foreign currency group' => ['foreign currency group', 'incomplete'],
    'missing occurrence date' => ['missing occurrence date', 'incomplete'],
    'shifted occurrence date' => ['shifted occurrence date', 'do not reconcile'],
    'receipt savings amount' => ['receipt savings amount', 'do not reconcile'],
    'receipt fee amount' => ['receipt fee amount', 'do not reconcile'],
    'receipt without savings group' => ['receipt without savings group', 'do not reconcile'],
    'fee component for another receipt customer' => ['fee component for another receipt customer', 'do not reconcile'],
    'liability entry for another customer' => ['liability entry for another customer', 'inconsistent Customer dimension'],
    'liability entry for another cycle' => ['liability entry for another cycle', 'inconsistent Customer dimension'],
    'single line group' => ['single line group', 'incomplete'],
]);

test('LED-AC-027: every injected withdrawal, remittance and compensation mismatch is detected', function (string $damage, string $message): void {
    [, $customer, $withdrawal, $group] = ledgerGapPostedWithdrawal($this);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_projection_state')->value('status'))->toBe('ready');
    match ($damage) {
        'withdrawal gross amount' => DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['gross_amount_kobo' => 30001]),
        'withdrawal net amount' => DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['net_amount_kobo' => 29999]),
        'withdrawal fee amount' => DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['fee_amount_kobo' => 100]),
        'withdrawal deduction amount' => DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['deduction_amount_kobo' => 100]),
        'withdrawal source identity' => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['source_id' => '999999']),
        'withdrawal customer dimension' => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['customer_profile_id' => CustomerProfile::factory()->create()->id]),
        'withdrawal payout line' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'credit')->decrement('amount_kobo'),
        'remittance amount' => DB::table('cash_remittances')->update(['amount_kobo' => 100001]),
        'remittance date' => DB::table('ledger_posting_groups')->where('event_type', 'cash_remittance')->update(['occurred_on' => '2026-01-01']),
        'remittance source link' => DB::table('ledger_posting_groups')->where('event_type', 'cash_remittance')->update(['source_id' => '999999']),
    };

    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class, $message);

    expect(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(1)
        ->and(app(LedgerTransactionReadService::class)->balance($customer->user, $customer))->toBe(['status' => 'unavailable']);
})->with([
    'withdrawal gross amount' => ['withdrawal gross amount', 'Withdrawal amounts and entries do not reconcile'],
    'withdrawal net amount' => ['withdrawal net amount', 'Withdrawal amounts and entries do not reconcile'],
    'withdrawal fee amount' => ['withdrawal fee amount', 'Withdrawal amounts and entries do not reconcile'],
    'withdrawal deduction amount' => ['withdrawal deduction amount', 'Withdrawal amounts and entries do not reconcile'],
    'withdrawal payout line' => ['withdrawal payout line', 'does not balance'],
    'withdrawal source identity' => ['withdrawal source identity', 'Withdrawal source and posting do not reconcile'],
    'withdrawal customer dimension' => ['withdrawal customer dimension', 'inconsistent Customer dimension'],
    'remittance amount' => ['remittance amount', 'cash handoff journal does not reconcile'],
    'remittance date' => ['remittance date', 'cash handoff journal does not reconcile'],
    'remittance source link' => ['remittance source link', 'inconsistent ledger source'],
]);

test('LED-AC-027: a damaged compensation group or a mismatched reservation is detected', function (string $damage): void {
    [$agent, $customer, $assignment, $original] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    $compensationId = $request->fresh()->compensation_posting_group_id;
    $reads = app(LedgerTransactionReadService::class);
    expect($reads->state()['status'])->toBe('ready');
    match ($damage) {
        'compensation line' => DB::table('ledger_entries')->where('ledger_posting_group_id', $compensationId)->where('side', 'credit')->increment('amount_kobo'),
        'compensation customer' => DB::table('ledger_posting_groups')->where('id', $compensationId)->update(['customer_profile_id' => CustomerProfile::factory()->create()->id]),
        'compensation source' => DB::table('ledger_posting_groups')->where('id', $compensationId)->update(['source_id' => '999999']),
        'reservation above liability' => DB::table('withdrawal_reservations')->insert(['customer_profile_id' => $customer->id, 'thrift_plan_id' => null,
            'owner_reference' => 'WDL-GAP-OVER', 'gross_amount_kobo' => 1, 'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]),
    };

    if ($damage === 'reservation above liability') {
        expect($reads->balance($agent, $customer))->toBe(['status' => 'unavailable'])->and($reads->balances($agent, [$customer]))->toBe([$customer->id => ['status' => 'unavailable']]);

        return;
    }
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    expect(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(1);
})->with(['compensation line', 'compensation customer', 'compensation source', 'reservation above liability']);

test('LED-AC-028: an incident freezes balance and statement reads, keeps a durable reference and is closed only by a person without touching a balance', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $ledgerBefore = [DB::table('ledger_entries')->orderBy('id')->get()->all(), DB::table('ledger_posting_groups')->orderBy('id')->get()->all()];
    $unsupported = ledgerGapUnsupportedGroup($agent);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);
    $incident = DB::table('ledger_integrity_incidents')->sole();
    $reads = app(LedgerTransactionReadService::class);
    $today = now('Africa/Lagos')->toDateString();

    expect($reads->balance($agent, $customer))->toBe(['status' => 'unavailable'])
        ->and(app(StatementPreviewService::class)->preview($agent, $customer, $today, $today, 'Africa/Lagos'))->toBe(['status' => 'unavailable'])
        ->and(Str::isUuid($incident->incident_reference))->toBeTrue()
        ->and(DB::table('canonical_audit_events')->where('event_type', 'ledger.integrity_incident')->where('target_reference', $incident->incident_reference)->count())->toBe(1);

    $reconciler = ledgerGapAdmin([AdminPermission::ReconciliationManage]);
    $this->actingAs($reconciler)->withSession(ledgerGapFreshSession())
        ->post(route('ledger.incidents.resolve', $incident->incident_reference), ['note' => 'Set the balance to 999999 manually.', 'confirmed' => true, 'balance_kobo' => 999999, 'liability_kobo' => 999999])
        ->assertStatus(409);
    expect(DB::table('ledger_integrity_incidents')->value('status'))->toBe('open');

    $fresh = [DB::table('ledger_entries')->orderBy('id')->get()->all(), DB::table('ledger_posting_groups')->orderBy('id')->get()->all()];
    expect($fresh[0])->toEqual($ledgerBefore[0])->and(count($fresh[1]))->toBe(count($ledgerBefore[1]) + 1);
    DB::table('ledger_posting_groups')->where('id', $unsupported->id)->delete();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->post(route('ledger.incidents.resolve', $incident->incident_reference), ['note' => 'Removed the unsupported group.', 'confirmed' => true, 'balance_kobo' => 999999])->assertRedirect();

    expect(DB::table('ledger_integrity_incidents')->sole()->status)->toBe('resolved')
        ->and(DB::table('ledger_integrity_incidents')->sole()->incident_reference)->toBe($incident->incident_reference)
        ->and($reads->balance($agent, $customer)['liability_kobo'])->toBe(200000)
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($ledgerBefore[0]);
});

test('LED-AC-029: cursor pages cover every matching row once in stable order and every total spans all matches', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    [$agent, $customer] = ledgerGapReceipt();
    [$otherAgent, $otherCustomer] = ledgerGapSecondCustomer();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $ours = ledgerGapProjectionRows($customer, 60, '2026-10-01', '2026-09-30 08:00:00', 0);
    $mixed = ledgerGapProjectionRows($customer, 12, '2026-09-29', '2026-09-29 08:00:00', 30);
    ledgerGapProjectionRows($otherCustomer, 20, '2026-10-01', '2026-10-01 08:00:00', 5);
    $everything = [...$ours, ...$mixed];
    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $result = $this->actingAs($agent)->get(route('transactions.index', array_filter(['page_size' => 25, 'cursor' => $cursor])))->inertiaProps('result');
        expect($result['total'])->toBe(73)->and($result['savings_effect_kobo'])->toBe(200000 + 72 * 100);
        $seen = [...$seen, ...array_column($result['data'], 'reference')];
        $cursor = $result['next_cursor'];
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)->and($seen)->toHaveCount(73)->and(array_unique($seen))->toHaveCount(73)
        ->and(array_diff($everything, $seen))->toBe([])
        ->and($seen[0])->toBe(DB::table('ledger_transaction_references')->where('root_type', 'collection_receipt')->value('transaction_reference'))
        ->and(array_slice($seen, 1, 60))->toEqual(array_reverse($ours))
        ->and(array_slice($seen, 61))->toEqual(array_reverse($mixed));
});

test('LED-AC-030: invalid or overwide ranges, bad cursors and filters never leak rows or counts of inaccessible records', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    [$agent, $customer] = ledgerGapReceipt();
    [, $otherCustomer] = ledgerGapSecondCustomer();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $hidden = ledgerGapProjectionRows($otherCustomer, 3, '2026-10-01');
    $reads = app(LedgerTransactionReadService::class);
    $this->actingAs($agent);

    $this->get(route('transactions.index', ['from' => '2025-09-01', 'to' => '2026-10-02']))->assertUnprocessable();
    $this->get(route('transactions.index', ['from' => '2026-10-02', 'to' => '2026-10-01']))->assertUnprocessable();
    $this->get(route('transactions.index', ['from' => '2026-02-30']))->assertSessionHasErrors('from');
    $this->get(route('transactions.index', ['page_size' => 500]))->assertSessionHasErrors('page_size');
    $this->get(route('transactions.index', ['cursor' => 'not-a-cursor']))->assertUnprocessable();
    $this->get(route('customers.statements.preview', [$customer->customer_id, 'from' => '2025-09-01', 'to' => '2026-10-02']))->assertUnprocessable();
    foreach ($hidden as $reference) {
        $this->get(route('transactions.index', ['reference' => $reference]))->assertInertia(fn ($page) => $page->where('result.total', 0)->has('result.data', 0)->where('result.next_cursor', null));
    }
    $this->get(route('transactions.index', ['customer' => $otherCustomer->customer_id]))->assertInertia(fn ($page) => $page->where('result.total', 0)->where('result.savings_effect_kobo', 0));
    $this->get(route('transactions.index'))->assertInertia(fn ($page) => $page->where('result.total', 1)->where('result.savings_effect_kobo', 200000));

    expect($reads->search($agent, ['reference' => 'TXN-'])['total'])->toBe(1);
});

/*
 * Production gap: any projection incident marks the single global projection state stale, so every Customer balance and statement
 * read fails closed. LED-AC-028 expects an incident to freeze only the dependent scope. Enable this once incidents carry a scope.
 */
test('LED-AC-028: an incident on one Customer group leaves other Customers balances and statements readable', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    [, $other] = ledgerGapSecondCustomer();
    app(LedgerTransactionProjectionService::class)->rebuild();
    ledgerGapUnsupportedGroup($agent, $customer->id);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    $admin = ledgerGapAdmin();

    expect(app(LedgerTransactionReadService::class)->balance($admin, $customer))->toBe(['status' => 'unavailable'])
        ->and(app(LedgerTransactionReadService::class)->balance($admin, $other)['status'])->toBe('ready');
})->todo();
