<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSynchronizationService
{
    /**
     * Synchronize a user's single Spatie role to match their user_type.
     *
     * Ordinary newly created Admins receive zero granular permissions.
     */
    public function synchronize(User $user): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $this->rejectUserTypeChange($user);

        $roleName = $user->user_type->value;
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if ($role === null) {
            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        }

        $user->syncRoles([$role]);
    }

    /**
     * Reject post-creation user_type changes on existing users.
     */
    public function rejectUserTypeChange(User $user): void
    {
        if ($user->exists && ! $user->wasRecentlyCreated && $user->isDirty('user_type')) {
            throw new RuntimeException('Cannot change user_type on an existing user; roles are immutable.');
        }
    }

    /**
     * Idempotently provision the bootstrap Administrator with the complete permission catalogue.
     *
     * Selects the earliest active Admin if no target is provided.
     * Rejects non-active or non-Admin targets.
     */
    public function bootstrapAdmin(?User $admin = null): ?User
    {
        if ($admin !== null) {
            if ($admin->user_type !== UserType::Admin) {
                throw new InvalidArgumentException('Target user must be an Administrator.');
            }

            if ($admin->account_state !== AccountState::Active) {
                throw new InvalidArgumentException('Target user must be an active Administrator.');
            }
        } else {
            $admin = User::query()
                ->where('user_type', UserType::Admin->value)
                ->where('account_state', AccountState::Active->value)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();

            if ($admin === null) {
                return null;
            }
        }

        $this->synchronize($admin);

        $existingSeedCodes = PermissionGrantHistory::query()
            ->where('user_id', $admin->id)
            ->where('source', 'system_seed')
            ->pluck('permission_code')
            ->map(fn ($code) => $code instanceof AdminPermission ? $code->value : (string) $code)
            ->all();

        $allPermissionCodes = AdminPermission::values();
        $isFullySeeded = count(array_intersect($allPermissionCodes, $existingSeedCodes)) === count($allPermissionCodes);

        if ($isFullySeeded && $admin->permissions()->count() === count($allPermissionCodes)) {
            return $admin;
        }

        DB::transaction(function () use ($admin, $existingSeedCodes, $allPermissionCodes): void {
            $admin->syncPermissions($allPermissionCodes);

            $batchId = (string) Str::uuid();
            $now = Carbon::now();
            $newRecords = [];

            foreach (AdminPermission::cases() as $permission) {
                if (! in_array($permission->value, $existingSeedCodes, true)) {
                    $newRecords[] = [
                        'batch_id' => $batchId,
                        'user_id' => $admin->id,
                        'permission_code' => $permission->value,
                        'action' => 'grant',
                        'source' => 'system_seed',
                        'actor_user_id' => null,
                        'reason' => 'Initial bootstrap grant of complete permission catalogue to earliest active Administrator',
                        'permission_version' => 1,
                        'created_at' => $now,
                    ];
                }
            }

            if (! empty($newRecords)) {
                PermissionGrantHistory::insert($newRecords);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    /**
     * Check role synchronization, catalogue integrity, and bootstrap grant provenance.
     * Strictly read-only; never mutates data.
     */
    public function check(): RoleSyncReport
    {
        $issues = [];
        $details = [];

        // 1. Role Catalogue Check
        $expectedRoles = UserType::values();
        $roles = DB::table('roles')->get();
        $roleNames = $roles->pluck('name')->all();

        $missingRoles = array_diff($expectedRoles, $roleNames);
        foreach ($missingRoles as $missing) {
            $issues[] = "Missing fixed role '{$missing}' on web guard.";
        }

        $unexpectedRoles = $roles->filter(fn ($r) => ! in_array($r->name, $expectedRoles, true) || $r->guard_name !== 'web');
        foreach ($unexpectedRoles as $unexpected) {
            $issues[] = "Unexpected role '{$unexpected->name}' with guard '{$unexpected->guard_name}' detected in roles table.";
        }

        $details['roles_count'] = $roles->count();

        // 2. Empty role_has_permissions pivot check
        $rolePermissionsCount = DB::table('role_has_permissions')->count();
        $details['role_has_permissions_count'] = $rolePermissionsCount;
        if ($rolePermissionsCount > 0) {
            $issues[] = "role_has_permissions pivot is not empty ({$rolePermissionsCount} entries found). Roles must never inherit permissions.";
        }

        // 3. User-role mappings check
        $users = User::all();
        $details['users_count'] = $users->count();

        $userRoleMap = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->select('model_has_roles.model_id', 'roles.name as role_name')
            ->get()
            ->groupBy('model_id');

        foreach ($users as $user) {
            $assigned = $userRoleMap->get($user->id);

            if ($assigned === null || $assigned->isEmpty()) {
                $issues[] = "User #{$user->id} ({$user->email}) is missing an assigned role.";

                continue;
            }

            if ($assigned->count() > 1) {
                $roleList = $assigned->pluck('role_name')->implode(', ');
                $issues[] = "User #{$user->id} ({$user->email}) has multiple assigned roles: [{$roleList}].";

                continue;
            }

            $singleRole = $assigned->first()->role_name;
            if ($singleRole !== $user->user_type->value) {
                $issues[] = "User #{$user->id} ({$user->email}) role mismatch: user_type is '{$user->user_type->value}' but assigned role is '{$singleRole}'.";
            }
        }

        // 4. Direct-grant boundaries check
        $directGrants = DB::table('model_has_permissions')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->join('users', 'model_has_permissions.model_id', '=', 'users.id')
            ->where('model_has_permissions.model_type', User::class)
            ->select('model_has_permissions.model_id', 'users.user_type', 'users.email', 'permissions.name as permission_name')
            ->get();

        $details['direct_grants_count'] = $directGrants->count();

        $allowedPermissions = AdminPermission::values();

        foreach ($directGrants as $grant) {
            if ($grant->user_type !== UserType::Admin->value) {
                $issues[] = "Non-admin user #{$grant->model_id} ({$grant->user_type}) has direct grant '{$grant->permission_name}'.";
            }

            if (! in_array($grant->permission_name, $allowedPermissions, true)) {
                $issues[] = "User #{$grant->model_id} has uncatalogued permission '{$grant->permission_name}' directly granted.";
            }
        }

        // 5. Bootstrap grant provenance check
        $activeAdmins = User::query()
            ->where('user_type', UserType::Admin->value)
            ->where('account_state', AccountState::Active->value)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $details['active_admins_count'] = $activeAdmins->count();

        if ($activeAdmins->isNotEmpty()) {
            $bootstrapAdmin = $activeAdmins->first();
            $bootstrapPermissions = DB::table('model_has_permissions')
                ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
                ->where('model_has_permissions.model_type', User::class)
                ->where('model_has_permissions.model_id', $bootstrapAdmin->id)
                ->pluck('permissions.name')
                ->all();

            $missingGrants = array_diff($allowedPermissions, $bootstrapPermissions);
            if (! empty($missingGrants)) {
                $missingStr = implode(', ', $missingGrants);
                $issues[] = "Bootstrap Administrator #{$bootstrapAdmin->id} ({$bootstrapAdmin->email}) is missing direct permissions: [{$missingStr}].";
            }

            $seedHistories = PermissionGrantHistory::query()
                ->where('user_id', $bootstrapAdmin->id)
                ->where('source', 'system_seed')
                ->get();

            if ($seedHistories->count() !== count($allowedPermissions)) {
                $issues[] = "Bootstrap Administrator #{$bootstrapAdmin->id} has {$seedHistories->count()} system_seed history records; expected 13.";
            } else {
                $batches = $seedHistories->pluck('batch_id')->unique();
                if ($batches->count() !== 1) {
                    $issues[] = "Bootstrap Administrator #{$bootstrapAdmin->id} system_seed history has multiple batch identifiers.";
                }
            }
        }

        return new RoleSyncReport(
            valid: empty($issues),
            issues: $issues,
            details: $details,
        );
    }
}
