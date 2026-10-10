<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Suspends, reactivates and deactivates another Admin's account (Module 03 Section 8, 10.4 and 12.5).
 */
class AdminStatusService
{
    /** @var array<string, list<AccountState>> */
    public const SOURCE_STATES = [
        'suspend' => [AccountState::Active, AccountState::MfaSetupRequired, AccountState::TemporarilyLocked],
        'reactivate' => [AccountState::Suspended],
        'deactivate' => [AccountState::Active, AccountState::MfaSetupRequired, AccountState::TemporarilyLocked, AccountState::Suspended],
    ];

    /** @var array<string, string> */
    private const EVENTS = ['suspend' => 'admin.suspended', 'reactivate' => 'admin.reactivated', 'deactivate' => 'admin.deactivated'];

    public function __construct(private AuthorizationService $authorization) {}

    /**
     * The status actions the actor may currently offer for the target, without changing anything.
     *
     * @return list<string>
     */
    public function availableActions(User $actor, User $target): array
    {
        if ($target->user_type !== UserType::Admin || $actor->id === $target->id
            || ! $this->authorization->allows($actor, AdminPermission::AdminsManage)) {
            return [];
        }

        return array_values(array_filter(array_keys(self::SOURCE_STATES),
            fn (string $action): bool => in_array($target->account_state, self::SOURCE_STATES[$action], true)));
    }

    /**
     * Apply one status action after locking both accounts and re-checking authority and safeguards at commit time.
     */
    public function change(User $actor, User $target, string $action, int $expectedVersion, string $reason): User
    {
        if (! array_key_exists($action, self::SOURCE_STATES)) {
            throw new \InvalidArgumentException('Unknown Admin status action.');
        }

        $denial = null;
        try {
            return $this->apply($actor, $target, $action, $expectedVersion, $reason, $denial);
        } catch (AuthorizationException|ValidationException $exception) {
            if ($denial !== null) {
                AuditEvent::record('admin.status_denied', User::class, $target->id, null,
                    ['changed_fields' => [$action], 'denial_code' => $denial], $actor,
                    ['required_permission' => AdminPermission::AdminsManage->value, 'executor' => self::class, 'outcome' => 'Denied']);
            }
            throw $exception;
        }
    }

    private function apply(User $actor, User $target, string $action, int $expectedVersion, string $reason, ?string &$denial): User
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $target, $action, $expectedVersion, $reason, &$denial): User {
            $locked = User::query()->whereKey([$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedActor = $locked->get($actor->id);
            $lockedTarget = $locked->get($target->id);
            if ($lockedActor === null || $lockedTarget === null || $lockedTarget->user_type !== UserType::Admin) {
                abort(404, 'Administrator not found.');
            }
            if ($lockedActor->id === $lockedTarget->id) {
                $denial = 'self_management';
                throw new AuthorizationException('Administrators cannot change their own account status.');
            }
            if (! $this->authorization->allows($lockedActor, AdminPermission::AdminsManage)) {
                $denial = 'missing_authority';
                throw new AuthorizationException('Current Admin management authority is required.');
            }
            if ((int) $lockedTarget->lifecycle_access_version !== $expectedVersion) {
                throw new ConflictHttpException('This Administrator\'s account changed. Refresh and review the action again.');
            }
            if (! in_array($lockedTarget->account_state, self::SOURCE_STATES[$action], true)) {
                throw ValidationException::withMessages(['action' => [__('This action is not available while the account is :state.', ['state' => str_replace('_', ' ', $lockedTarget->account_state->value)])]]);
            }

            $before = $lockedTarget->account_state;
            if ($action !== 'reactivate') {
                $denial = $this->safeguardFailure($lockedTarget);
                if ($denial !== null) {
                    throw ValidationException::withMessages(['action' => [$denial === 'final_active_admin'
                        ? __('The final active Administrator cannot be suspended or deactivated.')
                        : __('At least one active Administrator who can manage Admins must remain.')]]);
                }
            }

            $lockedTarget->forceFill([
                'account_state' => $action === 'reactivate' ? $this->restorationState($lockedTarget) : ($action === 'suspend' ? AccountState::Suspended : AccountState::Deactivated),
                'remember_token' => null,
                'lifecycle_access_version' => (int) $lockedTarget->lifecycle_access_version + 1,
            ])->save();
            $lockedTarget->revokeAllSessions();
            $lockedTarget->revokeAllTrustedDevices();

            AuditEvent::record(self::EVENTS[$action], User::class, $lockedTarget->id, null,
                ['from_account_state' => $before->value, 'account_state' => $lockedTarget->account_state->value, 'reason' => $reason], $lockedActor,
                ['required_permission' => AdminPermission::AdminsManage->value, 'executor' => self::class]);

            return $lockedTarget;
        }, attempts: 3);
    }

    /**
     * Name the Section 10.4 safeguard the change would break: one active Admin and one active, unrestricted holder of admins.manage.
     */
    private function safeguardFailure(User $target): ?string
    {
        $remaining = User::query()
            ->where('user_type', UserType::Admin->value)
            ->where('account_state', AccountState::Active->value)
            ->whereKeyNot($target->id)
            ->lockForUpdate()
            ->get();

        if ($target->account_state === AccountState::Active && $remaining->isEmpty()) {
            return 'final_active_admin';
        }
        if ($this->authorization->allows($target, AdminPermission::AdminsManage)
            && $remaining->filter(fn (User $admin): bool => $this->authorization->allows($admin, AdminPermission::AdminsManage))->isEmpty()) {
            return 'final_capable_admin';
        }

        return null;
    }

    private function restorationState(User $target): AccountState
    {
        if ($target->password === null || $target->password === '' || $target->email_verified_at === null) {
            return AccountState::Invited;
        }

        return $target->hasConfirmedTwoFactor() && $target->hasAcknowledgedRecoveryCodes()
            ? AccountState::Active : AccountState::MfaSetupRequired;
    }
}
