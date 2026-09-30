<?php

namespace App\Services;

class WithdrawalMethodRegistry
{
    public function available(): bool
    {
        return config('withdrawals.cash_enabled') === true && config('withdrawals.cash_certified') === true;
    }

    /** @return array{version: int, destination_reference: string, destination_mask: string} */
    public function resolve(int $customerProfileId, string $method, string $destinationReference): array
    {
        app(BusinessSettings::class)->ensureFeature('withdrawal_cash');
        app(BusinessSettings::class)->ensureFeature('payout_execution');
        abort_unless($this->available(), 503, 'Cash payouts are awaiting release certification.');
        abort_unless($method === 'cash', 422, 'Only the certified cash method is supported.');
        abort_unless($destinationReference === 'customer:'.$customerProfileId, 422, 'Cash must be paid to this Customer personally.');

        return ['version' => app(CashMethodCatalogue::class)->version('withdrawal'),
            'destination_reference' => 'customer:'.$customerProfileId, 'destination_mask' => 'Customer personally'];
    }
}
