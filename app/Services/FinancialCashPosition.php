<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinancialCashPosition
{
    public function balance(LedgerAccountCode $code): int
    {
        $account = LedgerAccount::query()->where('code', $code->value)->sole();
        [$class, $side] = match ($code) {
            LedgerAccountCode::BusinessCash => [LedgerAccountClass::Asset, LedgerEntrySide::Debit],
            LedgerAccountCode::FeeIncome => [LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit],
            LedgerAccountCode::BusinessDistributions => [LedgerAccountClass::BusinessDistributions, LedgerEntrySide::Debit],
            LedgerAccountCode::CustomerSavingsLiability => [LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit],
            LedgerAccountCode::RefundPayable => [LedgerAccountClass::RefundPayable, LedgerEntrySide::Credit],
            LedgerAccountCode::UnappliedFunds => [LedgerAccountClass::UnappliedFunds, LedgerEntrySide::Credit],
            default => throw new RuntimeException('Unsupported financial liquidity account.'),
        };
        if ($account->mapping_status !== 'mapped' || $account->currency !== 'NGN' || $account->account_class !== $class
            || $account->normal_balance !== $side || $account->version < 1) {
            throw new RuntimeException('The financial account mapping is unavailable.');
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
    public function read(?int $excludingDisbursementId = null): array
    {
        $cash = $this->balance(LedgerAccountCode::BusinessCash);
        $pending = $this->reservedCashKobo($excludingDisbursementId);
        $draws = $this->integerSum(CashDisbursement::query()->when($excludingDisbursementId !== null, fn ($query) => $query->where('id', '!=', $excludingDisbursementId))->where('kind', 'earnings_draw')->whereIn('status', ['processing', 'outcome_unknown'])->sum('amount_kobo'));
        $undrawn = max(0, max(0, $this->balance(LedgerAccountCode::FeeIncome) - $this->balance(LedgerAccountCode::BusinessDistributions)) - $draws);
        $free = $cash;
        foreach ([LedgerAccountCode::CustomerSavingsLiability, LedgerAccountCode::RefundPayable, LedgerAccountCode::UnappliedFunds] as $code) {
            $free = max(0, $free - $this->balance($code));
        }
        $free = max(0, $free - $pending);

        return ['cash_kobo' => $cash, 'pending_cash_kobo' => $pending, 'available_cash_kobo' => max(0, $cash - $pending),
            'undrawn_earnings_kobo' => $undrawn, 'free_cash_kobo' => $free, 'draw_limit_kobo' => min($undrawn, $free)];
    }

    public function reservedCashKobo(?int $excludingDisbursementId = null): int
    {
        $withdrawals = $this->integerSum(CashExecution::query()->whereIn('status', ['processing', 'outcome_unknown'])->sum('amount_kobo'));
        $disbursements = $this->integerSum(CashDisbursement::query()->when($excludingDisbursementId !== null, fn ($query) => $query->where('id', '!=', $excludingDisbursementId))
            ->whereIn('status', ['processing', 'outcome_unknown'])->sum('amount_kobo'));
        if ($disbursements > PHP_INT_MAX - $withdrawals) {
            throw new RuntimeException('Cash reservations exceed the supported range.');
        }

        return $withdrawals + $disbursements;
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
