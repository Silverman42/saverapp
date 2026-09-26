<?php

namespace App\Http\Responses;

use App\Enums\AccountState;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\ResumeCookieService;
use App\Support\RoleDestinationResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $now = Carbon::now()->getTimestamp();
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

        if ($user !== null && $user->account_state === AccountState::Active) {
            try {
                AuditEvent::record('auth.login_succeeded', User::class, $user->id, null, [], $user,
                    ['executor' => self::class, 'operation_id' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'))]);
            } catch (\Throwable $exception) {
                Auth::logout();
                $request->session()->invalidate();
                throw $exception;
            }
        }

        $resumeDestination = $user ? app(ResumeCookieService::class)->consumeResumeDestination($user, $request) : null;
        $destination = $resumeDestination ?: RoleDestinationResolver::resolveUrl($user);

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($destination);
    }
}
