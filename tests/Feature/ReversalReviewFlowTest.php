<?php

use App\Enums\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\ReversalService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';

function receiptCorrectionRequest(object $test): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$recorder, $customer, $assignment, $plan, $date] = collectionFixture(1, slotAmountKobo: 200000);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($recorder, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($recorder, $customer, $payload);
    LedgerAccount::query()->where('code', 'unapplied_funds_ngn')->update(['mapping_status' => 'mapped']);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $service = app(ReversalService::class);
    $quote = $service->preview($recorder, $original);
    $request = $service->submit($recorder, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'Actual tender remains controlled by the original Agent.',
        'customer_explanation' => 'The erroneous original is corrected with its required linked entries.',
        'evidence_text' => 'Original amount and agreed terms verified.', 'confirmed' => true]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);

    return [$recorder, $admin, $request, $original];
}

test('the Admin review preview is a read that returns the full compensation and the page can approve with it', function (): void {
    [, $admin, $request] = receiptCorrectionRequest($this);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp];

    $this->actingAs($admin)->withSession($session)->post(route('reversals.review-preview', $request))->assertStatus(405);
    $preview = $this->getJson(route('reversals.review-preview', $request))->assertOk()
        ->assertJsonStructure(['preview_fingerprint', 'gross_kobo', 'summary', 'dependencies', 'request_version'])->json();
    $this->get(route('reversals.show', $request))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reversals/Show')->where('can_approve', true));

    $this->post(route('reversals.approve', $request), ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['request_version'],
        'preview_fingerprint' => $preview['preview_fingerprint'], 'decision_reason' => 'Reviewed the full compensation.', 'confirmed' => true])->assertRedirect();

    expect($request->fresh()->state)->toBe('approved_posted');
});

test('a review preview survives unrelated activity but not a change to the same Customer', function (bool $sameCustomer): void {
    [$recorder, $admin, $request, $original] = receiptCorrectionRequest($this);
    $preview = app(ReversalService::class)->reviewPreview($admin, $request);
    $other = CustomerProfile::factory()->create();
    LedgerPostingGroup::create(['posting_reference' => 'TEST-ACTIVITY-'.Str::uuid(), 'idempotency_key' => 'test-activity-'.Str::uuid(),
        'payload_hash' => str_repeat('d', 64), 'source_type' => 'test_activity', 'source_id' => '1', 'event_type' => 'test_activity',
        'currency' => 'NGN', 'actor_user_id' => $recorder->id, 'customer_profile_id' => $sameCustomer ? $original->customer_profile_id : $other->id,
        'occurred_at' => now(), 'committed_at' => now()]);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp];

    $response = $this->actingAs($admin)->withSession($session)->post(route('reversals.approve', $request), ['attempt_reference' => (string) Str::uuid(),
        'version' => $request->version, 'preview_fingerprint' => $preview['preview_fingerprint'], 'decision_reason' => 'Reviewed.', 'confirmed' => true]);

    $sameCustomer ? $response->assertStatus(409) : $response->assertRedirect();
    expect($request->fresh()->state)->toBe($sameCustomer ? 'pending_review' : 'approved_posted');
})->with(['unrelated Customer activity' => [false], 'same Customer activity' => [true]]);
