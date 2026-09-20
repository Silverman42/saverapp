<?php

namespace App\Http\Responses;

use App\Services\ResumeCookieService;
use App\Support\RoleDestinationResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $user = $request->user();

        // Section 5.1 & 8: Record non-extendable password and MFA freshness timestamps
        $now = Carbon::now()->timestamp;
        if (! $request->session()->has('auth.login_at')) {
            $request->session()->put('auth.login_at', $now);
        }
        $request->session()->put('auth.last_active_at', $now);
        $request->session()->put('auth.fresh_until', $now + 600);
        $request->session()->put('auth.password_confirmed_at', $now);
        $request->session()->put('auth.mfa_confirmed_at', $now);

        // Section 5.10: Recovery-code login creates restricted session requiring authenticator replacement
        if ($request->session()->get('two_factor_replacement_required', false)) {
            return $request->wantsJson()
                ? new JsonResponse(['two_factor_replacement_required' => true], 200)
                : redirect()->route('two-factor.enrolment');
        }

        $resumeDestination = $user ? app(ResumeCookieService::class)->consumeResumeDestination($user, $request) : null;
        $destination = $resumeDestination ?: RoleDestinationResolver::resolveUrl($user);

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($destination);
    }
}
