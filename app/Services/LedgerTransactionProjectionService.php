<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CashRemittance;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionReceipt;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ReversalRequest;
use App\Models\WithdrawalRequest;
use App\Support\PlatformBlocked;
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
        } catch (PlatformBlocked $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            app(PlatformGuard::class)->transaction('derived', function () use ($exception): void {
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
                ],
                    context: ['executor' => self::class]
                );
            }, attempts: 3);

            throw $exception;
        }
    }

    /** @return array{version: int, transactions: int, groups: int} */
    private function rebuildVerified(): array
    {
        return app(PlatformGuard::class)->transaction('derived', function (): array {
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

            foreach (CashExecution::query()->where('status', 'posted')->lazyById(100) as $execution) {
                $withdrawal = WithdrawalRequest::query()->findOrFail($execution->withdrawal_request_id);
                $group = LedgerPostingGroup::query()->findOrFail($execution->ledger_posting_group_id);
                $this->writeWithdrawal($withdrawal, $group, $version);
                $covered[$group->id] = true;
                $transactions++;
            }

            foreach (FeeRefund::query()->lazyById(100) as $refund) {
                $group = LedgerPostingGroup::query()->findOrFail($refund->ledger_posting_group_id);
                $this->writeRefund($refund, $group, $version);
                $covered[$group->id] = true;
                $transactions++;
            }
            foreach (CashDisbursement::query()->where('status', 'posted')->lazyById(100) as $disbursement) {
                $group = LedgerPostingGroup::query()->findOrFail($disbursement->ledger_posting_group_id);
                $this->writeDisbursement($disbursement, $group, $version);
                $covered[$group->id] = true;
                $transactions++;
            }

            foreach (ManualCharge::query()->whereNotNull('ledger_posting_group_id')->lazyById(100) as $charge) {
                $group = LedgerPostingGroup::query()->findOrFail($charge->ledger_posting_group_id);
                $this->writeCharge($charge, $group, $version);
                $covered[$group->id] = true;
                $transactions++;
            }

            foreach (ReversalRequest::query()->where('state', 'approved_posted')->lazyById(100) as $reversal) {
                $group = LedgerPostingGroup::query()->findOrFail($reversal->compensation_posting_group_id);
                $this->writeReversal($reversal, $group, $version);
                $covered[$group->id] = true;
                $transactions++;
            }

            foreach (LedgerPostingGroup::query()->whereIn('source_type', ['cash_recovery', 'disbursement_recovery'])->lazyById(100) as $group) {
                $this->assertBalanced($group);
                $recovery = $group->source_type === 'cash_recovery' ? CashRecovery::query()->findOrFail($group->source_id) : null;
                if ($recovery !== null && $recovery->return_posting_group_id !== $group->id) {
                    throw new RuntimeException('Cash recovery source does not reconcile.');
                }
                $this->writeOwnedMovement($group->event_type, $group->source_id, $group, $version, (int) $group->entries()->where('side', 'debit')->sum('amount_kobo'), 0, 0, $group->source_type);
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
            ],
                context: ['executor' => self::class]
            );

            return ['version' => $version, 'transactions' => $transactions, 'groups' => $groups];
        }, attempts: 3);
    }

    public function projectReceipt(CollectionReceipt $receipt): void
    {
        app(PlatformGuard::class)->transaction('derived', function () use ($receipt): void {
            $this->projectReceiptAllowed($receipt);
        });
    }

    private function projectReceiptAllowed(CollectionReceipt $receipt): void
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
        app(PlatformGuard::class)->transaction('derived', function () use ($remittanceId): void {
            $this->projectRemittanceAllowed($remittanceId);
        });
    }

    private function projectRemittanceAllowed(int $remittanceId): void
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
            $expectedPlanId = $receipt->thrift_plan_id;
            if ($expectedPlanId === null && $group->thrift_plan_id !== null) {
                $fee = FeeObligation::query()->whereIn('id', DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->where('ledger_posting_group_id', $group->id)->select('fee_obligation_id'))->first();
                $expectedPlanId = $fee === null ? null : app(LedgerPostingService::class)->planForObligation($fee);
            }
            if ($group->customer_profile_id !== $receipt->customer_profile_id
                || ! in_array($group->source_type, ['collection_receipt', 'fee_application'], true)
                || $group->occurred_on?->toDateString() !== $receipt->received_date
                || $group->business_timezone !== $receipt->timezone
                || $group->thrift_plan_id !== $expectedPlanId) {
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
            'type' => $receipt->replacement_reversal_id === null ? 'contribution' : 'replacement', 'status' => 'posted', 'occurred_on' => $receipt->received_date,
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

    public function projectWithdrawal(WithdrawalRequest $withdrawal): void
    {
        app(PlatformGuard::class)->transaction('derived', function () use ($withdrawal): void {
            $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || $state->status !== 'ready') {
                return;
            }
            $execution = CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->where('status', 'posted')->sole();
            $this->writeWithdrawal($withdrawal, LedgerPostingGroup::query()->findOrFail($execution->ledger_posting_group_id), (int) $state->active_version);
            $this->advanceWatermark();
        });
    }

    private function writeWithdrawal(WithdrawalRequest $withdrawal, LedgerPostingGroup $group, int $version): void
    {
        $this->assertBalanced($group);
        if ($withdrawal->state !== 'posted' || $group->source_type !== 'withdrawal' || $group->source_id !== (string) $withdrawal->id
            || $group->customer_profile_id !== $withdrawal->customer_profile_id || $group->thrift_plan_id !== $withdrawal->thrift_plan_id) {
            throw new RuntimeException('Withdrawal source and posting do not reconcile.');
        }
        $savings = 0;
        $cash = 0;
        $fee = 0;
        foreach ($group->entries as $entry) {
            if ($entry->account->code === LedgerAccountCode::CustomerSavingsLiability && $entry->side->value === 'debit') {
                $savings += $entry->amount_kobo;
            } elseif ($entry->account->code === LedgerAccountCode::BusinessCash && $entry->side->value === 'credit') {
                $cash += $entry->amount_kobo;
            } elseif ($entry->account->code === LedgerAccountCode::FeeIncome && $entry->side->value === 'credit') {
                $fee += $entry->amount_kobo;
            } else {
                throw new RuntimeException('Withdrawal posting contains an unsupported line.');
            }
        }
        if ($savings !== $withdrawal->gross_amount_kobo || $cash !== $withdrawal->net_amount_kobo || $fee !== $withdrawal->fee_amount_kobo) {
            throw new RuntimeException('Withdrawal amounts and entries do not reconcile.');
        }
        $date = $group->occurred_on->toDateString();
        $this->upsertProjection($this->reference('withdrawal', (string) $withdrawal->id, $date), $version, [
            'customer_profile_id' => $withdrawal->customer_profile_id, 'type' => 'withdrawal', 'status' => 'posted',
            'occurred_on' => $date, 'committed_at' => $group->committed_at, 'timezone' => $group->business_timezone,
            'currency' => 'NGN', 'gross_amount_kobo' => $savings, 'savings_effect_kobo' => -$savings,
            'fee_amount_kobo' => $fee, 'posting_group_count' => 1, 'source_hash' => $this->sourceHash([$group]), 'source_max_group_id' => $group->id,
        ]);
    }

    public function projectRefund(FeeRefund $refund): void
    {
        $this->projectAdditionalOwner(fn (int $version) => $this->writeRefund($refund, LedgerPostingGroup::query()->findOrFail($refund->ledger_posting_group_id), $version));
    }

    public function projectDisbursement(CashDisbursement $disbursement): void
    {
        $this->projectAdditionalOwner(fn (int $version) => $this->writeDisbursement($disbursement, LedgerPostingGroup::query()->findOrFail($disbursement->ledger_posting_group_id), $version));
    }

    /** @param \Closure(int): void $project */
    private function projectAdditionalOwner(\Closure $project): void
    {
        app(PlatformGuard::class)->transaction('derived', function () use ($project): void {
            $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || $state->status !== 'ready') {
                return;
            }
            $project((int) $state->active_version);
            $this->advanceWatermark();
        });
    }

    private function writeRefund(FeeRefund $refund, LedgerPostingGroup $group, int $version): void
    {
        $this->assertBalanced($group);
        $expectedSource = $refund->kind === 'savings' ? 'fee_refund' : 'external_refund_entitlement';
        if ($group->source_type !== $expectedSource || $group->source_id !== $refund->refund_reference || $group->customer_profile_id !== $refund->customer_profile_id
            || $group->entries->count() !== 2) {
            throw new RuntimeException('The refund entitlement and ledger source do not reconcile.');
        }
        foreach ($group->entries as $line) {
            $expected = $line->side === LedgerEntrySide::Debit ? LedgerAccountCode::FeeIncome : ($refund->kind === 'savings' ? LedgerAccountCode::CustomerSavingsLiability : LedgerAccountCode::RefundPayable);
            if ($line->amount_kobo !== $refund->amount_kobo || $line->fee_obligation_id !== $refund->fee_obligation_id || $line->account->code !== $expected) {
                throw new RuntimeException('Refund amounts or destinations do not match the approved source.');
            }
        }
        $this->writeOwnedMovement('fee_refund', $refund->refund_reference, $group, $version, $refund->amount_kobo,
            $refund->kind === 'savings' ? $refund->amount_kobo : 0, $refund->amount_kobo);
    }

    private function writeDisbursement(CashDisbursement $execution, LedgerPostingGroup $group, int $version): void
    {
        $this->assertBalanced($group);
        if ($group->source_type !== 'cash_disbursement' || $group->source_id !== (string) $execution->id || $group->customer_profile_id !== $execution->customer_profile_id
            || $group->entries->count() !== 2 || $execution->acknowledgement === null) {
            throw new RuntimeException('The evidenced cash payment and ledger source do not reconcile.');
        }
        foreach ($group->entries as $line) {
            $expected = $line->side === LedgerEntrySide::Credit ? LedgerAccountCode::BusinessCash : ($execution->kind === 'earnings_draw' ? LedgerAccountCode::BusinessDistributions : LedgerAccountCode::RefundPayable);
            if ($line->amount_kobo !== $execution->amount_kobo || $line->account->code !== $expected) {
                throw new RuntimeException('Cash payment amounts or destinations do not match the exact attempt.');
            }
        }
        $this->writeOwnedMovement($execution->kind === 'fee_refund' ? 'external_refund_payment' : $execution->kind, (string) $execution->id, $group, $version, $execution->amount_kobo, 0, 0, 'cash_disbursement');
    }

    private function writeOwnedMovement(string $type, string $rootId, LedgerPostingGroup $group, int $version, int $gross, int $effect, int $fee, ?string $rootType = null): void
    {
        $date = $group->occurred_on->toDateString();
        $this->upsertProjection($this->reference($rootType ?? $type, $rootId, $date), $version, [
            'customer_profile_id' => $group->customer_profile_id, 'type' => $type, 'status' => 'posted', 'occurred_on' => $date,
            'committed_at' => $group->committed_at, 'timezone' => $group->business_timezone, 'currency' => 'NGN', 'gross_amount_kobo' => $gross,
            'savings_effect_kobo' => $effect, 'fee_amount_kobo' => $fee, 'posting_group_count' => 1, 'source_hash' => $this->sourceHash([$group]), 'source_max_group_id' => $group->id,
        ]);
    }

    public function projectCharge(ManualCharge $charge): void
    {
        app(PlatformGuard::class)->transaction('derived', function () use ($charge): void {
            $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || $state->status !== 'ready') {
                return;
            }
            $this->writeCharge($charge, LedgerPostingGroup::query()->findOrFail($charge->ledger_posting_group_id), (int) $state->active_version);
            $this->advanceWatermark();
        });
    }

    private function writeCharge(ManualCharge $charge, LedgerPostingGroup $group, int $version): void
    {
        $this->assertBalanced($group);
        $category = ChargeCategoryVersion::query()->findOrFail($charge->charge_category_version_id);
        if ($category->kind !== 'deduction' || $group->event_type !== 'other_deduction' || $group->source_type !== 'manual_charge'
            || $group->source_id !== $charge->operation_reference || $group->customer_profile_id !== $charge->customer_profile_id
            || $group->thrift_plan_id !== $charge->thrift_plan_id || $group->entries->count() !== 2) {
            throw new RuntimeException('Charge source and deduction ledger do not reconcile.');
        }
        foreach ($group->entries as $entry) {
            $expectedCode = $entry->side === LedgerEntrySide::Debit ? LedgerAccountCode::CustomerSavingsLiability->value : $category->destination_code;
            if ($entry->amount_kobo !== $charge->amount_kobo || $entry->account->code->value !== $expectedCode) {
                throw new RuntimeException('Deduction does not match the approved amount and destination.');
            }
        }
        $date = $group->occurred_on->toDateString();
        $this->upsertProjection($this->reference('manual_charge', $charge->operation_reference, $date), $version, [
            'customer_profile_id' => $charge->customer_profile_id, 'type' => 'deduction', 'status' => 'posted',
            'occurred_on' => $date, 'committed_at' => $group->committed_at, 'timezone' => $group->business_timezone,
            'currency' => 'NGN', 'gross_amount_kobo' => $charge->amount_kobo, 'savings_effect_kobo' => -$charge->amount_kobo,
            'fee_amount_kobo' => 0, 'posting_group_count' => 1, 'source_hash' => $this->sourceHash([$group]), 'source_max_group_id' => $group->id,
        ]);
    }

    public function projectReversal(ReversalRequest $reversal): void
    {
        app(PlatformGuard::class)->transaction('derived', function () use ($reversal): void {
            $state = DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || $state->status !== 'ready') {
                return;
            }
            $this->writeReversal($reversal, LedgerPostingGroup::query()->findOrFail($reversal->compensation_posting_group_id), (int) $state->active_version);
            $this->advanceWatermark();
        });
    }

    private function writeReversal(ReversalRequest $reversal, LedgerPostingGroup $group, int $version): void
    {
        $this->assertBalanced($group);
        if ($reversal->state !== 'approved_posted' || $group->source_type !== 'reversal_request'
            || $group->source_id !== (string) $reversal->id || $group->customer_profile_id !== $reversal->customer_profile_id) {
            throw new RuntimeException('The reversal and its compensation do not reconcile.');
        }
        $effect = 0;
        foreach ($group->entries as $entry) {
            if ($entry->account->code === LedgerAccountCode::CustomerSavingsLiability) {
                $effect += $entry->side === LedgerEntrySide::Credit ? $entry->amount_kobo : -$entry->amount_kobo;
            }
        }
        $date = $group->occurred_on->toDateString();
        $this->upsertProjection($this->reference('reversal_request', (string) $reversal->id, $date), $version, [
            'customer_profile_id' => $reversal->customer_profile_id, 'type' => 'reversal', 'status' => 'posted',
            'occurred_on' => $date, 'committed_at' => $group->committed_at, 'timezone' => $group->business_timezone,
            'currency' => 'NGN', 'gross_amount_kobo' => $reversal->original_amount_kobo, 'savings_effect_kobo' => $effect,
            'fee_amount_kobo' => 0, 'posting_group_count' => 1, 'source_hash' => $this->sourceHash([$group]), 'source_max_group_id' => $group->id,
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
