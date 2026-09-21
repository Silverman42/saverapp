<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\Permission;
use App\Models\User;
use App\Support\AuthorizationEvidence;
use Illuminate\Auth\Access\AuthorizationException;

class AuthorizationService
{
    public function __construct(
        protected AuthorizationRestrictionService $restrictionService,
    ) {}

    /**
     * Determine whether an authenticated user is authorized for an AdminPermission.
     *
     * Invariants enforced:
     * 1. Active account state.
     * 2. Administrator classification (user_type === admin).
     * 3. Exactly one synchronized 'admin' Spatie role.
     * 4. Active web permission definition in the closed catalogue.
     * 5. Direct user grant on model_has_permissions (role-inherited grants never count).
     * 6. No currently effective authorization restriction on the requested permission.
     */
    public function allows(User $user, AdminPermission $permission): bool
    {
        // 1. Account state check
        if ($user->account_state !== AccountState::Active) {
            return false;
        }

        // 2. User type check
        if ($user->user_type !== UserType::Admin) {
            return false;
        }

        // 3. Exactly one synchronized 'admin' role
        $roles = $user->getRoleNames();
        if ($roles->count() !== 1 || $roles->first() !== UserType::Admin->value) {
            return false;
        }

        // 4. Active catalogue definition
        $isCatalogueActive = Permission::query()
            ->where('name', $permission->value)
            ->where('guard_name', 'web')
            ->where('status', 'active')
            ->exists();

        if (! $isCatalogueActive) {
            return false;
        }

        // 5. Direct user grant (role inheritance strictly prohibited)
        if (! $user->hasDirectPermission($permission->value)) {
            return false;
        }

        // 6. No currently effective temporary restriction
        if ($this->restrictionService->hasActiveRestriction($user, $permission)) {
            return false;
        }

        return true;
    }

    /**
     * Get list of effective Admin permission codes currently authorized for a user.
     *
     * Returns an empty array for guests, non-Admins, inactive accounts, drifted roles,
     * or Admins with zero effective grants.
     *
     * @return list<string>
     */
    public function effectivePermissionCodes(User $user): array
    {
        if ($user->account_state !== AccountState::Active || $user->user_type !== UserType::Admin) {
            return [];
        }

        $roles = $user->getRoleNames();
        if ($roles->count() !== 1 || $roles->first() !== UserType::Admin->value) {
            return [];
        }

        // Active permission catalogue names
        $activeCatalogueNames = Permission::query()
            ->where('guard_name', 'web')
            ->where('status', 'active')
            ->pluck('name')
            ->all();

        // Direct user grants from Spatie
        $directPermissionNames = $user->getDirectPermissions()
            ->pluck('name')
            ->all();

        $effectiveCodes = [];

        foreach (AdminPermission::cases() as $permission) {
            if (! in_array($permission->value, $activeCatalogueNames, true)) {
                continue;
            }

            if (! in_array($permission->value, $directPermissionNames, true)) {
                continue;
            }

            if ($this->restrictionService->hasActiveRestriction($user, $permission)) {
                continue;
            }

            $effectiveCodes[] = $permission->value;
        }

        return $effectiveCodes;
    }

    /**
     * Capture immutable authorization evidence for an authorized Admin action.
     *
     * @param  string|array<string, mixed>|null  $subjectType
     * @param  array<string, mixed>  $subjectContext
     *
     * @throws AuthorizationException
     */
    public function captureEvidence(
        User $user,
        AdminPermission $permission,
        string|array|null $subjectType = null,
        string|int|null $subjectId = null,
        ?int $subjectVersion = null,
        array $subjectContext = [],
    ): AuthorizationEvidence {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException("This action is unauthorized for permission [{$permission->value}].");
        }

        if (is_array($subjectType)) {
            $context = $subjectType;
            $subjectType = isset($context['subject_type']) ? (string) $context['subject_type'] : null;
            $subjectId = $context['subject_id'] ?? null;
            $subjectVersion = isset($context['subject_version']) ? (int) $context['subject_version'] : null;
        } elseif (! empty($subjectContext)) {
            $subjectType = $subjectType ?? ($subjectContext['subject_type'] ?? null);
            $subjectId = $subjectId ?? ($subjectContext['subject_id'] ?? null);
            $subjectVersion = $subjectVersion ?? ($subjectContext['subject_version'] ?? null);
        }

        return new AuthorizationEvidence(
            actorId: $user->id,
            permission: $permission,
            permissionVersion: $user->permission_version,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectVersion: $subjectVersion,
        );
    }

    /**
     * Reauthorize previously captured authorization evidence.
     *
     * Reloads the actor, requires exact permission_version alignment, and reruns the
     * authoritative authorization check.
     */
    public function reauthorize(AuthorizationEvidence $evidence): bool
    {
        $actor = User::query()->find($evidence->actorId);

        if ($actor === null) {
            return false;
        }

        // Version pinning: Any intervening grant, revocation, or restriction changes the version
        if ($actor->permission_version !== $evidence->permissionVersion) {
            return false;
        }

        return $this->allows($actor, $evidence->permission);
    }
}
