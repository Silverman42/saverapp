<?php

use App\Enums\AdminPermission;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionEvidenceScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\CreatesLifecycleAgents;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionFixtures.php';

uses(CreatesLifecycleCustomers::class, CreatesLifecycleAgents::class);

const QA_INFECTED_MARKER = 'SAVERAPP-QA-INFECTED-MARKER';
const QA_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** Builds a structurally valid PNG so the upload passes image checks and only the scanner can reject it. */
function scannerLivePng(bool $infected): UploadedFile
{
    $bytes = base64_decode(QA_PNG, true).($infected ? QA_INFECTED_MARKER : '');

    return UploadedFile::fake()->createWithContent($infected ? 'infected.png' : 'clean.png', $bytes);
}

beforeEach(function (): void {
    $binary = env('COLLECTION_EVIDENCE_SCANNER_BINARY');
    if (env('COLLECTION_EVIDENCE_SCANNER_LIVE') !== '1' || ! is_string($binary) || ! is_executable($binary)) {
        $this->markTestSkipped('Requires an operational scanner: set COLLECTION_EVIDENCE_SCANNER_LIVE=1 and COLLECTION_EVIDENCE_SCANNER_BINARY.');
    }
    config()->set([
        'collections.enabled' => true, 'collections.noncash_enabled' => true,
        'collections.evidence_scanner_binary' => $binary, 'collections.evidence_scanner_version' => 'live-scanner-test',
    ]);
    $probe = sys_get_temp_dir().'/qa-marker-'.Str::uuid();
    file_put_contents($probe, base64_decode(QA_PNG, true).QA_INFECTED_MARKER);
    try {
        app(CollectionEvidenceScanner::class)->scan($probe);
        $this->markTestSkipped('Requires the local QA signature that flags '.QA_INFECTED_MARKER.' (a harmless marker, not malware).');
    } catch (ValidationException) {
        // The scanner flags the marker, as required.
    } finally {
        unlink($probe);
    }
    Storage::fake('collection_evidence');
});

test('live scanner through the evidence owner stores clean evidence and rejects an infected upload without any trace', function (): void {
    [$agent, $customer, $assignment] = collectionFixture(3);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::BusinessSettingsManage, AdminPermission::ReconciliationManage]);
    LedgerAccount::query()->whereIn('code', ['business_bank_ngn', 'payment_clearing_ngn', 'unapplied_funds_ngn'])->update(['mapping_status' => 'mapped']);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $methodId = $this->actingAs($admin)->withSession($session)->postJson(route('collection-methods.store'), [
        'publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 1, 'label' => 'Verified transfer',
        'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1, 'destination_key' => 'test-destination',
        'attachment_required' => true, 'reason' => 'Approved test collection destination.',
    ])->assertCreated()->json('method_version_id');
    $submit = fn (bool $infected) => $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), [
        'evidence_reference' => (string) Str::uuid(), 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'collection_method_version_id' => $methodId, 'method_reference' => 'TEST-PAYMENT-1', 'received_date' => now('Africa/Lagos')->toDateString(),
        'amount_ngn' => '2000.00', 'source_attestation' => 'Customer supplied this payment confirmation.',
        'files' => [scannerLivePng($infected)],
    ]);

    $submit(true)->assertUnprocessable()->assertJsonValidationErrors('files');
    expect(Storage::disk('collection_evidence')->allFiles())->toBe([])
        ->and(DB::table('collection_payment_evidence')->count())->toBe(0)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);

    $submit(false)->assertCreated();
    expect(Storage::disk('collection_evidence')->allFiles())->toHaveCount(1)
        ->and(DB::table('collection_payment_evidence')->count())->toBe(1)
        ->and(DB::table('collection_receipts')->count())->toBe(0);
});
