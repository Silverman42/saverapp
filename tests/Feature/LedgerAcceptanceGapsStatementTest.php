<?php

use App\Enums\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\FinancialArtifact;
use App\Models\LedgerAccount;
use App\Services\CollectionReadService;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\StatementPreviewService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require_once __DIR__.'/../LedgerAcceptanceGapFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

beforeEach(function (): void {
    $this->freezeTime();
    Queue::fake();
    Storage::fake('local');
});

/** @return array<string, mixed> */
function ledgerGapPreview(object $viewer, CustomerProfile $customer): array
{
    return app(StatementPreviewService::class)->preview($viewer, $customer, now('Africa/Lagos')->startOfMonth()->toDateString(), now('Africa/Lagos')->toDateString(), 'Africa/Lagos');
}

/** @param array<string, mixed> $preview */
function ledgerGapDocument(array $preview, CustomerProfile $customer): string
{
    return view('financial-document', ['artifact' => (object) ['kind' => 'statement', 'artifact_reference' => 'ART-GAP'],
        'manifest' => ['business_name' => 'Gap Business', 'captured_at' => 'now', 'snapshot_hash' => 'hash', 'supersedes_reference' => null],
        'snapshot' => [...$preview, 'customer_name' => 'Gap Customer', 'customer_id' => $customer->customer_id]])->render();
}

test('LED-AC-037: per-type totals reconcile to activity with a posted withdrawal and no remittance in the Customer statement', function (): void {
    [, $customer, $withdrawal] = ledgerGapPostedWithdrawal($this);

    $preview = ledgerGapPreview($customer->user, $customer);

    $totals = collect($preview['type_totals'])->keyBy('type');
    expect($totals->keys()->all())->toBe(['contribution', 'withdrawal'])
        ->and($totals['contribution']['savings_effect_kobo'])->toBe(100000)
        ->and($totals['withdrawal']['savings_effect_kobo'])->toBe(-30000)
        ->and($totals['withdrawal']['fee_amount_kobo'])->toBe($withdrawal->fee_amount_kobo)
        ->and(array_sum(array_column($preview['type_totals'], 'savings_effect_kobo')))->toBe($preview['activity_kobo'])
        ->and([$preview['opening_kobo'], $preview['activity_kobo'], $preview['closing_kobo']])->toBe([0, 70000, 70000])
        ->and(array_sum(array_column($preview['lines'], 'savings_effect_kobo')))->toBe(70000)
        ->and(collect($preview['lines'])->pluck('type')->contains('remittance'))->toBeFalse()
        ->and(app(CollectionReadService::class)->liability($customer))->toBe($preview['closing_kobo']);
    foreach ($preview['lines'] as $line) {
        $this->actingAs($customer->user)->get(route('transactions.show', $line['reference']))->assertOk();
        $this->actingAs(CustomerProfile::factory()->create()->user)->get(route('transactions.show', $line['reference']))->assertNotFound();
    }
});

test('LED-AC-037: an external fee on a mixed receipt is shown beside the savings effect and never subtracted from it', function (): void {
    [$agent, $customer] = ledgerGapMixedReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();

    $preview = ledgerGapPreview($agent, $customer);

    expect($preview['type_totals'])->toBe([['type' => 'contribution', 'count' => 1, 'savings_effect_kobo' => 200000, 'fee_amount_kobo' => 50000]])
        ->and($preview['lines'][0]['gross_amount_kobo'])->toBe(250000)
        ->and($preview['lines'][0]['fee_amount_kobo'])->toBe(50000)
        ->and([$preview['activity_kobo'], $preview['closing_kobo']])->toBe([200000, 200000])
        ->and($preview['unpaid_fees_kobo'])->toBe(0);
});

test('LED-AC-037: a full reversal nets the original out of activity and closing while both transactions stay listed', function (): void {
    [$agent, $customer, $assignment, $original] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    approveReceiptCorrection($this, $agent, $customer, $assignment, $original);

    $preview = ledgerGapPreview($agent, $customer);

    expect(collect($preview['type_totals'])->pluck('savings_effect_kobo', 'type')->all())->toBe(['contribution' => 200000, 'reversal' => -200000])
        ->and([$preview['opening_kobo'], $preview['activity_kobo'], $preview['closing_kobo']])->toBe([0, 0, 0])
        ->and(array_column($preview['lines'], 'type'))->toEqualCanonicalizing(['contribution', 'reversal']);
});

