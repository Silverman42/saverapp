<?php

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountCode;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\FeeRefund;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalNotificationIntent;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionNoMoneyCorrection;
use App\Services\CollectionReplacementService;
use App\Services\FeeConcessionPosition;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\ReversalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../CollectionNoMoneyFixtures.php';

test('fully conceded fee-only correction preserves a paid or unpaid refund without posting money', function (bool $paid): void {
    [$agent, $customer, $assignment, $plan, $date, $fee, $receipt, $original, $refund, $admin, $request, $decision] = noMoneyReceiptFixture($this, $paid);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $entryCount = LedgerEntry::query()->count();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $before = app(LedgerTransactionReadService::class)->search($customer->user, ['page_size' => 1]);
    expect($before['next_cursor'])->not->toBeNull();

    $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $request), $decision)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('reversals.approve', $request), $decision)->assertRedirect()->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->state)->toBe('approved_no_money')->and($request->compensation_posting_group_id)->toBeNull()
        ->and($request->posted_original_posting_group_id)->toBe($original->id);
    $proof = app(CollectionNoMoneyCorrection::class)->assertOutcome($request);
    expect($proof->facts['controlled_kobo'])->toBe(0);
    expect(fn () => app(LedgerTransactionReadService::class)->search($customer->user, ['page_size' => 1, 'cursor' => $before['next_cursor']]))
        ->toThrow(UnprocessableEntityHttpException::class, 'Transaction cursor expired.');
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect(LedgerEntry::query()->count())->toBe($entryCount);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(50000);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee))->toBe(['savings_kobo' => 0, 'external_kobo' => 0]);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe($paid ? 0 : 50000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe($paid ? 0 : 50000);
    expect($refund->fresh()->ledger_posting_group_id)->toBe($refund->ledger_posting_group_id)->and($refund->fresh()->compensation_posting_group_id)->toBeNull();
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('financial_workflow_supplements', 1);
    $event = $request->events()->where('event_type', 'approved_no_money')->sole();
    expect(ReversalNotificationIntent::query()->where('reversal_event_id', $event->id)->where('audience_type', 'subject_customer')->count())->toBe(2);
    $this->actingAs($agent)->get(route('reversals.show', $request))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('reversals/Show')->where('reversal.state', 'approved_no_money')->where('can_replace', false));
    $this->get(route('reversals.replacement', $request))->assertConflict();
    expect(fn () => app(ReversalService::class)->preview($agent, $original))->toThrow(ConflictHttpException::class);
    $replacement = [...collectionPayload($customer, $assignment, $plan, $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    expect(fn () => app(CollectionReplacementService::class)->preview($agent, $request, $replacement))->toThrow(ConflictHttpException::class);
    expect(app(ReversalService::class)->archivalStatus($customer))->toBe('passed');
    expect(app(ReversalService::class)->agentOffboardingStatus($agent->agentProfile))->toBe('passed');
    $this->actingAs($customer->user)->get(route('reversals.show', $request))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reversal.customer_explanation', $request->customer_explanation)->where('reversal.internal_reason', null));
    app(LedgerTransactionProjectionService::class)->rebuild();
    $history = app(LedgerTransactionReadService::class)->search($customer->user, ['type' => 'reversal']);
    expect($history['status'])->toBe('ready')->and($history['total'])->toBe(1)
        ->and($history['data'][0]['status'])->toBe('approved_no_money')->and($history['data'][0]['posting_group_count'])->toBe(0)
        ->and($history['data'][0]['savings_effect_kobo'])->toBe(0)->and($history['data'][0]['fee_amount_kobo'])->toBe(0);
})->with(['unpaid entitlement' => false, 'paid refund' => true]);

test('a no-money receipt correction does not require an unapplied-funds posting destination', function (): void {
    [, , , , , , , , , $admin, $request] = noMoneyReceiptFixture($this);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds)->update(['mapping_status' => 'unconfigured']);
    $review = app(ReversalService::class)->reviewPreview($admin, $request);
    $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $request), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $request->version, 'preview_fingerprint' => $review['preview_fingerprint'],
        'decision_reason' => 'No controlled funds remain and no destination is required.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($request->fresh()->state)->toBe('approved_no_money');
});

