<?php

use App\Enums\AccountState;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\ReversalRequest;
use App\Services\CollectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';

test('REV-AC-003: the current Agent can correct an Inactive or Restricted Customer without enabling other activity', function (string $status): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original, 'plan' => $plan, 'assignment' => $assignment] = revGapReceipt(2);
    revGapSetCustomerStatus($customer, $status);
    $customer = $customer->fresh();

    $request = revGapSubmit($this, $agent, $original);

    expect($request->state)->toBe('pending_review')->and($request->requested_by_user_id)->toBe($agent->id)
        ->and($request->customer_profile_id)->toBe($customer->id);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    expect(Gate::forUser($agent)->allows('initiateReversal', $customer))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('initiateWithdrawal', $customer))->toBe($status === CustomerStatus::Inactive->value);

    $payload = collectionPayload($customer, $assignment, $plan, now('Africa/Lagos')->toDateString(), '1000.00');
    expect(fn () => app(CollectionService::class)->preview($agent, $customer, $payload))->toThrow(ValidationException::class, 'This Customer cannot receive a savings contribution.');
    $this->assertDatabaseCount('collection_receipts', 1);
})->with([CustomerStatus::Inactive->value, CustomerStatus::Restricted->value]);

test('REV-AC-003: an Agent who is not currently eligible, or an Archived Customer, cannot initiate and nothing is written', function (string $case, int $status): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);
    match ($case) {
        'operationally inactive Agent' => AgentProfile::query()->where('user_id', $agent->id)->update(['operational_status' => AgentStatus::Inactive->value]),
        'suspended Agent' => $agent->forceFill(['account_state' => AccountState::Suspended])->save(),
        default => revGapSetCustomerStatus($customer, CustomerStatus::Archived->value),
    };
    $before = revGapEffectCounts();

    $this->actingAs($agent->fresh())->postJson(route('reversals.preview', $original->posting_reference))->assertStatus($status);
    $this->actingAs($agent->fresh())->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote))->assertStatus($status);

    expect(revGapEffectCounts())->toBe($before)->and(ReversalRequest::query()->count())->toBe(0);
})->with([
    'operationally inactive Agent' => ['operationally inactive Agent', 403],
    'suspended Agent' => ['suspended Agent', 302],
    'Archived Customer' => ['Archived Customer', 403],
]);

test('REV-AC-006: reason, explanation and evidence text must be present and within their limits', function (string $field, mixed $value): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);
    $before = revGapEffectCounts();
    $payload = revGapSubmission($quote, [$field => $value]);
    if ($value === null) {
        unset($payload[$field]);
    }

    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(revGapEffectCounts())->toBe($before);
})->with([
    'missing internal_reason' => ['internal_reason', null],
    'blank internal_reason' => ['internal_reason', ''],
    'overlong internal_reason' => ['internal_reason', str_repeat('r', 1001)],
    'missing customer_explanation' => ['customer_explanation', null],
    'overlong customer_explanation' => ['customer_explanation', str_repeat('c', 501)],
    'missing evidence_text' => ['evidence_text', null],
    'overlong evidence_text' => ['evidence_text', str_repeat('e', 1001)],
    'unknown reason_category' => ['reason_category', 'because'],
    'unconfirmed' => ['confirmed', false],
]);

test('REV-AC-006: values at the exact limits are accepted and unknown fields are rejected without effect', function (): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);
    $before = revGapEffectCounts();

    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote, ['approver_id' => 1]))
        ->assertUnprocessable()->assertJsonValidationErrors('approver_id');
    expect(revGapEffectCounts())->toBe($before);

    $request = revGapSubmit($this, $agent, $original, ['internal_reason' => str_repeat('r', 1000),
        'customer_explanation' => str_repeat('c', 500), 'evidence_text' => str_repeat('e', 1000)]);

    expect(mb_strlen($request->internal_reason))->toBe(1000)->and(mb_strlen($request->customer_explanation))->toBe(500)
        ->and(mb_strlen($request->evidence_text))->toBe(1000);
});

