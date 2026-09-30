<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** @property int $amount_kobo */
#[Fillable(['publication_reference', 'payload_hash', 'category_key', 'version', 'kind', 'purpose', 'customer_description', 'destination_code', 'destination_mapping_version', 'amount_kobo', 'fee_rule_id', 'published_by_user_id'])]
class ChargeCategoryVersion extends Model
{
    protected function casts(): array
    {
        return ['version' => 'integer', 'destination_mapping_version' => 'integer', 'amount_kobo' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Published charge categories are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Published charge categories cannot be deleted.');
        });
    }
}