test('late audit failure rolls back a no-money receipt correction and its concession consumption', function (): void {
    [, , , , , $fee, , , , $admin, $request, $decision] = noMoneyReceiptFixture($this);
    Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
        if ($event->event_type === 'reversal.approved_no_money') {
            throw new RuntimeException('No-money approval audit unavailable.');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $request), $decision))
        ->toThrow(RuntimeException::class, 'No-money approval audit unavailable.');
    expect($request->fresh()->state)->toBe('pending_review');
    expect($fee->fresh()->settledAmountKobo())->toBe(50000);
    expect(app(FeeConcessionPosition::class)->read($fee)['external_kobo'])->toBe(50000);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseMissing('fee_obligation_entries', ['source_type' => 'receipt_no_money']);
});

test('corrupted no-money proof prevents fee-source reuse and successful projection reconstruction', function (): void {
    [, , , , , $fee, , , , $admin, $request, $decision] = noMoneyReceiptFixture($this);
    $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $request), $decision)->assertRedirect();
    $proof = FinancialWorkflowSupplement::query()->sole();
    $facts = $proof->facts;
    $facts['controlled_kobo'] = 1;
    DB::table('financial_workflow_supplements')->where('id', $proof->id)->update(['facts' => json_encode($facts, JSON_THROW_ON_ERROR)]);

    expect(fn () => app(FeeConcessionPosition::class)->retainedSources($fee))->toThrow(ConflictHttpException::class);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(ConflictHttpException::class);
    expect(app(LedgerTransactionReadService::class)->state()['status'])->not->toBe('ready');
});

test('rejected or cancelled fully conceded receipt requests leave their settlement and concession intact', function (string $action): void {
    [$agent, , , , , $fee, , , , $admin, $request, $decision] = noMoneyReceiptFixture($this);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $actor = $action === 'reject' ? $admin : $agent;
    $data = [...$decision, 'attempt_reference' => (string) Str::uuid()];
    $this->actingAs($actor)->withSession(cashSession())->post(route('reversals.'.$action, $request), $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('reversals.'.$action, $request), $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($request->fresh()->state)->toBe($action === 'reject' ? 'rejected' : 'cancelled');
    expect($fee->fresh()->settledAmountKobo())->toBe(50000);
    expect(app(FeeConcessionPosition::class)->read($fee)['external_kobo'])->toBe(50000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseMissing('fee_obligation_entries', ['source_type' => 'receipt_no_money']);
    expect(app(ReversalService::class)->archivalStatus($fee->customerProfile))->toBe('passed');
})->with(['reject', 'cancel']);

test('a later full concession expires the retained-tender approval and requires fresh no-money review', function (): void {
    [, , , , , $fee, , , , $admin, $request, $decision] = noMoneyReceiptFixture($this, concessionKobo: 25000);
    $snapshot = $request->getAttribute('dependency_snapshot');
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'external', 'amount_ngn' => '250.00',
        'reason' => 'The remaining independently owed concession was authorized.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->post(route('reversals.approve', $request), $decision)->assertConflict();
    expect($request->fresh()->state)->toBe('pending_review');
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $review = app(ReversalService::class)->reviewPreview($admin, $request->fresh());
    expect($review['summary']['controlled_kobo'])->toBe(0)->and($review['summary']['no_money'])->toBeTrue();
    $this->post(route('reversals.approve', $request), [...$decision, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $review['preview_fingerprint']])->assertRedirect()->assertSessionHasNoErrors();
    expect($request->fresh()->state)->toBe('approved_no_money');
    expect($request->fresh()->getAttribute('dependency_snapshot'))->toBe($snapshot);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee)['external_kobo'])->toBe(0);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    $this->assertDatabaseCount('fee_refunds', 2);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(50000);
});

