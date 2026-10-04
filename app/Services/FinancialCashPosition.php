<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BankPayoutAttempt;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\LedgerAccount;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinancialCashPosition
{
    public function balance(LedgerAccountCode $code, bool $forUpdate = false): int
    {
        $this->assertCurrentTransaction($forUpdate);
        $account = LedgerAccount::query()->where('code', $code->value)->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        [$class, $side] = match ($code) {
            LedgerAccountCode::BusinessCash, LedgerAccountCode::BusinessBank, LedgerAccountCode::PaymentClearing => [LedgerAccountClass::Asset, LedgerEntrySide::Debit],
            LedgerAccountCode::FeeIncome => [LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit],
            LedgerAccountCode::BusinessDistributions => [LedgerAccountClass::BusinessDistributions, LedgerEntrySide::Debit],
            LedgerAccountCode::CustomerSavingsLiability => [LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit],
            LedgerAccountCode::RefundPayable => [LedgerAccountClass::RefundPayable, LedgerEntrySide::Credit],
            LedgerAccountCode::CashRecoveryClearing => [LedgerAccountClass::CashRecoveryClearing, LedgerEntrySide::Credit],
            LedgerAccountCode::PayoutClearing => [LedgerAccountClass::PayoutClearing, LedgerEntrySide::Credit],
            LedgerAccountCode::UnappliedFunds => [LedgerAccountClass::UnappliedFunds, LedgerEntrySide::Credit],
            default => throw new RuntimeException('Unsupported financial liquidity account.'),
        };
        if ($account->mapping_status !== 'mapped' || $account->currency !== 'NGN' || $account->account_class !== $class
            || $account->normal_balance !== $side || $account->version < 1) {
            throw new RuntimeException('The financial account mapping is unavailable.');
        }
        if ($forUpdate) {
            $balance = 0;
            foreach (DB::table('ledger_entries')->where('ledger_account_id', $account->id)->orderBy('id')->lockForUpdate()
                ->get(['amount_kobo', 'side']) as $entry) {
                $amount = filter_var($entry->amount_kobo, FILTER_VALIDATE_INT);
                if ($amount === false || $amount < 1 || ! in_array($entry->side, ['debit', 'credit'], true)) {
                    throw new RuntimeException('The current financial journal is unavailable.');
                }
                $balance = $this->add($balance, $entry->side === $account->normal_balance->value ? $amount : -$amount);
            }
            if ($balance < 0) {
                throw new RuntimeException('The current financial balance is unavailable.');
            }

            return $balance;
        }
        $signed = $account->normal_balance->value === 'debit' ? 'debit' : 'credit';
        $amount = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN side = ? THEN amount_kobo ELSE -CAST(amount_kobo AS SIGNED) END), 0) AS balance', [$signed])->value('balance');
        $integer = filter_var($amount, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException('The verified financial balance is unavailable.');
        }

        return $integer;
    }

    /** @return array{cash_kobo: int, pending_cash_kobo: int, available_cash_kobo: int, undrawn_earnings_kobo: int, free_cash_kobo: int, draw_limit_kobo: int} */
    public function read(?int $excludingDisbursementId = null, bool $forUpdate = false): array
    {
        $this->assertCurrentTransaction($forUpdate);
        if ($forUpdate) {
            LedgerAccount::query()->whereIn('code', [LedgerAccountCode::BusinessCash->value, LedgerAccountCode::FeeIncome->value,
                LedgerAccountCode::BusinessDistributions->value, LedgerAccountCode::CustomerSavingsLiability->value,
                LedgerAccountCode::RefundPayable->value, LedgerAccountCode::UnappliedFunds->value, LedgerAccountCode::CashRecoveryClearing->value])
                ->orderBy('id')->lockForUpdate()->get();
        }
        $cash = $this->balance(LedgerAccountCode::BusinessCash, $forUpdate);
        $pending = $this->reservedCashKobo($excludingDisbursementId, $forUpdate);
        $undrawn = $this->undrawnEarningsKobo($excludingDisbursementId, $forUpdate);
        $free = $cash;
        foreach ([LedgerAccountCode::CustomerSavingsLiability, LedgerAccountCode::RefundPayable, LedgerAccountCode::UnappliedFunds, LedgerAccountCode::CashRecoveryClearing] as $code) {
            $free = max(0, $free - $this->balance($code, $forUpdate));
        }
        $free = max(0, $free - $pending);

        return ['cash_kobo' => $cash, 'pending_cash_kobo' => $pending, 'available_cash_kobo' => max(0, $cash - $pending),
            'undrawn_earnings_kobo' => $undrawn, 'free_cash_kobo' => $free, 'draw_limit_kobo' => min($undrawn, $free)];
    }

    public function undrawnEarningsKobo(?int $excludingDisbursementId = null, bool $forUpdate = false): int
    {
        $this->assertCurrentTransaction($forUpdate);
        if ($forUpdate) {
            LedgerAccount::query()->whereIn('code', [LedgerAccountCode::FeeIncome->value, LedgerAccountCode::BusinessDistributions->value])
                ->orderBy('id')->lockForUpdate()->get();
        }
        $income = $this->balance(LedgerAccountCode::FeeIncome, $forUpdate);
        $drawn = $this->balance(LedgerAccountCode::BusinessDistributions, $forUpdate);
        $pending = $this->reservationSum(CashDisbursement::query()
            ->when($excludingDisbursementId !== null, fn ($query) => $query->where('id', '!=', $excludingDisbursementId))
            ->where('kind', 'earnings_draw')->whereIn('status', ['processing', 'outcome_unknown'])->toBase(), $forUpdate);

        return max(0, max(0, $income - $drawn) - $pending);
    }

    public function reservedCashKobo(?int $excludingDisbursementId = null, bool $forUpdate = false): int
    {
        $this->assertCurrentTransaction($forUpdate);
        $withdrawals = $this->reservationSum(CashExecution::query()->whereIn('status', ['processing', 'outcome_unknown'])->toBase(), $forUpdate);
        $disbursements = $this->reservationSum(CashDisbursement::query()->when($excludingDisbursementId !== null, fn ($query) => $query->where('id', '!=', $excludingDisbursementId))
            ->whereIn('status', ['processing', 'outcome_unknown'])->toBase(), $forUpdate);
        if ($disbursements > PHP_INT_MAX - $withdrawals) {
            throw new RuntimeException('Cash reservations exceed the supported range.');
        }

        return $withdrawals + $disbursements;
    }

    /** Bank money committed to an attempt that has not yet posted: in flight, or succeeded and awaiting its balanced posting. */
    public function reservedBankPayoutKobo(bool $forUpdate = false): int
    {
        $this->assertCurrentTransaction($forUpdate);

        return $this->reservationSum(BankPayoutAttempt::query()->where(function ($query): void {
            $query->whereIn('status', ['prepared', 'submitted', 'unknown'])
                ->orWhere(fn ($query) => $query->where('status', 'succeeded')->whereNull('ledger_posting_group_id'));
        })->toBase(), $forUpdate);
    }

    private function assertCurrentTransaction(bool $forUpdate): void
    {
        if ($forUpdate && DB::transactionLevel() === 0) {
            throw new RuntimeException('A current financial cash position requires an owning transaction.');
        }
    }

    private function reservationSum(QueryBuilder $query, bool $forUpdate): int
    {
        if (! $forUpdate) {
            return $this->integerSum($query->sum('amount_kobo'));
        }
        $total = 0;
        foreach ($query->orderBy('id')->lockForUpdate()->get(['amount_kobo']) as $reservation) {
            $amount = filter_var($reservation->amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1) {
                throw new RuntimeException('The current cash reservation is unavailable.');
            }
            $total = $this->add($total, $amount);
        }

        return $total;
    }

    private function add(int $total, int $amount): int
    {
        if (($amount > 0 && $total > PHP_INT_MAX - $amount) || ($amount < 0 && $total < PHP_INT_MIN - $amount)) {
            throw new RuntimeException('The current financial position exceeds the supported range.');
        }

        return $total + $amount;
    }

    private function integerSum(mixed $amount): int
    {
        $integer = filter_var($amount, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException('Pending cash obligations exceed the supported range.');
        }

        return $integer;
    }
}
