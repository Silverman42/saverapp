<?php

use App\Enums\AdminPermission;
use App\Models\CashDisbursement;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\ReversalService;
use Illuminate\Support\Str;

require_once __DIR__.'/CollectionFixtures.php';
require_once __DIR__.'/FeeFixtures.php';
require_once __DIR__.'/CashExecutionFixtures.php';
require_once __DIR__.'/NoncashCollectionFixtures.php';

function noMoneyReceiptFixture(object $test, bool $paid = false, int $concessionKobo = 50000): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true, 'fees.refunds_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::ReconciliationManage, AdminPermission::ReversalsReview]);
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $receipt->batch), [
        'handoff_reference' => 'FULLY-CONCEDED-FEE', 'amount_ngn' => '500.00', 'handoff_date' => $date,
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Counted original fee-only tender.',
        'batch_version' => $receipt->batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('admin.fees.refunds.store', $fee), ['refund_reference' => (string) Str::uuid(), 'kind' => 'external',
        'amount_ngn' => sprintf('%d.%02d', intdiv($concessionKobo, 100), $concessionKobo % 100), 'reason' => 'Independent valid-charge concession.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $refund = FeeRefund::query()->sole();
    if ($paid) {
        config()->set('fees.cash_disbursements_enabled', true);
        $admin->givePermissionTo(AdminPermission::CashExecute);
        $test->post(route('fee-refunds.cash', $refund), ['execution_reference' => (string) Str::uuid(),
            'evidence' => 'Full independently owed refund cash.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $execution = CashDisbursement::query()->sole();
        $test->post(route('cash-disbursements.handoff', $execution), ['delivered' => true, 'evidence' => 'Exact refund delivered.', 'confirmed' => true])->assertRedirect();
        $test->actingAs($customer->user)->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    }
    $original = LedgerPostingGroup::query()->where('source_type', 'collection_receipt')->where('source_id', $receipt->id.'-'.$fee->id)->sole();
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $request = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'The historical receipt requires correction; the full fee is already conceded.',
        'customer_explanation' => 'Your existing refund is preserved. The receipt history is corrected without further money movement.',
        'evidence_text' => 'Original receipt and complete independent concession reviewed.', 'confirmed' => true]);
    $review = $service->reviewPreview($admin, $request);
    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => $request->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Full concession and no remaining controlled funds verified.', 'confirmed' => true];

    return [$agent, $customer, $assignment, $plan, $date, $fee, $receipt, $original, $refund, $admin, $request, $decision];
}

function noncashNoMoneyReceiptFixture(object $test, string $method, string $custody, bool $multiple): array
{
    [$agent, $customer, $assignment, $plan, $date, $admin, , $data] = noncashFixture($test, $method, $custody, feeKobo: $multiple ? 30000 : 0, savings: '0.00');
    config()->set('fees.refunds_enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fees = [reportFeeObligation($agent, $customer, $multiple ? 20000 : 50000)];
    if ($multiple) {
        $fees[] = FeeObligation::query()->where('customer_profile_id', $customer->id)->where('source_type', 'plan')->sole();
    }
    $data['fees'] = array_map(fn (FeeObligation $fee): array => ['obligation_id' => $fee->id,
        'amount_ngn' => sprintf('%d.%02d', intdiv($fee->amount_kobo, 100), $fee->amount_kobo % 100)], $fees);
    $receipt = postNoncashReceipt($test, $customer, $data);
    $cashCustomer = CustomerProfile::factory()->create();
    $cashAssignment = CustomerAssignment::factory()->create(['customer_profile_id' => $cashCustomer->id,
        'agent_profile_id' => $assignment->agent_profile_id, 'assigned_by_user_id' => $agent->id]);
    $cashFee = reportFeeObligation($agent, $cashCustomer, 50000, version: 3);
    $cashData = [...collectionPayload($cashCustomer, $cashAssignment, $plan, $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $cashFee->id, 'amount_ngn' => '500.00']]];
    $cashData['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $cashCustomer, $cashData)['preview_fingerprint'];
    $cashReceipt = app(CollectionService::class)->record($agent, $cashCustomer, $cashData);
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::ReversalsReview]);
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $cashReceipt->batch), [
        'handoff_reference' => 'NO-MONEY-INDEPENDENT-FEE-CASH', 'amount_ngn' => '500.00', 'handoff_date' => $date,
        'receiving_location' => 'Lagos business office', 'source_attestation' => 'Independent fee earnings cash backs the conceded external refunds.',
        'batch_version' => $cashReceipt->batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    foreach ($fees as $fee) {
        $test->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
            'refund_reference' => (string) Str::uuid(), 'kind' => 'external',
            'amount_ngn' => sprintf('%d.%02d', intdiv($fee->amount_kobo, 100), $fee->amount_kobo % 100),
            'reason' => 'Independently conceded valid external fee component.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    $original = LedgerPostingGroup::query()->where('source_type', 'collection_receipt')
        ->where('source_id', $receipt->id.'-'.$fees[0]->id)->sole();
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $request = $service->submit($agent, $original, [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Correct the historical noncash fee-only receipt after its complete independent concessions.',
        'customer_explanation' => 'Existing refunds remain owed and no further money moves in this correction.',
        'evidence_text' => 'Original independent payment proof and every fee concession reviewed.', 'confirmed' => true,
    ]);
    $review = $service->reviewPreview($admin, $request);
    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => $request->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Every component is fully conceded with no controlled funds remaining.', 'confirmed' => true];

    return [$agent, $customer, $admin, $receipt, $fees, $request, $decision, $date];
}
