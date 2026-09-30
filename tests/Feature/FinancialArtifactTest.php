<?php

use App\Enums\AdminPermission;
use App\Models\FinancialArtifact;
use App\Models\User;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\StatementPreviewService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
});

test('issued statement freezes verified source values and renders an encrypted private PDF once', function (): void {
    [$agent, $customer] = withdrawalFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $payload = ['operation_reference' => (string) Str::uuid(), 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'confirmed' => true];
    $payload['preview_fingerprint'] = app(StatementPreviewService::class)->preview($customer->user, $customer, $payload['from'], $payload['to'], 'Africa/Lagos')['preview_fingerprint'];
    $this->actingAs($customer->user)->post(route('customers.statements.issue', $customer->customer_id), $payload)->assertRedirect();
    $this->post(route('customers.statements.issue', $customer->customer_id), $payload)->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    expect($artifact->snapshot['closing_kobo'])->toBe(100000)->and($artifact->expires_at)->toBeNull();
    app(FinancialArtifactService::class)->render($artifact->id);
    app(FinancialArtifactService::class)->render($artifact->id);
    $artifact->refresh();
    expect($artifact->status)->toBe('ready');
    $stored = Storage::disk('local')->get($artifact->storage_path);
    expect($stored)->not->toStartWith('%PDF')->and(Crypt::decryptString($stored))->toStartWith('%PDF');
    $url = URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $customer->user_id]);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(User::factory()->customer()->create())->get($url)->assertNotFound();
});

test('statement rejects stale ledger future dates changed replay and another Customer scope', function (): void {
    [$agent, $customer] = withdrawalFixture();
    $payload = ['operation_reference' => (string) Str::uuid(), 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'confirmed' => true];
    $payload['preview_fingerprint'] = str_repeat('a', 64);
    $this->actingAs($customer->user)->postJson(route('customers.statements.issue', $customer->customer_id), $payload)->assertConflict();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $payload['preview_fingerprint'] = app(StatementPreviewService::class)->preview($customer->user, $customer, $payload['from'], $payload['to'], 'Africa/Lagos')['preview_fingerprint'];
    $this->post(route('customers.statements.issue', $customer->customer_id), $payload)->assertRedirect();
    $this->postJson(route('customers.statements.issue', $customer->customer_id), [...$payload, 'from' => now()->subDay()->toDateString()])->assertConflict();
    $this->postJson(route('customers.statements.issue', $customer->customer_id), [...$payload, 'to' => now()->addDay()->toDateString()])->assertUnprocessable();
    $this->actingAs(User::factory()->customer()->create())->postJson(route('customers.statements.issue', $customer->customer_id), $payload)->assertNotFound();
});

test('CSV profile neutralizes formulas without changing source values', function (): void {
    $snapshot = ['sections' => ['primary' => ['status' => 'Partial', 'reason' => 'Supported source only.', 'metrics' => [],
        'columns' => ['name' => 'Customer'], 'rows' => [['name' => ' =HYPERLINK("evil")'], ['name' => '@SUM(1)'], ['name' => "\u{FEFF}=SUM(1)"], ['name' => "\0+SUM(1)"]]]]];
    $csv = app(FinancialArtifactService::class)->csv($snapshot);
    expect($csv)->toContain("' =HYPERLINK")->toContain("'@SUM(1)")->and($snapshot['sections']['primary']['rows'][0]['name'])->toBe(' =HYPERLINK("evil")');
    expect($csv)->toContain("'\u{FEFF}=SUM(1)")->toContain("'\0+SUM(1)");
});

test('long statement PDF paginates exact signed totals with preserved control evidence', function (): void {
    [, $customer] = withdrawalFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $snapshot = app(StatementPreviewService::class)->preview($customer->user, $customer, now()->startOfMonth()->toDateString(), now()->toDateString(), 'Africa/Lagos');
    $snapshot['customer_name'] = 'Statement Pagination Test Customer';
    $snapshot['lines'] = array_map(static fn (int $index): array => ['reference' => 'TXN-20260930-'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
        'type' => 'contribution', 'occurred_on' => now()->toDateString(), 'committed_at' => now()->toDateTimeString(),
        'gross_amount_kobo' => 500, 'savings_effect_kobo' => 500, 'fee_amount_kobo' => 0], range(1, 200));
    $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    $manifest = ['schema_version' => 1, 'business_name' => 'SaverApp PDF acceptance fixture', 'captured_at' => now()->toIso8601String(),
        'snapshot_hash' => $hash, 'control_totals' => ['opening_kobo' => 0, 'activity_kobo' => 100000, 'closing_kobo' => 100000, 'line_count' => 200]];
    $artifact = FinancialArtifact::create(['artifact_reference' => (string) Str::uuid(), 'operation_reference' => (string) Str::uuid(),
        'requester_user_id' => $customer->user_id, 'customer_profile_id' => $customer->id, 'kind' => 'statement', 'format' => 'pdf', 'status' => 'queued',
        'payload_hash' => $hash, 'snapshot_hash' => $hash, 'snapshot' => $snapshot, 'manifest' => $manifest]);
    $service = app(FinancialArtifactService::class);
    $service->render($artifact->id);
    $artifact->refresh();
    $bytes = $service->download($customer->user, $artifact);
    expect(preg_match_all('/\/Type\s*\/Page\b/', $bytes))->toBeGreaterThan(2)
        ->and($artifact->manifest['control_totals']['closing_kobo'])->toBe(100000)
        ->and(array_sum(array_column($artifact->snapshot['lines'], 'savings_effect_kobo')))->toBe(100000);
    if (getenv('FINANCIAL_PDF_QA') === '1') {
        file_put_contents('/private/tmp/saverapp-statement-pagination-qa.pdf', $bytes);
    }
});

