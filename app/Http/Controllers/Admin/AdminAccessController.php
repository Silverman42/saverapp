<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminPermissionsRequest;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Services\AuthorizationRestrictionService;
use App\Services\AuthorizationService;
use App\Services\FreshAuthenticationService;
use App\Services\PermissionManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminAccessController extends Controller
{
    /**
     * Display the Admin access directory.
     */
    public function index(Request $request, AuthorizationService $authService): Response
    {
        $currentAdmin = $request->user();
        $canManageAdmins = $currentAdmin ? $authService->allows($currentAdmin, AdminPermission::AdminsManage) : false;

        $admins = User::query()
            ->where('user_type', UserType::Admin->value)
            ->orderBy('name')
            ->paginate(15)
            ->through(function (User $admin) use ($currentAdmin, $canManageAdmins) {
                $isSelf = $currentAdmin && $admin->id === $currentAdmin->id;
                $directPermissions = $admin->getDirectPermissions()->pluck('name')->all();

                $summary = [];
                if ($isSelf || $canManageAdmins) {
                    $summary = $directPermissions;
                } else {
                    // Concise responsibility summary for other Admins
                    $count = count($directPermissions);
                    $summary = [
                        $count === 13 ? 'Full Administrator' : ($count === 0 ? 'Baseline Access' : "{$count} permissions assigned"),
                    ];
                }

                return [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'account_state' => $admin->account_state->value,
                    'permission_version' => $admin->permission_version,
                    'is_self' => $isSelf,
                    'can_manage' => $canManageAdmins && ! $isSelf,
                    'summary' => $summary,
                    'created_at' => $admin->created_at?->toIso8601String(),
                ];
            });

        return Inertia::render('admin/access/Index', [
            'admins' => $admins,
            'canManage' => $canManageAdmins,
        ]);
    }

    /**
     * Display detailed permission information and history for an Administrator.
     */
    public function show(
        Request $request,
        User $admin,
        AuthorizationService $authService,
        AuthorizationRestrictionService $restrictionService,
        FreshAuthenticationService $freshService,
    ): Response {
        if ($admin->user_type !== UserType::Admin) {
            abort(404, 'Administrator not found.');
        }

        $currentAdmin = $request->user();
        $isSelf = $currentAdmin && $admin->id === $currentAdmin->id;
        $canManage = $currentAdmin ? $authService->allows($currentAdmin, AdminPermission::AdminsManage) : false;

        if (! $isSelf && ! $canManage) {
            abort(403, 'Unauthorized to view administrator permission management details.');
        }

        $directPermissions = $admin->getDirectPermissions()->pluck('name')->all();
        $effectivePermissions = $authService->effectivePermissionCodes($admin);

        $activeRestrictions = $restrictionService->getActiveRestrictions($admin)->map(fn ($r) => [
            'id' => $r->id,
            'restriction_type' => $r->restriction_type->value,
            'permission_code' => $r->permission_code,
            'source' => $r->source,
            'started_at' => $r->started_at?->toIso8601String(),
            'expires_at' => $r->expires_at?->toIso8601String(),
        ])->all();

        $catalogue = Permission::query()
            ->where('guard_name', 'web')
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->map(fn (Permission $p) => [
                'name' => $p->name,
                'display_name' => $p->display_name ?? $p->name,
                'description' => $p->description ?? '',
                'is_highest_risk' => $p->name === AdminPermission::AdminsManage->value,
            ]);

        $history = PermissionGrantHistory::with('actor')
            ->where('user_id', $admin->id)
            ->latest('id')
            ->paginate(10)
            ->through(fn (PermissionGrantHistory $h) => [
                'id' => $h->id,
                'batch_id' => $h->batch_id,
                'permission_code' => $h->permission_code instanceof AdminPermission ? $h->permission_code->value : (string) $h->permission_code,
                'action' => $h->action,
                'source' => $h->source,
                'actor_name' => $h->actor?->name ?? 'System',
                'reason' => $h->reason,
                'permission_version' => $h->permission_version,
                'created_at' => $h->created_at?->toIso8601String(),
            ]);

        $isFresh = $currentAdmin ? $freshService->isFresh($currentAdmin, $request) : false;

        return Inertia::render('admin/access/Show', [
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'account_state' => $admin->account_state->value,
                'permission_version' => $admin->permission_version,
                'is_self' => $isSelf,
                'direct_permissions' => $directPermissions,
                'effective_permissions' => $effectivePermissions,
                'restrictions' => $activeRestrictions,
                'created_at' => $admin->created_at?->toIso8601String(),
            ],
            'catalogue' => $catalogue,
            'history' => $history,
            'canManage' => $canManage && ! $isSelf,
            'isFresh' => $isFresh,
            'isSelf' => $isSelf,
        ]);
    }

    /**
     * Update an Administrator's permissions.
     */
    public function update(
        UpdateAdminPermissionsRequest $request,
        User $admin,
        PermissionManagementService $managementService,
    ): RedirectResponse {
        if ($admin->user_type !== UserType::Admin) {
            abort(404, 'Administrator not found.');
        }

        $currentAdmin = $request->user();
        if ($currentAdmin && $admin->id === $currentAdmin->id) {
            abort(403, 'Administrators cannot manage their own permissions.');
        }

        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        /** @var string $reason */
        $reason = $request->validated('reason');
        /** @var int $expectedVersion */
        $expectedVersion = (int) $request->validated('expected_permission_version');

        $managementService->updatePermissions(
            actor: $currentAdmin,
            target: $admin,
            desiredPermissions: $permissions,
            reason: $reason,
            expectedPermissionVersion: $expectedVersion,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Administrator permissions updated successfully.'),
        ]);

        return redirect()->route('admin.access.show', $admin->id);
    }
}
