<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CreationAttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CreationAttempt;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates, resends, corrects and cancels Admin invitations (Module 02 §3.2, §3.5–3.11).
 */
class AdminInvitationService
{
    public const LIFETIME_HOURS = 24;

    public function __construct(
        protected AuthorizationService $authorizationService,
        protected InvitationSenderReadinessService $senderReadinessService,
    ) {}

    /**
     * Invite a new Admin idempotently with the initial permissions chosen by the inviting Admin.
     * Competing inserts for one email can deadlock on gap locks, so the transaction retries and the loser sees the duplicate.
     *
     * @param  array{name: string, email: string, permissions: list<string>}  $data
     * @return array{admin: User, replayed: bool}
     */
    public function invite(User $actor, string $attemptReference, array $data): array
    {
        $business = BusinessProfile::current();
        $this->senderReadinessService->ensureReady($business);

        $permissions = array_values(array_unique($data['permissions']));
        sort($permissions);
        $fingerprint = hash('sha256', json_encode([
            'name' => trim($data['name']),
            'email' => IdentityNormalizer::normalizeEmail($data['email']),
            'permissions' => $permissions,
        ], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $business, $attemptReference, $data, $permissions, $fingerprint): array {
            $lockedActor = $this->lockAuthorizedActor($actor);

            $existingAttempt = CreationAttempt::query()->where('attempt_reference', $attemptReference)->lockForUpdate()->first();
            if ($existingAttempt !== null) {
                if ($existingAttempt->user_id !== $lockedActor->id || $existingAttempt->operation_type !== 'admin_invitation') {
                    throw new ConflictHttpException('Creation attempt reference belongs to another operation.');
                }
                if ($existingAttempt->hasConflictWith($fingerprint)) {
                    throw new ConflictHttpException('Changed payload conflicts with previous creation attempt.');
                }
                if ($existingAttempt->isCommitted()) {
                    return ['admin' => User::query()->findOrFail($existingAttempt->record_id), 'replayed' => true];
                }
            }

            $this->ensureGrantable($permissions);

            $email = trim($data['email']);
            $emailNormalized = IdentityNormalizer::normalizeEmail($email);
            $this->ensureEmailAvailable($emailNormalized);

            $attempt = $existingAttempt ?? new CreationAttempt([
                'attempt_reference' => $attemptReference,
                'user_id' => $lockedActor->id,
                'business_id' => $business->business_id,
                'operation_type' => 'admin_invitation',
                'payload_fingerprint' => $fingerprint,
            ]);
            $attempt->status = CreationAttemptStatus::InProgress;
            $attempt->save();

            $admin = new User([
                'name' => trim($data['name']),
                'email' => $email,
                'email_normalized' => $emailNormalized,
                'user_type' => UserType::Admin,
                'account_state' => AccountState::Invited,
                'password' => null,
                'permission_version' => 1,
            ]);
            $admin->save();

            if ($permissions !== []) {
                $admin->syncPermissions($permissions);
                $batchId = (string) Str::uuid();
                foreach ($permissions as $code) {
                    PermissionGrantHistory::create([
                        'batch_id' => $batchId,
                        'user_id' => $admin->id,
                        'permission_code' => $code,
                        'action' => 'grant',
                        'source' => 'admin_invitation',
                        'actor_user_id' => $lockedActor->id,
                        'reason' => 'Initial permissions set at invitation',
                        'permission_version' => $admin->permission_version,
                    ]);
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            [$invitation, $plainToken] = $this->issue($admin, $lockedActor, 1);

            $attempt->status = CreationAttemptStatus::Committed;
            $attempt->record_type = User::class;
            $attempt->record_id = $admin->id;
            $attempt->result_summary = ['name' => $admin->name, 'email' => $admin->email, 'account_state' => $admin->account_state->value];
            $attempt->save();

            AuditEvent::record('admin.invited', User::class, $admin->id, null, [
                'invitation_id' => $invitation->id,
                'generation' => 1,
                'permissions' => $permissions,
                'account_state' => $admin->account_state->value,
                'name' => $admin->name,
                'email_normalized' => $admin->email_normalized,
            ], $lockedActor, ['executor' => self::class, 'required_permission' => AdminPermission::AdminsManage->value, 'operation_id' => $attemptReference]);

            $this->dispatchAfterCommit($invitation, $plainToken);

            return ['admin' => $admin, 'replayed' => false];
        }, attempts: 3);
    }

    /**
     * Issue a new invitation generation, invalidating every earlier token.
     */
    public function resend(User $admin, User $actor, ?string $reason = null): Invitation
    {
        $this->senderReadinessService->ensureReady(BusinessProfile::current());

        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $actor, $reason): Invitation {
            $lockedActor = $this->lockAuthorizedActor($actor);
            $target = $this->lockInvitedAdmin($admin, $lockedActor);

            if (Invitation::query()->where('user_id', $target->id)->where('created_at', '>=', now()->subMinute())->exists()) {
                throw ValidationException::withMessages(['resend' => ['Invitations may only be resent once per minute.']]);
            }
            if (Invitation::query()->where('user_id', $target->id)->where('created_at', '>=', now()->subDay())->count() >= 5) {
                throw ValidationException::withMessages(['resend' => ['Daily invitation resend limit (5 per 24 hours) reached for this account.']]);
            }

            $current = Invitation::query()->where('user_id', $target->id)->latest('generation')->lockForUpdate()->first();
            if ($current !== null && ! $current->canResend()) {
                throw new ConflictHttpException("Current invitation state [{$current->status->value}] does not permit resending.");
            }

            $this->cancelOutstanding($target, $lockedActor, 'Superseded by invitation resend');
            [$invitation, $plainToken] = $this->issue($target, $lockedActor, ($current->generation ?? 0) + 1);

            AuditEvent::record('invitation.resent', Invitation::class, $invitation->id, null, [
                'generation' => $invitation->generation,
                'target_email_normalized' => $invitation->target_email_normalized,
                'reason' => $reason,
            ], $lockedActor, ['executor' => self::class, 'required_permission' => AdminPermission::AdminsManage->value]);

            $this->dispatchAfterCommit($invitation, $plainToken);

            return $invitation;
        });
    }

