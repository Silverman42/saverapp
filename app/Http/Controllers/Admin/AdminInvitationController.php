<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Services\AdminInvitationService;
use App\Services\AuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminInvitationController extends Controller
{
    public function __construct(protected AuthorizationService $authorizationService) {}

    /**
     * Display the Admin invitation form with the grantable permission catalogue.
     */
    public function create(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('admin/access/Invite', self::formProps());
    }

    /**
     * Build the props the invitation form needs: a fresh attempt reference and the grantable permissions.
     *
     * @return array{attempt_reference: string, permissions: Collection<int, array{code: string, name: string, description: string}>}
     */
    public static function formProps(): array
    {
        $active = Permission::query()->where('guard_name', 'web')->where('status', 'active')->pluck('name')->all();

        return [
            'attempt_reference' => (string) Str::uuid(),
            'permissions' => collect(AdminPermission::cases())
                ->filter(fn (AdminPermission $permission): bool => in_array($permission->value, $active, true))
                ->map(fn (AdminPermission $permission): array => [
                    'code' => $permission->value,
                    'name' => $permission->displayName(),
                    'description' => $permission->description(),
                ])->values(),
        ];
    }

    /**
     * Invite a new Administrator idempotently.
     */
    public function store(Request $request, AdminInvitationService $service): RedirectResponse
    {
        $this->authorizeManager($request);

        $validated = $request->validate([
            'attempt_reference' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
            'confirmed' => ['accepted'],
        ]);

        $result = $service->invite($request->user(), $validated['attempt_reference'], [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'permissions' => array_values($validated['permissions']),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['replayed'] ? 'Existing Administrator invitation resolved.' : 'Administrator invited. Invitation queued for delivery.',
        ]);

        return redirect()->route('admin.access.show', $result['admin']->id);
    }

    /**
     * Resend an invitation, invalidating every earlier link.
     */
    public function resend(Request $request, User $admin, AdminInvitationService $service): RedirectResponse
    {
        $this->authorizeTarget($request, $admin);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $service->resend($admin, $request->user(), $validated['reason'] ?? null);

        return $this->backWith($admin, 'Invitation resent. Earlier links no longer work.');
    }

    /**
     * Correct the invited Administrator's email and send a new invitation there.
     */
    public function correctEmail(Request $request, User $admin, AdminInvitationService $service): RedirectResponse
    {
        $this->authorizeTarget($request, $admin);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $service->correctEmail($admin, $request->user(), $validated['email'], $validated['reason']);

        return $this->backWith($admin, 'Email corrected and a new invitation queued.');
    }

    /**
     * Cancel all outstanding invitation links for the invited Administrator.
     */
    public function cancel(Request $request, User $admin, AdminInvitationService $service): RedirectResponse
    {
        $this->authorizeTarget($request, $admin);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:500']]);

        $service->cancel($admin, $request->user(), $validated['reason']);

        return $this->backWith($admin, 'Invitation cancelled.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($this->authorizationService->allows($request->user(), AdminPermission::AdminsManage), 403);
    }

    private function authorizeTarget(Request $request, User $admin): void
    {
        $this->authorizeManager($request);
        abort_if($admin->user_type !== UserType::Admin, 404, 'Administrator not found.');
        abort_if($admin->id === $request->user()->id, 403, 'Administrators cannot manage their own invitation.');
    }

    private function backWith(User $admin, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('admin.access.show', $admin->id);
    }
}
