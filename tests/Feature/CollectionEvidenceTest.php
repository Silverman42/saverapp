<?php

use App\Enums\AdminPermission;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CollectionMethodCatalogue;
use App\Services\CollectionPaymentEvidenceService;
use App\Services\PlatformState;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set([
        'collections.noncash_enabled' => true,
        'collections.evidence_scanner_binary' => '/opt/clamav/bin/clamdscan',
        'collections.evidence_scanner_version' => 'test-signatures-1',
    ]);
    Storage::fake('collection_evidence');
    Process::fake();
    Process::preventStrayProcesses();
});

function paymentEvidenceFixture(): array
{
    [$agent, $customer, $assignment, , $date] = collectionFixture();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::BusinessSettingsManage, AdminPermission::ReconciliationManage]);
    LedgerAccount::query()->where('code', 'business_bank_ngn')->update(['mapping_status' => 'mapped']);

    return [$agent, $customer, $assignment, $date, $admin];
}

function paymentEvidenceFreshSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

function paymentEvidenceMethod(): array
{
    return ['publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 1,
        'label' => 'Business bank transfer', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
        'destination_key' => 'business-bank-1', 'attachment_required' => true, 'reason' => 'Verified business destination for testing.'];
}

function paymentEvidencePayload($customer, $assignment, string $date, int $method): array
{
    return ['evidence_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'collection_method_version_id' => $method,
        'method_reference' => 'BANK-REF-123', 'received_date' => $date, 'amount_ngn' => '2000.00',
        'source_attestation' => 'Customer supplied the transfer confirmation.',
        'files' => [UploadedFile::fake()->image('proof.png')]];
}

function paymentEvidenceReview(string $outcome = 'verified', int $version = 0): array
{
    return ['operation_reference' => (string) Str::uuid(), 'expected_version' => $version, 'outcome' => $outcome,
        'reason' => 'Independent destination receipt checked against bank record.',
        'verified_reference' => 'BANK-REF-123', 'verified_amount_ngn' => '2000.00', 'verified_destination_key' => 'business-bank-1'];
}

test('method publications require delegated fresh authority and current mapped custody', function (): void {
    [$agent, , , , $admin] = paymentEvidenceFixture();
    $data = paymentEvidenceMethod();
    $this->actingAs($agent)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), $data)->assertForbidden();
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession());
    $this->postJson(route('collection-methods.store'), [...$data, 'mapping_version' => 2])->assertConflict();
    $response = $this->postJson(route('collection-methods.store'), $data)->assertCreated();
    $this->postJson(route('collection-methods.store'), $data)->assertCreated()->assertJsonPath('method_version_id', $response->json('method_version_id'));
    $this->postJson(route('collection-methods.store'), [...$data, 'label' => 'Changed'])->assertConflict();
    $this->postJson(route('collection-methods.store'), [...$data, 'publication_reference' => (string) Str::uuid(), 'version' => 2, 'custody_account_code' => 'payment_clearing_ngn'])->assertConflict();
    $this->assertDatabaseCount('collection_method_versions', 1);
    expect(DB::table('collection_method_versions')->value('reason'))->not->toContain('Verified business');
});

test('scanned evidence remains private posts no money and replays without orphaned files', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->assertCreated()->json('method_version_id');
    $payload = paymentEvidencePayload($customer, $assignment, $date, $method);
    $response = $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), $payload)->assertCreated()->assertJsonPath('status', 'pending');
    expect($response->getContent())->not->toContain('storage_path');
    $payload['files'] = [UploadedFile::fake()->image('proof.png')];
    $this->postJson(route('customers.collection-evidence.store', $customer), $payload)->assertCreated();
    $this->assertDatabaseCount('collection_payment_evidence', 1);
    $this->assertDatabaseCount('collection_evidence_files', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->assertDatabaseCount('collection_receipts', 0);
    Storage::disk('collection_evidence')->assertCount('files', 1);
    expect(DB::table('collection_payment_evidence')->value('source_attestation'))->not->toContain('Customer supplied');
    $payload['files'] = [UploadedFile::fake()->image('proof.png')];
    $payload['evidence_reference'] = (string) Str::uuid();
    $this->postJson(route('customers.collection-evidence.store', $customer), $payload)->assertConflict();
    Storage::disk('collection_evidence')->assertCount('files', 1);
});

test('evidence scanning rejects infected files and unavailable scanners without durable proof', function (int $exitCode, int $status): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    Process::fake(['*' => Process::result(exitCode: $exitCode)]);
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertStatus($status);
    $this->assertDatabaseCount('collection_payment_evidence', 0);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
})->with([[1, 422], [2, 503]]);

