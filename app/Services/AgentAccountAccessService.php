<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AgentAccountAccessService
{
    public function __construct(private SecurityCaseService $security) {}

    public function restorationState(User $user, bool $forUpdate = false): AccountState
    {
        if ($user->user_type !== UserType::Agent || $user->getRoleNames()->count() !== 1 || $user->getRoleNames()->first() !== 'agent') {
            throw ValidationException::withMessages(['lifecycle' => ['Agent account classification must be verified before restoring access.']]);
        }
        if ($this->security->agentLifecycleStatus($user, $forUpdate) !== 'passed') {
            throw ValidationException::withMessages(['lifecycle' => ['Security clearance is blocked or unavailable. Authorized security staff must resolve restrictions before access restoration.']]);
        }
        if ($user->email_verified_at === null || $user->password === null || $user->password === '') {
            return AccountState::Invited;
        }

        return $user->hasConfirmedTwoFactor() && $user->hasAcknowledgedRecoveryCodes()
            ? AccountState::Active : AccountState::MfaSetupRequired;
    }

    public function revoke(User $user): void
    {
        $user->revokeAllSessions();
        $user->revokeAllTrustedDevices();
        $user->forceFill(['remember_token' => null, 'lifecycle_access_version' => (int) $user->lifecycle_access_version + 1])->save();
    }
}
