<?php

use App\Models\LedgerAccount;
use App\Services\CollectionReadService;
use App\Services\LedgerTransactionProjectionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../CashExecutionFixtures.php';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Destructive rehearsal is allowed only in saverapp_audit_testing.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

/** @return array<string, array{count: int, hash: string}> */
function restoreControlManifest(): array
{
    $manifest = [];
    foreach (['ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'cash_recoveries', 'fee_obligations', 'fee_obligation_entries', 'financial_workflow_supplements', 'platform_recovery_work'] as $table) {
        $rows = DB::table($table)->orderBy('id')->get()->toArray();
        $manifest[$table] = ['count' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    return $manifest;
}

test('isolated mysql logical backup restore and projection rebuild preserve source hashes reservations and balances', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Controlled test-only delivered cash.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $before = restoreControlManifest();
    $balance = app(CollectionReadService::class)->position($customer)['liability_kobo'];
    $projection = DB::table('ledger_transaction_projections')->where('projection_version', DB::table('ledger_projection_state')->value('active_version'))->orderBy('ledger_transaction_reference_id')->get(['ledger_transaction_reference_id', 'source_hash', 'gross_amount_kobo', 'savings_effect_kobo'])->toArray();
    $pdo = DB::connection()->getPdo();
    $dump = 'SET FOREIGN_KEY_CHECKS=0;';
    foreach (DB::select('SHOW TABLES') as $tableRecord) {
        $table = $tableRecord->Tables_in_saverapp_audit_testing;
        $definition = DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
        $dump .= $definition->{'Create Table'}.';';
        foreach (DB::table($table)->get() as $record) {
            $values = (array) $record;
            $columns = implode(',', array_map(fn (string $column): string => '`'.$column.'`', array_keys($values)));
            $encoded = implode(',', array_map(fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($values)));
            $dump .= 'INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$encoded.');';
        }
    }
    foreach (DB::select('SHOW TRIGGERS') as $trigger) {
        $definition = DB::selectOne('SHOW CREATE TRIGGER `'.$trigger->Trigger.'`');
        $dump .= $definition->{'SQL Original Statement'}.';';
    }
    $dump .= 'SET FOREIGN_KEY_CHECKS=1;';
    expect($dump)->toContain('Immutable financial evidence');
    $this->artisan('db:wipe', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    DB::unprepared($dump);
    expect(restoreControlManifest())->toBe($before)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($balance);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $rebuilt = DB::table('ledger_transaction_projections')->where('projection_version', DB::table('ledger_projection_state')->value('active_version'))->orderBy('ledger_transaction_reference_id')->get(['ledger_transaction_reference_id', 'source_hash', 'gross_amount_kobo', 'savings_effect_kobo'])->toArray();
    expect(json_encode($rebuilt, JSON_THROW_ON_ERROR))->toBe(json_encode($projection, JSON_THROW_ON_ERROR));
    $afterRebuild = restoreControlManifest();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect(restoreControlManifest())->toBe($afterRebuild);
});
