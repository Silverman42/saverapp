<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Services\AuthenticationAbuseService;
use App\Services\TwoFactorService;
use App\Support\IdentityNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorEnrolmentController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected TwoFactorService $twoFactorService,
    ) {}

    /**
     * Display the mandatory MFA enrolment page.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        if ($user->user_type === UserType::Customer) {
            abort(403, 'Two-factor authentication is not available for Customers.');
        }

        // Check if user already has an active pending secret; if not, initialize one
        if (! $user->two_factor_pending_secret || ($user->two_factor_pending_expires_at && $user->two_factor_pending_expires_at->isPast())) {
            $this->twoFactorService->startEnrolment($user);
            $user->refresh();
        }

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_pending_secret);

        $response = Inertia::render('auth/TwoFactorEnrolment', [
            'qrCodeSvg' => $this->twoFactorService->qrCodeSvg($user->email, $secret),
            'manualSetupKey' => $secret,
            'expiresAt' => $user->two_factor_pending_expires_at?->toISOString(),
            'isReplacement' => (bool) $request->session()->get('two_factor_replacement_required', false) || $user->two_factor_pending_purpose === 'replacement',
            'recoveryCodes' => session('recoveryCodes', []),
            'hasConfirmed' => ! empty($user->two_factor_secret) && ! empty($user->two_factor_confirmed_at),
        ]);

        $httpResponse = $response->toResponse($request);
        $httpResponse->headers->set('Cache-Control', 'no-store, private');
        $httpResponse->headers->set('Pragma', 'no-cache');
        $httpResponse->headers->set('Referrer-Policy', 'no-referrer');

        return $httpResponse;
    }

    /**
     * Confirm the pending authenticator code and issue 10 recovery codes.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();

        $attempts = (int) $request->session()->get('setup.two_factor_attempts', 0) + 1;
        $request->session()->put('setup.two_factor_attempts', $attempts);

        if ($attempts > 5) {
            $this->twoFactorService->cancelPendingSetupOrReplacement($user);
            $request->session()->forget('setup.two_factor_attempts');

            throw ValidationException::withMessages([
                'code' => [__('Too many invalid attempts. The setup session has expired.')],
            ]);
        }

        $isReplacement = (bool) $request->session()->get('two_factor_replacement_required', false)
            || $user->two_factor_pending_purpose === 'replacement';

        if ($isReplacement) {
            $codes = $this->twoFactorService->confirmReplacement($user, (string) $request->code, $request->session()->getId());
        } else {
            $codes = $this->twoFactorService->confirmEnrolment($user, (string) $request->code);
        }

        $request->session()->forget('setup.two_factor_attempts');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Authenticator app verified. Please save your recovery codes.'),
        ]);

        return back()->with('recoveryCodes', $codes);
    }

    /**
     * Acknowledge that recovery codes have been saved.
     */
    public function acknowledge(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->twoFactorService->acknowledgeRecoveryCodes($user);
        $request->session()->forget('two_factor_replacement_required');

        app(AuthenticationAbuseService::class)->clearPasswordFailures(
            IdentityNormalizer::normalizeEmail($user->email),
            $user
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Two-factor authentication setup is complete.'),
        ]);

        return redirect()->route('dashboard');
    }
}
