<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\LedgerPostingGroup;
use Illuminate\Support\Facades\DB;

/**
 * The part of the ledger a reversal preview actually depends on. A preview goes stale when its Customer's postings change or
 * when a business-wide account it reads (for example undrawn earnings) changes, never because of unrelated activity elsewhere.
 */
class ReversalWatermark
{
    /**
     * @param  list<LedgerAccountCode>  $businessAccounts
     * @return array{customer_group: int, accounts: array<string, int>}
     */
    public function forCustomer(int $customerProfileId, array $businessAccounts = []): array
    {
        $accounts = [];
        foreach ($businessAccounts as $code) {
            $accounts[$code->value] = (int) (DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
                ->where('ledger_accounts.code', $code->value)->max('ledger_entries.id') ?? 0);
        }

        return ['customer_group' => (int) (LedgerPostingGroup::query()->where('customer_profile_id', $customerProfileId)->max('id') ?? 0), 'accounts' => $accounts];
    }
}
