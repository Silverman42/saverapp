<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['contribution_slot_id', 'actor_user_id', 'version', 'kind', 'reason'])]
class CollectionAnnotation extends Model
{
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
