<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\FeeObligation;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CollectionReplacementService;
use App\Services\CustomerReassignmentService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportReadService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

require_once __DIR__.'/../NoncashCollectionFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    LedgerAccount::query()->whereNotNull('effective_at')->update(['effective_at' => now()->subDay()]);
});

test('verified noncash savings uses its closed custody account without creating another physical receipt', function (string $method, string $custody): void {
    [, $customer, , , , , , $payload] = noncashFixture($this, $method, $custody);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $asset = LedgerAccount::query()->where('code', $custody)->sole();
    $debit = DB::table('ledger_entries')->where('ledger_account_id', $asset->id)->sole();
    expect((int) $debit->amount_kobo)->toBe(200000)->and($debit->side)->toBe('debit')
        ->and($debit->agent_profile_id)->toBe($custody === 'agent_receivable_ngn' ? $receipt->recording_agent_profile_id : null);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $entries = DB::table('ledger_entries')->where('ledger_posting_group_id', $receipt->savings_posting_group_id)->get();
    expect((int) $entries->where('side', 'debit')->sum('amount_kobo'))->toBe(200000)
        ->and((int) $entries->where('side', 'credit')->sum('amount_kobo'))->toBe(200000);
    expect($receipt->method)->toBe($method)->and($receipt->batch->custody_account_code)->toBe($custody);
    if ($custody !== 'agent_receivable_ngn') {
        expect(DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.code', 'agent_receivable_ngn')->count())->toBe(0);
    }
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('customer_profile_id', $customer->id)->where('type', 'contribution')->count())->toBe(1);
    $retry = [...$payload, 'attempt_reference' => (string) Str::uuid()];
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $retry)->assertConflict();
    $this->assertDatabaseCount('collection_receipts', 1);
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn'], ['other', 'agent_receivable_ngn']]);

test('repeated noncash references retain one investigated proof and one receipt across method custody paths', function (string $method, string $custody): void {
    [$agent, $customer, $assignment, $plan, $date, $admin, $proof, $payload] = noncashFixture($this, $method, $custody);
    $duplicate = ['evidence_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'collection_method_version_id' => $payload['collection_method_version_id'],
        'method_reference' => '  test-payment-1  ', 'received_date' => $date, 'amount_ngn' => '2000.00',
        'source_attestation' => 'Possible repeated confirmation supplied for investigation.'];
    $before = noncashHandoverFinancialRows();
    $files = Storage::disk('collection_evidence')->allFiles();
    $this->postJson(route('customers.collection-evidence.store', $customer), [...$duplicate,
        'files' => [UploadedFile::fake()->image('duplicate.png')]])->assertConflict();
    expect(noncashHandoverFinancialRows())->toEqual($before)
        ->and(Storage::disk('collection_evidence')->allFiles())->toBe($files);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $originalReview = DB::table('collection_evidence_reviews')->sole();
    $this->actingAs($admin)->withSession($session)->getJson(route('collection-evidence.show', $proof))
        ->assertOk()->assertJsonPath('method_reference', 'TEST-PAYMENT-1')->assertJsonPath('consumed', false);
    $this->postJson(route('collection-evidence.review', $proof), [
        'operation_reference' => (string) Str::uuid(), 'expected_version' => 1, 'outcome' => 'rejected',
        'reason' => 'Hold original confirmation while independently investigating its repetition.',
    ])->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('review_version', 2);
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertConflict();
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $review = ['operation_reference' => (string) Str::uuid(), 'expected_version' => 2, 'outcome' => 'verified',
        'reason' => 'Independent destination investigation confirms one payment; repeated document is not another receipt.',
        'verified_reference' => 'TEST-PAYMENT-1', 'verified_amount_ngn' => '2000.00', 'verified_destination_key' => 'test-destination'];
    $this->actingAs($admin)->withSession($session)->postJson(route('collection-evidence.review', $proof), $review)
        ->assertOk()->assertJsonPath('status', 'verified')->assertJsonPath('review_version', 3);
    expect(DB::table('collection_evidence_reviews')->where('id', $originalReview->id)->sole())->toEqual($originalReview);
    expect(DB::table('collection_evidence_reviews')->orderBy('version')->pluck('outcome')->all())->toBe(['verified', 'rejected', 'verified']);
    $this->actingAs($agent);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    expect($receipt->method)->toBe($method)->and($receipt->custody_account_code)->toBe($custody)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $posted = noncashHandoverFinancialRows();
    $this->postJson(route('customers.collection-evidence.store', $customer), [...$duplicate,
        'evidence_reference' => (string) Str::uuid(), 'files' => [UploadedFile::fake()->image('later-duplicate.png')]])->assertConflict();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), [...$payload,
        'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->fresh()->version])->assertConflict();
    $this->actingAs($admin)->withSession($session)->postJson(route('collection-evidence.review', $proof), [...$review,
        'operation_reference' => (string) Str::uuid(), 'expected_version' => 3])->assertConflict();
    $this->getJson(route('collection-evidence.show', $proof))->assertOk()
        ->assertJsonPath('consumed', true)->assertJsonPath('review_version', 3);
    expect(noncashHandoverFinancialRows())->toEqual($posted)
        ->and(Storage::disk('collection_evidence')->allFiles())->toBe($files);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('collection_payment_evidence', 1);
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn'], ['other', 'agent_receivable_ngn']]);

test('fee receipt reports become unavailable when stored custody disagrees with its immutable posting', function (): void {
    [, $customer, , , $date, , , $payload] = noncashFixture($this, feeKobo: 50000);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    DB::table('collection_receipts')->where('id', $receipt->id)->update(['custody_account_code' => 'payment_clearing_ngn']);
    $report = app(ReportReadService::class)->read($customer->user, 'fees', [
        'from' => $date, 'to' => $date, 'page_size' => 25, 'group' => '',
    ]);
    expect($report['sections']['external_receipts']['status'])->toBe('Unavailable');
    expect($report['sections']['external_receipts']['rows'])->toBe([]);
});

test('noncash mixed and fee-only tender retains separate fee income and savings', function (string $savings): void {
    [, $customer, , , , , , $payload] = noncashFixture($this, feeKobo: 50000, savings: $savings);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $expectedSavings = $savings === '0.00' ? 0 : 200000;
    expect($receipt->savings_amount_kobo)->toBe($expectedSavings)->and($receipt->fee_amount_kobo)->toBe(50000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessBank))->toBe($expectedSavings + 50000)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(50000)
        ->and(FeeObligation::query()->sole()->settledAmountKobo())->toBe(50000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($expectedSavings);
    expect(DB::table('ledger_entries')->whereNotNull('agent_profile_id')->count())->toBe(0);
    app(LedgerTransactionProjectionService::class)->rebuild();
})->with(['2000.00', '0.00']);

test('noncash preview binds exact tender date method and customer and rejects cash proof reuse', function (): void {
    [, $customer, , , $date, , , $payload] = noncashFixture($this);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), [...$payload, 'savings_ngn' => '1999.99'])->assertConflict();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), [...$payload, 'received_date' => now()->subDay()->toDateString(), 'late_reason' => 'Late payment recording'])->assertConflict();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), [...$payload, 'method' => 'pos'])->assertConflict();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), [...$payload, 'method' => 'cash'])->assertConflict();
    $this->assertDatabaseCount('collection_receipts', 0);
});

