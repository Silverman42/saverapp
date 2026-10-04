<?php

use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('an empty authoritative ledger can be verified without inventing money', function (): void {
    $result = app(LedgerTransactionProjectionService::class)->rebuild();

    expect($result)->toMatchArray(['transactions' => 0, 'groups' => 0])
        ->and(app(LedgerTransactionReadService::class)->state()['status'])->toBe('ready');

    $customer = CustomerProfile::factory()->create();
    $position = app(LedgerTransactionReadService::class)->balance($customer->user, $customer);
    expect($position)->toMatchArray([
        'status' => 'ready', 'liability_kobo' => 0, 'reservations_kobo' => 0, 'available_kobo' => 0,
    ]);
});

test('an unsupported posting cannot promote a transaction projection', function (): void {
    $agent = User::factory()->agent()->create();
    LedgerPostingGroup::create([
        'posting_reference' => 'COL-UNVERIFIED-001',
        'idempotency_key' => 'unverified-'.Str::uuid(),
        'payload_hash' => str_repeat('a', 64),
        'source_type' => 'unsupported_event', 'source_id' => '1',
        'event_type' => 'unsupported_event', 'currency' => 'NGN',
        'actor_user_id' => $agent->id, 'customer_profile_id' => null,
        'occurred_at' => now(), 'committed_at' => now(),
    ]);

    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())
        ->toThrow(RuntimeException::class, 'unsupported or unlinked');
    expect(DB::table('ledger_projection_state')->value('status'))->toBe('unavailable')
        ->and(DB::table('ledger_transaction_projections')->count())->toBe(0)
        ->and(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(1);

    LedgerPostingGroup::query()->delete();
    $result = app(LedgerTransactionProjectionService::class)->rebuild();
    expect($result['groups'])->toBe(0)
        ->and(DB::table('ledger_projection_state')->value('status'))->toBe('ready')
        ->and(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(0)
        ->and(DB::table('ledger_integrity_incidents')->where('status', 'recovered')->count())->toBe(1)
        ->and(DB::table('ledger_integrity_incidents')->where('status', 'resolved')->count())->toBe(0);
});