test('fully conceded noncash receipt correction releases every component once and preserves physical custody', function (string $method, string $custody, bool $multiple): void {
    [, $customer, $admin, $receipt, $fees, $request, $decision, $date] = noncashNoMoneyReceiptFixture($this, $method, $custody, $multiple);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $entries = LedgerEntry::query()->count();
    $refunds = FeeRefund::query()->get()->toArray();
    $position = app(CollectionBatchPosition::class)->read($receipt->batch->fresh());
    $this->actingAs($admin)->post(route('reversals.approve', $request), $decision)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('reversals.approve', $request), $decision)->assertRedirect()->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->state)->toBe('approved_no_money');
    expect($request->compensation_posting_group_id)->toBeNull();
    $proof = app(CollectionNoMoneyCorrection::class)->assertOutcome($request);
    expect($proof->facts['gross_kobo'])->toBe(50000);
    expect($proof->facts['controlled_kobo'])->toBe(0);
    expect($proof->facts['fee_effects'])->toHaveCount($multiple ? 2 : 1);
    foreach ($fees as $fee) {
        expect($fee->fresh()->settledAmountKobo())->toBe(0);
        expect($fee->fresh()->outstandingAmountKobo())->toBe($fee->amount_kobo);
        expect($fee->entries()->where('entry_type', FeeObligationEntryType::SettlementReversal)->count())->toBe(1);
        expect(app(FeeConcessionPosition::class)->retainedSources($fee))->toBe(['savings_kobo' => 0, 'external_kobo' => 0]);
    }
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect(LedgerEntry::query()->count())->toBe($entries);
    expect(FeeRefund::query()->get()->toArray())->toBe($refunds);
    expect(CollectionReceipt::query()->count())->toBe(2);
    expect(app(CollectionBatchPosition::class)->read($receipt->batch->fresh()))->toBe($position);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(50000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(50000);
    $this->get(route('reversals.show', $request))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reversal.state', 'approved_no_money')->where('can_replace', false));
    if ($custody === 'payment_clearing_ngn') {
        $bank = $this->postJson(route('collection-methods.store'), [
            'publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 1,
            'label' => 'Approved settlement bank', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
            'destination_key' => 'no-money-settlement-bank', 'attachment_required' => true, 'reason' => 'Approved actual bank settlement destination.',
        ])->assertCreated()->json('method_version_id');
        $this->postJson(route('collection-batches.settlements.store', $receipt->batch), [
            'settlement_reference' => (string) Str::uuid(), 'batch_version' => $receipt->batch->fresh()->version,
            'bank_method_version_id' => $bank, 'bank_reference' => 'NO-MONEY-SETTLEMENT', 'settled_date' => $date,
            'amount_ngn' => '500.00', 'source_attestation' => 'Full original captured tender independently credited by the bank.',
            'reason' => 'No-money correction preserves the full physical capture requiring settlement.', 'confirmed' => true,
            'files' => [UploadedFile::fake()->image('settlement.png')],
        ])->assertCreated();
        expect(app(CollectionBatchPosition::class)->read($receipt->batch->fresh())['outstanding_kobo'])->toBe(0);
        expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::PaymentClearing))->toBe(0);
        expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessBank))->toBe(50000);
        expect(app(CollectionNoMoneyCorrection::class)->assertOutcome($request)->id)->toBe($proof->id);
        expect(FeeRefund::query()->get()->toArray())->toBe($refunds);
    }
})->with([
    'transfer fee' => ['transfer', 'business_bank_ngn', false],
    'transfer multiple fees' => ['transfer', 'business_bank_ngn', true],
    'POS multiple fees' => ['pos', 'payment_clearing_ngn', true],
    'Other bank multiple fees' => ['other', 'business_bank_ngn', true],
    'Other Agent multiple fees' => ['other', 'agent_receivable_ngn', true],
    'Other clearing multiple fees' => ['other', 'payment_clearing_ngn', true],
]);

