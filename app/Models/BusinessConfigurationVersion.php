<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BusinessConfigurationVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $business_profile_id
 * @property int $version
 * @property int $base_version
 * @property array<string, mixed>|null $values
 * @property string $values_hash
 * @property list<string> $changed_codes
 * @property string $dependency_hash
 * @property int|null $actor_user_id
 * @property string|null $actor_label
 * @property string $reason
 * @property string $source
 * @property CarbonImmutable $requested_effective_at
 */
#[Fillable(['business_profile_id', 'version', 'base_version', 'values', 'values_hash', 'changed_codes', 'dependency_hash', 'actor_user_id', 'actor_label', 'reason', 'source', 'requested_effective_at'])]
class BusinessConfigurationVersion extends Model
{
    /** @use HasFactory<BusinessConfigurationVersionFactory> */
    use HasFactory;

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

    protected $hidden = ['values', 'reason', 'actor_label'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'values' => 'encrypted:array',
            'reason' => 'encrypted',
            'actor_label' => 'encrypted',
            'changed_codes' => 'array',
            'requested_effective_at' => 'immutable_datetime',
            'version' => 'integer',
            'base_version' => 'integer',
        ];
    }
}
