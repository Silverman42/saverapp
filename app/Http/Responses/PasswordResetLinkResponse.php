<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulContract;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetLinkResponse implements FailedContract, SuccessfulContract
{
    /**
     * Create a new response instance.
     */
    public function __construct(
        protected ?string $status = null
    ) {}

    /**
     * Create an HTTP response that represents the object.
     * Always returns the generic status message per Section 7.1 and Acceptance Criterion 11.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $message = __("If an account exists for this email, we've sent password reset instructions.");

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
