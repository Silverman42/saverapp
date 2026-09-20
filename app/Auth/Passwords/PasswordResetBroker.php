<?php

namespace App\Auth\Passwords;

use App\Enums\AccountState;
use App\Models\User;
use App\Support\IdentityNormalizer;
use Closure;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Passwords\PasswordBroker as BasePasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetBroker extends BasePasswordBroker
{
    /**
     * Get the user for the given credentials using normalized email lookup.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function getUser(#[\SensitiveParameter] array $credentials): ?CanResetPasswordContract
    {
        $email = (string) ($credentials['email'] ?? '');

        if (trim($email) === '') {
            return null;
        }

        return User::findByNormalizedEmail($email);
    }

    /**
     * Send a password reset link to a user.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function sendResetLink(#[\SensitiveParameter] array $credentials, ?Closure $callback = null): string
    {
        return $this->timebox->call(function () use ($credentials, $callback) {
            $email = (string) ($credentials['email'] ?? '');
            $normalizedEmail = IdentityNormalizer::normalizeEmail($email);
            $ip = request()->ip() ?? '127.0.0.1';

            $ipMinuteKey = 'reset_pass:ip:min:'.$ip;
            $ipHourKey = 'reset_pass:ip:hr:'.$ip;
            $accountMinuteKey = 'reset_pass:account:min:'.$normalizedEmail;
            $accountHourKey = 'reset_pass:account:hr:'.$normalizedEmail;

            // Enforce Section 7.7 rate limits: 1 per minute, 5 per hour per account and IP
            $isThrottled = RateLimiter::tooManyAttempts($ipMinuteKey, 1)
                || RateLimiter::tooManyAttempts($ipHourKey, 5)
                || ($normalizedEmail !== '' && (
                    RateLimiter::tooManyAttempts($accountMinuteKey, 1)
                    || RateLimiter::tooManyAttempts($accountHourKey, 5)
                ));

            if ($isThrottled) {
                // Return generic success response without sending email or leaking throttle status
                return static::RESET_LINK_SENT;
            }

            RateLimiter::hit($ipMinuteKey, 60);
            RateLimiter::hit($ipHourKey, 3600);
            if ($normalizedEmail !== '') {
                RateLimiter::hit($accountMinuteKey, 60);
                RateLimiter::hit($accountHourKey, 3600);
            }

            $user = $this->getUser($credentials);

            // Generic response if account does not exist
            if (is_null($user)) {
                return static::RESET_LINK_SENT;
            }

            // Section 7.6 & AC 15: Password reset must not activate or send reset link to Invited accounts
            if ($user instanceof User && $user->account_state === AccountState::Invited) {
                return static::RESET_LINK_SENT;
            }

            // Generate token (automatically removes any existing tokens for this user)
            $token = $this->tokens->create($user);

            if ($callback) {
                return $callback($user, $token) ?? static::RESET_LINK_SENT;
            }

            $user->sendPasswordResetNotification($token);

            $this->events?->dispatch(new PasswordResetLinkSent($user));

            return static::RESET_LINK_SENT;
        }, $this->timeboxDuration);
    }

    /**
     * Validate a password reset for the given credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function validateReset(#[\SensitiveParameter] array $credentials): CanResetPasswordContract|string
    {
        if (is_null($user = $this->getUser($credentials))) {
            return static::INVALID_USER;
        }

        if ($user instanceof User && $user->account_state === AccountState::Invited) {
            return static::INVALID_TOKEN;
        }

        if (! $this->tokens->exists($user, (string) ($credentials['token'] ?? ''))) {
            return static::INVALID_TOKEN;
        }

        return $user;
    }
}