test('LED-AC-038: reserved, available and unpaid fees stay beside closing while custody and remittance never enter posted totals', function (): void {
    [$agent, $customer, , , $plan] = ledgerGapReceipt(200000, '2000.00', 2);
    reportFeeObligation($agent, $customer, 50000);
    DB::table('withdrawal_reservations')->insert(['customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id, 'owner_reference' => 'WDL-GAP-ST',
        'gross_amount_kobo' => 30000, 'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $unremitted = ledgerGapPreview($agent, $customer);
    ledgerGapRemittance();
    app(LedgerTransactionProjectionService::class)->rebuild();

    $remitted = ledgerGapPreview($agent, $customer);

    foreach ([$unremitted, $remitted] as $preview) {
        expect([$preview['opening_kobo'], $preview['activity_kobo'], $preview['closing_kobo']])->toBe([0, 200000, 200000])
            ->and([$preview['current_reserved_kobo'], $preview['current_available_kobo'], $preview['unpaid_fees_kobo']])->toBe([30000, 170000, 50000])
            ->and(array_column($preview['type_totals'], 'type'))->toBe(['contribution'])
            ->and(array_column($preview['lines'], 'type'))->toBe(['contribution']);
        $html = ledgerGapDocument($preview, $customer);
        expect($html)->toContain('Closing NGN 2000.00')->toContain('reserved for pending withdrawals NGN 300.00')
            ->toContain('available savings NGN 1700.00')->toContain('unpaid fees NGN 500.00');
    }
});

test('LED-AC-041: the issued PDF carries the previewed figures, a verified hash and an authorized integrity-checked download', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $preview = ledgerGapPreview($agent, $customer);
    $payload = ['operation_reference' => (string) Str::uuid(), 'from' => $preview['from'], 'to' => $preview['to'], 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true];

    $this->actingAs($agent)->post(route('customers.statements.issue', $customer->customer_id), $payload)->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    app(FinancialArtifactService::class)->render($artifact->id);
    $artifact->refresh();

    $snapshot = $artifact->snapshot;
    foreach (['from', 'to', 'timezone', 'currency', 'cutoff_at', 'ledger_watermark', 'projection_version', 'opening_kobo', 'activity_kobo', 'closing_kobo', 'type_totals', 'lines',
        'current_reserved_kobo', 'current_available_kobo', 'unpaid_fees_kobo', 'preview_fingerprint'] as $key) {
        expect($snapshot[$key])->toEqual($preview[$key], $key);
    }
    $html = ledgerGapDocument($snapshot, $customer);
    expect($html)->toContain('Closing NGN 2000.00')->toContain('Cutoff '.$preview['cutoff_at'])->toContain('Ledger watermark '.$preview['ledger_watermark'])
        ->toContain($preview['lines'][0]['reference'])
        ->and($artifact->manifest['control_totals'])->toBe(['opening_kobo' => 0, 'activity_kobo' => 200000, 'closing_kobo' => 200000, 'line_count' => 1])
        ->and($artifact->snapshot_hash)->toBe($artifact->manifest['snapshot_hash'])
        ->and($artifact->status)->toBe('ready');
    $stored = Crypt::decryptString(Storage::disk('local')->get($artifact->storage_path));
    expect($stored)->toStartWith('%PDF')->and(hash('sha256', $stored))->toBe($artifact->artifact_hash)
        ->and(app(FinancialArtifactService::class)->download($agent, $artifact))->toBe($stored);

    Storage::disk('local')->put($artifact->storage_path, Crypt::encryptString($stored.'tampered'));
    $this->actingAs($agent)->get(URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $agent->id]))
        ->assertStatus(503);
});

