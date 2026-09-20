<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AgentTrustedDeviceService;
use App\Services\ResumeCookieService;
use App\Services\SessionManagerService;
use App\Support\RoleDestinationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DeviceEvictionController extends Controller
{
    public function __construct(
        protected SessionManagerService $sessionManager,
        protected AgentTrustedDeviceService $trustedDeviceService,
        protected ResumeCookieService $resumeCookieService,
    ) {}

    /**
     * Show the concurrent device eviction selection screen.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get('login.pending_eviction');
        if (! $pending || ! isset($pending['user_id'])) {
            return redirect()->route('login');
        }

        $user = User::find($pending['user_id']);
        if (! $user) {
            $request->session()->forget('login.pending_eviction');

            return redirect()->route('login');
        }

        $activeSessions = $this->sessionManager->getActiveSessions($user);

        // If under the limit (e.g. previous session naturally expired in between)
        if ($activeSessions->count() < $user->maxConcurrentDevices()) {
            return $this->finalizeLogin($request, $user, $pending);
        }

        return Inertia::render('auth/DeviceEviction', [
            'sessions' => $activeSessions->values()->all(),
            'userType' => $user->user_type->value,
            'maxDevices' => $user->maxConcurrentDevices(),
        ]);
    }

    /**
     * Confirm eviction of an existing session and establish the new login.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('login.pending_eviction');
        if (! $pending || ! isset($pending['user_id'])) {
            return redirect()->route('login');
        }

        $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $user = User::findOrFail($pending['user_id']);
        $sessionIdToEvict = (string) $request->input('session_id');

        $evicted = $this->sessionManager->evictSession($user, $sessionIdToEvict, $request);
        if (! $evicted) {
            return back()->withErrors(['session_id' => __('The selected session could not be found or has already expired.')]);
        }

        return $this->finalizeLogin($request, $user, $pending);
    }

    /**
     * Cancel the eviction flow and return to login.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget('login.pending_eviction');

        return redirect()->route('login');
    }

    /**
     * Complete authentication after eviction or when limit is no longer exceeded.
     */
    protected function finalizeLogin(Request $request, User $user, array $pending): RedirectResponse
    {
        $remember = (bool) ($pending['remember'] ?? false);
        $replacementRequired = (bool) ($pending['replacement_required'] ?? false);
        $trustDevice = (bool) ($pending['trust_device'] ?? false);

        $request->session()->forget('login.pending_eviction');

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $now = Carbon::now()->timestamp;
        $request->session()->put('auth.login_at', $now);
        $request->session()->put('auth.last_active_at', $now);
        $request->session()->put('auth.fresh_until', $now + 600);
        $request->session()->put('auth.password_confirmed_at', $now);

        if ($replacementRequired) {
            $request->session()->put('two_factor_replacement_required', true);

            return redirect()->route('two-factor.enrolment');
        }

        $resumeDestination = $this->resumeCookieService->consumeResumeDestination($user, $request);
        $destination = $resumeDestination ?: RoleDestinationResolver::resolveUrl($user);

        $response = redirect()->intended($destination);

        if ($trustDevice) {
            $cookie = $this->trustedDeviceService->createTrustedDevice($user, $request);
            $response->withCookie($cookie);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Active session updated and signed in successfully.'),
        ]);

        return $response;
    }
}
