<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Models\AuditEvent;
use App\Models\AuthorizationRestriction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuthorizationRestrictionService
{
    /**
     * Apply a temporary authorization restriction to an Administrator.
     *
     * Idempotent: If an active restriction matching the type and permission exists, it is returned.
     * Transactionally locks the target user and increments permission_version exactly once per effective change.
     */
    public function apply(
        User $target,
        AuthorizationRestrictionType $type,
        string $source,
        ?string $sourceReference = null,
        ?User $createdBy = null,
        ?CarbonInterface $startedAt = null,
        ?CarbonInterface $expiresAt = null,
        ?AdminPermission $permission = null,
    ): AuthorizationRestriction {
        return DB::transaction(function () use (
            $target,
            $type,
            $source,
            $sourceReference,
            $createdBy,
            $startedAt,
            $expiresAt,
            $permission,
        ): AuthorizationRestriction {
            $lockedUser = User::query()->where('id', $target->id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            $permissionCode = $permission !== null ? $permission->value : $type->affectedPermission()->value;

            // Check if an effective active restriction of the same type and permission already exists
            $existing = AuthorizationRestriction::query()
                ->where('user_id', $lockedUser->id)
                ->where('restriction_type', $type->value)
                ->where('permission_code', $permissionCode)
                ->whereNull('cleared_at')
                ->where('started_at', '<=', $now)
                ->where(function ($q) use ($now): void {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Advance permission_version exactly once
            $newVersion = $lockedUser->permission_version + 1;
            $lockedUser->permission_version = $newVersion;
            $lockedUser->save();

            $start = $startedAt ?? $now;
            $expiry = $expiresAt ?? ($type->defaultDurationMinutes() ? Carbon::instance($start)->addMinutes($type->defaultDurationMinutes()) : null);

            $restriction = AuthorizationRestriction::create([
                'user_id' => $lockedUser->id,
                'restriction_type' => $type,
                'permission_code' => $permissionCode,
                'source' => $source,
                'source_reference' => $sourceReference,
                'started_at' => $start,
                'expires_at' => $expiry,
                'created_by' => $createdBy?->id,
                'applied_permission_version' => $newVersion,
            ]);

            AuditEvent::record('authorization.restriction_applied', AuthorizationRestriction::class, $restriction->id, null,
                ['restriction_id' => $restriction->id, 'restriction_type' => $type->value, 'permission_code' => $permissionCode, 'to_version' => $newVersion], $createdBy,
                ['executor' => self::class, 'operation_id' => 'restriction:'.$restriction->id]);
            $target->permission_version = $newVersion;

            return $restriction;
        });
    }

    /**
     * Clear an authorization restriction.
     *
     * Idempotent: If the restriction is already cleared, returns it unchanged.
     * Transactionally locks the target user and increments permission_version exactly once per effective transition.
     */
    public function clear(
        AuthorizationRestriction $restriction,
        ?string $reason = null,
        ?User $clearedBy = null,
    ): AuthorizationRestriction {
        if ($restriction->cleared_at !== null) {
            return $restriction;
        }

        return DB::transaction(function () use ($restriction, $reason, $clearedBy): AuthorizationRestriction {
            $lockedUser = User::query()->where('id', $restriction->user_id)->lockForUpdate()->firstOrFail();
            $freshRestriction = AuthorizationRestriction::query()->where('id', $restriction->id)->lockForUpdate()->firstOrFail();

            if ($freshRestriction->cleared_at !== null) {
                return $freshRestriction;
            }

            $newVersion = $lockedUser->permission_version + 1;
            $lockedUser->permission_version = $newVersion;
            $lockedUser->save();

            $freshRestriction->update([
                'cleared_at' => Carbon::now(),
                'cleared_by' => $clearedBy?->id,
                'clear_reason' => $reason,
                'cleared_permission_version' => $newVersion,
            ]);

            AuditEvent::record('authorization.restriction_cleared', AuthorizationRestriction::class, $freshRestriction->id, null,
                ['restriction_id' => $freshRestriction->id, 'to_version' => $newVersion], $clearedBy, ['executor' => self::class]);
            $restriction->user->permission_version = $newVersion;

            return $freshRestriction;
        });
    }

    /**
     * Clear active restrictions of a specific type for a user.
     */
    public function clearActiveType(
        User $user,
        AuthorizationRestrictionType $type,
        ?string $reason = null,
        ?User $clearedBy = null,
    ): int {
        return DB::transaction(function () use ($user, $type, $reason, $clearedBy): int {
            $lockedUser = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            $activeRestrictions = AuthorizationRestriction::query()
                ->where('user_id', $lockedUser->id)
                ->where('restriction_type', $type->value)
                ->whereNull('cleared_at')
                ->where('started_at', '<=', $now)
                ->where(function ($q) use ($now): void {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->lockForUpdate()
                ->get();

            if ($activeRestrictions->isEmpty()) {
                return 0;
            }

            $newVersion = $lockedUser->permission_version + 1;
            $lockedUser->permission_version = $newVersion;
            $lockedUser->save();

            foreach ($activeRestrictions as $restriction) {
                $restriction->update([
                    'cleared_at' => $now,
                    'cleared_by' => $clearedBy?->id,
                    'clear_reason' => $reason,
                    'cleared_permission_version' => $newVersion,
                ]);
            }

            foreach ($activeRestrictions as $restriction) {
                AuditEvent::record('authorization.restriction_cleared', AuthorizationRestriction::class, $restriction->id, null,
                    ['restriction_id' => $restriction->id, 'to_version' => $newVersion], $clearedBy, ['executor' => self::class]);
            }
            $user->permission_version = $newVersion;

            return $activeRestrictions->count();
        });
    }

    /**
     * Get all currently active authorization restrictions for a user.
     *
     * @return Collection<int, AuthorizationRestriction>
     */
    public function getActiveRestrictions(User $user, ?CarbonInterface $now = null): Collection
    {
        $now = $now ?? Carbon::now();

        return AuthorizationRestriction::query()
            ->where('user_id', $user->id)
            ->whereNull('cleared_at')
            ->where('started_at', '<=', $now)
            ->where(function ($q) use ($now): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->get();
    }

    /**
     * Check if a user currently has an effective active restriction on a specific permission.
     */
    public function hasActiveRestriction(User $user, AdminPermission $permission, ?CarbonInterface $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return AuthorizationRestriction::query()
            ->where('user_id', $user->id)
            ->whereNull('cleared_at')
            ->where('started_at', '<=', $now)
            ->where(function ($q) use ($now): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->where(function ($q) use ($permission): void {
                $q->whereNull('permission_code')
                    ->orWhere('permission_code', $permission->value);
            })
            ->exists();
    }

    /**
     * Close elapsed restrictions across all users and advance each affected Admin's
     * permission version once.
     *
     * Idempotent and concurrency-safe.
     */
    public function expireElapsedRestrictions(?CarbonInterface $now = null): int
    {
        $now = $now ?? Carbon::now();

        $affectedUserIds = AuthorizationRestriction::query()
            ->whereNull('cleared_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->pluck('user_id')
            ->unique()
            ->values();

        $totalClosed = 0;

        foreach ($affectedUserIds as $userId) {
            $totalClosed += DB::transaction(function () use ($userId, $now): int {
                $lockedUser = User::query()->where('id', $userId)->lockForUpdate()->first();
                if ($lockedUser === null) {
                    return 0;
                }

                $elapsed = AuthorizationRestriction::query()
                    ->where('user_id', $userId)
                    ->whereNull('cleared_at')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $now)
                    ->lockForUpdate()
                    ->get();

                if ($elapsed->isEmpty()) {
                    return 0;
                }

                $newVersion = $lockedUser->permission_version + 1;
                $lockedUser->permission_version = $newVersion;
                $lockedUser->save();

                foreach ($elapsed as $restriction) {
                    $restriction->update([
                        'cleared_at' => $now,
                        'clear_reason' => 'Expired by scheduled restriction reaper',
                        'cleared_permission_version' => $newVersion,
                    ]);
                }

                foreach ($elapsed as $restriction) {
                    AuditEvent::record('authorization.restriction_expired', AuthorizationRestriction::class, $restriction->id, null,
                        ['restriction_id' => $restriction->id, 'to_version' => $newVersion], null, ['executor' => self::class, 'outcome' => 'Expired']);
                }

                return $elapsed->count();
            });
        }

        return $totalClosed;
    }
}