test('LED-AC-042: an inconsistent ledger source or altered snapshot produces no artifact file and no partial PDF', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $preview = ledgerGapPreview($agent, $customer);
    $payload = ['operation_reference' => (string) Str::uuid(), 'from' => $preview['from'], 'to' => $preview['to'], 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true];
    $this->actingAs($agent)->post(route('customers.statements.issue', $customer->customer_id), $payload)->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    expect(fn () => $artifact->update(['snapshot' => [...$artifact->snapshot, 'closing_kobo' => 999999]]))->toThrow(RuntimeException::class, 'immutable');
    DB::table('financial_artifacts')->where('id', $artifact->id)->update(['snapshot' => Crypt::encryptString(json_encode([...$artifact->snapshot, 'closing_kobo' => 999999], JSON_THROW_ON_ERROR))]);

    expect(fn () => app(FinancialArtifactService::class)->render($artifact->id))->toThrow(RuntimeException::class, 'snapshot integrity mismatch');

    expect(Storage::disk('local')->allFiles())->toBe([])->and($artifact->fresh()->status)->toBe('queued')->and($artifact->fresh()->storage_path)->toBeNull();
    DB::table('ledger_transaction_projections')->where('customer_profile_id', $customer->id)->delete();
    $before = DB::table('financial_artifacts')->count();
    $this->postJson(route('customers.statements.issue', $customer->customer_id), [...$payload, 'operation_reference' => (string) Str::uuid()])->assertConflict();

    expect(DB::table('financial_artifacts')->count())->toBe($before)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('LED-AC-034: a single-Customer statement is baseline scoped read access that audit.view neither grants nor withdraws', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $preview = ledgerGapPreview($agent, $customer);
    $payload = fn (): array => ['operation_reference' => (string) Str::uuid(), 'from' => $preview['from'], 'to' => $preview['to'],
        'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true];

    $this->actingAs(ledgerGapAdmin())->post(route('customers.statements.issue', $customer->customer_id), $payload())->assertRedirect();
    $this->actingAs(ledgerGapAdmin([AdminPermission::AuditView]))->post(route('customers.statements.issue', $customer->customer_id), $payload())->assertRedirect();

    expect(FinancialArtifact::query()->where('kind', 'statement')->count())->toBe(2)
        ->and(FinancialArtifact::query()->where('kind', 'report')->count())->toBe(0);
});

test('LED-AC-055: removing each owner dependency makes reads unavailable or withholds only the labelled figure, never zero', function (string $dependency, string $balance, string $statement): void {
    [$agent, $customer, , , $plan] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $liability = LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->value('id');
    match ($dependency) {
        'unmapped savings account' => LedgerAccount::query()->whereKey($liability)->update(['mapping_status' => 'unmapped']),
        'never verified projection' => DB::table('ledger_projection_state')->update(['status' => 'unavailable', 'verified_at' => null, 'ledger_group_watermark' => 0]),
        'invalid reservation' => DB::table('withdrawal_reservations')->insert(['customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id, 'owner_reference' => 'WDL-GAP-BAD',
            'gross_amount_kobo' => 900000000, 'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]),
        'damaged group entry' => DB::table('ledger_entries')->where('ledger_account_id', $liability)->update(['side' => 'unknown']),
        'zero amount entry' => DB::table('ledger_entries')->where('ledger_account_id', $liability)->update(['amount_kobo' => 0]),
        'missing projected transactions' => DB::table('ledger_transaction_projections')->where('customer_profile_id', $customer->id)->delete(),
    };
    $reads = app(LedgerTransactionReadService::class);

    $position = $reads->balance($agent, $customer);
    $statementResult = ledgerGapPreview($agent, $customer);
    $this->actingAs($agent);
    $endpoint = $this->getJson(route('customers.ledger-balance', $customer->customer_id));

    expect($position['status'])->toBe($balance)->and($statementResult['status'])->toBe($statement)
        ->and($endpoint->json('status'))->toBe($balance)
        ->and($reads->balances($agent, [$customer])[$customer->id]['status'])->toBe($balance);
    if ($balance === 'unavailable') {
        expect($position)->toBe(['status' => 'unavailable'])->and($endpoint->json())->toBe(['status' => 'unavailable']);
    }
    if ($statement === 'unavailable') {
        expect($statementResult)->toBe(['status' => 'unavailable']);
    }
    if ($dependency === 'invalid reservation') {
        expect($statementResult['current_reserved_kobo'])->toBeNull()->and($statementResult['current_available_kobo'])->toBeNull()->and($statementResult['closing_kobo'])->toBe(200000);
    }
    if ($dependency === 'missing projected transactions') {
        expect($position['liability_kobo'])->toBe(200000);
    }
})->with([
    'unmapped savings account' => ['unmapped savings account', 'unavailable', 'unavailable'],
    'never verified projection' => ['never verified projection', 'unavailable', 'unavailable'],
    'invalid reservation' => ['invalid reservation', 'unavailable', 'ready'],
    'damaged group entry' => ['damaged group entry', 'unavailable', 'unavailable'],
    'zero amount entry' => ['zero amount entry', 'unavailable', 'unavailable'],
    'missing projected transactions' => ['missing projected transactions', 'ready', 'unavailable'],
]);
