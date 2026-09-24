<?php

namespace App\Models;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property LedgerAccountCode $code
 * @property LedgerAccountClass $account_class
 * @property LedgerEntrySide $normal_balance
 * @property string $currency
 * @property string $mapping_status
 * @property int $version
 * @property string|null $display_name
 * @property string|null $purpose
 * @property array<int, string>|null $supported_dimensions
 * @property CarbonImmutable|null $effective_at
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'account_class', 'normal_balance', 'currency', 'mapping_status', 'version',
    'display_name', 'purpose', 'supported_dimensions', 'effective_at', 'retired_at'])]
class LedgerAccount extends Model
{
    protected function casts(): array
    {
        return [
            'code' => LedgerAccountCode::class,
            'account_class' => LedgerAccountClass::class,
            'normal_balance' => LedgerEntrySide::class,
            'version' => 'integer',
            'supported_dimensions' => 'array',
            'effective_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LedgerAccount $account): void {
            if ($account->isDirty(['code', 'account_class', 'normal_balance', 'currency'])) {
                throw new RuntimeException('Ledger account identity and classification are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException('Ledger accounts cannot be deleted.');
        });
    }

    /**
     * Get the ledger entries posted to this account.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
