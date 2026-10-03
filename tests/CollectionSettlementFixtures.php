<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

require_once __DIR__.'/NoncashCollectionFixtures.php';

function settlementFixture(object $test): array
{
    [$agent, $customer, $assignment, , $date, $admin, , $receiptPayload] = noncashFixture($test, 'pos', 'payment_clearing_ngn', 50000);
    $receipt = postNoncashReceipt($test, $customer, $receiptPayload);
    $batch = $receipt->batch;
    $batch->update(['status' => 'ready_for_review']);
    $method = $test->actingAs($admin)->postJson(route('collection-methods.store'), [
        'publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 1,
        'label' => 'Settlement bank', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
        'destination_key' => 'settlement-bank', 'attachment_required' => true, 'reason' => 'Approved settlement bank destination.',
    ])->assertCreated()->json('method_version_id');
    $payload = ['settlement_reference' => (string) Str::uuid(), 'batch_version' => $batch->version,
        'bank_method_version_id' => $method, 'bank_reference' => 'SETTLEMENT-BANK-1', 'settled_date' => $date,
        'amount_ngn' => '2500.00', 'source_attestation' => 'Actual bank credit independently matched to this terminal batch.',
        'reason' => 'Confirmed settlement into the configured bank.', 'confirmed' => true,
        'files' => [UploadedFile::fake()->image('bank-proof.png')]];

    return [$agent, $customer, $assignment, $admin, $batch, $payload];
}
