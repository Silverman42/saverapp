<?php

use App\Models\AgentProfile;
use App\Models\CashRemittance;
use App\Models\LedgerPostingGroup;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportReadService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CollectionExceptionFixtures.php';

function postedCashHandoffFixture(object $test): array
{
    [$agent, $customer, $admin, $batch, , $date] = collectionInvestigationFixture($test, openCase: false);
    $test->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->version, 'handoff_reference' => 'VERIFIED-CASH-OWNER', 'amount_ngn' => '2000.00',
        'handoff_date' => $date, 'receiving_location' => 'Lagos business office',
        'source_attestation' => 'Counted complete original Agent tender into business custody.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return [$agent, $customer, $admin, $batch->fresh(), CashRemittance::query()->sole()];
}

test('verified posted cash custody permits reconciliation without changing Customer liability', function (): void {
    [$agent, $customer, , $batch] = postedCashHandoffFixture($this);
    expect(app(CollectionBatchPosition::class)->read($batch))->toBe(['expected_kobo' => 200000, 'received_kobo' => 200000,
        'outstanding_kobo' => 0, 'settlement_pending' => false]);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->version, 'reason' => 'Exact posted handoff and original custody reconcile.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    expect(app(CollectionReadService::class)->agentOffboardingStatus($agent->agentProfile))->toBe('passed');
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('missing or mismatched cash handoff journals cannot clear custody gates or project as confirmed', function (string $damage): void {
    [$agent, $customer, , $batch, $remittance] = postedCashHandoffFixture($this);
    $change = match ($damage) {
        'missing', 'deleted' => ['ledger_posting_group_id' => null],
        'amount' => ['amount_kobo' => 199999],
        'agent' => ['agent_profile_id' => AgentProfile::factory()->active()->create()->id],
        'actor' => ['confirmed_by_user_id' => $agent->id],
        'date' => ['handoff_date' => now()->subDay()->toDateString()],
        'other_source' => ['ledger_posting_group_id' => $batch->receipts()->sole()->savings_posting_group_id],
    };
    if ($damage === 'deleted') {
        DB::table('cash_remittances')->where('id', $remittance->id)->delete();
    } else {
        DB::table('cash_remittances')->where('id', $remittance->id)->update($change);
    }
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    expect(fn () => app(CollectionBatchPosition::class)->read($batch))->toThrow(ConflictHttpException::class);
    $this->get(route('collection-batches.show', $batch))->assertConflict();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->version, 'reason' => 'A handoff row alone is insufficient proof of custody.', 'confirmed' => true,
    ])->assertConflict();
    $this->assertDatabaseCount('collection_batch_reviews', 0);
    DB::table('collection_batches')->where('id', $batch->id)->update(['status' => 'reconciled']);
    expect(app(CollectionReadService::class)->agentOffboardingStatus($agent->agentProfile))->toBe('unavailable');
    expect(app(CollectionReadService::class)->archivalStatus($customer))->toBe('unavailable');
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
})->with(['deleted source' => 'deleted', 'missing journal' => 'missing', 'mismatched amount' => 'amount', 'different Agent' => 'agent',
    'different confirming actor' => 'actor', 'different handoff date' => 'date', 'unrelated balanced journal' => 'other_source']);

test('posted cash handoff facts and posting identity cannot be rewritten or deleted', function (): void {
    [, , , , $remittance] = postedCashHandoffFixture($this);
    foreach (['amount_kobo' => 1, 'handoff_reference' => 'REWRITE', 'ledger_posting_group_id' => null,
        'receiving_location' => 'Changed destination', 'source_attestation' => 'Changed evidence'] as $field => $value) {
        expect(fn () => $remittance->fresh()->update([$field => $value]))->toThrow(RuntimeException::class, 'Confirmed cash handoff evidence is immutable.');
    }
    expect(fn () => $remittance->fresh()->delete())->toThrow(RuntimeException::class, 'Cash handoff history cannot be deleted.');
    $this->assertDatabaseCount('cash_remittances', 1);
});

test('initial handoff posting attachment rejects another balanced cash handoff source', function (): void {
    [, , , , $posted] = postedCashHandoffFixture($this);
    $draft = CashRemittance::create(['collection_batch_id' => $posted->collection_batch_id, 'agent_profile_id' => $posted->agent_profile_id,
        'confirmed_by_user_id' => $posted->confirmed_by_user_id, 'handoff_reference' => 'DIFFERENT-SOURCE',
        'amount_kobo' => 200000, 'handoff_date' => $posted->handoff_date, 'receiving_location' => 'Lagos business office',
        'source_attestation' => 'A different proposed handoff cannot reuse the original posted source.']);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    expect(fn () => $draft->update(['ledger_posting_group_id' => $posted->ledger_posting_group_id]))->toThrow(ConflictHttpException::class);
    expect($draft->fresh()->ledger_posting_group_id)->toBeNull();
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

test('cash custody reporting refuses damaged handoff evidence even when its projection was previously valid', function (string $damage): void {
    [$agent, , $admin, $batch, $remittance] = postedCashHandoffFixture($this);
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->version,
        'reason' => 'Original verified custody fully reconciles.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $filters = ['from' => $remittance->handoff_date, 'to' => $remittance->handoff_date, 'page_size' => 25, 'group' => ''];
    expect(app(ReportReadService::class)->read($admin, 'reconciliation', $filters)['sections']['batch_reconciliation']['status'])->toBe('Partial');
    if ($damage === 'deleted') {
        DB::table('cash_remittances')->where('id', $remittance->id)->delete();
    } else {
        DB::table('cash_remittances')->where('id', $remittance->id)->update(['confirmed_by_user_id' => $agent->id]);
    }
    expect(app(ReportReadService::class)->read($admin, 'reconciliation', $filters)['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
})->with(['actor', 'deleted']);
