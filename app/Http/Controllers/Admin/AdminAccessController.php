<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminPermissionsRequest;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Services\AuthorizationRestrictionService;
use App\Services\AuthorizationService;
use App\Services\FreshAuthenticationService;
use App\Services\PermissionManagementService;
use App\Services\StaffRecoveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminAccessController extends Controller
{
    /**
     * Display the Admin access directory.
     */
    public function index(Request $request, AuthorizationService $authService, FreshAuthenticationService $freshService): Response
    {
        $currentAdmin = $request->user();
        $canManageAdmins = $currentAdmin ? $authService->allows($currentAdmin, AdminPermission::AdminsManage) : false;
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'account_state' => ['nullable', 'string', 'in:active,invited,mfa_setup,suspended,deactivated,all'],
            'per_page' => ['nullable', 'integer', 'in:15,25,50'],
        ]);

        $query = User::query()->where('user_type', UserType::Admin->value);
        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $accountState = $validated['account_state'] ?? '';
        if ($accountState !== '' && $accountState !== 'all') {
            $query->where('account_state', $accountState);
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $admins = $query
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
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
            'isFresh' => $canManageAdmins && $currentAdmin !== null && $freshService->isFresh($currentAdmin, $request),
            'inviteForm' => Inertia::optional(fn (): ?array => $canManageAdmins ? AdminInvitationController::formProps() : null),
            'filters' => [
                'search' => $search,
                'account_state' => $accountState,
                'per_page' => $perPage,
            ],
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

        $historyFilters = $request->validate([
            'history_search' => ['nullable', 'string', 'max:100'],
            'history_action' => ['nullable', 'string', 'in:grant,revoke,all'],
            'history_per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $directPermissions = $admin->getDirectPermissions()->pluck('name')->all();
        $effectivePermissions = $authService->effectivePermissionCodes($admin);

        $activeRestrictions = $restrictionService->getActiveRestrictions($admin)->map(fn ($r) => [
            'id' => $r->id,
            'restriction_type' => $r->restriction_type->value,
            'permission_code' => $r->permission_code,
            'source' => $r->source,
            'started_at' => $r->started_at->toIso8601String(),
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

        $historyQuery = PermissionGrantHistory::with('actor')->where('user_id', $admin->id);
        $historySearch = trim((string) ($historyFilters['history_search'] ?? ''));

        if ($historySearch !== '') {
            $historyQuery->where(function (Builder $searchQuery) use ($historySearch): void {
                $searchQuery->where('permission_code', 'like', "%{$historySearch}%")
                    ->orWhereHas('actor', function (Builder $actorQuery) use ($historySearch): void {
                        $actorQuery->where('name', 'like', "%{$historySearch}%");
                    });
            });
        }

        $historyAction = $historyFilters['history_action'] ?? '';
        if ($historyAction !== '' && $historyAction !== 'all') {
            $historyQuery->where('action', $historyAction);
        }

        $historyPerPage = (int) ($historyFilters['history_per_page'] ?? 10);
        $history = $historyQuery->latest('id')
            ->paginate($historyPerPage)
            ->withQueryString()
            ->through(fn (PermissionGrantHistory $h) => [
                'id' => $h->id,
                'batch_id' => $h->batch_id,
                'permission_code' => $h->permission_code instanceof AdminPermission ? $h->permission_code->value : (string) $h->permission_code,
                'action' => $h->action,
                'source' => $h->source,
                'actor_name' => $h->actor->name ?? 'System',
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
            'history_filters' => [
                'search' => $historySearch,
                'action' => $historyAction,
                'per_page' => $historyPerPage,
            ],
            'canManage' => $canManage && ! $isSelf,
            'canRequestRecovery' => $currentAdmin !== null && $admin->account_state !== AccountState::Invited
                && app(StaffRecoveryService::class)->canManage($currentAdmin, $admin),
            'invitation' => $canManage && ! $isSelf && $admin->account_state === AccountState::Invited
                ? $this->latestInvitation($admin)
                : null,
            'isFresh' => $isFresh,
            'isSelf' => $isSelf,
        ]);
    }

    /**
     * Summarize the latest invitation for an invited Administrator without exposing its token.
     *
     * @return array{status: string, delivery_status: string, generation: int, issued_at: string|null, expires_at: string, is_expired: bool, can_resend: bool}|null
     */
    private function latestInvitation(User $admin): ?array
    {
        $invitation = Invitation::query()->where('user_id', $admin->id)->latest('generation')->first();
        if ($invitation === null) {
            return null;
        }

        return [
            'status' => $invitation->status->value,
            'delivery_status' => $invitation->delivery_status->value,
            'generation' => $invitation->generation,
            'issued_at' => $invitation->created_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'is_expired' => $invitation->isExpired(),
            'can_resend' => $invitation->canResend(),
        ];
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
