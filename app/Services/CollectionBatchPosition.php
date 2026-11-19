<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
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
        $expected = $this->expectedKobo($batch);
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
        if ($batch->custody_account_code === LedgerAccountCode::CustomerWalletLiability->value) {
            return $this->walletPosition($batch, $expected, $cashRemitted);
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
        if ($this->uncollectedKobo($batch) !== 0) {
            throw new ConflictHttpException('Only cash batches can carry an uncollected receipt correction.');
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

    /**
     * A wallet batch holds no physical money: every receipt is a posted wallet debit, so nothing is outstanding or remittable.
     *
     * @return array{expected_kobo: int, received_kobo: int, outstanding_kobo: int, settlement_pending: bool}
     */
    private function walletPosition(CollectionBatch $batch, int $expected, int $cashRemitted): array
    {
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::CustomerWalletLiability->value)->first();
        if ($cashRemitted !== 0 || $this->uncollectedKobo($batch) !== 0 || $account === null || $account->mapping_status !== 'mapped'
            || $account->account_class !== LedgerAccountClass::CustomerWalletLiability || $account->normal_balance !== LedgerEntrySide::Credit) {
            throw new ConflictHttpException('The wallet batch custody does not reconcile.');
        }
        foreach ($batch->receipts()->lazyById(100) as $receipt) {
            app(CollectionReceiptMethod::class)->assertReceipt($receipt);
            $groups = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->pluck('ledger_posting_group_id')->all();
            if ($receipt->savings_posting_group_id !== null) {
                $groups[] = $receipt->savings_posting_group_id;
            }
            $posted = (int) DB::table('ledger_entries')->whereIn('ledger_posting_group_id', $groups)
                ->where('ledger_account_id', $account->id)->where('side', 'debit')->sum('amount_kobo');
            if ($receipt->method !== 'wallet' || $posted !== $receipt->tender_amount_kobo) {
                throw new ConflictHttpException('Wallet receipts and posted wallet debits do not reconcile.');
            }
        }

        return ['expected_kobo' => $expected, 'received_kobo' => $expected, 'outstanding_kobo' => 0, 'settlement_pending' => false];
    }

    /**
     * Cash the batch should hold: every recorded receipt, less the part of any receipt an approved correction
     * found was never collected.
     */
    public function expectedKobo(CollectionBatch $batch): int
    {
        $recorded = (int) $batch->receipts()->sum('tender_amount_kobo');
        $uncollected = $this->uncollectedKobo($batch);
        if ($uncollected < 0 || $uncollected > $recorded) {
            throw new ConflictHttpException('The batch uncollected corrections do not reconcile.');
        }

        return $recorded - $uncollected;
    }

    /** Recorded receipt cash that approved corrections found was never collected. */
    public function uncollectedKobo(CollectionBatch $batch): int
    {
        return (int) $this->uncollectedQuery()->where('receipts.collection_batch_id', $batch->id)
            ->sum(DB::raw('reversals.original_amount_kobo - reversals.received_amount_kobo'));
    }

    /**
     * Approved partly-received receipt corrections, joined to their original receipt.
     */
    public function uncollectedQuery(): QueryBuilder
    {
        return DB::table('reversal_requests as reversals')
            ->join('collection_receipts as receipts', 'receipts.savings_posting_group_id', '=', 'reversals.original_posting_group_id')
            ->where('reversals.state', 'approved_posted')->whereNotNull('reversals.received_amount_kobo')
            ->whereNull('receipts.replacement_reversal_id');
    }
}
