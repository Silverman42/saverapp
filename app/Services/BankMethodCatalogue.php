<?php

namespace App\Services;

use App\Models\CashMethodVersion;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The immutable, hash-verified bank-transfer method contract. Limits live inside the hashed contract,
 * so changing a limit means publishing a new version.
 */
class BankMethodCatalogue
{
    public const METHOD_KEY = 'bank_transfer';

    public const VERSION_ONE = ['schema_version' => 1, 'currency' => 'NGN', 'rail' => 'bank_transfer',
        'destination' => ['owner' => 'customer', 'verification' => ['provider_name_enquiry', 'customers_manage_review'], 'third_party_payees' => false],
        'execution_permissions' => ['withdrawal' => ['withdrawals.review']],
        'accounts' => ['liability' => 'customer_savings_liability_ngn', 'payout' => 'payout_clearing_ngn', 'funding' => 'business_bank_ngn',
            'fee' => 'fee_income_ngn', 'deduction' => 'other_deduction_destination_ngn', 'recovery' => 'cash_recovery_clearing_ngn'],
        'finality' => ['success' => 'provider_query_succeeded', 'no_transfer' => ['provider_query_failed', 'not_found_after_window'],
            'unknown' => ['timeout', 'accepted_without_final', 'conflicting']],
        'idempotency' => 'provider_key_per_attempt',
        'callback' => ['signature' => 'hmac_sha256_timestamped', 'confirmation' => 'query_same_key'],
        'limits' => ['per_payout_max_kobo' => 500000000, 'daily_business_max_kobo' => 5000000000, 'max_attempts_per_request' => 3],
        'unknown_outcome' => 'preserve_reservations_and_block_another_payment',
        'returns' => 'linked_return_then_module09_compensation'];

    /** @return array<string, mixed> */
    public function contract(?int $version = null): array
    {
        $version ??= (int) config('withdrawals.bank_method_version', 1);
        $method = CashMethodVersion::query()->where('method_key', self::METHOD_KEY)->where('version', $version)->first();
        $expected = AuditProjection::digest(self::VERSION_ONE);
        if ($version !== 1 || $method === null || $method->effective_at->isFuture()
            || ! hash_equals($expected, $method->contract_hash) || ! hash_equals($method->contract_hash, AuditProjection::digest($method->contract))) {
            throw new ConflictHttpException('The immutable bank method contract is unavailable or unsupported.');
        }

        return $method->contract;
    }

    public function version(?int $version = null): int
    {
        $this->contract($version);

        return $version ?? (int) config('withdrawals.bank_method_version', 1);
    }

    public function limit(string $name, ?int $version = null): int
    {
        $value = $this->contract($version)['limits'][$name] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new ConflictHttpException('The bank method limit is unavailable.');
        }

        return $value;
    }
}
