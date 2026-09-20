<?php

namespace App\Http\Responses;

use App\Support\RoleDestinationResolver;
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
        $roleDestination = RoleDestinationResolver::resolveUrl($request->user());

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($roleDestination);
    }
}