test('statement supersession preserves original bytes and requires a current confirmed preview', function (): void {
    [, $customer] = withdrawalFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $from = now()->startOfMonth()->toDateString();
    $to = now()->toDateString();
    $preview = app(StatementPreviewService::class)->preview($customer->user, $customer, $from, $to, 'Africa/Lagos');
    $payload = ['operation_reference' => (string) Str::uuid(), 'from' => $from, 'to' => $to, 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true];
    $this->actingAs($customer->user)->post(route('customers.statements.issue', $customer->customer_id), $payload)->assertRedirect();
    $original = FinancialArtifact::query()->sole();
    $service = app(FinancialArtifactService::class);
    $service->render($original->id);
    $original->refresh();
    $bytes = $service->download($customer->user, $original);
    $customer->update(['version' => $customer->version + 1]);
    $replacement = [...$payload, 'operation_reference' => (string) Str::uuid(), 'supersedes_reference' => $original->artifact_reference];
    $this->postJson(route('customers.statements.issue', $customer->customer_id), $replacement)->assertConflict();
    $replacement['preview_fingerprint'] = app(StatementPreviewService::class)->preview($customer->user, $customer, $from, $to, 'Africa/Lagos')['preview_fingerprint'];
    $this->post(route('customers.statements.issue', $customer->customer_id), $replacement)->assertRedirect();
    $this->post(route('customers.statements.issue', $customer->customer_id), $replacement)->assertRedirect();
    $new = FinancialArtifact::query()->latest('id')->firstOrFail();
    expect($new->supersedes_artifact_id)->toBe($original->id)->and($new->manifest['supersedes_reference'])->toBe($original->artifact_reference)
        ->and($service->download($customer->user, $original))->toBe($bytes);
    $this->postJson(route('customers.statements.issue', $customer->customer_id), [...$replacement, 'operation_reference' => (string) Str::uuid()])->assertConflict();
    $this->assertDatabaseCount('financial_artifacts', 2);
});

test('reports require current export authority and report expiry preserves held artifacts and manifests', function (): void {
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertForbidden();
    $admin->givePermissionTo(AdminPermission::ReportsExport);
    $this->actingAs($admin->fresh())->post(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    app(FinancialArtifactService::class)->render($artifact->id);
    $this->travel(8)->days();
    $artifact->update(['held' => true]);
    expect(app(FinancialArtifactService::class)->expire())->toBe(0);
    $artifact->update(['held' => false]);
    expect(app(FinancialArtifactService::class)->expire())->toBe(1)->and(FinancialArtifact::query()->count())->toBe(1)
        ->and($artifact->fresh()->status)->toBe('expired');
});

test('failed artifact retry keeps its snapshot and checks revoked access and cancellation finality', function (): void {
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReportsExport);
    $this->actingAs($admin)->post(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    $hash = $artifact->snapshot_hash;
    $service = app(FinancialArtifactService::class);
    $service->recordFailure($artifact->id);
    $service->render($artifact->id);
    expect($artifact->fresh()->status)->toBe('failed')->and($artifact->fresh()->storage_path)->toBeNull();
    $admin->revokePermissionTo(AdminPermission::ReportsExport);
    $this->post(route('financial-artifacts.retry', $artifact), ['confirmed' => true])->assertNotFound();
    $admin->givePermissionTo(AdminPermission::ReportsExport);
    $this->post(route('financial-artifacts.retry', $artifact), ['confirmed' => true])->assertRedirect();
    $this->post(route('financial-artifacts.retry', $artifact), ['confirmed' => true])->assertRedirect();
    $service->recordFailure($artifact->id, 1);
    $service->render($artifact->id, 1);
    expect($artifact->fresh()->snapshot_hash)->toBe($hash)->and($artifact->fresh()->status)->toBe('queued')
        ->and($artifact->fresh()->render_generation)->toBe(2)->and($artifact->fresh()->storage_path)->toBeNull();
    $this->post(route('financial-artifacts.cancel', $artifact), ['confirmed' => true])->assertRedirect();
    $service->recordFailure($artifact->id);
    $this->post(route('financial-artifacts.retry', $artifact), ['confirmed' => true])->assertConflict();
    expect($artifact->fresh()->status)->toBe('cancelled');
});
