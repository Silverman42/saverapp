<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property Carbon $created_at
 * @property array<string, mixed> $facts
 */
class AgentLifecycleHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Agent lifecycle history is immutable.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Agent lifecycle history must be retained.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'facts' => 'array', 'created_at' => 'datetime', 'from_version' => 'integer', 'to_version' => 'integer'];
    }
}
