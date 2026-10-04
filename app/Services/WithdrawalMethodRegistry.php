<?php

namespace App\Services;

use App\Models\CustomerPayoutDestination;
use App\Support\PayoutProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WithdrawalMethodRegistry
{
    public function available(): bool
    {
        return config('withdrawals.cash_enabled') === true && config('withdrawals.cash_certified') === true;
    }

    public function bankAvailable(): bool
    {
        return config('withdrawals.bank_enabled') === true && config('withdrawals.bank_certified') === true
            && app(PayoutProvider::class)->key() !== 'unavailable';
    }

    /**
     * Methods a Customer withdrawal could currently be previewed with. Never throws: a missing gate simply omits the method.
     *
     * @return list<string>
     */
    public function availableMethods(): array
    {
        $methods = [];
        foreach (['cash' => [$this->available(), 'withdrawal_cash'], 'bank_transfer' => [$this->bankAvailable(), 'withdrawal_transfer']] as $method => [$configured, $feature]) {
            if (! $configured) {
                continue;
            }
            try {
                app(BusinessSettings::class)->ensureFeature($feature);
                app(BusinessSettings::class)->ensureFeature('payout_execution');
                $methods[] = $method;
            } catch (HttpException) {
                // The business setting gate is closed; this method is not offered.
            }
        }

        return $methods;
    }

    /** @return array{version: int, destination_reference: string, destination_mask: string, payout_destination_id: int|null, destination_version: int|null} */
    public function resolve(int $customerProfileId, string $method, string $destinationReference): array
    {
        abort_unless(in_array($method, ['cash', 'bank_transfer'], true), 422, 'Choose a supported payout method.');
        if ($method === 'bank_transfer') {
            return $this->resolveBank($customerProfileId, $destinationReference);
        }
        app(BusinessSettings::class)->ensureFeature('withdrawal_cash');
        app(BusinessSettings::class)->ensureFeature('payout_execution');
        abort_unless($this->available(), 503, 'Cash payouts are awaiting release certification.');
        abort_unless($destinationReference === 'customer:'.$customerProfileId, 422, 'Cash must be paid to this Customer personally.');

        return ['version' => app(CashMethodCatalogue::class)->version('withdrawal'),
            'destination_reference' => 'customer:'.$customerProfileId, 'destination_mask' => 'Customer personally',
            'payout_destination_id' => null, 'destination_version' => null];
    }

    /** @return array{version: int, destination_reference: string, destination_mask: string, payout_destination_id: int|null, destination_version: int|null} */
    private function resolveBank(int $customerProfileId, string $destinationReference): array
    {
        app(BusinessSettings::class)->ensureFeature('withdrawal_transfer');
        app(BusinessSettings::class)->ensureFeature('payout_execution');
        abort_unless($this->bankAvailable(), 503, 'Bank transfers are awaiting release certification.');
        $destination = CustomerPayoutDestination::query()->where('destination_reference', $destinationReference)
            ->where('customer_profile_id', $customerProfileId)->where('status', 'verified')->first();
        abort_if($destination === null, 422, 'Choose this Customer\'s verified bank destination.');

        return ['version' => app(BankMethodCatalogue::class)->version(), 'destination_reference' => $destination->destination_reference,
            'destination_mask' => $destination->bank_name.' '.$destination->account_mask,
            'payout_destination_id' => $destination->id, 'destination_version' => $destination->version];
    }
}
