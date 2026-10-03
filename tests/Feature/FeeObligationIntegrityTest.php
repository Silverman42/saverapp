<?php

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\FeeObligationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../FeeFixtures.php';

test('fee balances reject damaged original assessment evidence without changing financial history', function (string $damage): void {
    $agent = User::factory()->agent()->create();
    $customer = CustomerProfile::factory()->create();
    $obligation = reportFeeObligation($agent, $customer);
    $entry = DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)->sole();
    if ($damage === 'missing') {
        DB::table('fee_obligation_entries')->where('id', $entry->id)->delete();
    } elseif ($damage === 'duplicate') {
        $copy = (array) $entry;
        unset($copy['id']);
        $copy['idempotency_key'] = 'damaged-duplicate-'.Str::uuid();
        $copy['source_id'] = 'damaged-duplicate-source';
        DB::table('fee_obligation_entries')->insert($copy);
    } else {
        $patch = match ($damage) {
            'currency' => ['currency' => 'USD'],
            'zero' => ['amount_kobo' => 0],
            'negative' => ['amount_kobo' => -1],
            'fractional' => ['amount_kobo' => 50000.5],
            'mismatched' => ['amount_kobo' => 49999],
            'unsupported type' => ['entry_type' => 'unknown_fee_effect'],
            default => throw new LogicException('Unknown damage fixture.'),
        };
        DB::table('fee_obligation_entries')->where('id', $entry->id)->update($patch);
    }
    $before = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
    foreach (['assessedAmountKobo', 'settledAmountKobo', 'waivedAmountKobo', 'outstandingAmountKobo'] as $method) {
        expect(fn () => $obligation->fresh()->{$method}())->toThrow(RuntimeException::class);
    }
    $rows = app(FeeObligationService::class)->customerObligations($customer->fresh());
    expect($rows[0]['status'])->toBe('unavailable')
        ->and($rows[0])->not->toHaveKey('outstanding_amount_kobo')
        ->and(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($before);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
})->with(['missing', 'duplicate', 'currency', 'zero', 'negative', 'fractional', 'mismatched', 'unsupported type']);