test('changed review mapping or runtime enablement rejects an already prepared receipt', function (string $change): void {
    [, $customer, , , , $admin, $proof, $payload] = noncashFixture($this);
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    if ($change === 'review') {
        $this->actingAs($admin)->postJson(route('collection-evidence.review', $proof), [
            'operation_reference' => (string) Str::uuid(), 'expected_version' => 1, 'outcome' => 'rejected',
            'reason' => 'Independent receipt evidence was withdrawn.',
        ])->assertOk();
        $this->actingAs(User::findOrFail(DB::table('collection_payment_evidence')->value('recorded_by_user_id')));
    } elseif ($change === 'mapping') {
        LedgerAccount::query()->where('code', 'business_bank_ngn')->update(['version' => 2]);
    } else {
        config()->set('collections.noncash_enabled', false);
    }
    $this->postJson(route('customers.collections.store', $customer->customer_id), [...$payload, 'preview_fingerprint' => $preview['preview_fingerprint']])->assertConflict();
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
})->with(['review', 'mapping', 'disabled']);

test('cash and noncash receipts use separate method batches on the same date', function (): void {
    [, $customer, , $plan, , , , $payload] = noncashFixture($this);
    $noncash = postNoncashReceipt($this, $customer, $payload);
    $cash = [...$payload, 'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->fresh()->version, 'method' => 'cash'];
    unset($cash['collection_method_version_id'], $cash['evidence_reference']);
    $receipt = postNoncashReceipt($this, $customer, $cash);
    expect($receipt->collection_batch_id)->not->toBe($noncash->collection_batch_id)
        ->and($receipt->batch->method_identity)->toBe('cash')->and($noncash->batch->method_identity)->toStartWith('method-');
    $this->assertDatabaseCount('collection_batches', 2);
});

test('a receipt replay remains one posting after evidence is consumed', function (): void {
    [, $customer, , , , , , $payload] = noncashFixture($this);
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $data = [...$payload, 'preview_fingerprint' => $preview['preview_fingerprint']];
    $this->post(route('customers.collections.store', $customer->customer_id), $data)->assertRedirect();
    $this->post(route('customers.collections.store', $customer->customer_id), $data)->assertRedirect();
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('collection_notification_intents', 2);
});

test('a corrected noncash receipt and its replacement retain original bank custody and one proof claim', function (): void {
    [$agent, $customer, $assignment, $plan, $date, , , $payload] = noncashFixture($this);
    $original = postNoncashReceipt($this, $customer, $payload);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($original->savings_posting_group_id))->fresh();
    $data = collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00');
    $this->actingAs($agent);
    $quote = app(CollectionReplacementService::class)->preview($agent, $reversal, $data);
    $data['preview_fingerprint'] = $quote['preview_fingerprint'];
    $data['replacement_fingerprint'] = $quote['replacement_fingerprint'];
    $replacement = app(CollectionReplacementService::class)->record($agent, $reversal, $data);
    expect($replacement->method)->toBe('transfer')->and($replacement->collection_payment_evidence_id)->toBeNull()
        ->and($replacement->collection_evidence_review_id)->toBe($original->collection_evidence_review_id)
        ->and($replacement->collection_batch_id)->toBe($original->collection_batch_id)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessBank))->toBe(200000);
    expect((int) $original->batch->receipts()->sum('tender_amount_kobo'))->toBe(200000);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('bank and POS batches reject cash remittances and bank batches reconcile against verified receipt postings', function (string $method, string $custody): void {
    [, $customer, , , $date, $admin, , $payload] = noncashFixture($this, $method, $custody);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $batch = $receipt->batch;
    $batch->update(['status' => 'ready_for_review']);
    $this->actingAs($admin)->postJson(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'FALSE-CASH-HANDOFF', 'amount_ngn' => '2000.00', 'handoff_date' => $date,
        'receiving_location' => 'Test cash desk', 'source_attestation' => 'This must not create another cash asset.',
        'batch_version' => $batch->version, 'confirmed' => true,
    ])->assertConflict();
    $response = $this->postJson(route('collection-batches.review', $batch), ['batch_version' => $batch->version, 'reason' => 'Independent batch evidence review.', 'confirmed' => true]);
    if ($method === 'transfer') {
        $response->assertRedirect();
        expect($batch->fresh()->status)->toBe('reconciled');
        $this->assertDatabaseHas('collection_batch_reviews', ['collection_batch_id' => $batch->id, 'expected_kobo' => 200000, 'remitted_kobo' => 200000, 'outstanding_kobo' => 0]);
    } else {
        $response->assertConflict();
        expect($batch->fresh()->status)->toBe('ready_for_review');
        $this->assertDatabaseCount('collection_batch_reviews', 0);
    }
    $this->assertDatabaseCount('cash_remittances', 0);
    $this->assertDatabaseCount('collection_exceptions', 0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe(0);
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn']]);

test('unconsumed verified evidence blocks Customer archival Agent offboarding and month closure until rejected', function (): void {
    [$agent, $customer, , , $date, $admin, $proof] = noncashFixture($this);
    $read = app(CollectionReadService::class);
    expect($read->archivalStatus($customer))->toBe('blocked')->and($read->agentOffboardingStatus($agent->agentProfile))->toBe('blocked');
    $admin->givePermissionTo(AdminPermission::FinancialPeriodsManage);
    $this->travelTo(now()->timezone('Africa/Lagos')->startOfMonth()->addMonth()->addHour());
    $this->actingAs($admin)->withSession([
        'auth.login_at' => now()->timestamp,
        'auth.last_active_at' => now()->timestamp,
        'auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp,
    ]);
    $month = substr($date, 0, 7);
    $period = FinancialPeriod::query()->whereDate('month', $month.'-01')->sole();
    $this->postJson(route('admin.financial-periods.close', $month), ['version' => $period->version, 'reason' => 'Review closure with unconsumed payment.'])->assertConflict();
    $this->postJson(route('collection-evidence.review', $proof), [
        'operation_reference' => (string) Str::uuid(), 'expected_version' => 1, 'outcome' => 'rejected',
        'reason' => 'Claim rejected after independent destination investigation.',
    ])->assertOk();
    expect($read->archivalStatus($customer))->toBe('passed')->and($read->agentOffboardingStatus($agent->agentProfile))->toBe('passed');
    $this->postJson(route('admin.financial-periods.close', $month), ['version' => $period->version, 'reason' => 'All payment claims are resolved.'])->assertRedirect();
    $this->postJson(route('collection-evidence.review', $proof), [
        'operation_reference' => (string) Str::uuid(), 'expected_version' => 2, 'outcome' => 'verified',
        'reason' => 'A closed month cannot accept new verified money.',
        'verified_reference' => 'TEST-PAYMENT-1', 'verified_amount_ngn' => '2000.00', 'verified_destination_key' => 'test-destination',
    ])->assertConflict();
    $this->assertDatabaseCount('collection_evidence_reviews', 2);
});

test('a current Agent can post prior verified evidence after handover while the original recording Agent and batch custody stay retained', function (): void {
    [$agent, $customer, , $plan, , $admin, $proof, $payload] = noncashFixture($this);
    $successor = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $successor->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer, $profile->id);
    $handover->execute($admin, $customer, [
        'attempt_reference' => (string) Str::uuid(), 'version' => $quote['version'], 'assignment_version' => $quote['assignment_version'],
        'target_agent_id' => $profile->id, 'preview_token' => $quote['preview_token'], 'confirmed' => true,
        'reason' => 'Service responsibility handover.', 'customer_explanation' => 'Your newly assigned Agent will record this verified payment.',
    ]);
    $this->actingAs($agent)->getJson(route('collection-evidence.show', $proof))->assertForbidden();
    $this->actingAs($successor)->getJson(route('collection-evidence.show', $proof))->assertOk();
    $current = $customer->fresh();
    $payload['customer_version'] = $current->version;
    $payload['assignment_version'] = $current->currentAssignment->version;
    $payload['plan_version'] = $plan->fresh()->version;
    $receipt = postNoncashReceipt($this, $current, $payload);
    expect($receipt->recording_agent_profile_id)->toBe($agent->agentProfile->id)
        ->and($receipt->recorded_by_user_id)->toBe($successor->id)
        ->and($receipt->batch->agent_profile_id)->toBe($agent->agentProfile->id);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessBank))->toBe(200000);
});

