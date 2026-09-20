<?php

namespace App\Actions\Fortify;

use App\Enums\UserType;
use App\Services\AgentTrustedDeviceService;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as BaseAction;

class RedirectIfTwoFactorAuthenticatable extends BaseAction
{
    /**
     * Handle the incoming request.
     *
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        // Section 8.2 & AUTH-047: Agent trusted device bypasses TOTP challenge
        if ($user && $user->user_type === UserType::Agent) {
            $trustedDeviceService = app(AgentTrustedDeviceService::class);
            if ($trustedDeviceService->hasValidTrustedDevice($user, $request)) {
                return $next($request);
            }
        }

        return parent::handle($request, $next);
    }
}
