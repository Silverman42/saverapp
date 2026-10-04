<?php

namespace App\Services;

use App\Data\PayoutAccountResolution;
use App\Data\PayoutCallback;
use App\Data\PayoutInstruction;
use App\Data\PayoutProviderResult;
use App\Support\InvalidPayoutCallback;
use App\Support\PayoutProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The default provider binding. No real provider is approved, so every call fails closed.
 */
class UnavailablePayoutProvider implements PayoutProvider
{
    public function key(): string
    {
        return 'unavailable';
    }

    public function resolveAccount(string $bankCode, string $accountNumber): PayoutAccountResolution
    {
        throw $this->unavailable();
    }

    public function initiate(PayoutInstruction $instruction): PayoutProviderResult
    {
        throw $this->unavailable();
    }

    public function query(string $idempotencyKey): PayoutProviderResult
    {
        throw $this->unavailable();
    }

    public function verifyCallback(string $rawBody, array $headers): PayoutCallback
    {
        throw new InvalidPayoutCallback('Bank payouts are unavailable.');
    }

    private function unavailable(): ServiceUnavailableHttpException
    {
        return new ServiceUnavailableHttpException(null, 'Bank payouts are unavailable.');
    }
}
