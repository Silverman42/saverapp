<?php

namespace App\Services;

use App\Data\PayoutAccountResolution;
use App\Data\PayoutCallback;
use App\Data\PayoutInstruction;
use App\Data\PayoutProviderResult;
use App\Enums\PayoutProviderOutcome;
use App\Support\InvalidPayoutCallback;
use App\Support\PayoutProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use JsonException;
use RuntimeException;

/**
 * A deterministic, cache-backed sandbox provider. It is bound only in local and testing environments.
 * The last digit of the account number selects the scenario:
 * 1 or 0 success, 2 definitive failure, 3 accepted then success, 4 timeout after a recorded success,
 * 5 timeout with no transfer, 6 timeout with an unknowable outcome, 7 success followed by a provider return,
 * 8 failure that the provider later reverses into success, 9 accepted and never final.
 */
class FakePayoutProvider implements PayoutProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function resolveAccount(string $bankCode, string $accountNumber): PayoutAccountResolution
    {
        if (! preg_match('/^\d{10}$/', $accountNumber) || ! preg_match('/^\d{3}$/', $bankCode)) {
            throw new RuntimeException('The account could not be resolved.');
        }
        $fingerprint = hash_hmac('sha256', $bankCode.'|'.$accountNumber, (string) config('app.key'));
        $name = $this->store()->get('fake-payout:name:'.$fingerprint, 'FAKE ACCOUNT HOLDER');

        return new PayoutAccountResolution('fake:'.substr($accountNumber, -1).':'.substr($fingerprint, 0, 24), $name,
            substr($accountNumber, -4), $fingerprint, 'Fake Bank '.$bankCode);
    }

    public static function nameAccount(string $bankCode, string $accountNumber, string $name): void
    {
        $fingerprint = hash_hmac('sha256', $bankCode.'|'.$accountNumber, (string) config('app.key'));
        self::cache()->put('fake-payout:name:'.$fingerprint, $name, now()->addDays(30));
    }

    public function initiate(PayoutInstruction $instruction): PayoutProviderResult
    {
        return $this->locked($instruction->idempotencyKey, function () use ($instruction): PayoutProviderResult {
            $store = $this->store();
            $state = $store->get($this->stateKey($instruction->idempotencyKey));
            if (is_array($state)) {
                return $this->result($instruction->idempotencyKey, $state);
            }
            $scenario = $this->scenario($instruction->destinationToken);
            $store->put($this->intentKey($instruction->idempotencyKey), $scenario, now()->addDays(30));
            $state = ['scenario' => $scenario, 'amount_kobo' => $instruction->amountKobo, 'currency' => $instruction->currency,
                'token' => $instruction->destinationToken, 'occurred_at' => now()->toIso8601String()];
            switch ($scenario) {
                case 2:
                    return $this->record($instruction->idempotencyKey, $state + ['status' => 'failed', 'failure_code' => 'account_closed']);
                case 5:
                case 6:
                    throw new RuntimeException('The provider did not answer in time.');
                case 4:
                    $this->record($instruction->idempotencyKey, $state + ['status' => 'succeeded']);
                    throw new RuntimeException('The provider response was lost after acceptance.');
                case 3:
                case 9:
                    return $this->record($instruction->idempotencyKey, $state + ['status' => 'accepted']);
                case 8:
                    return $this->record($instruction->idempotencyKey, $state + ['status' => 'failed', 'failure_code' => 'provider_declined']);
                default:
                    return $this->record($instruction->idempotencyKey, $state + ['status' => 'succeeded']);
            }
        });
    }

    public function query(string $idempotencyKey): PayoutProviderResult
    {
        return $this->locked($idempotencyKey, function () use ($idempotencyKey): PayoutProviderResult {
            $store = $this->store();
            $forced = $store->get('fake-payout:force:'.$idempotencyKey);
            $state = $store->get($this->stateKey($idempotencyKey));
            if (is_string($forced) && is_array($state)) {
                $state['status'] = $forced;
                $store->put($this->stateKey($idempotencyKey), $state, now()->addDays(30));
            }
            if (! is_array($state)) {
                $scenario = $store->get($this->intentKey($idempotencyKey));

                return new PayoutProviderResult($scenario === 6 ? PayoutProviderOutcome::Unknown : PayoutProviderOutcome::NotFound);
            }
            if ($state['status'] === 'accepted' && $state['scenario'] === 3) {
                $state['status'] = 'succeeded';
                $store->put($this->stateKey($idempotencyKey), $state, now()->addDays(30));
            }

            return $this->result($idempotencyKey, $state);
        });
    }

    /** Make a later query report the given definitive status for a transfer the provider already knows. */
    public static function forceQuery(string $idempotencyKey, string $status): void
    {
        self::cache()->put('fake-payout:force:'.$idempotencyKey, $status, now()->addDays(30));
    }

    public function verifyCallback(string $rawBody, array $headers): PayoutCallback
    {
        $signedAt = app(PayoutCallbackSignature::class)->verify((string) config('withdrawals.bank.callback_secret'), $rawBody, $headers);
        try {
            $body = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPayoutCallback('The callback body is not valid JSON.');
        }
        if (! is_array($body) || ! is_string($body['event_id'] ?? null) || ! is_string($body['event_type'] ?? null)
            || ! is_string($body['idempotency_key'] ?? null) || strlen($body['event_id']) > 100
            || ! in_array($body['event_type'], ['transfer.succeeded', 'transfer.failed', 'transfer.returned', 'transfer.settled'], true)) {
            throw new InvalidPayoutCallback('The callback body is incomplete.');
        }

        return new PayoutCallback($this->key(), $body['event_id'], $body['event_type'], $body['idempotency_key'], $signedAt,
            hash('sha256', $rawBody), is_string($body['provider_reference'] ?? null) ? $body['provider_reference'] : null,
            is_int($body['amount_kobo'] ?? null) ? $body['amount_kobo'] : null,
            is_string($body['return_reference'] ?? null) ? $body['return_reference'] : null,
            isset($body['occurred_at']) && is_string($body['occurred_at']) ? CarbonImmutable::parse($body['occurred_at']) : null);
    }

    /**
     * Build a correctly signed callback for tests and the local QA command.
     *
     * @param  array<string, mixed>  $event
     * @return array{body: string, headers: array<string, string>}
     */
    public static function signedCallback(array $event, ?int $timestamp = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();

        return ['body' => $body, 'headers' => [PayoutCallbackSignature::HEADER => app(PayoutCallbackSignature::class)
            ->sign((string) config('withdrawals.bank.callback_secret'), $timestamp, $body)]];
    }

    private function scenario(string $token): int
    {
        return preg_match('/^fake:(\d):/', $token, $parts) === 1 ? (int) $parts[1] : 1;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function record(string $key, array $state): PayoutProviderResult
    {
        $this->store()->put($this->stateKey($key), $state, now()->addDays(30));

        return $this->result($key, $state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function result(string $key, array $state): PayoutProviderResult
    {
        $outcome = match ($state['status']) {
            'succeeded' => PayoutProviderOutcome::Succeeded,
            'failed' => PayoutProviderOutcome::Failed,
            default => PayoutProviderOutcome::Accepted,
        };
        $reference = 'FP-'.substr(hash('sha256', $key), 0, 16);

        return new PayoutProviderResult($outcome, $reference, (int) $state['amount_kobo'], (string) $state['currency'],
            (string) $state['token'], CarbonImmutable::parse((string) $state['occurred_at']),
            $state['failure_code'] ?? null, ['provider' => 'fake', 'reference' => $reference, 'status' => $state['status']]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(string $key, callable $callback): mixed
    {
        $store = $this->store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The sandbox payout provider needs a cache store that supports locks.');
        }

        return $store->lock('fake-payout:lock:'.$key, 10)->block(5, $callback);
    }

    private function stateKey(string $key): string
    {
        return 'fake-payout:transfer:'.$key;
    }

    private function intentKey(string $key): string
    {
        return 'fake-payout:intent:'.$key;
    }

    private function store(): Repository
    {
        return self::cache();
    }

    private static function cache(): Repository
    {
        $store = config('withdrawals.bank.fake_store');

        return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
    }
}
