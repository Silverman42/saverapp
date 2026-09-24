<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\AuditEvent;
use App\Models\CashRemittance;
use App\Models\CollectionReceipt;
use App\Models\LedgerPostingGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LedgerTransactionProjectionService
{
    public function __construct(private PublicIdGenerator $ids) {}

    /** @return array{version: int, transactions: int, groups: int} */
    public function rebuild(): array
    {
        try {
            return $this->rebuildVerified();
        } catch (RuntimeException $exception) {
            DB::transaction(function () use ($exception): void {
                $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
                if ($state === null) {
                    return;
                }
                $reference = (string) Str::uuid();
                DB::table('ledger_integrity_incidents')->insert([
                    'incident_reference' => $reference,
                    'category' => 'projection_rebuild',
                    'status' => 'open',
                    'summary' => mb_substr($exception->getMessage(), 0, 255),
                    'projection_version' => (int) $state->active_version,
                    'ledger_group_watermark' => (int) (LedgerPostingGroup::query()->max('id') ?? 0),
                    'detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('ledger_projection_state')->where('id', 1)->update([
                    'status' => 'unavailable', 'updated_at' => now(),
                ]);
                AuditEvent::record('ledger.integrity_incident', LedgerPostingGroup::class, null, $reference, [
                    'category' => 'projection_rebuild',
                    'projection_version' => (int) $state->active_version,
                ]);
            }, attempts: 3);

            throw $exception;
        }
    }

    /** @return array{version: int, transactions: int, groups: int} */
    private function rebuildVerified(): array
    {
        return DB::transaction(function (): array {
            $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null) {
                throw new RuntimeException('Ledger projection state is unavailable.');
            }
            $version = (int) $state->active_version + 1;
            $covered = [];
            $transactions = 0;

            foreach (CollectionReceipt::query()->lazyById(100) as $receipt) {
                $groups = $this->receiptGroups($receipt);
                if ($groups === []) {
                    continue;
                }
                $this->writeReceipt($receipt, $groups, $version);
                foreach ($groups as $group) {
                    $covered[$group->id] = true;
                }
                $transactions++;
            }
            foreach (CashRemittance::query()->whereNotNull('ledger_posting_group_id')->lazyById(100) as $remittance) {
                $group = LedgerPostingGroup::query()->whereKey($remittance->ledger_posting_group_id)->firstOrFail();
                if ($group->source_type !== 'cash_remittance' || $group->source_id !== (string) $remittance->id) {
                    throw new RuntimeException('A remittance has an inconsistent ledger source.');
                }
                $this->writeRemittance($remittance, $group, $version);
                $covered[$group->id] = true;
                $transactions++;
            }

            $groups = LedgerPostingGroup::query()->count();
            if (count($covered) !== $groups) {
                throw new RuntimeException('An unsupported or unlinked posting group prevents projection promotion.');
            }
            DB::table('ledger_projection_state')->where('id', 1)->update([
                'active_version' => $version,
                'ledger_group_watermark' => (int) (LedgerPostingGroup::query()->max('id') ?? 0),
                'verified_at' => now(), 'status' => 'ready', 'updated_at' => now(),
            ]);
            DB::table('ledger_integrity_incidents')->where('category', 'projection_rebuild')->where('status', 'open')->update([
                'status' => 'resolved', 'updated_at' => now(),
            ]);
            AuditEvent::record('ledger.projection_promoted', LedgerPostingGroup::class, null, (string) $version, [
                'projection_version' => $version, 'transaction_count' => $transactions,
                'posting_group_count' => $groups,
            ]);

            return ['version' => $version, 'transactions' => $transactions, 'groups' => $groups];
        }, attempts: 3);
    }

    public function projectReceipt(CollectionReceipt $receipt): void
    {
        $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
        if ($state === null || $state->status !== 'ready') {
            return;
        }
        $this->writeReceipt($receipt, $this->receiptGroups($receipt), (int) $state->active_version);
        $this->advanceWatermark();
    }

    public function projectRemittance(int $remittanceId): void
    {
        $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
        if ($state === null || $state->status !== 'ready') {
            return;
        }
        $remittance = CashRemittance::query()->whereKey($remittanceId)->firstOrFail();
        if ($remittance->ledger_posting_group_id === null) {
            throw new RuntimeException('The confirmed remittance has no ledger group.');
        }
        $this->writeRemittance($remittance, LedgerPostingGroup::query()->whereKey($remittance->ledger_posting_group_id)->firstOrFail(), (int) $state->active_version);
        $this->advanceWatermark();
    }

    /** @return list<LedgerPostingGroup> */
    private function receiptGroups(CollectionReceipt $receipt): array
    {
        $ids = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)
            ->pluck('ledger_posting_group_id')->all();
        if ($receipt->savings_posting_group_id !== null) {
            $ids[] = $receipt->savings_posting_group_id;
        }
        $application = LedgerPostingGroup::query()->where('source_type', 'fee_application')
            ->where('source_id', (string) $receipt->id)->first();
        if ($application !== null) {
            $ids[] = $application->id;
        }

        return array_values(LedgerPostingGroup::query()->whereIn('id', $ids)->orderBy('id')->get()->all());
    }

    /** @param list<LedgerPostingGroup> $groups */
    private function writeReceipt(CollectionReceipt $receipt, array $groups, int $version): void
    {
        if ($groups === []) {
            throw new RuntimeException('A posted receipt has no ledger group.');
        }
        $savingsCredit = 0;
        $savingsDebit = 0;
        $feeCredit = 0;
        $committedAt = null;
        foreach ($groups as $group) {
            $this->assertBalanced($group);
            if ($group->customer_profile_id !== $receipt->customer_profile_id
                || ! in_array($group->source_type, ['collection_receipt', 'fee_application'], true)
                || $group->occurred_on?->toDateString() !== $receipt->received_date
                || $group->business_timezone !== $receipt->timezone
                || $group->thrift_plan_id !== $receipt->thrift_plan_id) {
                throw new RuntimeException('Receipt and ledger dimensions do not reconcile.');
            }
            $committedAt = $committedAt === null || $group->committed_at->greaterThan($committedAt)
                ? $group->committed_at : $committedAt;
            foreach ($group->entries as $entry) {
                if ($entry->account->code === LedgerAccountCode::CustomerSavingsLiability) {
                    if ($entry->side->value === 'credit') {
                        $savingsCredit = $this->checkedAdd($savingsCredit, $entry->amount_kobo);
                    } else {
                        $savingsDebit = $this->checkedAdd($savingsDebit, $entry->amount_kobo);
                    }
                }
                if ($entry->account->code === LedgerAccountCode::FeeIncome && $entry->side->value === 'credit') {
                    $feeCredit = $this->checkedAdd($feeCredit, $entry->amount_kobo);
                }
            }
        }
        if ($savingsCredit !== $receipt->savings_amount_kobo
            || $feeCredit < $receipt->fee_amount_kobo
            || $savingsCredit + $receipt->fee_amount_kobo !== $receipt->tender_amount_kobo) {
            throw new RuntimeException('Receipt amounts and ledger components do not reconcile.');
        }
        $this->upsertProjection($this->reference('collection_receipt', (string) $receipt->id, $receipt->received_date, $receipt->receipt_reference), $version, [
            'customer_profile_id' => $receipt->customer_profile_id,
            'type' => 'contribution', 'status' => 'posted', 'occurred_on' => $receipt->received_date,
            'committed_at' => $committedAt, 'timezone' => $receipt->timezone, 'currency' => 'NGN',
            'gross_amount_kobo' => $receipt->tender_amount_kobo,
            'savings_effect_kobo' => $savingsCredit - $savingsDebit,
            'fee_amount_kobo' => $feeCredit, 'posting_group_count' => count($groups),
            'source_max_group_id' => max(array_map(fn (LedgerPostingGroup $group): int => $group->id, $groups)),
            'source_hash' => $this->sourceHash($groups),
        ]);
    }

    private function writeRemittance(CashRemittance $remittance, LedgerPostingGroup $group, int $version): void
    {
        $this->assertBalanced($group);
        if ($group->occurred_on?->toDateString() !== $remittance->handoff_date) {
            throw new RuntimeException('Remittance occurrence date and ledger group do not reconcile.');
        }
        $this->upsertProjection($this->reference('cash_remittance', (string) $remittance->id, $remittance->handoff_date), $version, [
            'customer_profile_id' => null, 'type' => 'remittance', 'status' => 'posted',
            'occurred_on' => $remittance->handoff_date, 'committed_at' => $group->committed_at,
            'timezone' => 'Africa/Lagos', 'currency' => 'NGN',
            'gross_amount_kobo' => (int) $remittance->amount_kobo,
            'savings_effect_kobo' => 0, 'fee_amount_kobo' => 0,
            'posting_group_count' => 1, 'source_hash' => $this->sourceHash([$group]),
            'source_max_group_id' => $group->id,
        ]);
    }

    private function assertBalanced(LedgerPostingGroup $group): void
    {
        $group->load('entries.account');
        if ($group->entries->count() < 2 || $group->currency !== 'NGN'
            || $group->occurred_on === null || $group->business_timezone === null
            || $group->schema_version < 1) {
            throw new RuntimeException('The ledger group is incomplete.');
        }
        $debits = 0;
        $credits = 0;
        foreach ($group->entries as $entry) {
            if ($entry->amount_kobo < 1 || $entry->account->currency !== $group->currency) {
                throw new RuntimeException('A ledger entry has invalid amount or currency.');
            }
            if ($entry->account->code === LedgerAccountCode::CustomerSavingsLiability
                && ($entry->customer_profile_id === null || $entry->customer_profile_id !== $group->customer_profile_id
                    || $entry->thrift_plan_id !== $group->thrift_plan_id)) {
                throw new RuntimeException('A Customer liability entry has an inconsistent Customer dimension.');
            }
            if ($entry->account->code === LedgerAccountCode::AgentReceivable && $entry->agent_profile_id === null) {
                throw new RuntimeException('An Agent receivable entry has no responsible Agent.');
            }
            if ($entry->side->value === 'debit') {
                $debits = $this->checkedAdd($debits, $entry->amount_kobo);
            } else {
                $credits = $this->checkedAdd($credits, $entry->amount_kobo);
            }
        }
        if ($debits !== $credits) {
            throw new RuntimeException('The ledger group does not balance.');
        }
    }

    private function reference(string $rootType, string $rootId, string $date, ?string $knownReference = null): int
    {
        $existing = DB::table('ledger_transaction_references')->where('root_type', $rootType)
            ->where('root_id', $rootId)->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }
        $number = $knownReference === null ? $this->ids->generate('ledger_transaction') : null;

        return DB::table('ledger_transaction_references')->insertGetId([
            'root_type' => $rootType, 'root_id' => $rootId,
            'transaction_reference' => $knownReference ?? 'TXN-'.str_replace('-', '', $date).'-'.$number,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function upsertProjection(int $referenceId, int $version, array $data): void
    {
        DB::table('ledger_transaction_projections')->updateOrInsert(
            ['ledger_transaction_reference_id' => $referenceId, 'projection_version' => $version],
            [...$data, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** @param list<LedgerPostingGroup> $groups */
    private function sourceHash(array $groups): string
    {
        $snapshot = [];
        foreach ($groups as $group) {
            $lines = [];
            foreach ($group->entries as $entry) {
                $lines[] = [$entry->line_number, $entry->ledger_account_id, $entry->side->value, $entry->amount_kobo];
            }
            $snapshot[] = [$group->id, $group->payload_hash, $lines];
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function advanceWatermark(): void
    {
        DB::table('ledger_projection_state')->where('id', 1)->update([
            'ledger_group_watermark' => (int) (LedgerPostingGroup::query()->max('id') ?? 0),
            'verified_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new RuntimeException('Ledger totals exceed the supported integer range.');
        }

        return $left + $right;
    }
}
