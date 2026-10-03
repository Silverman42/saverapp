<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable(['collection_batch_id', 'opened_by_user_id', 'kind', 'status', 'amount_kobo', 'reason'])]
class CollectionException extends Model
{
    protected static function booted(): void
    {
        static::updating(function (CollectionException $exception): void {
            if ($exception->isDirty(['collection_batch_id', 'opened_by_user_id', 'kind', 'amount_kobo', 'reason'])) {
                throw new RuntimeException('Original collection exception facts are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Collection exception history cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer'];
    }
}