test('a fully conceded fee-only replacement closes its own settlement without consuming ancestral relief twice', function (): void {
    [$agent, $customer, $assignment, $plan, $date, $fee, $originalReceipt, , , $admin, $first, $decision] = noMoneyReceiptFixture($this, concessionKobo: 20000);
    $this->actingAs($admin)->post(route('reversals.approve', $first), [...$decision,
        'decision_reason' => 'Original twenty-thousand-kobo concession leaves exactly thirty-thousand controlled funds.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $first->refresh();
    expect($first->state)->toBe('approved_posted');
    $replacementData = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '300.00']]];
    $replacements = app(CollectionReplacementService::class);
    $quote = $replacements->preview($agent, $first, $replacementData);
    $replacement = $replacements->record($agent, $first, [...$replacementData, 'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    expect($replacement->tender_amount_kobo)->toBe(30000);
    $this->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'external', 'amount_ngn' => '300.00',
        'reason' => 'Independently concede the remaining valid replacement fee settlement.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $original = LedgerPostingGroup::query()->where('source_type', 'collection_receipt')->where('source_id', $replacement->id.'-'.$fee->id)->sole();
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $second = $service->submit($agent, $original, [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Correct the replacement after its remaining fee was independently conceded.',
        'customer_explanation' => 'Both independently granted refunds remain owed. No additional money moves.',
        'evidence_text' => 'Original correction, controlled replacement and later concession verified.', 'confirmed' => true,
    ]);
    $review = $service->reviewPreview($admin, $second);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $refunds = FeeRefund::query()->get()->toArray();
    $custody = app(CollectionBatchPosition::class)->read($originalReceipt->batch->fresh());
    $approval = ['attempt_reference' => (string) Str::uuid(), 'version' => $second->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'The replacement has no remaining controlled funds.', 'confirmed' => true];
    $this->post(route('reversals.approve', $second), $approval)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('reversals.approve', $second), $approval)->assertRedirect()->assertSessionHasNoErrors();
    $second->refresh();
    expect($second->state)->toBe('approved_no_money');
    $proof = app(CollectionNoMoneyCorrection::class)->assertOutcome($second);
    expect($proof->facts['receipt_id'])->toBe($replacement->id);
    expect($proof->facts['gross_kobo'])->toBe(30000);
    expect($proof->facts['fee_effects'][0]['consumed_external_concession_kobo'])->toBe(30000);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(50000);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee))->toBe(['savings_kobo' => 0, 'external_kobo' => 0]);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect(FeeRefund::query()->get()->toArray())->toBe($refunds);
    expect(app(CollectionBatchPosition::class)->read($originalReceipt->batch->fresh()))->toBe($custody);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(50000);
    $this->assertDatabaseCount('collection_receipts', 2);
    $this->assertDatabaseCount('financial_workflow_supplements', 3);
});

test('cold no-money proof cannot omit a previously approved fee component', function (): void {
    [, , $admin, $receipt, $fees, $request, $decision] = noncashNoMoneyReceiptFixture($this, 'transfer', 'business_bank_ngn', true);
    $this->actingAs($admin)->post(route('reversals.approve', $request), $decision)->assertRedirect()->assertSessionHasNoErrors();
    $proof = app(CollectionNoMoneyCorrection::class)->assertOutcome($request->fresh());
    DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->where('fee_obligation_id', $fees[1]->id)->delete();
    DB::table('fee_obligation_entries')->where('source_type', 'receipt_no_money')->where('source_id', $proof->id.'-'.$fees[1]->id)->delete();
    DB::table('financial_workflow_supplements')->where('id', $proof->id)->update([
        'facts' => json_encode([...$proof->facts, 'fee_effects' => [$proof->facts['fee_effects'][0]]], JSON_THROW_ON_ERROR),
    ]);
    expect(fn () => app(CollectionNoMoneyCorrection::class)->assertOutcome($request->fresh()))
        ->toThrow(ConflictHttpException::class, 'The no-money correction fee component graph is incomplete.');
    expect(fn () => app(FeeConcessionPosition::class)->retainedSources($fees[0]))->toThrow(ConflictHttpException::class);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class, 'Receipt amounts and ledger components do not reconcile.');
    expect(app(LedgerTransactionReadService::class)->state()['status'])->not->toBe('ready');
});
