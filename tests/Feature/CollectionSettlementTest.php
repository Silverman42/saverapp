<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionSettlementService;
use App\Services\CollectionWorkspaceService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlatformState;
use App\Services\ReportReadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../CollectionSettlementFixtures.php';

test('partial and final POS settlements move clearing to bank once and leave savings and fee income unchanged', function (): void {
    [, , , $admin, $batch, $payload] = settlementFixture($this);
    $incomeAccount = LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome)->sole();
    $recognizedIncome = DB::table('ledger_entries')->where('ledger_account_id', $incomeAccount->id)->orderBy('id')->get()->all();
    expect($recognizedIncome)->toHaveCount(1);
    expect((int) $recognizedIncome[0]->amount_kobo)->toBe(50000);
    expect($recognizedIncome[0]->side)->toBe('credit');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $payload['amount_ngn'] = '1000.00';
    $route = route('collection-batches.settlements.store', $batch);
    $this->postJson($route, $payload)->assertCreated();
    $this->postJson($route, $payload)->assertCreated();
    $position = app(CollectionBatchPosition::class)->read($batch->fresh());
    expect($position)->toBe(['expected_kobo' => 250000, 'received_kobo' => 100000, 'outstanding_kobo' => 150000, 'settlement_pending' => true]);
    $this->assertDatabaseCount('collection_settlements', 1);
    $this->postJson(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version, 'reason' => 'Only partial settlement received.', 'confirmed' => true])->assertConflict();
    $payload = [...$payload, 'settlement_reference' => (string) Str::uuid(), 'bank_reference' => 'SETTLEMENT-BANK-2',
        'batch_version' => $batch->fresh()->version, 'amount_ngn' => '1500.00', 'files' => [UploadedFile::fake()->image('final-proof.png')]];
    $this->postJson($route, $payload)->assertCreated();
    expect(app(CollectionBatchPosition::class)->read($batch->fresh())['settlement_pending'])->toBeFalse();
    $cash = app(FinancialCashPosition::class);
    expect($cash->balance(LedgerAccountCode::PaymentClearing))->toBe(0)
        ->and($cash->balance(LedgerAccountCode::BusinessBank))->toBe(250000)
        ->and($cash->balance(LedgerAccountCode::BusinessCash))->toBe(0);
    expect((int) DB::table('ledger_entries as entries')->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
        ->where('accounts.code', 'customer_savings_liability_ngn')->sum('entries.amount_kobo'))->toBe(200000);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('collection_fee_components', 1);
    $this->assertDatabaseCount('cash_remittances', 0);
    $this->postJson(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version, 'reason' => 'All bank settlement evidence reconciles.', 'confirmed' => true])->assertRedirect();
    expect($batch->fresh()->status)->toBe('reconciled');
    expect(DB::table('ledger_entries')->where('ledger_account_id', $incomeAccount->id)->orderBy('id')->get()->all())->toEqual($recognizedIncome);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->assertDatabaseCount('ledger_transaction_references', 3);
    Process::assertRan(fn ($process): bool => is_array($process->command) && $process->command[0] === '/opt/clamav/bin/clamdscan');
});

test('a settlement reference cannot change and a bank credit cannot be claimed as a payment receipt', function (string $method): void {
    [$agent, $customer, $assignment, , $batch, $payload] = settlementFixture($this);
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated();
    $changed = [...$payload, 'amount_ngn' => '2000.00', 'files' => [UploadedFile::fake()->image('same-proof.png')]];
    $this->postJson(route('collection-batches.settlements.store', $batch), $changed)->assertConflict();
    if ($method === 'other') {
        $payload['bank_method_version_id'] = $this->postJson(route('collection-methods.store'), [
            'publication_reference' => (string) Str::uuid(), 'method_key' => 'other', 'version' => 1,
            'label' => 'Configured Other bank receipt', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
            'destination_key' => 'settlement-bank', 'attachment_required' => true, 'reason' => 'Same underlying bank destination.',
        ])->assertCreated()->json('method_version_id');
    }
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), [
        'evidence_reference' => (string) Str::uuid(), 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'collection_method_version_id' => $payload['bank_method_version_id'], 'method_reference' => $payload['bank_reference'],
        'received_date' => $payload['settled_date'], 'amount_ngn' => '2500.00', 'source_attestation' => 'This bank credit already settles the clearing batch.',
        'files' => [UploadedFile::fake()->image('duplicate-bank-proof.png')],
    ])->assertConflict();
    $this->assertDatabaseCount('collection_settlements', 1);
    $this->assertDatabaseCount('collection_payment_evidence', 1);
})->with(['transfer', 'other']);