test('REV-AC-006: evidence text is encrypted at rest and submitted terms are immutable', function (): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original, ['evidence_text' => 'Teller slip 88421 shows a single tender.']);

    $raw = (string) DB::table('reversal_requests')->where('id', $request->id)->value('evidence_text');
    expect($raw)->not->toBe('Teller slip 88421 shows a single tender.')->not->toContain('88421')
        ->and($request->fresh()->evidence_text)->toBe('Teller slip 88421 shows a single tender.');

    foreach (['internal_reason', 'customer_explanation', 'evidence_text', 'reason_category', 'original_amount_kobo'] as $field) {
        expect(fn () => $request->fresh()->update([$field => $field === 'original_amount_kobo' ? 1 : 'Altered after submission']))
            ->toThrow(RuntimeException::class, 'Submitted reversal terms are immutable.');
    }
    expect(fn () => $request->fresh()->delete())->toThrow(RuntimeException::class)
        ->and($request->fresh()->internal_reason)->toBe('Actual tender remains controlled by the original Agent.');
});

test('REV-AC-006: a stale or mismatched preview reference is rejected without a request', function (string $field, mixed $value): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);
    $before = revGapEffectCounts();

    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote, [$field => $value]))
        ->assertConflict();

    expect(revGapEffectCounts())->toBe($before);
})->with([
    'preview fingerprint' => ['preview_fingerprint', str_repeat('f', 64)],
    'customer version' => ['customer_version', 99],
    'assignment version' => ['assignment_version', 99],
]);

test('REV-AC-009: an original that already has an approved correction cannot be corrected again, nor can its compensation', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'assignment' => $assignment, 'original' => $original] = revGapReceipt();
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted');
    $before = revGapEffectCounts();
    $quote = ['preview_fingerprint' => str_repeat('a', 64), 'customer_version' => 1, 'assignment_version' => 1];

    $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))
        ->assertConflict()->assertJsonPath('message', 'A reversal already exists for this posting.');
    $this->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote))
        ->assertConflict()->assertJsonPath('message', 'A reversal already exists for this posting.');
    $compensation = $request->fresh()->compensation_posting_group_id;
    $this->postJson(route('reversals.preview', DB::table('ledger_posting_groups')->where('id', $compensation)->value('posting_reference')))
        ->assertConflict()->assertJsonPath('message', 'This posting is not eligible for a full Customer reversal.');

    expect(revGapEffectCounts())->toBe($before)->and(ReversalRequest::query()->count())->toBe(1);
});

test('REV-AC-009: partial or amount-bearing submissions are rejected without effect', function (string $field, mixed $value): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);
    $before = revGapEffectCounts();

    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(revGapEffectCounts())->toBe($before);
})->with([
    'amount' => ['amount', '10.00'], 'amount_kobo' => ['amount_kobo', 1000], 'partial flag' => ['partial', true],
    'gross_kobo' => ['gross_kobo', 1],
]);

test('REV-AC-009: a posting outside the actor scope is indistinguishable from one that does not exist', function (string $viewer): void {
    ['original' => $original] = revGapReceipt();
    $actor = $viewer === 'another Agent' ? revGapAgentWithCustomer()[0] : CustomerProfile::factory()->create()->user;
    $before = revGapEffectCounts();

    $foreign = $this->actingAs($actor)->postJson(route('reversals.preview', $original->posting_reference))->assertNotFound();
    $absent = $this->postJson(route('reversals.preview', 'COL-DOES-NOT-EXIST'))->assertNotFound();
    $this->postJson(route('reversals.store', $original->posting_reference), revGapSubmission(
        ['preview_fingerprint' => str_repeat('a', 64), 'customer_version' => 1, 'assignment_version' => 1]))->assertNotFound();

    expect($foreign->json('message'))->toBe($absent->json('message'))->and(revGapEffectCounts())->toBe($before);
})->with(['another Agent', 'another Customer']);
