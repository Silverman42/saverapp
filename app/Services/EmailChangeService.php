<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Notifications\EmailChangeNotification;
use App\Support\IdentityNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailChangeService
{
    public function __construct(
        protected ProfileManagementService $historyService,
    ) {}

    public function begin(User $actor, string $proposedEmail): void
    {
        $proposedEmail = trim($proposedEmail);
        $normalizedEmail = IdentityNormalizer::normalizeEmail($proposedEmail);
        $rateLimitKey = 'email-change:'.$actor->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            throw ValidationException::withMessages(['email' => ['Too many email change requests. Try again in '.RateLimiter::availableIn($rateLimitKey).' seconds.']]);
        }
        RateLimiter::hit($rateLimitKey, 86400);

        try {
            app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $proposedEmail, $normalizedEmail): void {
                $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if ($user->account_state !== AccountState::Active || $user->email_verified_at === null) {
                    throw ValidationException::withMessages(['email' => ['Email changes are available only for active, verified accounts.']]);
                }
                if (hash_equals($user->email_normalized, $normalizedEmail)) {
                    throw ValidationException::withMessages(['email' => ['Enter a different email address.']]);
                }
                if (User::query()->where('email_normalized', $normalizedEmail)->where('id', '!=', $user->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['email' => ['The email address is already in use.']]);
                }

                PendingEmailChange::query()->where('expires_at', '<=', now())->delete();
                PendingEmailChange::query()->where('user_id', $user->id)->lockForUpdate()->delete();
                $currentToken = Str::random(64);
                $proposedToken = Str::random(64);
                $pending = PendingEmailChange::create([
                    'user_id' => $user->id,
                    'current_email' => $user->email,
                    'proposed_email' => $proposedEmail,
                    'proposed_email_normalized' => $normalizedEmail,
                    'current_token_hash' => hash('sha256', $currentToken),
                    'proposed_token_hash' => hash('sha256', $proposedToken),
                    'expires_at' => now()->addMinutes(30),
                ]);

                $currentUrl = route('email-change.confirm.show', ['pendingEmailChange' => $pending->id]).'#token='.$currentToken;
                $proposedUrl = route('email-change.confirm.show', ['pendingEmailChange' => $pending->id]).'#token='.$proposedToken;
                DB::afterCommit(function () use ($user, $proposedEmail, $currentUrl, $proposedUrl): void {
                    Notification::route('mail', $user->email)->notify(new EmailChangeNotification(
                        subject: 'Confirm your email change',
                        message: 'Confirm that you want to change the email address on your account. The current address remains in use until both addresses are confirmed.',
                        actionUrl: $currentUrl,
                        actionLabel: 'Confirm current email',
                    ));
                    Notification::route('mail', $proposedEmail)->notify(new EmailChangeNotification(
                        subject: 'Confirm this new email address',
                        message: 'Confirm that this email address belongs to you and should be used for the account.',
                        actionUrl: $proposedUrl,
                        actionLabel: 'Confirm new email',
                    ));
                });
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages(['email' => ['The email address is already reserved or in use.']]);
        }
    }

    public function confirm(int $pendingId, string $plainToken): bool
    {
        $failure = null;
        $completed = app(PlatformGuard::class)->transaction('mutation', function () use ($pendingId, $plainToken, &$failure): bool {
            $pending = PendingEmailChange::query()->whereKey($pendingId)->lockForUpdate()->first();
            if ($pending === null || $pending->expires_at->isPast()) {
                if ($pending !== null) {
                    $pending->delete();
                }

                $failure = 'token';

                return false;
            }

            $user = User::query()->whereKey($pending->user_id)->lockForUpdate()->firstOrFail();
            if ($user->account_state !== AccountState::Active || $user->email_verified_at === null
                || ! hash_equals($user->email_normalized, IdentityNormalizer::normalizeEmail($pending->current_email))) {
                $pending->delete();
                $failure = 'token';

                return false;
            }

            $tokenHash = hash('sha256', $plainToken);
            $purpose = null;
            if ($pending->current_confirmed_at === null && hash_equals($pending->current_token_hash, $tokenHash)) {
                $purpose = 'current';
                $pending->current_confirmed_at = Carbon::now();
            } elseif ($pending->proposed_confirmed_at === null && hash_equals($pending->proposed_token_hash, $tokenHash)) {
                $purpose = 'proposed';
                $pending->proposed_confirmed_at = Carbon::now();
            }

            if ($purpose === null) {
                $failure = 'token';

                return false;
            }
            $pending->save();

            if ($pending->current_confirmed_at === null || $pending->proposed_confirmed_at === null) {
                return false;
            }

            if (User::query()->where('email_normalized', $pending->proposed_email_normalized)->where('id', '!=', $user->id)->lockForUpdate()->exists()) {
                $pending->delete();
                $failure = 'unavailable';

                return false;
            }

            $oldEmail = $user->email;
            $newEmail = $pending->proposed_email;
            $user->email = $newEmail;
            $user->email_verified_at = Carbon::now();
            $user->remember_token = Str::random(60);
            $user->save();

            Invitation::query()->where('user_id', $user->id)
                ->whereIn('status', [InvitationStatus::PendingDelivery, InvitationStatus::Sent, InvitationStatus::Opened])
                ->get()
                ->each(function (Invitation $invitation) use ($user): void {
                    $invitation->status = InvitationStatus::Cancelled;
                    $invitation->cancelled_at = now();
                    $invitation->cancelled_by_user_id = $user->id;
                    $invitation->cancellation_reason = 'Email address changed';
                    $invitation->save();
                });

            DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, IdentityNormalizer::normalizeEmail($oldEmail)])->delete();
            $user->revokeAllSessions();
            $user->revokeAllTrustedDevices();

            $this->historyService->recordChange(
                eventType: 'user.email_changed',
                targetType: User::class,
                targetId: $user->id,
                subjectUser: $user,
                actor: $user,
                changedFields: ['email'],
                before: ['email' => $oldEmail],
                after: ['email' => $newEmail],
                reason: null,
                fromVersion: null,
                toVersion: null,
                targetReference: 'user:'.$user->id,
            );
            $pending->delete();

            DB::afterCommit(function () use ($oldEmail, $newEmail): void {
                $notification = new EmailChangeNotification(
                    subject: 'Your account email address changed',
                    message: 'The email address on your account was changed. If you did not make this change, use the account recovery options immediately.',
                    actionUrl: route('login'),
                    actionLabel: 'Sign in',
                );
                Notification::route('mail', $oldEmail)->notify($notification);
                Notification::route('mail', $newEmail)->notify($notification);
            });

            return true;
        });

        if ($failure === 'unavailable') {
            throw ValidationException::withMessages(['token' => ['This email address is no longer available.']]);
        }

        if ($failure !== null) {
            throw ValidationException::withMessages(['token' => ['This confirmation link has expired or is no longer valid.']]);
        }

        return $completed;
    }
}
