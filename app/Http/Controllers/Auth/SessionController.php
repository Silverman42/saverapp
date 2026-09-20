<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SessionManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function __construct(
        protected SessionManagerService $sessionManager,
    ) {}

    /**
     * Get active devices/sessions for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessionManager->getActiveSessions(
            $request->user(),
            $request->session()->getId(),
        );

        return response()->json([
            'sessions' => $sessions->values()->all(),
        ]);
    }

    /**
     * Revoke a specific session ("Sign out this device").
     */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($id === $request->session()->getId()) {
            return back()->withErrors(['session' => __('To sign out of your current session, use the standard sign-out button.')]);
        }

        $revoked = $this->sessionManager->revokeSession($request->user(), $id);

        if (! $revoked) {
            return back()->withErrors(['session' => __('The selected session could not be found or has already expired.')]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Device signed out successfully.'),
        ]);

        return back();
    }

    /**
     * Revoke all other sessions ("Sign out all other devices").
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $count = $this->sessionManager->revokeOtherSessions(
            $request->user(),
            $request->session()->getId(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('All other active sessions have been signed out.'),
        ]);

        return back();
    }

    /**
     * Revoke all sessions everywhere ("Sign out everywhere").
     */
    public function destroyAll(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->sessionManager->revokeAllSessionsAndTrustedDevices($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('All sessions and trusted devices have been signed out everywhere.'));
    }
}
