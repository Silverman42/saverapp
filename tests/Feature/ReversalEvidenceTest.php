<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalEvidenceFile;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';

/** @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: LedgerPostingGroup} */
function evidenceFixture(bool $scanner = true): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true,
        'collections.evidence_scanner_binary' => $scanner ? '/opt/clamav/bin/clamdscan' : null, 'collections.evidence_scanner_version' => 'test-signatures-1']);
    Storage::fake('collection_evidence');
    Process::fake(['*clamdscan*' => Process::result()]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1, slotAmountKobo: 200000);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', 'unapplied_funds_ngn')->update(['mapping_status' => 'mapped']);

    return [$agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id)];
}

function submitCorrection(object $test, User $agent, object $customer, object $assignment, LedgerPostingGroup $original, array $files = [], ?string $reference = null, ?string $fingerprint = null): TestResponse
{
    $fingerprint ??= $test->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json('preview_fingerprint');

    return $test->post(route('reversals.store', $original->posting_reference), ['attempt_reference' => $reference ?? (string) Str::uuid(),
        'preview_fingerprint' => $fingerprint, 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'The tender remains controlled by the original Agent.',
        'customer_explanation' => 'The erroneous original is corrected with its linked entries.', 'evidence_text' => 'Original amount verified.',
        'confirmed' => true, 'files' => $files]);
}