test('evidence upload rejects missing attachments invalid bytes stale assignments and disabled methods', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $payload = paymentEvidencePayload($customer, $assignment, $date, $method);
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), [...$payload, 'files' => []])->assertUnprocessable();
    $this->postJson(route('customers.collection-evidence.store', $customer), [...$payload, 'files' => [UploadedFile::fake()->createWithContent('bad.pdf', "%PDF-1.7\ninvalid")]])->assertUnprocessable();
    $this->postJson(route('customers.collection-evidence.store', $customer), [...$payload, 'assignment_version' => $assignment->version + 1])->assertUnprocessable();
    config()->set('collections.noncash_enabled', false);
    $this->postJson(route('customers.collection-evidence.store', $customer), $payload)->assertConflict();
    $this->assertDatabaseCount('collection_payment_evidence', 0);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
});

test('payment verification matches destination amount and reference and appends immutable review history', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $reference = $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertCreated()->json('evidence_reference');
    $review = paymentEvidenceReview();
    $this->postJson(route('collection-evidence.review', $reference), $review)->assertForbidden();
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession());
    $this->postJson(route('collection-evidence.review', $reference), [...$review, 'verified_amount_ngn' => '1999.99'])->assertUnprocessable();
    $this->postJson(route('collection-evidence.review', $reference), [...$review, 'verified_destination_key' => 'personal-bank'])->assertUnprocessable();
    $this->postJson(route('collection-evidence.review', $reference), $review)->assertOk()->assertJsonPath('status', 'verified')->assertJsonPath('review_version', 1);
    $this->postJson(route('collection-evidence.review', $reference), $review)->assertOk()->assertJsonPath('review_version', 1);
    $this->postJson(route('collection-evidence.review', $reference), paymentEvidenceReview('rejected'))->assertConflict();
    $this->postJson(route('collection-evidence.review', $reference), paymentEvidenceReview('rejected', 1))->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('review_version', 2);
    $this->assertDatabaseCount('collection_evidence_reviews', 2);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    expect(DB::table('collection_evidence_reviews')->value('reason'))->not->toContain('Independent');
});

test('private downloads require current scope unexpired signatures and intact bytes', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $proof = $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertCreated()->json();
    $file = $proof['files'][0]['id'];
    $route = ['reference' => $proof['evidence_reference'], 'file' => $file];
    $url = $this->getJson(route('collection-evidence.files.link', $route))->assertOk()->json('url');
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('collection-evidence.files.download', $route))->assertForbidden();
    $this->actingAs(User::factory()->agent()->create())->get($url)->assertForbidden();
    $this->actingAs($customer->user)->getJson(route('collection-evidence.show', $proof['evidence_reference']))->assertForbidden();
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession());
    Storage::disk('collection_evidence')->put(DB::table('collection_evidence_files')->value('storage_path'), 'tampered');
    $this->get($url)->assertConflict();
    $this->travel(3)->minutes();
    $this->get($url)->assertForbidden();
});

test('collection proof method files and reviews cannot be overwritten or deleted', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $proof = $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertCreated()->json('evidence_reference');
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-evidence.review', $proof), paymentEvidenceReview())->assertOk();
    foreach (['collection_method_versions', 'collection_payment_evidence', 'collection_evidence_files', 'collection_evidence_reviews'] as $table) {
        expect(fn () => DB::table($table)->delete())->toThrow(QueryException::class);
        expect(fn () => DB::table($table)->update(['created_at' => now()->subDay()]))->toThrow(QueryException::class);
    }
});

test('unconfigured scanning cannot label an upload clean', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    config()->set('collections.evidence_scanner_version', null);
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertServiceUnavailable();
    $this->assertDatabaseCount('collection_payment_evidence', 0);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
    Process::assertNothingRan();
});

test('evidence preparation rechecks platform state after scanning', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    Process::fake(function () {
        app(PlatformState::class)->transition(['mode' => 'read_only', 'expected_version' => 1, 'operation_id' => (string) Str::uuid(),
            'operator' => 'test-ops', 'reason' => 'Pause during evidence preparation', 'incident' => 'TEST-1', 'expires_at' => null]);

        return Process::result();
    });
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertServiceUnavailable();
    $this->assertDatabaseCount('collection_payment_evidence', 0);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
});

test('audit failure rolls back proof and clears staged files', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $payload = paymentEvidencePayload($customer, $assignment, $date, $method);
    $this->mock(AuditCapture::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Injected audit failure'));
    expect(fn () => app(CollectionPaymentEvidenceService::class)->store($agent, $customer, array_diff_key($payload, ['files' => true]), $payload['files']))->toThrow(RuntimeException::class, 'Injected audit failure');
    $this->assertDatabaseCount('collection_payment_evidence', 0);
    $this->assertDatabaseCount('collection_evidence_files', 0);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
});

