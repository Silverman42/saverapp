<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

class TwoFactorManagementController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected TwoFactorService $twoFactorService,
    ) {}

    /**
     * Get the two factor authentication QR code.
     */
    public function qrCode(Request $request): JsonResponse
    {
        $user = $request->user();

        $secret = $user->two_factor_pending_secret
            ? Fortify::currentEncrypter()->decrypt($user->two_factor_pending_secret)
            : ($user->two_factor_secret ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null);

        if (! $secret) {
            return response()->json(['svg' => '', 'url' => '']);
        }

        return response()->json([
            'svg' => $this->twoFactorService->qrCodeSvg($user->email, $secret),
            'url' => $this->twoFactorService->qrCodeUrl($user->email, $secret),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Get the two factor authentication secret key.
     */
    public function secretKey(Request $request): JsonResponse
    {
        $user = $request->user();

        $secret = $user->two_factor_pending_secret
            ? Fortify::currentEncrypter()->decrypt($user->two_factor_pending_secret)
            : ($user->two_factor_secret ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null);

        return response()->json([
            'secretKey' => $secret,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Return no raw recovery codes per AUTH-025 and Section 5.9.
     */
    public function showRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'codes' => [],
            'remaining' => $user->unconsumedRecoveryCodesCount(),
            'acknowledged' => $user->hasAcknowledgedRecoveryCodes(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Start the authenticator replacement process.
     */
    public function replace(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'current_code' => ['required', 'string', 'size:6'],
        ]);

        $setupData = $this->twoFactorService->startReplacement(
            $request->user(),
            (string) $request->current_password,
            (string) $request->current_code
        );

        Toast::info('Replacement started', __('Authenticator replacement initiated. Scan the new QR code to confirm.'));

        return back()->with('replacementSetup', $setupData);
    }

    /**
     * Confirm authenticator replacement with code from new authenticator.
     */
    public function confirmReplacement(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $codes = $this->twoFactorService->confirmReplacement(
            $request->user(),
            (string) $request->code,
            $request->session()->getId()
        );

        Toast::success('Authenticator replaced', __('Authenticator app replaced and other sessions revoked.'));

        return back()->with('recoveryCodes', $codes);
    }

    /**
     * Cancel a pending authenticator replacement.
     */
    public function cancelReplacement(Request $request): RedirectResponse
    {
        $this->twoFactorService->cancelPendingSetupOrReplacement($request->user());

        Toast::info('Replacement cancelled', __('Authenticator replacement cancelled.'));

        return back();
    }

    /**
     * Regenerate emergency recovery codes with password and active TOTP.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $codes = $this->twoFactorService->regenerateRecoveryCodes(
            $request->user(),
            (string) $request->password,
            (string) $request->code
        );

        Toast::success('Recovery codes regenerated', __('Recovery codes regenerated successfully.'));

        return back()->with('recoveryCodes', $codes);
    }

    /**
     * Handle disable / cancel request.
     */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->authenticator_state->isPending() || $user->two_factor_pending_secret !== null) {
            $this->twoFactorService->cancelPendingSetupOrReplacement($user);

            Toast::info('Setup cancelled', __('Pending two-factor authentication setup was cancelled.'));

            return back();
        }

        abort(403, 'Two-factor authentication is mandatory for your account and cannot be disabled.');
    }
}
