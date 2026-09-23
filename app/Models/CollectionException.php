<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['collection_batch_id', 'opened_by_user_id', 'kind', 'status', 'amount_kobo', 'reason'])]
class CollectionException extends Model
{
    protected function casts(): array
    {
        return ['amount_kobo' => 'integer'];
    }
}