test('evidence retention cleanup removes only aged unreferenced files', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertCreated();
    $disk = Storage::disk('collection_evidence');
    $retained = DB::table('collection_evidence_files')->value('storage_path');
    $disk->put('files/old-orphan', 'unpublished');
    $disk->put('files/recent-orphan', 'pending');
    touch($disk->path($retained), now()->subDays(2)->timestamp);
    touch($disk->path('files/old-orphan'), now()->subDays(2)->timestamp);
    $this->artisan('collections:clean-evidence')->assertSuccessful();
    $disk->assertExists([$retained, 'files/recent-orphan']);
    $disk->assertMissing('files/old-orphan');
    $this->assertDatabaseCount('collection_evidence_files', 1);
});

test('method publication supports only closed transfer POS and Other custody patterns without enabling collections', function (): void {
    [, , , , $admin] = paymentEvidenceFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable_ngn', 'payment_clearing_ngn'])->update(['mapping_status' => 'mapped']);
    config()->set('collections.noncash_enabled', false);
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession());
    $this->postJson(route('collection-methods.store'), paymentEvidenceMethod())->assertCreated();
    $this->postJson(route('collection-methods.store'), [...paymentEvidenceMethod(), 'method_key' => 'pos', 'label' => 'Verified POS capture', 'custody_account_code' => 'payment_clearing_ngn', 'destination_key' => 'processor-1'])->assertCreated();
    $this->postJson(route('collection-methods.store'), [...paymentEvidenceMethod(), 'method_key' => 'other', 'label' => 'Approved Agent custody', 'custody_account_code' => 'agent_receivable_ngn', 'destination_key' => 'approved-agent-custody'])->assertCreated();
    $this->assertDatabaseCount('collection_method_versions', 3);
    expect(config('collections.noncash_enabled'))->toBeFalse();
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('a revoked reconciliation grant loses proof and download access', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->json('method_version_id');
    $proof = $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), paymentEvidencePayload($customer, $assignment, $date, $method))->assertCreated()->json();
    $this->actingAs($admin);
    $url = $this->getJson(route('collection-evidence.files.link', ['reference' => $proof['evidence_reference'], 'file' => $proof['files'][0]['id']]))->assertOk()->json('url');
    $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
    $this->getJson(route('collection-evidence.show', $proof['evidence_reference']))->assertForbidden();
    $this->get($url)->assertForbidden();
    $this->postJson(route('collection-evidence.review', $proof['evidence_reference']), paymentEvidenceReview())->assertForbidden();
    $this->assertDatabaseCount('collection_evidence_reviews', 0);
});

/** @return array<string, mixed> */
function evidenceBoundaryOwnerRows(): array
{
    $rows = [];
    foreach (['collection_payment_evidence', 'collection_evidence_files', 'collection_evidence_reviews',
        'collection_receipts', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligations', 'fee_obligation_entries', 'thrift_plans', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function evidenceBoundaryFile(string $kind): UploadedFile
{
    if ($kind === 'oversize' || $kind === 'limit') {
        $image = UploadedFile::fake()->image('proof.png')->getContent();
        $length = 5120 * 1024 + ($kind === 'oversize' ? 1 : 0);
        $fixture = UploadedFile::fake()->createWithContent('proof.png', $image.str_repeat("\0", $length - strlen($image)));
    } else {
        $fixture = UploadedFile::fake()->createWithContent('proof.pdf', match ($kind) {
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'html' => '<!DOCTYPE html><html><body>Unsupported document</body></html>',
            'encrypted' => "%PDF-1.7\n1 0 obj << /Encrypt 2 0 R >> endobj\n%%EOF\n",
        });
    }

    return new class($fixture) extends UploadedFile
    {
        public function __construct(private UploadedFile $fixture)
        {
            parent::__construct($fixture->getPathname(), $fixture->getClientOriginalName(), null, UPLOAD_ERR_OK, true);
        }
    };
}

test('unsupported encrypted oversized and excess evidence uploads create no proof or financial records', function (string $kind): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->assertCreated()->json('method_version_id');
    $payload = paymentEvidencePayload($customer, $assignment, $date, $method);
    $payload['files'] = $kind === 'count' ? array_map(fn (int $number): UploadedFile => UploadedFile::fake()->image('proof-'.$number.'.png'), range(1, 4))
        : [evidenceBoundaryFile($kind)];
    $baseline = evidenceBoundaryOwnerRows();
    $response = $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), $payload)->assertUnprocessable();
    $response->assertJsonValidationErrors(in_array($kind, ['count', 'encrypted'], true) ? 'files' : 'files.0');
    expect(evidenceBoundaryOwnerRows())->toEqual($baseline);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
    Process::assertNothingRan();
})->with(['svg', 'html', 'oversize', 'encrypted', 'count']);

