<?php

namespace App\Http\Responses;

use App\Support\RoleDestinationResolver;
use Illuminate\Http\Request;
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
        $roleDestination = RoleDestinationResolver::resolveUrl($request->user());

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($roleDestination);
    }
}
