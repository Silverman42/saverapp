<?php

use App\Enums\AdminPermission;
use App\Enums\FeeSettlementSource;
use App\Models\CollectionReceipt;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FeeObligationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/CollectionFixtures.php';
require_once __DIR__.'/ReversalFixtures.php';

function noncashFixture(object $test, string $method = 'transfer', string $custody = 'business_bank_ngn', int $feeKobo = 0, string $savings = '2000.00'): array
{
    config()->set(['collections.enabled' => true, 'collections.noncash_enabled' => true,
        'collections.receipt_corrections_enabled' => true, 'collections.evidence_scanner_binary' => '/opt/clamav/bin/clamdscan',
        'collections.evidence_scanner_version' => 'test-signatures-1']);
    Storage::fake('collection_evidence');
    Process::fake(['*clamdscan*' => Process::result()]);
    Process::preventStrayProcesses();
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, 0, 200000, $feeKobo, feeSource: FeeSettlementSource::ExternalReceipt);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::BusinessSettingsManage, AdminPermission::ReconciliationManage]);
    LedgerAccount::query()->whereIn('code', ['business_bank_ngn', 'payment_clearing_ngn', 'unapplied_funds_ngn'])->update(['mapping_status' => 'mapped']);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $methodId = $test->actingAs($admin)->withSession($session)->postJson(route('collection-methods.store'), [
        'publication_reference' => (string) Str::uuid(), 'method_key' => $method, 'version' => 1, 'label' => 'Verified '.$method,
        'custody_account_code' => $custody, 'mapping_version' => 1, 'destination_key' => 'test-destination',
        'attachment_required' => true, 'reason' => 'Approved test collection destination.',
    ])->assertCreated()->json('method_version_id');
    $amount = $savings === '0.00' ? '500.00' : ($feeKobo > 0 ? '2500.00' : $savings);
    $proof = $test->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), [
        'evidence_reference' => (string) Str::uuid(), 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'collection_method_version_id' => $methodId, 'method_reference' => 'TEST-PAYMENT-1', 'received_date' => $date,
        'amount_ngn' => $amount, 'source_attestation' => 'Customer supplied this payment confirmation.',
        'files' => [UploadedFile::fake()->image('proof.png')],
    ])->assertCreated()->json('evidence_reference');
    $test->actingAs($admin)->withSession($session)->postJson(route('collection-evidence.review', $proof), [
        'operation_reference' => (string) Str::uuid(), 'expected_version' => 0, 'outcome' => 'verified',
        'reason' => 'Independent destination receipt confirmed.',
        'verified_reference' => 'TEST-PAYMENT-1', 'verified_amount_ngn' => $amount, 'verified_destination_key' => 'test-destination',
    ])->assertOk();
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, $savings), 'method' => $method,
        'collection_method_version_id' => $methodId, 'evidence_reference' => $proof];
    if ($savings === '0.00') {
        $payload['plan_id'] = null;
        $payload['plan_version'] = null;
    }
    if ($feeKobo > 0) {
        $fee = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
        $payload['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']];
    }
    $test->actingAs($agent);

    return [$agent, $customer, $assignment, $plan, $date, $admin, $proof, $payload];
}

function postNoncashReceipt(object $test, $customer, array $payload): CollectionReceipt
{
    $preview = $test->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $test->post(route('customers.collections.store', $customer->customer_id), [...$payload, 'preview_fingerprint' => $preview['preview_fingerprint']])->assertRedirect();

    return CollectionReceipt::query()->where('attempt_reference', $payload['attempt_reference'])->sole();
}