test('noncash reports separate physical cash from total tender and verify external fees against actual custody', function (string $method, string $custody): void {
    [, $customer, , , $date, , , $payload] = noncashFixture($this, $method, $custody, 50000);
    postNoncashReceipt($this, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $filters = ['from' => $date, 'to' => $date, 'page_size' => 25, 'group' => ''];
    $reports = app(ReportReadService::class);
    $contributions = $reports->read($customer->user, 'contributions', $filters)['sections']['primary'];
    $metrics = collect($contributions['metrics'])->keyBy('code');
    expect($contributions['rows'])->toHaveCount(1);
    expect($metrics['received_savings']['value'])->toBe(200000)
        ->and($metrics['received_fees']['value'])->toBe(50000)
        ->and($metrics['cash_received']['value'])->toBe(0)
        ->and($metrics['total_received']['value'])->toBe(250000);
    expect($contributions['rows'][0]['method'])->toBe('Verified '.$method);
    $fees = $reports->read($customer->user, 'fees', $filters)['sections']['external_receipts'];
    expect($fees['rows'])->toHaveCount(1);
    expect(collect($fees['metrics'])->firstWhere('code', 'external_fees_received')['value'])->toBe(50000);
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn'], ['other', 'agent_receivable_ngn']]);

test('daily totals and bank batch reports preserve method custody without Agent cash debt', function (string $method, string $custody): void {
    [$agent, $customer, , , $date, $admin, , $payload] = noncashFixture($this, $method, $custody, 50000);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->get(route('collections.index', ['date' => $date]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('totals.tender_kobo', 250000)->where('totals.cash_kobo', 0)
        ->where('totals.bank_kobo', $custody === 'business_bank_ngn' ? 250000 : 0)
        ->where('totals.clearing_kobo', $custody === 'payment_clearing_ngn' ? 250000 : 0)
        ->where('totals.other_kobo', $custody === 'agent_receivable_ngn' ? 250000 : 0)
        ->where('receipts.data.0.method', 'Verified '.$method));
    $report = app(ReportReadService::class)->read($agent, 'reconciliation', ['page_size' => 25, 'group' => '']);
    $section = $report['sections']['batch_reconciliation'];
    expect($section['status'])->not->toBe('Unavailable');
    $metrics = collect($section['metrics'])->keyBy('code');
    expect($metrics['unremitted']['value'])->toBe($custody === 'agent_receivable_ngn' ? 250000 : 0)
        ->and($metrics['bank_received']['value'])->toBe($custody === 'business_bank_ngn' ? 250000 : 0)
        ->and($metrics['pending_settlement']['value'])->toBe($custody === 'payment_clearing_ngn' ? 250000 : 0);
    if ($custody === 'business_bank_ngn') {
        $receipt->batch->update(['status' => 'ready_for_review']);
        $this->actingAs($admin)->postJson(route('collection-batches.review', $receipt->batch), [
            'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'Bank custody confirmed.', 'confirmed' => true,
        ])->assertRedirect();
        expect(app(ReportReadService::class)->read($agent, 'reconciliation', ['page_size' => 25, 'group' => ''])['sections']['batch_reconciliation']['status'])->not->toBe('Unavailable');
    }
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn'], ['other', 'agent_receivable_ngn']]);

/** @return array<string, mixed> */
function noncashHandoverFinancialRows(): array
{
    $rows = [];
    foreach (['collection_payment_evidence', 'collection_evidence_files', 'collection_evidence_reviews',
        'collection_receipts', 'collection_allocations', 'collection_fee_components', 'collection_batches',
        'cash_remittances', 'collection_settlements', 'collection_settlement_files', 'ledger_posting_groups',
        'ledger_entries', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'thrift_plans',
        'plan_terms_revisions', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('noncash handover removes former Customer proof access while preserving masked original settlement responsibility', function (string $method, string $custody): void {
    [$agent, $customer, , $plan, , $admin, $proof, $payload] = noncashFixture($this, $method, $custody, 50000);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $file = DB::table('collection_evidence_files')->sole();
    $fileRoute = ['reference' => $proof, 'file' => $file->id];
    $signed = $this->getJson(route('collection-evidence.files.link', $fileRoute))->assertOk()->json('url');
    $this->get($signed)->assertOk();
    $successor = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $successor->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $baseline = noncashHandoverFinancialRows();
    $owner = app(CustomerReassignmentService::class);
    $preview = $owner->preview($admin, $customer, $profile->id);
    $owner->execute($admin, $customer, [
        'attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'target_agent_id' => $profile->id, 'preview_token' => $preview['preview_token'], 'confirmed' => true,
        'reason' => 'Reviewed service responsibility transfer.', 'customer_explanation' => 'Your service contact changed.',
    ]);
    expect(noncashHandoverFinancialRows())->toEqual($baseline);
    $this->actingAs($agent)->get(route('collection-evidence.index', ['status' => 'consumed']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('evidence.data', 0));
    $this->get(route('collection-evidence.view', $proof))->assertForbidden();
    $this->getJson(route('collection-evidence.show', $proof))->assertForbidden();
    $this->getJson(route('collection-evidence.files.link', $fileRoute))->assertForbidden();
    $this->get($signed)->assertForbidden();
    $this->get(route('collections.show', $receipt))->assertNotFound();
    $this->get(route('plans.card', $plan))->assertNotFound();
    $this->get(route('collection-batches.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('batches.data', 1)->where('batches.data.0.id', $receipt->collection_batch_id));
    $masked = $this->get(route('collection-batches.show', $receipt->batch))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('batch.expected_kobo', 250000)
            ->where('batch.savings_kobo', 200000)->where('batch.fees_kobo', 50000)
            ->where('batch.remitted_kobo', $custody === 'business_bank_ngn' ? 250000 : 0)
            ->where('batch.outstanding_kobo', $custody === 'business_bank_ngn' ? 0 : 250000)
            ->where('batch.settlement_pending', $custody === 'payment_clearing_ngn')
            ->where('receipts', null)->where('settlements', null)->where('resolution_records', null)
            ->where('settlement_banks', [])->where('can_manage', false));
    expect($masked->getContent())->not->toContain($proof)->not->toContain($receipt->receipt_reference)
        ->not->toContain($customer->customer_id)->not->toContain($file->storage_path)->not->toContain('TEST-PAYMENT-1');
    $this->postJson(route('collection-batches.remittances.store', $receipt->batch), [
        'handoff_reference' => 'HANDOVER-DENIED', 'amount_ngn' => '2500.00',
        'handoff_date' => $receipt->received_date, 'receiving_location' => 'Reviewed receiving office',
        'source_attestation' => 'A former Agent cannot authorize this handoff.',
        'batch_version' => $receipt->batch->version, 'confirmed' => true,
    ])->assertForbidden();
    $this->postJson(route('collection-batches.settlements.store', $receipt->batch), [])->assertForbidden();
    $this->actingAs($successor)->get(route('collection-evidence.index', ['status' => 'consumed']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('evidence.data', 1));
    $this->get(route('collection-evidence.view', $proof))->assertOk();
    $newSigned = $this->getJson(route('collection-evidence.files.link', $fileRoute))->assertOk()->json('url');
    $this->get($newSigned)->assertOk();
    $this->get(route('collections.show', $receipt))->assertOk();
    $this->get(route('plans.card', $plan))->assertOk();
    $this->get(route('collection-batches.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('batches.data', 0));
    $this->get(route('collection-batches.show', $receipt->batch))->assertForbidden();
    expect($receipt->fresh()->recording_agent_profile_id)->toBe($agent->agentProfile->id)
        ->and($receipt->fresh()->recorded_by_user_id)->toBe($agent->id)
        ->and($receipt->batch->fresh()->agent_profile_id)->toBe($agent->agentProfile->id)
        ->and(noncashHandoverFinancialRows())->toEqual($baseline);
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn'], ['other', 'agent_receivable_ngn']]);

test('pending or rejected noncash claims cannot preview or post a receipt despite configured custody', function (string $method, string $custody, string $state): void {
    [$agent, $customer, $assignment, , $date, $admin, , $payload] = noncashFixture($this, $method, $custody, feeKobo: 50000);
    $proof = $this->postJson(route('customers.collection-evidence.store', $customer), [
        'evidence_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'collection_method_version_id' => $payload['collection_method_version_id'],
        'method_reference' => 'UNCONFIRMED-PAYMENT-2', 'received_date' => $date, 'amount_ngn' => '2500.00',
        'source_attestation' => 'The Customer supplied a claim that still requires independent verification.',
        'files' => [UploadedFile::fake()->image('claimed-payment.png')],
    ])->assertCreated()->assertJsonPath('status', 'pending')->json('evidence_reference');
    if ($state === 'rejected') {
        $this->actingAs($admin)->postJson(route('collection-evidence.review', $proof), [
            'operation_reference' => (string) Str::uuid(), 'expected_version' => 0, 'outcome' => 'rejected',
            'reason' => 'Independent destination investigation found no matching payment.',
        ])->assertOk();
    }
    $baseline = noncashHandoverFinancialRows();
    $data = [...$payload, 'evidence_reference' => $proof];
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $data)->assertConflict();
    $this->postJson(route('customers.collections.store', $customer->customer_id), [...$data, 'preview_fingerprint' => str_repeat('a', 64)])->assertConflict();
    expect(noncashHandoverFinancialRows())->toEqual($baseline);
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(50000);
    $this->assertDatabaseCount('ledger_entries', 0);
})->with([
    ['transfer', 'business_bank_ngn', 'pending'], ['transfer', 'business_bank_ngn', 'rejected'],
    ['pos', 'payment_clearing_ngn', 'pending'], ['pos', 'payment_clearing_ngn', 'rejected'],
    ['other', 'agent_receivable_ngn', 'pending'], ['other', 'agent_receivable_ngn', 'rejected'],
]);
