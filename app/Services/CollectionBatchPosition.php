<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerEntrySide;
use App\Models\CollectionBatch;
use App\Models\LedgerAccount;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionBatchPosition
{
    /** @return array{expected_kobo: int, received_kobo: int, outstanding_kobo: int, settlement_pending: bool} */
    public function read(CollectionBatch $batch): array
    {
        $expected = (int) $batch->receipts()->sum('tender_amount_kobo');
        $cashRemitted = (int) $batch->remittances()->sum('amount_kobo');
        if ($batch->custody_account_code === 'agent_receivable_ngn') {
            $orphaned = DB::table('ledger_entries as lines')->join('ledger_posting_groups as posting', 'posting.id', '=', 'lines.ledger_posting_group_id')
                ->where('lines.agent_profile_id', $batch->agent_profile_id)->where('posting.source_type', 'cash_remittance')
                ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('cash_remittances as handoff')
                    ->whereColumn('handoff.id', 'posting.source_id')->whereColumn('handoff.ledger_posting_group_id', 'posting.id'))->exists();
            if ($orphaned) {
                throw new ConflictHttpException('The Agent cash handoff journal has no matching durable source.');
            }
            foreach ($batch->remittances()->with('postingGroup.entries.account')->lazyById(100) as $remittance) {
                app(CollectionRemittanceProof::class)->assertPosted($remittance, $batch);
            }
            if ($cashRemitted > $expected) {
                throw new ConflictHttpException('The batch custody amounts do not reconcile.');
            }

            return ['expected_kobo' => $expected, 'received_kobo' => $cashRemitted, 'outstanding_kobo' => $expected - $cashRemitted, 'settlement_pending' => false];
        }
        if (! in_array($batch->custody_account_code, ['business_bank_ngn', 'payment_clearing_ngn'], true) || $cashRemitted !== 0) {
            throw new ConflictHttpException('The batch has an unsupported custody or cash handoff.');
        }
        $account = LedgerAccount::query()->where('code', $batch->custody_account_code)->first();
        if ($account === null || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
            || $account->account_class !== LedgerAccountClass::Asset || $account->normal_balance !== LedgerEntrySide::Debit) {
            throw new ConflictHttpException('The bank or clearing custody mapping is unavailable.');
        }
        foreach ($batch->receipts()->lazyById(100) as $receipt) {
            app(CollectionReceiptMethod::class)->assertReceipt($receipt);
            if ($receipt->collection_method_version_id !== $batch->collection_method_version_id || $receipt->custody_account_code !== $batch->custody_account_code) {
                throw new ConflictHttpException('The receipt and batch method identities do not reconcile.');
            }
            $groups = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->pluck('ledger_posting_group_id')->all();
            if ($receipt->savings_posting_group_id !== null) {
                $groups[] = $receipt->savings_posting_group_id;
            }
            $posted = (int) DB::table('ledger_entries')->whereIn('ledger_posting_group_id', $groups)
                ->where('ledger_account_id', $account->id)->where('side', 'debit')->sum('amount_kobo');
            if ($posted !== $receipt->tender_amount_kobo) {
                throw new ConflictHttpException('Verified evidence and posted custody do not reconcile.');
            }
        }
        $received = $expected;
        if ($batch->custody_account_code === 'payment_clearing_ngn') {
            $received = 0;
            foreach (DB::table('collection_settlements')->where('collection_batch_id', $batch->id)->orderBy('id')->lazyById(100) as $settlement) {
                app(CollectionSettlementService::class)->assertPosted($settlement, true);
                $received += (int) $settlement->amount_kobo;
            }
            if ($received > $expected) {
                throw new ConflictHttpException('Settlements exceed original clearing custody.');
            }
        }

        return ['expected_kobo' => $expected, 'received_kobo' => $received,
            'outstanding_kobo' => $expected - $received,
            'settlement_pending' => $batch->custody_account_code === 'payment_clearing_ngn' && $received < $expected];
    }
}
