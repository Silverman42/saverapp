<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AgentTrustedDeviceService;
use App\Services\ResumeCookieService;
use App\Services\SessionManagerService;
use App\Support\RoleDestinationResolver;
use App\Support\Toast;
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

        $user = User::query()->whereKey($pending['user_id'])->first();
        if (! $user || ! $user->canSignIn() || (int) ($pending['access_version'] ?? 0) !== (int) $user->lifecycle_access_version) {
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

        $user = User::query()->whereKey($pending['user_id'])->firstOrFail();
        if (! $user->canSignIn() || (int) ($pending['access_version'] ?? 0) !== (int) $user->lifecycle_access_version) {
            $request->session()->forget('login.pending_eviction');

            return redirect()->route('login');
        }
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

        Toast::info('Sign-in cancelled', 'Your other devices stay signed in.');

        return redirect()->route('login');
    }

    /**
     * Complete authentication after eviction or when limit is no longer exceeded.
     */
    /** @param array<string, mixed> $pending */
    protected function finalizeLogin(Request $request, User $user, array $pending): RedirectResponse
    {
        $user->refresh();
        if (! $user->canSignIn() || (int) ($pending['access_version'] ?? 0) !== (int) $user->lifecycle_access_version) {
            $request->session()->forget('login.pending_eviction');

            return redirect()->route('login');
        }
        $remember = (bool) ($pending['remember'] ?? false);
        $replacementRequired = (bool) ($pending['replacement_required'] ?? false);
        $trustDevice = (bool) ($pending['trust_device'] ?? false);

        $request->session()->forget('login.pending_eviction');

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $now = Carbon::now()->getTimestamp();
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

        Toast::success('Signed in', __('Active session updated and signed in successfully.'));

        return $response;
    }
}
