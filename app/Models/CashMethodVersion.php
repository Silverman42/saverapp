<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CashMethodVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property array<string, mixed> $contract
 * @property CarbonImmutable $effective_at
 */
#[Fillable(['method_key', 'version', 'contract', 'contract_hash', 'effective_at'])]
class CashMethodVersion extends Model
{
    /** @use HasFactory<CashMethodVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['version' => 'integer', 'contract' => 'array', 'effective_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Cash method contracts are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Cash method contracts must be retained with payment history.');
        });
    }
}
