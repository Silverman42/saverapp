<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

test('mysql refuses query-builder updates and deletes of posted ledger rows', function (): void {
    $actor = User::factory()->admin()->create();
    $account = LedgerAccount::query()->where('code', 'business_cash_ngn')->firstOrFail();
    $group = LedgerPostingGroup::create(['posting_reference' => 'TEST-IMMUTABLE-'.Str::uuid(), 'idempotency_key' => 'immutable-'.Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'source_type' => 'test', 'source_id' => '1', 'event_type' => 'test', 'currency' => 'NGN',
        'actor_user_id' => $actor->id, 'occurred_at' => now(), 'committed_at' => now()]);
    $entry = LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => 1, 'ledger_account_id' => $account->id, 'side' => 'debit', 'amount_kobo' => 100]);

    foreach ([
        fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_kobo' => 1]),
        fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete(),
        fn () => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['source_id' => '2']),
        fn () => DB::table('ledger_posting_groups')->where('id', $group->id)->delete(),
    ] as $statement) {
        expect($statement)->toThrow(QueryException::class, 'Posted ledger rows are immutable');
    }

    expect(DB::table('ledger_entries')->where('id', $entry->id)->value('amount_kobo'))->toBe(100)
        ->and(DB::table('ledger_posting_groups')->where('id', $group->id)->value('source_id'))->toBe('1');
});
