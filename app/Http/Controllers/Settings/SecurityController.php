<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\SessionManagerService;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    public function __construct(
        protected SessionManagerService $sessionManager,
    ) {}

    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $user = $request->user();
        $isCustomer = $user->user_type === UserType::Customer;

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication() && ! $isCustomer,
            'passwordRules' => PasswordPolicy::ruleForUser($user)->toPasswordRulesString(),
            'activeSessions' => $this->sessionManager->getActiveSessions($user, $request->session()->getId())->values()->all(),
            'maxConcurrentDevices' => $user->maxConcurrentDevices(),
        ];

        if ($props['canManageTwoFactor']) {
            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
            $props['authenticatorState'] = $user->authenticator_state->value;
            $props['remainingRecoveryCodes'] = $user->unconsumedRecoveryCodesCount();
            $props['hasAcknowledgedRecoveryCodes'] = $user->hasAcknowledgedRecoveryCodes();
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $request->user()->update(['password' => $request->password]);
            AuditEvent::record('auth.password_changed', User::class, $request->user()->id, null,
                ['changed_fields' => ['password']], $request->user(), ['executor' => self::class]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