test('direct evidence owner rejects unsupported or oversized bytes before staging or scanning', function (string $kind): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->assertCreated()->json('method_version_id');
    $payload = paymentEvidencePayload($customer, $assignment, $date, $method);
    $baseline = evidenceBoundaryOwnerRows();
    expect(fn () => app(CollectionPaymentEvidenceService::class)->store($agent, $customer,
        array_diff_key($payload, ['files' => true]), [evidenceBoundaryFile($kind)]))->toThrow(ValidationException::class);
    expect(evidenceBoundaryOwnerRows())->toEqual($baseline);
    Storage::disk('collection_evidence')->assertDirectoryEmpty('files');
    Process::assertNothingRan();
})->with(['svg', 'oversize']);

test('an exact five megabyte valid image is scanned privately with its full bytes and no financial posting', function (): void {
    [$agent, $customer, $assignment, $date, $admin] = paymentEvidenceFixture();
    $method = $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), paymentEvidenceMethod())->assertCreated()->json('method_version_id');
    $payload = paymentEvidencePayload($customer, $assignment, $date, $method);
    $file = evidenceBoundaryFile('limit');
    $payload['files'] = [$file];
    $baseline = evidenceBoundaryOwnerRows();
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), $payload)->assertCreated()->assertJsonPath('status', 'pending');
    $stored = DB::table('collection_evidence_files')->sole();
    expect($stored->byte_size)->toBe(5120 * 1024)->and($stored->mime_type)->toBe('image/png')
        ->and($stored->checksum)->toBe(hash('sha256', $file->getContent()))
        ->and($stored->scanner_version)->toBe('test-signatures-1')
        ->and(Storage::disk('collection_evidence')->get($stored->storage_path))->toBe($file->getContent());
    Process::assertRanTimes(static fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[0] === config('collections.evidence_scanner_binary'), 1);
    unset($baseline['collection_payment_evidence'], $baseline['collection_evidence_files']);
    $after = evidenceBoundaryOwnerRows();
    unset($after['collection_payment_evidence'], $after['collection_evidence_files']);
    expect($after)->toEqual($baseline);
});

test('unknown processor or protected publication fields cannot create or enable a collection method', function (string $field): void {
    [, , , , $admin] = paymentEvidenceFixture();
    LedgerAccount::query()->where('code', 'payment_clearing_ngn')->update(['mapping_status' => 'mapped']);
    $data = [...paymentEvidenceMethod(), 'method_key' => 'pos', 'custody_account_code' => 'payment_clearing_ngn', 'destination_key' => 'verified-processor'];
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession());
    $baseline = evidenceBoundaryOwnerRows();
    $audits = DB::table('canonical_audit_events')->count();
    $this->postJson(route('collection-methods.store'), [...$data, $field => 100])->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    expect(fn () => app(CollectionMethodCatalogue::class)->publish($admin, [...$data, $field => 100]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('collection_method_versions', 0);
    expect(app(CollectionMethodCatalogue::class)->available())->toBe([])
        ->and(evidenceBoundaryOwnerRows())->toEqual($baseline)
        ->and(DB::table('canonical_audit_events')->count())->toBe($audits);
    $published = $this->postJson(route('collection-methods.store'), $data)->assertCreated()->json('method_version_id');
    $this->postJson(route('collection-methods.store'), $data)->assertCreated()->assertJsonPath('method_version_id', $published);
    $this->assertDatabaseCount('collection_method_versions', 1);
    expect(evidenceBoundaryOwnerRows())->toEqual($baseline);
})->with(['processor_fee_kobo', 'processor_deduction_kobo', 'processor_fee_basis_points', 'net_amount_ngn', 'published_by_user_id', 'effective_at']);

test('an unavailable POS custody mapping removes the method and prevents publishing another version', function (): void {
    [, , , , $admin] = paymentEvidenceFixture();
    $account = LedgerAccount::query()->where('code', 'payment_clearing_ngn')->sole();
    $account->update(['mapping_status' => 'mapped']);
    $data = [...paymentEvidenceMethod(), 'method_key' => 'pos', 'custody_account_code' => 'payment_clearing_ngn', 'destination_key' => 'verified-processor'];
    $this->actingAs($admin)->withSession(paymentEvidenceFreshSession())->postJson(route('collection-methods.store'), $data)->assertCreated();
    expect(app(CollectionMethodCatalogue::class)->available())->toHaveCount(1);
    $account->update(['mapping_status' => 'unconfigured']);
    $baseline = evidenceBoundaryOwnerRows();
    $audits = DB::table('canonical_audit_events')->count();
    expect(app(CollectionMethodCatalogue::class)->available())->toBe([]);
    $this->postJson(route('collection-methods.store'), [...$data, 'publication_reference' => (string) Str::uuid(), 'version' => 2])->assertConflict();
    $this->assertDatabaseCount('collection_method_versions', 1);
    expect(evidenceBoundaryOwnerRows())->toEqual($baseline)
        ->and(DB::table('canonical_audit_events')->count())->toBe($audits);
});
