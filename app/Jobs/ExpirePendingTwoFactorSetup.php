<?php

namespace App\Jobs;

use App\Enums\AuthenticatorState;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpirePendingTwoFactorSetup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public string $expiresAtTimestamp,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user || $user->two_factor_pending_secret === null) {
            return;
        }

        // Idempotently verify that this job matches the current pending generation and has expired
        if ($user->two_factor_pending_expires_at && $user->two_factor_pending_expires_at->toISOString() === $this->expiresAtTimestamp) {
            if ($user->two_factor_pending_expires_at->isPast()) {
                $user->two_factor_pending_secret = null;
                $user->two_factor_pending_purpose = null;
                $user->two_factor_pending_expires_at = null;
                $user->two_factor_pending_last_used_timestep = null;

                if ($user->authenticator_state === AuthenticatorState::ReplacementPending) {
                    $user->authenticator_state = AuthenticatorState::Active;
                } elseif ($user->authenticator_state === AuthenticatorState::PendingConfirmation) {
                    $user->authenticator_state = AuthenticatorState::NotConfigured;
                }

                $user->save();
            }
        }
    }
}