test('protected evidence files are scanned, stored once and bound to the request', function (): void {
    [$agent, $customer, $assignment, $original] = evidenceFixture();

    submitCorrection($this, $agent, $customer, $assignment, $original, [UploadedFile::fake()->image('proof.png'), UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")])->assertRedirect()->assertSessionHasNoErrors();

    $request = ReversalRequest::query()->sole();
    $files = ReversalEvidenceFile::query()->get();
    expect($files)->toHaveCount(2)->and($files->every(fn ($file): bool => $file->scanner_version === 'test-signatures-1' && Storage::disk('collection_evidence')->exists($file->storage_path)))->toBeTrue()
        ->and($request->evidenceFiles()->count())->toBe(2)
        ->and(DB::table('canonical_audit_events')->where('event_type', 'reversal.evidence_added')->count())->toBe(1);
});

test('unsafe evidence and excess files create no request and leave no stored bytes', function (string $kind): void {
    [$agent, $customer, $assignment, $original] = evidenceFixture();
    $files = match ($kind) {
        'four files' => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.png"), [1, 2, 3, 4]),
        'unsupported type' => [UploadedFile::fake()->createWithContent('run.exe', 'MZ binary')],
        'oversize image' => [UploadedFile::fake()->create('big.png', 5121, 'image/png')],
        default => [UploadedFile::fake()->createWithContent('fake.png', 'not really an image')],
    };

    submitCorrection($this, $agent, $customer, $assignment, $original, $files)->assertSessionHasErrors();

    expect(ReversalRequest::query()->count())->toBe(0)->and(ReversalEvidenceFile::query()->count())->toBe(0)
        ->and(Storage::disk('collection_evidence')->allFiles())->toBe([]);
})->with(['four files', 'unsupported type', 'oversize image', 'disguised content']);

test('an unavailable scanner fails closed without a request', function (): void {
    [$agent, $customer, $assignment, $original] = evidenceFixture(scanner: false);

    submitCorrection($this, $agent, $customer, $assignment, $original, [UploadedFile::fake()->image('proof.png')])->assertStatus(503);

    expect(ReversalRequest::query()->count())->toBe(0)->and(Storage::disk('collection_evidence')->allFiles())->toBe([]);
});

test('a replayed submission with the same files stores nothing twice', function (): void {
    [$agent, $customer, $assignment, $original] = evidenceFixture();
    $reference = (string) Str::uuid();
    $seed = UploadedFile::fake()->image('seed.png');
    $bytes = (string) file_get_contents($seed->getPathname());

    $fingerprint = $this->actingAs($agent)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json('preview_fingerprint');

    submitCorrection($this, $agent, $customer, $assignment, $original, [UploadedFile::fake()->createWithContent('proof.png', $bytes)], $reference, $fingerprint)->assertRedirect();
    $stored = Storage::disk('collection_evidence')->allFiles();
    submitCorrection($this, $agent, $customer, $assignment, $original, [UploadedFile::fake()->createWithContent('proof.png', $bytes)], $reference, $fingerprint)->assertRedirect();

    expect(ReversalRequest::query()->count())->toBe(1)->and(ReversalEvidenceFile::query()->count())->toBe(1)
        ->and(Storage::disk('collection_evidence')->allFiles())->toBe($stored);
});

test('only the current Agent and a reviewing Admin retrieve evidence, through short-lived signed links, with access audit', function (): void {
    [$agent, $customer, $assignment, $original] = evidenceFixture();
    submitCorrection($this, $agent, $customer, $assignment, $original, [UploadedFile::fake()->image('proof.png')])->assertRedirect();
    $request = ReversalRequest::query()->sole();
    $file = ReversalEvidenceFile::query()->sole();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);
    $plainAdmin = User::factory()->admin()->withTwoFactor()->create();

    $url = $this->actingAs($admin)->getJson(route('reversals.evidence.link', [$request, $file->id]))->assertOk()->json('url');
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(DB::table('canonical_audit_events')->where('event_type', 'reversal.evidence_downloaded')->count())->toBe(1);

    $this->get(route('reversals.evidence.download', [$request, $file->id]))->assertForbidden();
    $this->get($url.'x')->assertForbidden();
    $this->actingAs($plainAdmin)->getJson(route('reversals.evidence.link', [$request, $file->id]))->assertNotFound();
    $this->actingAs($customer->user)->getJson(route('reversals.evidence.link', [$request, $file->id]))->assertNotFound();
    $former = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    AgentProfile::factory()->active()->create(['user_id' => $former->id]);
    $this->actingAs($former)->getJson(route('reversals.evidence.link', [$request, $file->id]))->assertNotFound();
    $signed = URL::temporarySignedRoute('reversals.evidence.download', now()->addMinutes(2), ['reversal' => $request->reversal_id, 'file' => $file->id]);
    $this->actingAs($customer->user)->get($signed)->assertNotFound();
    $this->actingAs($agent)->getJson(route('reversals.evidence.link', [$request, $file->id]))->assertOk();
});

test('evidence can be supplemented only while pending and never beyond three files', function (): void {
    [$agent, $customer, $assignment, $original] = evidenceFixture();
    submitCorrection($this, $agent, $customer, $assignment, $original, [UploadedFile::fake()->image('a.png')])->assertRedirect();
    $request = ReversalRequest::query()->sole();

    $this->actingAs($agent)->post(route('reversals.evidence.store', $request), ['files' => [UploadedFile::fake()->image('b.png')]])->assertRedirect();
    $this->post(route('reversals.evidence.store', $request), ['files' => [UploadedFile::fake()->image('c.png'), UploadedFile::fake()->image('d.png')]])->assertSessionHasErrors('files');
    expect(ReversalEvidenceFile::query()->count())->toBe(2);
    $this->post(route('reversals.evidence.store', $request), ['files' => [UploadedFile::fake()->image('c.png')]])->assertRedirect();
    expect(ReversalEvidenceFile::query()->count())->toBe(3);
    $this->post(route('reversals.evidence.store', $request), ['files' => [UploadedFile::fake()->image('e.png')]])->assertSessionHasErrors('files');

    $request->update(['state' => 'cancelled']);
    $this->post(route('reversals.evidence.store', $request), ['files' => [UploadedFile::fake()->image('f.png')]])->assertStatus(409);
    expect(fn () => ReversalEvidenceFile::query()->first()->update(['mime_type' => 'image/gif']))->toThrow(RuntimeException::class);
});
