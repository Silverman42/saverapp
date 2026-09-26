<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $configuration_id
 * @property int $version
 * @property string $event_type
 * @property int|null $actor_user_id
 * @property int $audit_event_id
 * @property list<string> $changed_codes
 */
#[Fillable(['configuration_id', 'version', 'event_type', 'actor_user_id', 'audit_event_id', 'changed_codes'])]
class BusinessConfigurationEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Published configuration evidence is immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Published configuration evidence is immutable.');
        });
    }

    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changed_codes' => 'array',
            'version' => 'integer',
        ];
    }
}
