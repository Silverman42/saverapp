<?php

namespace App\Http\Responses;

use App\Enums\AccountState;
use App\Services\ResumeCookieService;
use App\Support\RoleDestinationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user) {
            $now = Carbon::now()->timestamp;
            if (! $request->session()->has('auth.login_at')) {
                $request->session()->put('auth.login_at', $now);
            }
            $request->session()->put('auth.last_active_at', $now);
            $request->session()->put('auth.fresh_until', $now + 600);
            $request->session()->put('auth.password_confirmed_at', $now);
        }

        if ($user && $user->account_state === AccountState::MfaSetupRequired) {
            return $request->wantsJson()
                ? response()->json(['two_factor' => false, 'mfa_setup_required' => true])
                : redirect()->route('two-factor.enrolment');
        }

        $resumeDestination = $user ? app(ResumeCookieService::class)->consumeResumeDestination($user, $request) : null;
        $destination = $resumeDestination ?: RoleDestinationResolver::resolveUrl($user);

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($destination);
    }
}