test('invalid clearing settlement leaves every financial and evidence owner unchanged', function (string $failure): void {
    [, , , , $batch, $payload] = settlementFixture($this);
    match ($failure) {
        'excess' => $payload['amount_ngn'] = '2500.01',
        'stale' => $payload['batch_version'] = $batch->version + 1,
        'future' => $payload['settled_date'] = now('Africa/Lagos')->addDay()->toDateString(),
        'before-capture' => $payload['settled_date'] = now('Africa/Lagos')->subDay()->toDateString(),
        'wrong-destination' => $payload['bank_method_version_id'] = $batch->collection_method_version_id,
        'unmapped' => LedgerAccount::query()->where('code', 'business_bank_ngn')->update(['mapping_status' => 'unconfigured']),
        'closed' => DB::table('financial_periods')->update(['status' => 'closed']),
        'open-batch' => $batch->update(['status' => 'open']),
    };
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertConflict();
    $this->assertDatabaseCount('collection_settlements', 0);
    $this->assertDatabaseCount('collection_settlement_files', 0);
    expect(LedgerPostingGroup::query()->where('event_type', 'clearing_settlement')->count())->toBe(0);
    expect(Storage::disk('collection_evidence')->allFiles('files'))->toHaveCount(1);
})->with(['excess', 'stale', 'future', 'before-capture', 'wrong-destination', 'unmapped', 'closed', 'open-batch']);

test('settlement scan failure and unavailable scanner cannot claim custody', function (int $exitCode): void {
    [, , , , $batch, $payload] = settlementFixture($this);
    Process::fake(['*clamdscan*' => Process::result(exitCode: $exitCode)]);
    $response = $this->postJson(route('collection-batches.settlements.store', $batch), $payload);
    if ($exitCode === 1) {
        $response->assertUnprocessable();
    } else {
        $response->assertServiceUnavailable();
    }
    $this->assertDatabaseCount('collection_settlements', 0);
    expect(Storage::disk('collection_evidence')->allFiles('files'))->toHaveCount(1);
})->with([1, 2]);

test('settlement audit failure rolls the journal reference claim files and batch version back together', function (): void {
    [, , , $admin, $batch, $payload] = settlementFixture($this);
    $this->mock(AuditCapture::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Injected settlement audit failure'));
    expect(fn () => app(CollectionSettlementService::class)->record($admin, $batch, array_diff_key($payload, ['files' => true]), $payload['files']))
        ->toThrow(RuntimeException::class, 'Injected settlement audit failure');
    $this->assertDatabaseCount('collection_settlements', 0);
    $this->assertDatabaseCount('collection_bank_reference_claims', 0);
    expect($batch->fresh()->version)->toBe($batch->version);
    expect(LedgerPostingGroup::query()->where('event_type', 'clearing_settlement')->count())->toBe(0);
    expect(Storage::disk('collection_evidence')->allFiles('files'))->toHaveCount(1);
});

test('settlement raw files require current reconciliation authority and retain signed private access', function (): void {
    [$agent, $customer, , $admin, $batch, $payload] = settlementFixture($this);
    $reference = $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated()->json('settlement_reference');
    $file = DB::table('collection_settlement_files')->sole();
    $url = $this->getJson(route('collection-settlements.files.link', ['reference' => $reference, 'file' => $file->id]))->assertOk()->json('url');
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/octet-stream');
    $this->actingAs($agent)->get($url)->assertForbidden();
    $this->actingAs($customer->user)->get($url)->assertForbidden();
    $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin->fresh())->get($url)->assertForbidden();
});

test('settlement submission rejects Agent Customer and ungranted Admin financial authority', function (): void {
    [$agent, $customer, , , $batch, $payload] = settlementFixture($this);
    $route = route('collection-batches.settlements.store', $batch);
    $this->actingAs($agent)->postJson($route, $payload)->assertForbidden();
    $this->actingAs($customer->user)->postJson($route, $payload)->assertForbidden();
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->postJson($route, $payload)->assertForbidden();
    $this->assertDatabaseCount('collection_settlements', 0);
});

test('settlement rechecks revoked authority and financial freeze after scanning before posting', function (string $change): void {
    [, , , $admin, $batch, $payload] = settlementFixture($this);
    Process::fake(function () use ($change, $admin) {
        if ($change === 'permission') {
            $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
        } else {
            app(PlatformState::class)->transition(['mode' => 'financial_freeze', 'expected_version' => 1,
                'operation_id' => (string) Str::uuid(), 'operator' => 'test-ops', 'reason' => 'Pause during bank evidence preparation',
                'incident' => 'SETTLEMENT-1', 'expires_at' => null]);
        }

        return Process::result();
    });
    $response = $this->postJson(route('collection-batches.settlements.store', $batch), $payload);
    if ($change === 'permission') {
        $response->assertForbidden();
    } else {
        $response->assertServiceUnavailable();
    }
    $this->assertDatabaseCount('collection_settlements', 0);
    $this->assertDatabaseCount('collection_bank_reference_claims', 0);
    expect(Storage::disk('collection_evidence')->allFiles('files'))->toHaveCount(1);
})->with(['permission', 'platform']);

