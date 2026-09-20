<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $display_name
 * @property string|null $description
 * @property string $status
 * @property Carbon|null $introduced_at
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> retired()
 */
class Permission extends SpatiePermission
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'introduced_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to only include active permissions.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include retired permissions.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', 'retired');
    }

    /**
     * Determine if the permission is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determine if the permission has been retired.
     */
    public function isRetired(): bool
    {
        return $this->status === 'retired';
    }

    /**
     * Mark the permission as retired.
     */
    public function markRetired(): void
    {
        $this->update([
            'status' => 'retired',
            'retired_at' => Carbon::now(),
        ]);
    }
}
