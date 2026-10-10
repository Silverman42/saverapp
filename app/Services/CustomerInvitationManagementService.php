<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\Invitation;
use App\Models\User;
use App\Support\IdentityNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerInvitationManagementService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
        protected AgentEligibilityService $agentEligibilityService,
        protected InvitationSenderReadinessService $senderReadinessService,
    ) {}

    /**
     * Verify actor authority to manage the customer's invitation.
     */
    public function verifyAuthority(User $actor, CustomerProfile $customerProfile): void
    {
        if ($actor->account_state !== AccountState::Active) {
            throw new AuthorizationException('Actor account is not active.');
        }

        if ($actor->user_type === UserType::Admin) {
            if (! $this->authorizationService->allows($actor, AdminPermission::CustomersManage)) {
                throw new AuthorizationException('Admin does not have authority to manage customer invitations.');
            }

            return;
        }

        if ($actor->user_type === UserType::Agent) {
            if (! $this->agentEligibilityService->canPerformAssignedCustomerWork($actor)) {
                throw new AuthorizationException('Agent is not eligible to perform customer management work.');
            }

            $currentAssignment = $customerProfile->currentAssignment;
            if (! $currentAssignment || $currentAssignment->agent_profile_id !== $actor->agentProfile?->id) {
                throw new AuthorizationException('Agent is not currently assigned to this customer.');
            }

            return;
        }

        throw new AuthorizationException('Unauthorized to manage customer invitations.');
    }

    /**
     * Resend an invitation for a Customer.
     *
     * @return array{invitation: Invitation}
     *
     * @throws ValidationException|HttpException
     */
    public function resend(CustomerProfile $customerProfile, User $actor, ?string $reason = null): array
    {
        $business = BusinessProfile::current();
        $this->senderReadinessService->ensureReady($business);

        return app(PlatformGuard::class)->transaction('mutation', function () use ($customerProfile, $actor, $reason): array {
            /** @var User $freshActor */
            $freshActor = User::query()->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $this->verifyAuthority($freshActor, $customerProfile);

            $user = $customerProfile->user()->lockForUpdate()->firstOrFail();
            if ($user->account_state !== AccountState::Invited) {
                throw new ConflictHttpException('Only accounts in Invited state can receive invitation resends.');
            }

            // Rate-limiting checks (1 per minute, 5 per day)
            $oneMinuteAgo = now()->subMinute();
            $recentCount = Invitation::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>=', $oneMinuteAgo)
                ->count();

            if ($recentCount > 0) {
                throw ValidationException::withMessages([
                    'resend' => ['Invitations may only be resent once per minute.'],
                ]);
            }

            $oneDayAgo = now()->subDay();
            $dailyCount = Invitation::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>=', $oneDayAgo)
                ->count();

            if ($dailyCount >= 5) {
                throw ValidationException::withMessages([
                    'resend' => ['Daily invitation resend limit (5 per 24 hours) reached for this account.'],
                ]);
            }

            /** @var Invitation|null $currentInvitation */
            $currentInvitation = Invitation::query()
                ->where('user_id', $user->id)
                ->latest('generation')
                ->lockForUpdate()
                ->first();

            if ($currentInvitation && ! $currentInvitation->canResend()) {
                throw new ConflictHttpException("Current invitation state [{$currentInvitation->status->value}] does not permit resending.");
            }

            $generation = ($currentInvitation !== null ? $currentInvitation->generation : 0) + 1;

            if ($currentInvitation && $currentInvitation->status !== InvitationStatus::Cancelled) {
                $currentInvitation->status = InvitationStatus::Cancelled;
                $currentInvitation->cancelled_at = now();
                $currentInvitation->cancelled_by_user_id = $freshActor->id;
                $currentInvitation->cancellation_reason = 'Superseded by invitation resend';
                $currentInvitation->save();
            }

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            $newInvitation = Invitation::create([
                'user_id' => $user->id,
                'target_email' => $user->email,
                'target_email_normalized' => $user->email_normalized,
                'role' => UserType::Customer->value,
                'token_hash' => $tokenHash,
                'generation' => $generation,
                'status' => InvitationStatus::PendingDelivery,
                'delivery_status' => DeliveryStatus::Pending,
                'expires_at' => now()->addDays(7),
                'invited_by_user_id' => $freshActor->id,
            ]);

            AuditEvent::record(
                eventType: 'customer.invitation_resent',
                targetType: Invitation::class,
                targetId: $newInvitation->id,
                targetReference: null,
                payload: [
                    'customer_id' => $customerProfile->customer_id,
                    'target_email_normalized' => $newInvitation->target_email_normalized,
                    'generation' => $newInvitation->generation,
                    'reason' => $reason ?? 'Operational resend request',
                ],
                actor: $freshActor,

                context: ['executor' => self::class, 'required_permission' => $freshActor->user_type === UserType::Admin ? 'customers.manage' : null]
            );

            DB::afterCommit(function () use ($newInvitation, $plainToken, $generation): void {
                app(InvitationDeliveryIssues::class)->dispatch($newInvitation->id, $plainToken, $generation);
            });

            return [
                'invitation' => $newInvitation,
            ];
        });
    }

    /**
     * Correct the invited email address for a Customer.
     *
     * @return array{invitation: Invitation}
     *
     * @throws ValidationException|HttpException
     */
    public function correctEmail(CustomerProfile $customerProfile, User $actor, string $newEmail, ?string $reason = null): array
    {
        $business = BusinessProfile::current();
        $this->senderReadinessService->ensureReady($business);

        $newEmail = trim($newEmail);
        $newEmailNormalized = IdentityNormalizer::normalizeEmail($newEmail);

        return app(PlatformGuard::class)->transaction('mutation', function () use ($customerProfile, $actor, $newEmail, $newEmailNormalized, $reason): array {
            /** @var User $freshActor */
            $freshActor = User::query()->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $this->verifyAuthority($freshActor, $customerProfile);

            $user = $customerProfile->user()->lockForUpdate()->firstOrFail();
            if ($user->account_state !== AccountState::Invited) {
                throw new ConflictHttpException('Only accounts in Invited state can have their email corrected.');
            }

            // Uniqueness check for new email
            if (User::query()->whereNormalizedEmail($newEmailNormalized)->where('id', '!=', $user->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['The specified email address is already in use.'],
                ]);
            }

            $oldEmailNormalized = $user->email_normalized;
            $user->email = $newEmail;
            $user->email_normalized = $newEmailNormalized;
            $user->save();

            /** @var Invitation|null $currentInvitation */
            $currentInvitation = Invitation::query()
                ->where('user_id', $user->id)
                ->latest('generation')
                ->lockForUpdate()
                ->first();

            $generation = ($currentInvitation !== null ? $currentInvitation->generation : 0) + 1;

            if ($currentInvitation && $currentInvitation->status !== InvitationStatus::Cancelled) {
                $currentInvitation->status = InvitationStatus::Cancelled;
                $currentInvitation->cancelled_at = now();
                $currentInvitation->cancelled_by_user_id = $freshActor->id;
                $currentInvitation->cancellation_reason = 'Superseded by invited email correction';
                $currentInvitation->save();
            }

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            $newInvitation = Invitation::create([
                'user_id' => $user->id,
                'target_email' => $user->email,
                'target_email_normalized' => $user->email_normalized,
                'role' => UserType::Customer->value,
                'token_hash' => $tokenHash,
                'generation' => $generation,
                'status' => InvitationStatus::PendingDelivery,
                'delivery_status' => DeliveryStatus::Pending,
                'expires_at' => now()->addDays(7),
                'invited_by_user_id' => $freshActor->id,
            ]);

            AuditEvent::record(
                eventType: 'customer.invited_email_corrected',
                targetType: User::class,
                targetId: $user->id,
                targetReference: $customerProfile->customer_id,
                payload: [
                    'customer_id' => $customerProfile->customer_id,
                    'old_email_normalized' => $oldEmailNormalized,
                    'new_email_normalized' => $newEmailNormalized,
                    'generation' => $newInvitation->generation,
                    'reason' => $reason ?? 'Operational email correction',
                ],
                actor: $freshActor,

                context: ['executor' => self::class, 'required_permission' => $freshActor->user_type === UserType::Admin ? 'customers.manage' : null]
            );

            DB::afterCommit(function () use ($newInvitation, $plainToken, $generation): void {
                app(InvitationDeliveryIssues::class)->dispatch($newInvitation->id, $plainToken, $generation);
            });

            return [
                'invitation' => $newInvitation,
            ];
        }, attempts: 3);
    }

    /**
     * Cancel an invitation for a Customer.
     *
     * @throws ValidationException|HttpException
     */
    public function cancel(CustomerProfile $customerProfile, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Cancellation reason is required.'],
            ]);
        }

        app(PlatformGuard::class)->transaction('mutation', function () use ($customerProfile, $actor, $reason): void {
            /** @var User $freshActor */
            $freshActor = User::query()->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $this->verifyAuthority($freshActor, $customerProfile);

            $user = $customerProfile->user()->lockForUpdate()->firstOrFail();
            if ($user->account_state !== AccountState::Invited) {
                throw new ConflictHttpException('Only accounts in Invited state can have their invitation cancelled.');
            }

            /** @var Invitation|null $currentInvitation */
            $currentInvitation = Invitation::query()
                ->where('user_id', $user->id)
                ->latest('generation')
                ->lockForUpdate()
                ->first();

            if (! $currentInvitation || $currentInvitation->status === InvitationStatus::Cancelled) {
                throw new ConflictHttpException('Invitation is already cancelled.');
            }

            if ($currentInvitation->status === InvitationStatus::Activated) {
                throw new ConflictHttpException('Cannot cancel an already activated invitation.');
            }

            $currentInvitation->status = InvitationStatus::Cancelled;
            $currentInvitation->cancelled_at = now();
            $currentInvitation->cancelled_by_user_id = $freshActor->id;
            $currentInvitation->cancellation_reason = $reason;
            $currentInvitation->save();

            AuditEvent::record(
                eventType: 'customer.invitation_cancelled',
                targetType: Invitation::class,
                targetId: $currentInvitation->id,
                targetReference: $customerProfile->customer_id,
                payload: [
                    'customer_id' => $customerProfile->customer_id,
                    'reason' => $reason,
                    'generation' => $currentInvitation->generation,
                ],
                actor: $freshActor,

                context: ['executor' => self::class, 'required_permission' => $freshActor->user_type === UserType::Admin ? 'customers.manage' : null]
            );
        });
    }
}
