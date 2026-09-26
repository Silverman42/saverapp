<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class PermissionManagementService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
        protected AuthorizationRestrictionService $restrictionService,
    ) {}

    /**
     * Update an Administrator's permissions atomically.
     *
     * @param  list<string>  $desiredPermissions
     * @return array{batch_id: string, grants: list<string>, revocations: list<string>, new_version: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function updatePermissions(
        User $actor,
        User $target,
        array $desiredPermissions,
        string $reason,
        int $expectedPermissionVersion,
    ): array {
        // Basic pre-transaction sanity
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => [__('The reason must be between 1 and 500 characters.')],
            ]);
        }

        return app(PlatformGuard::class)->transaction('mutation', function () use (
            $actor,
            $target,
            $desiredPermissions,
            $reason,
            $expectedPermissionVersion,
        ): array {
            // 1. Gather all active admin IDs for continuity checks, actor ID, and target ID
            $activeAdminIds = User::query()
                ->where('user_type', UserType::Admin->value)
                ->where('account_state', AccountState::Active->value)
                ->pluck('id')
                ->all();

            $idsToLock = collect([$actor->id, $target->id])
                ->merge($activeAdminIds)
                ->unique()
                ->sort()
                ->values()
                ->all();

            // Lock all involved users in deterministic order
            User::query()->whereIn('id', $idsToLock)->lockForUpdate()->get();

            /** @var User $lockedActor */
            $lockedActor = User::query()->findOrFail($actor->id);
            /** @var User $lockedTarget */
            $lockedTarget = User::query()->findOrFail($target->id);

            // 2. Authoritative commit-time authorization check for actor
            if (! $this->authorizationService->allows($lockedActor, AdminPermission::AdminsManage)) {
                throw new AuthorizationException(__('You do not have authorization to manage administrator permissions.'));
            }

            // 3. Self-management prohibition
            if ($lockedActor->id === $lockedTarget->id) {
                throw new AuthorizationException(__('Administrators cannot manage their own permissions.'));
            }

            // 4. Target validation
            if ($lockedTarget->user_type !== UserType::Admin) {
                throw ValidationException::withMessages([
                    'target' => [__('Target user is not an Administrator.')],
                ]);
            }

            $targetRoles = $lockedTarget->getRoleNames();
            if ($targetRoles->count() !== 1 || $targetRoles->first() !== UserType::Admin->value) {
                throw ValidationException::withMessages([
                    'target' => [__('Target administrator has invalid or drifted role configuration.')],
                ]);
            }

            // Concurrency check
            if ($lockedTarget->permission_version !== $expectedPermissionVersion) {
                throw ValidationException::withMessages([
                    'expected_permission_version' => [__('The permissions for this administrator were updated by another session. Please refresh and review current state.')],
                ]);
            }

            // 5. Catalogue and duplicate check
            if (count($desiredPermissions) !== count(array_unique($desiredPermissions))) {
                throw ValidationException::withMessages([
                    'permissions' => [__('Duplicate permissions are not permitted.')],
                ]);
            }

            $validEnumValues = AdminPermission::values();
            $activeCatalogueNames = Permission::query()
                ->where('guard_name', 'web')
                ->where('status', 'active')
                ->pluck('name')
                ->all();

            foreach ($desiredPermissions as $perm) {
                if (! in_array($perm, $validEnumValues, true) || ! in_array($perm, $activeCatalogueNames, true)) {
                    throw ValidationException::withMessages([
                        'permissions' => [__('The permission [:permission] is invalid, retired, or unknown.', ['permission' => $perm])],
                    ]);
                }
            }

            // 6. Compute grants and revocations
            $currentPermissions = $lockedTarget->getDirectPermissions()->pluck('name')->all();
            $grants = array_values(array_diff($desiredPermissions, $currentPermissions));
            $revocations = array_values(array_diff($currentPermissions, $desiredPermissions));

            if (empty($grants) && empty($revocations)) {
                throw ValidationException::withMessages([
                    'permissions' => [__('No permission changes were requested.')],
                ]);
            }

            // 7. Final-capable-Admin safeguards
            if (in_array(AdminPermission::AdminsManage->value, $revocations, true)) {
                // Must preserve at least one active, unrestricted holder of admins.manage
                $remainingCapables = User::query()
                    ->where('user_type', UserType::Admin->value)
                    ->where('account_state', AccountState::Active->value)
                    ->where('id', '!=', $lockedTarget->id)
                    ->get()
                    ->filter(fn (User $admin) => $this->authorizationService->allows($admin, AdminPermission::AdminsManage));

                if ($remainingCapables->isEmpty()) {
                    throw ValidationException::withMessages([
                        'permissions' => [__('Cannot revoke admins.manage: at least one active, unrestricted Administrator with admins.manage must be preserved.')],
                    ]);
                }
            }

            // 8. Apply changes to Spatie pivots
            $lockedTarget->syncPermissions($desiredPermissions);

            // Increment permission version exactly once
            $newVersion = $lockedTarget->permission_version + 1;
            $lockedTarget->permission_version = $newVersion;
            $lockedTarget->save();

            // Append history records under a single UUID batch
            $batchId = (string) Str::uuid();

            foreach ($grants as $code) {
                PermissionGrantHistory::create([
                    'batch_id' => $batchId,
                    'user_id' => $lockedTarget->id,
                    'permission_code' => $code,
                    'action' => 'grant',
                    'source' => 'permission_change',
                    'actor_user_id' => $lockedActor->id,
                    'reason' => $reason,
                    'permission_version' => $newVersion,
                ]);
            }

            foreach ($revocations as $code) {
                PermissionGrantHistory::create([
                    'batch_id' => $batchId,
                    'user_id' => $lockedTarget->id,
                    'permission_code' => $code,
                    'action' => 'revoke',
                    'source' => 'permission_change',
                    'actor_user_id' => $lockedActor->id,
                    'reason' => $reason,
                    'permission_version' => $newVersion,
                ]);
            }

            AuditEvent::record('authorization.permissions_changed', User::class, $lockedTarget->id, null,
                ['batch_id' => $batchId, 'grants' => $grants, 'revocations' => $revocations,
                    'from_version' => $expectedPermissionVersion, 'to_version' => $newVersion], $lockedActor,
                ['required_permission' => AdminPermission::AdminsManage->value, 'executor' => self::class, 'operation_id' => $batchId]);
            // Clear Spatie cached permissions
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $target->permission_version = $newVersion;

            return [
                'batch_id' => $batchId,
                'grants' => $grants,
                'revocations' => $revocations,
                'new_version' => $newVersion,
            ];
        });
    }
}