    /**
     * Correct an invited Admin's email and send a fresh invitation to the corrected address.
     */
    public function correctEmail(User $admin, User $actor, string $newEmail, string $reason): Invitation
    {
        $this->senderReadinessService->ensureReady(BusinessProfile::current());
        $newEmail = trim($newEmail);
        $normalized = IdentityNormalizer::normalizeEmail($newEmail);

        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $actor, $newEmail, $normalized, $reason): Invitation {
            $lockedActor = $this->lockAuthorizedActor($actor);
            $target = $this->lockInvitedAdmin($admin, $lockedActor);
            $this->ensureEmailAvailable($normalized, $target->id);

            $previous = $target->email_normalized;
            $this->cancelOutstanding($target, $lockedActor, 'Superseded by email correction');
            DB::table('password_reset_tokens')->where('email', $target->email)->delete();

            $target->email = $newEmail;
            $target->email_normalized = $normalized;
            $target->save();

            $generation = (int) Invitation::query()->where('user_id', $target->id)->max('generation') + 1;
            [$invitation, $plainToken] = $this->issue($target, $lockedActor, $generation);

            AuditEvent::record('invitation.email_corrected', Invitation::class, $invitation->id, null, [
                'generation' => $generation,
                'previous_email_normalized' => $previous,
                'corrected_email_normalized' => $normalized,
                'reason' => $reason,
            ], $lockedActor, ['executor' => self::class, 'required_permission' => AdminPermission::AdminsManage->value]);

            $this->dispatchAfterCommit($invitation, $plainToken);

            return $invitation;
        }, attempts: 3);
    }

    /**
     * Cancel every outstanding invitation for an invited Admin without deleting the account.
     */
    public function cancel(User $admin, User $actor, string $reason): void
    {
        app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $actor, $reason): void {
            $lockedActor = $this->lockAuthorizedActor($actor);
            $target = $this->lockInvitedAdmin($admin, $lockedActor);

            $this->cancelOutstanding($target, $lockedActor, $reason);

            AuditEvent::record('invitation.cancelled', User::class, $target->id, null, [
                'target_email_normalized' => $target->email_normalized,
                'reason' => $reason,
            ], $lockedActor, ['executor' => self::class, 'required_permission' => AdminPermission::AdminsManage->value]);
        });
    }

    private function lockAuthorizedActor(User $actor): User
    {
        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if ($locked->account_state !== AccountState::Active || $locked->user_type !== UserType::Admin) {
            throw new ConflictHttpException('Admin account is not active.');
        }
        if (! $this->authorizationService->allows($locked, AdminPermission::AdminsManage)) {
            throw new ConflictHttpException('Admin does not have current authority to manage Administrators.');
        }

        return $locked;
    }

    private function lockInvitedAdmin(User $admin, User $actor): User
    {
        $target = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
        if ($target->user_type !== UserType::Admin) {
            abort(404, 'Administrator not found.');
        }
        if ($target->id === $actor->id) {
            throw new ConflictHttpException('Administrators cannot manage their own invitation.');
        }
        if ($target->account_state !== AccountState::Invited) {
            throw new ConflictHttpException('Only Administrators in Invited state have manageable invitations.');
        }

        return $target;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function ensureGrantable(array $permissions): void
    {
        $active = Permission::query()->where('guard_name', 'web')->where('status', 'active')->pluck('name')->all();
        foreach ($permissions as $permission) {
            if (! in_array($permission, AdminPermission::values(), true) || ! in_array($permission, $active, true)) {
                throw ValidationException::withMessages([
                    'permissions' => [__('The permission [:permission] is invalid, retired, or unknown.', ['permission' => $permission])],
                ]);
            }
        }
    }

    /**
     * Enforce one account per email; never convert or duplicate an existing account (§3.12).
     */
    private function ensureEmailAvailable(string $emailNormalized, ?int $exceptUserId = null): void
    {
        $existing = User::query()->whereNormalizedEmail($emailNormalized)
            ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->lockForUpdate()->first();
        if ($existing === null) {
            return;
        }

        $message = match (true) {
            $existing->user_type !== UserType::Admin => 'This email belongs to another type of account and cannot be invited as an Administrator.',
            $existing->account_state === AccountState::Invited => 'This Administrator is already invited. Resend, correct, or cancel the existing invitation instead.',
            default => 'This email address has already been registered.',
        };

        throw ValidationException::withMessages(['email' => [$message]]);
    }

    private function cancelOutstanding(User $target, User $actor, string $reason): void
    {
        Invitation::query()->where('user_id', $target->id)->whereNull('cancelled_at')
            ->where('status', '!=', InvitationStatus::Activated)
            ->update([
                'status' => InvitationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ]);
    }

    /**
     * @return array{0: Invitation, 1: string}
     */
    private function issue(User $admin, User $actor, int $generation): array
    {
        $plainToken = Str::random(64);
        $invitation = Invitation::create([
            'user_id' => $admin->id,
            'target_email' => $admin->email,
            'target_email_normalized' => $admin->email_normalized,
            'role' => UserType::Admin->value,
            'token_hash' => hash('sha256', $plainToken),
            'generation' => $generation,
            'status' => InvitationStatus::PendingDelivery,
            'delivery_status' => DeliveryStatus::Pending,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
            'invited_by_user_id' => $actor->id,
        ]);

        return [$invitation, $plainToken];
    }

    private function dispatchAfterCommit(Invitation $invitation, #[\SensitiveParameter] string $plainToken): void
    {
        DB::afterCommit(function () use ($invitation, $plainToken): void {
            app(InvitationDeliveryIssues::class)->dispatch($invitation->id, $plainToken, $invitation->generation);
        });
    }
}
