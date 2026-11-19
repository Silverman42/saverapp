<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The authoritative Customer wallet position (Module 18 §5): W from wallet-liability entries, minus live wallet-source reservations.
 * Wallet money is never attributed to a plan, so a wallet entry carrying a plan is an integrity failure.
 */
class WalletBalanceService
{
    /** @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int, as_of: string} */
    public function position(CustomerProfile $customer, bool $forUpdate = false): array
    {
        if ($forUpdate) {
            if (DB::transactionLevel() === 0) {
                throw new RuntimeException('An authoritative wallet position requires a transaction.');
            }
            CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        }
        $account = $this->account($forUpdate);
        $entries = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
            ->where('customer_profile_id', $customer->id)->select('side', 'amount_kobo', 'thrift_plan_id');
        $reservations = DB::table('withdrawal_reservations')->where('customer_profile_id', $customer->id)
            ->where('source', 'wallet')->where('status', 'live')->select('gross_amount_kobo');
        if ($forUpdate) {
            $entries->lockForUpdate();
            $reservations->lockForUpdate();
        }

        return [...$this->positionFromRows($entries->get(), $reservations->get()), 'as_of' => now()->utc()->toIso8601String()];
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, array{liability_kobo: int, reservations_kobo: int, available_kobo: int}|null>
     */
    public function positions(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $account = $this->account(false);
        $entries = DB::table('ledger_entries')->where('ledger_account_id', $account->id)->whereIn('customer_profile_id', $customerIds)
            ->select('customer_profile_id', 'side', 'amount_kobo', 'thrift_plan_id')->orderBy('id')->get()->groupBy('customer_profile_id');
        $reservations = DB::table('withdrawal_reservations')->whereIn('customer_profile_id', $customerIds)->where('source', 'wallet')
            ->where('status', 'live')->select('customer_profile_id', 'gross_amount_kobo')->get()->groupBy('customer_profile_id');
        $positions = [];
        foreach ($customerIds as $customerId) {
            try {
                $positions[$customerId] = $this->positionFromRows($entries->get($customerId, collect()), $reservations->get($customerId, collect()));
            } catch (RuntimeException) {
                $positions[$customerId] = null;
            }
        }

        return $positions;
    }

    /**
     * The total wallet liability of the Customers in scope, used by dashboard and reports.
     *
     * @param  Builder<CustomerProfile>  $customers
     */
    public function scopedLiability(Builder $customers): int
    {
        $account = $this->account(false);
        $value = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
            ->whereIn('customer_profile_id', (clone $customers)->toBase()->select('customer_profiles.id'))
            ->selectRaw("COALESCE(SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END), 0) AS liability")->value('liability');
        $liability = filter_var($value, FILTER_VALIDATE_INT);
        if ($liability === false || $liability < 0) {
            throw new RuntimeException('Wallet liability integrity is unavailable.');
        }

        return $liability;
    }

    /** Whether the wallet ledger account is mapped and usable; unmapped means every wallet feature stays unavailable. */
    public function available(): bool
    {
        try {
            $this->account(false);

            return true;
        } catch (ServiceUnavailableHttpException) {
            return false;
        }
    }

    private function account(bool $forUpdate): stdClass
    {
        $query = DB::table('ledger_accounts')->where('code', LedgerAccountCode::CustomerWalletLiability->value);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $account = $query->first();
        if ($account === null || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
            || $account->account_class !== 'customer_wallet_liability' || $account->normal_balance !== 'credit') {
            throw new ServiceUnavailableHttpException(null, 'The Customer wallet account mapping is unavailable.');
        }

        return $account;
    }

    /**
     * @param  Collection<int, stdClass>  $entries
     * @param  Collection<int, stdClass>  $reservations
     * @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int}
     */
    private function positionFromRows(Collection $entries, Collection $reservations): array
    {
        $liability = 0;
        foreach ($entries as $entry) {
            $amount = filter_var($entry->amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1 || $entry->thrift_plan_id !== null || ! in_array($entry->side, ['debit', 'credit'], true)) {
                throw new RuntimeException('Customer wallet entry integrity is unavailable.');
            }
            $liability += $entry->side === 'credit' ? $amount : -$amount;
        }
        $reserved = 0;
        foreach ($reservations as $reservation) {
            $amount = filter_var($reservation->gross_amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1) {
                throw new RuntimeException('Customer wallet reservation integrity is unavailable.');
            }
            $reserved += $amount;
        }
        if ($liability < 0 || $reserved > $liability) {
            throw new RuntimeException('Customer wallet balance integrity is unavailable.');
        }

        return ['liability_kobo' => $liability, 'reservations_kobo' => $reserved, 'available_kobo' => $liability - $reserved];
    }
}
