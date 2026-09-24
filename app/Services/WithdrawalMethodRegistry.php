<?php

namespace App\Services;

class WithdrawalMethodRegistry
{
    public function available(): bool
    {
        return false;
    }

    /** @return array{version: int, destination_reference: string, destination_mask: string} */
    public function resolve(int $customerProfileId, string $method, string $destinationReference): array
    {
        abort(503, 'Withdrawal submission is unavailable until a payout method is approved.');
    }
}
