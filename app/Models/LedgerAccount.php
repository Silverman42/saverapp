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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'account_class', 'normal_balance', 'currency', 'mapping_status'])]
class LedgerAccount extends Model
{
    protected function casts(): array
    {
        return [
            'code' => LedgerAccountCode::class,
            'account_class' => LedgerAccountClass::class,
            'normal_balance' => LedgerEntrySide::class,
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
