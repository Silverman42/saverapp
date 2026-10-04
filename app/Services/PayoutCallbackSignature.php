<?php

namespace App\Services;

use App\Support\InvalidPayoutCallback;
use Carbon\CarbonImmutable;

/**
 * HMAC-SHA256 over "{timestamp}.{rawBody}", sent as "t=<unix>,v1=<hex>", valid inside a bounded clock window.
 */
class PayoutCallbackSignature
{
    public const HEADER = 'x-payout-signature';

    public function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * @param  array<string, string>  $headers  Header names must be lower-case.
     */
    public function verify(string $secret, string $rawBody, array $headers): CarbonImmutable
    {
        $header = $headers[self::HEADER] ?? '';
        if ($secret === '' || ! preg_match('/^t=(\d{9,12}),v1=([a-f0-9]{64})$/', $header, $parts)) {
            throw new InvalidPayoutCallback('The callback signature is missing or malformed.');
        }
        $timestamp = (int) $parts[1];
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $parts[2])) {
            throw new InvalidPayoutCallback('The callback signature is invalid.');
        }
        $signedAt = CarbonImmutable::createFromTimestampUTC($timestamp);
        if (abs($signedAt->diffInSeconds(now(), false)) > (int) config('withdrawals.bank.callback_tolerance_seconds', 300)) {
            throw new InvalidPayoutCallback('The callback signature is outside the accepted time window.');
        }

        return $signedAt;
    }
}
