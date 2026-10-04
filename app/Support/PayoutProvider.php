<?php

namespace App\Support;

use App\Data\PayoutAccountResolution;
use App\Data\PayoutCallback;
use App\Data\PayoutInstruction;
use App\Data\PayoutProviderResult;

interface PayoutProvider
{
    public function key(): string;

    /** Resolve a bank account to a tokenized, name-verified destination. The account number must not be retained. */
    public function resolveAccount(string $bankCode, string $accountNumber): PayoutAccountResolution;

    /** Send one transfer. The same idempotency key must never produce a second transfer. A thrown exception means the outcome is unknown. */
    public function initiate(PayoutInstruction $instruction): PayoutProviderResult;

    /** Query the transfer by the same idempotency key. */
    public function query(string $idempotencyKey): PayoutProviderResult;

    /**
     * Verify a callback's authenticity and shape.
     *
     * @param  array<string, string>  $headers
     *
     * @throws InvalidPayoutCallback
     */
    public function verifyCallback(string $rawBody, array $headers): PayoutCallback;
}
