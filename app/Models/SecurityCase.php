<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityCase extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'episode' => 'integer'];
    }
}
