<?php

namespace App\Enums;

/**
 * The balance a withdrawal request debits. Plan-source requests move savings into the Customer wallet; wallet-source requests pay out.
 */
enum WithdrawalSource: string
{
    case Plan = 'plan';
    case Wallet = 'wallet';

    public function debitAccount(): LedgerAccountCode
    {
        return match ($this) {
            self::Plan => LedgerAccountCode::CustomerSavingsLiability,
            self::Wallet => LedgerAccountCode::CustomerWalletLiability,
        };
    }

    public function debitAccountClass(): LedgerAccountClass
    {
        return match ($this) {
            self::Plan => LedgerAccountClass::CustomerSavingsLiability,
            self::Wallet => LedgerAccountClass::CustomerWalletLiability,
        };
    }
}
