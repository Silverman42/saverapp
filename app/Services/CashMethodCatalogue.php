<?php

namespace App\Services;

use App\Models\CashMethodVersion;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashMethodCatalogue
{
    public const VERSION_ONE = ['schema_version' => 1, 'currency' => 'NGN', 'rail' => 'cash',
        'proof' => ['custodian_evidence', 'authenticated_recipient_acknowledgement'],
        'recipient' => ['withdrawal' => 'customer_personally', 'fee_refund' => 'customer_personally', 'earnings_draw' => 'executing_admin'],
        'execution_permissions' => ['withdrawal' => ['cash.execute'], 'fee_refund' => ['cash.execute'], 'earnings_draw' => ['cash.execute', 'fees.manage']],
        'unknown_outcome' => 'preserve_reservations_and_block_another_payment'];

    public function version(string $kind, ?int $version = null): int
    {
        $version ??= (int) config('withdrawals.cash_method_version', 1);
        $method = CashMethodVersion::query()->where('method_key', 'cash')->where('version', $version)->first();
        $expected = AuditProjection::digest(self::VERSION_ONE);
        if ($version !== 1 || ! array_key_exists($kind, self::VERSION_ONE['recipient']) || $method === null
            || $method->effective_at->isFuture() || ! hash_equals($expected, $method->contract_hash)
            || ! hash_equals($method->contract_hash, AuditProjection::digest($method->contract))) {
            throw new ConflictHttpException('The immutable cash method contract is unavailable or unsupported.');
        }

        return $method->version;
    }
}