test('settlement retention preserves published files and tampered evidence blocks batch reconciliation', function (): void {
    [, , , , $batch, $payload] = settlementFixture($this);
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated();
    $file = DB::table('collection_settlement_files')->sole();
    $disk = Storage::disk('collection_evidence');
    touch($disk->path($file->storage_path), now()->subDays(2)->timestamp);
    $disk->put('files/settlement-orphan', 'unpublished');
    touch($disk->path('files/settlement-orphan'), now()->subDays(2)->timestamp);
    $this->artisan('collections:clean-evidence')->assertSuccessful();
    $disk->assertExists($file->storage_path);
    $disk->assertMissing('files/settlement-orphan');
    $disk->put($file->storage_path, 'changed content');
    $this->postJson(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Evidence integrity must pass before reconciliation.', 'confirmed' => true])->assertConflict();
    expect($batch->fresh()->status)->toBe('ready_for_review');
});

test('saved settlement status recovers posted outcomes without another upload and scoped views hide raw evidence from Agents', function (): void {
    [$agent, , , $admin, $batch, $payload] = settlementFixture($this);
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated();
    $this->getJson(route('collection-batches.settlements.show', [$batch, $payload['settlement_reference']]))
        ->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('amount_kobo', 250000);
    $this->get(route('collection-batches.show', $batch))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('collections/Batch')->where('batch.settlement_pending', false)->has('settlements.data', 1)->has('settlement_banks', 1));
    $this->actingAs($agent)->get(route('collection-batches.show', $batch))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('settlements', null)->where('settlement_banks', []));
    $this->getJson(route('collection-batches.settlements.show', [$batch, $payload['settlement_reference']]))->assertForbidden();
    $this->actingAs($admin)->getJson(route('collection-batches.settlements.show', [$batch, (string) Str::uuid()]))->assertNotFound();
    $this->assertDatabaseCount('collection_settlements', 1);
});

test('known settlement rejection can be corrected while a claimed reference requires saved outcome recovery', function (): void {
    [, , , , $batch, $payload] = settlementFixture($this);
    $route = route('collection-batches.settlements.store', $batch);
    $excess = [...$payload, 'amount_ngn' => '2500.01'];
    $this->postJson($route, $excess)->assertConflict()->assertJsonPath('status', 'rejected');
    $this->postJson($route, $payload)->assertCreated();
    $changed = [...$payload, 'amount_ngn' => '2000.00'];
    $this->postJson($route, $changed)->assertConflict()->assertJsonPath('status', 'reference_in_use');
    $this->assertDatabaseCount('collection_settlements', 1);
});

test('disabling new captures does not strand already posted clearing funds', function (): void {
    [, , , , $batch, $payload] = settlementFixture($this);
    config()->set('collections.noncash_enabled', false);
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated();
    expect(app(CollectionBatchPosition::class)->read($batch->fresh())['outstanding_kobo'])->toBe(0);
});

test('partial and final settlement reports separate bank custody clearing and unchanged daily tender', function (): void {
    [$agent, , , , $batch, $payload] = settlementFixture($this);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $payload['amount_ngn'] = '1000.00';
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated();
    $read = fn () => app(ReportReadService::class)->read($agent, 'reconciliation', ['page_size' => 25, 'group' => ''])['sections']['batch_reconciliation'];
    $section = $read();
    expect($section['status'])->not->toBe('Unavailable');
    $metrics = collect($section['metrics'])->keyBy('code');
    expect($metrics['gross_tender']['value'])->toBe(250000)->and($metrics['confirmed_remittances']['value'])->toBe(0)
        ->and($metrics['unremitted']['value'])->toBe(0)->and($metrics['bank_received']['value'])->toBe(100000)
        ->and($metrics['pending_settlement']['value'])->toBe(150000);
    $payload = [...$payload, 'settlement_reference' => (string) Str::uuid(), 'bank_reference' => 'SECOND-CREDIT',
        'batch_version' => $batch->fresh()->version, 'amount_ngn' => '1500.00'];
    $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated();
    $this->postJson(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Both credits independently confirmed.', 'confirmed' => true])->assertRedirect();
    $section = $read();
    expect($section['status'])->not->toBe('Unavailable');
    expect(collect($section['metrics'])->firstWhere('code', 'bank_received')['value'])->toBe(250000);
    expect(collect($section['metrics'])->firstWhere('code', 'pending_settlement')['value'])->toBe(0);
    expect(app(CollectionWorkspaceService::class)->received($agent, $batch->received_date)['tender_kobo'])->toBe(250000);
    DB::table('ledger_transaction_projections')->where('type', 'remittance')->update(['gross_amount_kobo' => 1]);
    expect($read()['status'])->toBe('Unavailable');
});

test('missing settlement evidence returns unavailable and cannot reconcile or report success', function (): void {
    [$agent, , , , $batch, $payload] = settlementFixture($this);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reference = $this->postJson(route('collection-batches.settlements.store', $batch), $payload)->assertCreated()->json('settlement_reference');
    $file = DB::table('collection_settlement_files')->sole();
    $url = $this->getJson(route('collection-settlements.files.link', ['reference' => $reference, 'file' => $file->id]))->assertOk()->json('url');
    Storage::disk('collection_evidence')->delete($file->storage_path);
    $this->get($url)->assertServiceUnavailable();
    $this->postJson(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Unavailable file cannot pass review.', 'confirmed' => true])->assertServiceUnavailable();
    expect($batch->fresh()->status)->toBe('ready_for_review');
    expect(app(ReportReadService::class)->read($agent, 'reconciliation', ['page_size' => 25, 'group' => ''])['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
});
