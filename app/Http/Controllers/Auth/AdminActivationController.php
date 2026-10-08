<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountState;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\Invitation;
use App\Models\User;
use App\Support\PasswordPolicy;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AdminActivationController extends Controller
{
    /**
     * Display the Administrator invitation activation page.
     */
    public function show(string $token): Response
    {
        $business = BusinessProfile::current();

        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()
            ->wherePlainToken($token)
            ->where('role', UserType::Admin->value)
            ->with(['user'])
            ->first();

        if (! $invitation) {
            return Inertia::render('auth/AdminActivation', [
                'status' => 'invalid',
                'business_name' => $business->display_name,
            ]);
        }

        if ($invitation->status === InvitationStatus::Cancelled) {
            return Inertia::render('auth/AdminActivation', [
                'status' => 'cancelled',
                'business_name' => $business->display_name,
            ]);
        }

        if ($invitation->isExpired()) {
            return Inertia::render('auth/AdminActivation', [
                'status' => 'expired',
                'business_name' => $business->display_name,
            ]);
        }

        if ($invitation->status === InvitationStatus::Activated || $invitation->user->account_state !== AccountState::Invited) {
            return Inertia::render('auth/AdminActivation', [
                'status' => 'already_activated',
                'business_name' => $business->display_name,
            ]);
        }

        // Record invitation opening
        if (in_array($invitation->status, [InvitationStatus::PendingDelivery, InvitationStatus::Sent], true)) {
            $invitation->status = InvitationStatus::Opened;
            $invitation->opened_at = now();
            $invitation->save();

            AuditEvent::record(
                eventType: 'invitation.opened',
                targetType: Invitation::class,
                targetId: $invitation->id,
                targetReference: null,
                payload: [
                    'target_email_normalized' => $invitation->target_email_normalized,
                    'generation' => $invitation->generation,
                ],
                actor: null,

                context: ['executor' => self::class]
            );
        }

        return Inertia::render('auth/AdminActivation', [
            'status' => 'ready',
            'token' => $token,
            'name' => $invitation->user->name,
            'email' => $invitation->user->email,
            'business_name' => $business->display_name,
        ]);
    }

    /**
     * Complete Administrator activation: confirm identity, accept access, choose a password, then enter mandatory MFA setup.
     */
    public function activate(Request $request, string $token): RedirectResponse|SymfonyResponse
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()
            ->wherePlainToken($token)
            ->where('role', UserType::Admin->value)
            ->with(['user'])
            ->first();

        if (! $invitation || ! $invitation->isUsable() || $invitation->isExpired()) {
            abort(403, 'This invitation link is invalid or has expired.');
        }

        if ($invitation->user->account_state !== AccountState::Invited) {
            abort(403, 'This account is no longer pending activation.');
        }

        $request->validate([
            'identity_confirmed' => ['accepted'],
            'access_accepted' => ['accepted'],
            'password' => ['required', 'confirmed', PasswordPolicy::ruleForUserType(UserType::Admin)],
        ]);

        $user = DB::transaction(function () use ($invitation, $request): User {
            /** @var User $freshUser */
            $freshUser = $invitation->user()->lockForUpdate()->firstOrFail();

            if ($freshUser->account_state !== AccountState::Invited) {
                abort(403, 'Account is not in Invited state.');
            }

            $invitation = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if (! $invitation->isUsable()) {
                abort(403, 'This invitation link is invalid or has expired.');
            }

            $freshUser->password = Hash::make($request->password);
            $freshUser->account_state = AccountState::MfaSetupRequired;
            $freshUser->email_verified_at = Carbon::now();
            $freshUser->save();

            $invitation->status = InvitationStatus::Activated;
            $invitation->activated_at = now();
            $invitation->save();

            AuditEvent::record(
                eventType: 'admin.activated',
                targetType: User::class,
                targetId: $freshUser->id,
                targetReference: null,
                payload: [
                    'email_normalized' => $freshUser->email_normalized,
                    'invitation_id' => $invitation->id,
                    'account_state' => $freshUser->account_state->value,
                ],
                actor: $freshUser,

                context: ['executor' => self::class]
            );

            return $freshUser;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        Toast::success('Password created', 'Password created successfully. Please configure mandatory two-factor authentication.');

        return redirect()->route('two-factor.enrolment');
    }
}
