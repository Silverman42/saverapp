<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

/**
 * Record a ₦4,000 cash receipt and return what the correction tests need.
 *
 * @return array{agent: User, customer: CustomerProfile, plan: mixed, date: string, receipt: CollectionReceipt, original: LedgerPostingGroup}
 */
function partlyCollectedReceipt(): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '4000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->update(['mapping_status' => 'mapped']);

    return ['agent' => $agent, 'customer' => $customer, 'plan' => $plan, 'date' => $date, 'receipt' => $receipt,
        'original' => LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id)];
}

/**
 * @return array<string, mixed>
 */
function partlyCollectedRequest(array $quote, array $overrides = []): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Entered ₦4,000 but only ₦2,000 was collected.',
        'customer_explanation' => 'Your receipt amount is being corrected.', 'evidence_text' => 'Cash count at handover was ₦2,000.',
        'confirmed' => true, 'received_amount_ngn' => '2,000', ...$overrides];
}

function partlyCollectedReviewer(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);

    return $admin;
}

test('a cash receipt entered too high is split into the collected cash held for replacement and the uncollected rest cleared from the Agent', function (): void {
    $this->freezeTime();
    ['agent' => $agent, 'customer' => $customer, 'plan' => $plan, 'date' => $date, 'receipt' => $receipt, 'original' => $original] = partlyCollectedReceipt();
    $batch = CollectionBatch::findOrFail($receipt->collection_batch_id);
    expect(app(CollectionBatchPosition::class)->read($batch)['expected_kobo'])->toBe(400000);

    $quote = $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
    expect($quote['received_amount_supported'])->toBeTrue();
    $this->post(route('reversals.store', $original->posting_reference), partlyCollectedRequest($quote))
        ->assertRedirect()->assertSessionHasNoErrors();
    $pending = ReversalRequest::query()->sole();
    expect($pending->original_amount_kobo)->toBe(400000)
        ->and($pending->received_amount_kobo)->toBe(200000)
        ->and($pending->dependency_snapshot['summary']['uncollected_kobo'])->toBe(200000);

    $admin = partlyCollectedReviewer();
    $review = $this->actingAs($admin)->getJson(route('reversals.review-preview', $pending))->assertOk()->json();
    expect($review['summary']['controlled_kobo'])->toBe(200000)
        ->and($review['summary']['uncollected_kobo'])->toBe(200000)
        ->and($review['summary']['custody_disposition'])->toBe('partly_received');
    $this->withSession(cashSession())->post(route('reversals.approve', $pending), ['attempt_reference' => (string) Str::uuid(),
        'version' => $pending->version, 'preview_fingerprint' => $review['preview_fingerprint'],
        'decision_reason' => 'Cash count confirms only ₦2,000 was collected.', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $pending->refresh();
    expect($pending->state)->toBe('approved_posted');
    $credits = LedgerEntry::query()->where('ledger_posting_group_id', $pending->compensation_posting_group_id)
        ->where('side', LedgerEntrySide::Credit)->with('account')->get()->keyBy(fn (LedgerEntry $entry): string => $entry->account->code->value);
    expect($credits[LedgerAccountCode::UnappliedFunds->value]->amount_kobo)->toBe(200000)
        ->and($credits[LedgerAccountCode::AgentReceivable->value]->amount_kobo)->toBe(200000)
        ->and($credits[LedgerAccountCode::AgentReceivable->value]->agent_profile_id)->toBe($receipt->recording_agent_profile_id);
    $receivable = LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable)->sole()->entries()->get();
    expect((int) $receivable->where('side', LedgerEntrySide::Debit)->sum('amount_kobo') - (int) $receivable->where('side', LedgerEntrySide::Credit)->sum('amount_kobo'))
        ->toBe(200000)
        ->and(app(CollectionBatchPosition::class)->read($batch->fresh())['expected_kobo'])->toBe(200000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);

    $customer->refresh()->load('currentAssignment');
    $replacement = collectionPayload($customer, $customer->currentAssignment, $plan->fresh(), $date, '2000.00');
    $replacementQuote = $this->actingAs($agent)->postJson(route('reversals.replacement.preview', $pending), $replacement)->assertOk()->json();
    $this->post(route('reversals.replacement.store', $pending), [...$replacement,
        'preview_fingerprint' => $replacementQuote['preview_fingerprint'], 'replacement_fingerprint' => $replacementQuote['replacement_fingerprint']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(CollectionReceipt::query()->where('replacement_reversal_id', $pending->id)->sole()->tender_amount_kobo)->toBe(200000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and(app(CollectionBatchPosition::class)->read($batch->fresh())['expected_kobo'])->toBe(200000);
});

test('the collected amount must be a positive amount below the recorded receipt and only for a wrong amount', function (array $overrides, string $field): void {
    ['agent' => $agent, 'original' => $original] = partlyCollectedReceipt();
    $quote = $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();

    $response = $this->postJson(route('reversals.store', $original->posting_reference), partlyCollectedRequest($quote, $overrides));

    $field === 'conflict' ? $response->assertConflict() : $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(ReversalRequest::query()->count())->toBe(0);
})->with([
    'zero' => [['received_amount_ngn' => '0'], 'received_amount_ngn'],
    'three decimals' => [['received_amount_ngn' => '10.505'], 'received_amount_ngn'],
    'different reason' => [['reason_category' => 'duplicate_posting'], 'received_amount_ngn'],
    'equal to the receipt' => [['received_amount_ngn' => '4000'], 'received_amount_ngn'],
    'more than the receipt' => [['received_amount_ngn' => '5000'], 'received_amount_ngn'],
]);

test('a split is refused once the Agent has handed over more cash than it would leave in the batch', function (): void {
    ['agent' => $agent, 'receipt' => $receipt, 'original' => $original] = partlyCollectedReceipt();
    $this->travel(3)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::findOrFail($receipt->collection_batch_id);
    $cashier = User::factory()->admin()->withTwoFactor()->create();
    $cashier->givePermissionTo([AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $this->actingAs($cashier)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'HANDOFF-4000', 'amount_ngn' => '3000.00', 'handoff_date' => now('Africa/Lagos')->toDateString(),
        'receiving_location' => 'Business till', 'source_attestation' => 'Counted handed-over cash.', 'batch_version' => $batch->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $quote = $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
    $this->postJson(route('reversals.store', $original->posting_reference), partlyCollectedRequest($quote))->assertConflict();

    expect(ReversalRequest::query()->count())->toBe(0)
        ->and(DB::table('collection_batches')->where('id', $batch->id)->value('status'))->not->toBe('reconciled');
});

test('the batch page shows the corrected expected cash and the uncollected amount', function (): void {
    $this->freezeTime();
    ['agent' => $agent, 'receipt' => $receipt, 'original' => $original] = partlyCollectedReceipt();
    $quote = $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
    $this->post(route('reversals.store', $original->posting_reference), partlyCollectedRequest($quote))->assertSessionHasNoErrors();
    $pending = ReversalRequest::query()->sole();
    $admin = partlyCollectedReviewer();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $review = $this->actingAs($admin)->getJson(route('reversals.review-preview', $pending))->assertOk()->json();
    $this->withSession(cashSession())->post(route('reversals.approve', $pending), ['attempt_reference' => (string) Str::uuid(),
        'version' => $pending->version, 'preview_fingerprint' => $review['preview_fingerprint'],
        'decision_reason' => 'Cash count confirms only ₦2,000 was collected.', 'confirmed' => true])->assertSessionHasNoErrors();

    $this->get(route('collection-batches.show', $receipt->collection_batch_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('batch.expected_kobo', 200000)
            ->where('batch.uncollected_kobo', 200000)->where('batch.outstanding_kobo', 200000));
});
