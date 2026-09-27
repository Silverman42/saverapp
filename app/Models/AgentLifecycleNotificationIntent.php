<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, string> $payload */
class AgentLifecycleNotificationIntent extends Model
{
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime', 'suppressed_at' => 'datetime'];
    }
}
