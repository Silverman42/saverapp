<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountState;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeSnapshot;
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

class CustomerActivationController extends Controller
{
    /**
     * Display the customer invitation activation page.
     */
    public function show(string $token): Response
    {
        $business = BusinessProfile::current();

        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()
            ->wherePlainToken($token)
            ->where('role', UserType::Customer->value)
            ->with(['user'])
            ->first();

        if (! $invitation) {
            return Inertia::render('auth/CustomerActivation', [
                'status' => 'invalid',
                'business_name' => $business->display_name,
            ]);
        }

        if ($invitation->status === InvitationStatus::Cancelled) {
            return Inertia::render('auth/CustomerActivation', [
                'status' => 'cancelled',
                'business_name' => $business->display_name,
            ]);
        }

        if ($invitation->isExpired()) {
            return Inertia::render('auth/CustomerActivation', [
                'status' => 'expired',
                'business_name' => $business->display_name,
            ]);
        }

        if ($invitation->status === InvitationStatus::Activated || $invitation->user->account_state !== AccountState::Invited) {
            return Inertia::render('auth/CustomerActivation', [
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

        $customerProfile = CustomerProfile::where('user_id', $invitation->user_id)->first();
        $snapshot = $customerProfile?->feeSnapshot;

        return Inertia::render('auth/CustomerActivation', [
            'status' => 'ready',
            'token' => $token,
            'name' => $invitation->user->name,
            'email' => $invitation->user->email,
            'business_name' => $business->display_name,
            'fee_snapshot' => $snapshot ? [
                'name' => $snapshot->name,
                'amount_kobo' => $snapshot->amount_kobo,
                'formatted_amount' => $snapshot->formattedAmount(),
                'currency' => $snapshot->currency,
                'description' => $snapshot->customer_description,
                'is_zero' => $snapshot->isZero(),
            ] : null,
        ]);
    }

    /**
     * Complete customer activation by acknowledging fee terms and choosing a password.
     */
    public function activate(Request $request, string $token): RedirectResponse|SymfonyResponse
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()
            ->wherePlainToken($token)
            ->where('role', UserType::Customer->value)
            ->with(['user'])
            ->first();

        if (! $invitation || ! $invitation->isUsable() || $invitation->isExpired()) {
            abort(403, 'This invitation link is invalid or has expired.');
        }

        if ($invitation->user->account_state !== AccountState::Invited) {
            abort(403, 'This account is no longer pending activation.');
        }

        $request->validate([
            'fee_acknowledged' => ['accepted'],
            'password' => ['required', 'confirmed', PasswordPolicy::ruleForUserType(UserType::Customer)],
        ]);

        $user = DB::transaction(function () use ($invitation, $request): User {
            /** @var User $freshUser */
            $freshUser = $invitation->user()->lockForUpdate()->firstOrFail();

            if ($freshUser->account_state !== AccountState::Invited) {
                abort(403, 'Account is not in Invited state.');
            }

            $freshUser->password = Hash::make($request->password);
            $freshUser->account_state = AccountState::Active;
            $freshUser->email_verified_at = Carbon::now();
            $freshUser->save();

            $invitation->status = InvitationStatus::Activated;
            $invitation->activated_at = now();
            $invitation->save();

            // Acknowledge fee snapshot
            $customerProfile = CustomerProfile::where('user_id', $freshUser->id)->first();
            if ($customerProfile) {
                FeeSnapshot::query()
                    ->where('customer_profile_id', $customerProfile->id)
                    ->update(['acknowledged_at' => now()]);
            }

            AuditEvent::record(
                eventType: 'customer.activated',
                targetType: User::class,
                targetId: $freshUser->id,
                targetReference: $customerProfile?->customer_id,
                payload: [
                    'email_normalized' => $freshUser->email_normalized,
                    'invitation_id' => $invitation->id,
                ],
                actor: $freshUser,

                context: ['executor' => self::class]
            );

            return $freshUser;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $business = BusinessProfile::current();

        Toast::success('Account activated', "Account activated successfully. Welcome to {$business->display_name}!");

        return redirect()->route('customer.dashboard');
    }
}
