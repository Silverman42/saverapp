<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\StaffRecovery;
use App\Models\StaffRecoveryApproval;
use App\Models\User;
use App\Notifications\Auth\StaffRecoveryActivationNotification;
use App\Notifications\Auth\StaffRecoverySecurityNotification;
use App\Support\IdentityNormalizer;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * High-security assisted recovery for Agents and Admins (Module 02 §7.8.3, §7.8.4, §7.8.6, §7.8.7).
 *
 * Approval only revokes the old factors and issues a single-use link; the recovering user always
 * chooses their own password and authenticator, so no Admin can set or learn them.
 */
class StaffRecoveryService
{
    public const REQUEST_LIFETIME_DAYS = 7;

    public const ACTIVATION_LIFETIME_MINUTES = 60;

    public function __construct(
        private AuthorizationService $authorization,
        private EmailReservationService $emails,
        private AuthorizationRestrictionService $restrictions,
    ) {}

    /**
     * Open a recovery request after the requester verified the user's identity outside the affected account.
     *
     * @param  array<string, mixed>  $input
     */
    public function request(User $actor, User $target, array $input): StaffRecovery
    {
        $data = Validator::make($this->trimmed($input), [
            'email' => ['required', 'email:rfc', 'max:254'],
            'procedure_reference' => ['required', 'string', 'max:150'],
            'notes' => ['required', 'string', 'max:2000'],
            'verified_at' => ['required', 'date', 'before_or_equal:now', 'after_or_equal:'.now()->subDay()->toIso8601String()],
            'identity_verified' => ['accepted'],
        ])->validate();

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $target, $data): StaffRecovery {
            [$actor, $target] = $this->lockPair($actor, $target);
            $this->requireManager($actor, $target);
            if (! in_array($target->account_state, [AccountState::Active, AccountState::MfaSetupRequired], true)) {
                throw new ConflictHttpException('Only an activated Agent or Admin account can be recovered.');
            }

            $this->expireFor($target);
            if (StaffRecovery::query()->where('open_user_id', $target->id)->exists()) {
                throw new ConflictHttpException('Resolve the existing recovery for this account first.');
            }
            if (StaffRecovery::query()->where('user_id', $target->id)->where('created_at', '>', now()->subDay())->count() >= 3) {
                throw new ConflictHttpException('Recovery rate limit reached for this account. Try again later.');
            }

            $normalized = IdentityNormalizer::normalizeEmail($data['email']);
            $this->emails->assertAvailable($data['email'], $target->id);

            $required = $this->requiredApprovals($target, $actor);
            if ($required === 0) {
                throw new ConflictHttpException($target->user_type === UserType::Admin
                    ? 'No other eligible Administrator can approve this recovery. Use the final-Admin emergency recovery procedure.'
                    : 'No other eligible Administrator can approve this recovery.');
            }

            $recovery = StaffRecovery::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $target->id,
                'requested_by_user_id' => $actor->id,
                'open_user_id' => $target->id,
                'state' => 'awaiting_approval',
                'version' => 1,
                'required_approvals' => $required,
                'previous_email' => $target->email,
                'proposed_email' => $data['email'],
                'proposed_email_normalized' => $normalized,
                'procedure_reference' => $data['procedure_reference'],
                'verification_notes' => $data['notes'],
                'verified_at' => $data['verified_at'],
                'request_expires_at' => now()->addDays(self::REQUEST_LIFETIME_DAYS),
            ]);

            $this->record($recovery, 'requested', $actor, ['reason' => $data['notes']]);
            $this->securityNotice($recovery, 'requested');

            return $recovery;
        }, attempts: 3);
    }

    /**
     * Record one distinct approval; the final required approval revokes the old factors and issues the activation link.
     */
    public function approve(User $actor, StaffRecovery $recovery, int $expectedVersion, string $reason): StaffRecovery
    {
        return $this->decide($actor, $recovery, $expectedVersion, function (StaffRecovery $recovery, User $actor, User $target) use ($reason): void {
            if ($recovery->state !== 'awaiting_approval') {
                throw new ConflictHttpException('This recovery is not awaiting approval.');
            }
            $this->requireDistinctApprover($actor, $recovery);
            if ($recovery->approvals()->where('approver_user_id', $actor->id)->exists()) {
                throw new ConflictHttpException('You have already approved this recovery.');
            }

            StaffRecoveryApproval::create(['staff_recovery_id' => $recovery->id, 'approver_user_id' => $actor->id, 'reason' => $reason, 'created_at' => now()]);
            $approvals = $recovery->approvals()->count();

            if ($approvals < $recovery->required_approvals) {
                $recovery->forceFill(['version' => $recovery->version + 1])->save();
                $this->record($recovery, 'approval_recorded', $actor, ['reason' => $reason]);

                return;
            }

            if (! hash_equals($recovery->previous_email, $target->email)) {
                throw new ConflictHttpException('The account email changed after this request. Start a new recovery.');
            }
            $this->emails->assertAvailable($recovery->proposed_email, $target->id);
            $this->revokeFactors($target, $actor);
            $recovery->forceFill(['approved_at' => now()]);
            $this->issueActivation($recovery, $actor, 'approved', $reason);
        });
    }

    /**
     * Decline a pending recovery; the account keeps its existing access.
     */
    public function reject(User $actor, StaffRecovery $recovery, int $expectedVersion, string $reason): StaffRecovery
    {
        return $this->decide($actor, $recovery, $expectedVersion, function (StaffRecovery $recovery, User $actor) use ($reason): void {
            if ($recovery->state !== 'awaiting_approval') {
                throw new ConflictHttpException('Only a pending recovery can be rejected.');
            }
            $this->requireDistinctApprover($actor, $recovery);
            $this->close($recovery, 'rejected', $actor, $reason);
        });
    }

    /**
     * Cancel a recovery before approval completes.
     */
    public function cancel(User $actor, StaffRecovery $recovery, int $expectedVersion, string $reason): StaffRecovery
    {
        return $this->decide($actor, $recovery, $expectedVersion, function (StaffRecovery $recovery, User $actor) use ($reason): void {
            if ($recovery->state !== 'awaiting_approval') {
                throw new ConflictHttpException('An approved recovery cannot be cancelled; reissue its link instead.');
            }
            $this->close($recovery, 'cancelled', $actor, $reason);
        });
    }

    /**
     * Issue a fresh activation link for an approved recovery whose link expired or was lost.
     */
    public function reissue(User $actor, StaffRecovery $recovery, int $expectedVersion, string $reason): StaffRecovery
    {
        return $this->decide($actor, $recovery, $expectedVersion, function (StaffRecovery $recovery, User $actor, User $target) use ($reason): void {
            if (! in_array($recovery->state, ['awaiting_activation', 'activation_expired'], true) || ! $target->recovery_pending) {
                throw new ConflictHttpException('Only an approved recovery awaiting activation can be reissued.');
            }
            $this->emails->assertAvailable($recovery->proposed_email, $target->id);
            $this->issueActivation($recovery, $actor, 'reissued', $reason);
        });
    }

    /**
     * Complete recovery from the single-use link: verify the email, set the user's own password, then require new MFA.
     *
     * @param  array<string, mixed>  $input
     */
    public function activate(string $reference, #[\SensitiveParameter] array $input): User
    {
        $found = StaffRecovery::query()->where('reference', $reference)->first();
        $userType = $found?->user()->value('user_type');
        $data = Validator::make($input, [
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', PasswordPolicy::ruleForUserType($userType instanceof UserType ? $userType : UserType::Admin)],
        ])->validate();

        return app(PlatformGuard::class)->transaction('mutation', function () use ($found, $data): User {
            if ($found === null) {
                $this->invalidChallenge();
            }
            $user = User::query()->whereKey($found->user_id)->lockForUpdate()->firstOrFail();
            $recovery = StaffRecovery::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($recovery->state !== 'awaiting_activation' || $recovery->activation_expires_at === null || $recovery->activation_expires_at->lte(now())
                || $recovery->activation_token_hash === null || ! hash_equals($recovery->activation_token_hash, hash('sha256', $data['token']))
                || ! $user->recovery_pending || ! hash_equals($recovery->previous_email, $user->email)) {
                $this->invalidChallenge();
            }
            $this->emails->assertAvailable($recovery->proposed_email, $user->id);

            $user->forceFill([
                'email' => $recovery->proposed_email,
                'email_normalized' => IdentityNormalizer::normalizeEmail($recovery->proposed_email),
                'email_verified_at' => now(),
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
                'recovery_pending' => false,
                'account_state' => AccountState::MfaSetupRequired,
                'lifecycle_access_version' => $user->lifecycle_access_version + 1,
            ])->save();
            $user->revokeAllSessions();

            $recovery->forceFill(['state' => 'completed', 'version' => $recovery->version + 1, 'completed_at' => now(),
                'activation_token_hash' => null, 'activation_expires_at' => null, 'open_user_id' => null, 'proposed_email_normalized' => null])->save();

            if ($user->user_type === UserType::Admin) {
                $this->restrictions->apply(
                    target: $user,
                    type: AuthorizationRestrictionType::PostRecoveryAdminManagement,
                    source: $recovery->kind === 'emergency' ? 'emergency_recovery' : 'staff_recovery',
                    sourceReference: $recovery->reference,
                );
            }

            $this->record($recovery, 'completed', null, []);
            $this->securityNotice($recovery, 'completed');

            return $user;
        }, attempts: 3);
    }

    /**
     * Start final-Admin emergency recovery after the offline key was verified (§7.8.5).
     *
     * The link goes only to the seeded Admin email, proving control of that address; no approver exists by definition.
     */
    public function startEmergency(User $admin): StaffRecovery
    {
        $target = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
        StaffRecovery::query()->where('open_user_id', $target->id)->whereIn('state', StaffRecovery::OPEN_STATES)->lockForUpdate()->get()
            ->each(fn (StaffRecovery $open) => $this->close($open, 'cancelled', $target, 'Superseded by emergency recovery'));

        $recovery = StaffRecovery::create([
            'reference' => (string) Str::uuid(),
            'kind' => 'emergency',
            'user_id' => $target->id,
            'requested_by_user_id' => $target->id,
            'open_user_id' => $target->id,
            'state' => 'awaiting_approval',
            'version' => 1,
            'required_approvals' => 0,
            'previous_email' => $target->email,
            'proposed_email' => $target->email,
            'proposed_email_normalized' => null,
            'procedure_reference' => 'emergency-key',
            'verification_notes' => 'Offline business emergency key verified.',
            'verified_at' => now(),
            'request_expires_at' => now()->addDays(self::REQUEST_LIFETIME_DAYS),
        ]);
        $this->record($recovery, 'requested', null, []);
        $this->revokeFactors($target, $target);
        $target->revokeAllSessions();
        $recovery->forceFill(['approved_at' => now()]);
        $this->issueActivation($recovery, $target, 'approved', 'Emergency key verified');

        return $recovery;
    }

    /**
     * Expire stale requests and activation links for one account.
     */
    public function expireFor(User $target): void
    {
        foreach (StaffRecovery::query()->where('user_id', $target->id)->whereNotNull('open_user_id')->lockForUpdate()->get() as $recovery) {
            if ($recovery->state === 'awaiting_approval' && $recovery->request_expires_at->lte(now())) {
                $recovery->forceFill(['state' => 'expired', 'open_user_id' => null, 'proposed_email_normalized' => null, 'version' => $recovery->version + 1])->save();
                $this->record($recovery, 'expired', null, []);
            } elseif ($recovery->state === 'awaiting_activation' && $recovery->activation_expires_at?->lte(now())) {
                $recovery->forceFill(['state' => 'activation_expired', 'activation_token_hash' => null, 'version' => $recovery->version + 1])->save();
                $this->record($recovery, 'expired', null, []);
            }
        }
    }

    /**
     * Whether the viewer may manage recoveries for this account type.
     */
    public function canManage(User $viewer, User $target): bool
    {
        return $viewer->user_type === UserType::Admin && $viewer->account_state === AccountState::Active && $viewer->id !== $target->id
            && $this->authorization->allows($viewer, $this->permissionFor($target));
    }

    /**
     * Number of distinct approvals needed: two Admin approvers when two are eligible, otherwise one (§7.8.4); one for Agents.
     */
    public function requiredApprovals(User $target, User $requester): int
    {
        $eligible = $this->eligibleApproverCount($target, $requester);

        return $target->user_type === UserType::Admin ? min(2, $eligible) : min(1, $eligible);
    }

    /**
     * @param  callable(StaffRecovery, User, User): void  $decision
     */
    private function decide(User $actor, StaffRecovery $recovery, int $expectedVersion, callable $decision): StaffRecovery
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $recovery, $expectedVersion, $decision): StaffRecovery {
            [$actor, $target] = $this->lockPair($actor, $recovery->user);
            $this->requireManager($actor, $target);
            $recovery = StaffRecovery::query()->whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if ($recovery->version !== $expectedVersion) {
                throw new ConflictHttpException('The recovery changed. Refresh and review again.');
            }
            $this->expireFor($target);
            $recovery->refresh();
            $decision($recovery, $actor, $target);

            return $recovery->fresh();
        }, attempts: 3);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockPair(User $actor, User $target): array
    {
        $locked = User::query()->whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$locked[$actor->id], $locked[$target->id]];
    }

    private function requireManager(User $actor, User $target): void
    {
        if (! in_array($target->user_type, [UserType::Agent, UserType::Admin], true)) {
            abort(404);
        }
        if ($actor->id === $target->id) {
            abort(403, 'You cannot act on your own account recovery.');
        }
        abort_unless($this->canManage($actor, $target), 403);
    }

    private function requireDistinctApprover(User $actor, StaffRecovery $recovery): void
    {
        if ($actor->id === $recovery->requested_by_user_id || $actor->id === $recovery->user_id) {
            throw new ConflictHttpException('The requester and the recovering user cannot approve or reject this recovery.');
        }
    }

    private function permissionFor(User $target): AdminPermission
    {
        return $target->user_type === UserType::Admin ? AdminPermission::AdminsManage : AdminPermission::AgentsManage;
    }

    private function eligibleApproverCount(User $target, User $requester): int
    {
        $permission = $this->permissionFor($target);

        return User::query()->where('user_type', UserType::Admin->value)->where('account_state', AccountState::Active->value)
            ->whereNotIn('id', [$target->id, $requester->id])->get()
            ->filter(fn (User $admin): bool => $this->authorization->allows($admin, $permission))->count();
    }

    /**
     * Revoke every existing factor and link only after the final approval (§7.8.7).
     */
    private function revokeFactors(User $target, User $actor): void
    {
        $target->forceFill(['password' => null, 'remember_token' => Str::random(60), 'recovery_pending' => true,
            'lifecycle_access_version' => $target->lifecycle_access_version + 1, 'two_factor_secret' => null,
            'two_factor_confirmed_at' => null, 'two_factor_pending_secret' => null, 'two_factor_pending_purpose' => null,
            'two_factor_pending_expires_at' => null, 'two_factor_last_used_timestep' => null,
            'two_factor_pending_last_used_timestep' => null, 'recovery_codes_acknowledged_at' => null])->save();
        $target->revokeAllSessions();
        $target->revokeAllTrustedDevices();
        DB::table('two_factor_recovery_codes')->where('user_id', $target->id)->delete();
        DB::table('password_reset_tokens')->whereIn('email', [$target->email, $target->email_normalized])->delete();
        DB::table('pending_email_changes')->where('user_id', $target->id)->delete();
        Invitation::query()->where('user_id', $target->id)->whereIn('status', ['pending_delivery', 'sent', 'opened', 'delivery_failed'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id, 'cancellation_reason' => 'Approved assisted recovery']);
    }

    private function issueActivation(StaffRecovery $recovery, User $actor, string $action, string $reason): void
    {
        $token = Str::random(64);
        $recovery->forceFill(['state' => 'awaiting_activation', 'version' => $recovery->version + 1,
            'activation_token_hash' => hash('sha256', $token), 'activation_expires_at' => now()->addMinutes(self::ACTIVATION_LIFETIME_MINUTES)])->save();
        $this->record($recovery, $action, $actor, ['reason' => $reason]);
        $this->securityNotice($recovery, $action);

        $email = $recovery->proposed_email;
        $url = route('staff-recovery.activation', ['recovery' => $recovery->reference]).'#token='.$token;
        $expires = $recovery->activation_expires_at;
        DB::afterCommit(static function () use ($email, $url, $expires): void {
            Notification::route('mail', $email)->notify(new StaffRecoveryActivationNotification($url, $expires->toDayDateTimeString()));
        });
    }

    private function close(StaffRecovery $recovery, string $state, User $actor, string $reason): void
    {
        $recovery->forceFill(['state' => $state, 'version' => $recovery->version + 1, 'open_user_id' => null,
            'proposed_email_normalized' => null, 'activation_token_hash' => null, 'activation_expires_at' => null])->save();
        $this->record($recovery, $state, $actor, ['reason' => $reason]);
        $this->securityNotice($recovery, $state);
    }

    /**
     * Record the lifecycle transition without any token, password or authenticator secret.
     *
     * @param  array<string, mixed>  $details
     */
    private function record(StaffRecovery $recovery, string $action, ?User $actor, array $details): void
    {
        AuditEvent::record('auth.staff_recovery_'.$action, User::class, $recovery->user_id, $recovery->reference, [
            'state' => $recovery->state,
            'to_version' => $recovery->version,
            'approvals' => $recovery->approvals()->count(),
            'required_approvals' => $recovery->required_approvals,
            'previous_email_normalized' => IdentityNormalizer::normalizeEmail($recovery->previous_email),
            'corrected_email_normalized' => IdentityNormalizer::normalizeEmail($recovery->proposed_email),
            ...$details,
        ], $actor, ['executor' => self::class, 'operation_id' => $recovery->reference]);
    }

    /**
     * Tell both the previous and proposed addresses about material recovery steps (§7.8.7).
     */
    private function securityNotice(StaffRecovery $recovery, string $action): void
    {
        $addresses = array_unique([IdentityNormalizer::normalizeEmail($recovery->previous_email) => $recovery->previous_email,
            IdentityNormalizer::normalizeEmail($recovery->proposed_email) => $recovery->proposed_email]);
        DB::afterCommit(static function () use ($addresses, $action): void {
            foreach ($addresses as $address) {
                Notification::route('mail', $address)->notify(new StaffRecoverySecurityNotification($action));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function trimmed(array $input): array
    {
        return array_map(static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $input);
    }

    private function invalidChallenge(): never
    {
        throw ValidationException::withMessages(['token' => ['This recovery link is unavailable or expired. Contact your Administrator.']]);
    }
}
