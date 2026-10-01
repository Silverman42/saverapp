<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\Invitation;
use App\Models\User;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvitationManagementService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
        protected InvitationSenderReadinessService $senderReadinessService,
    ) {}

    /**
     * Resend an invitation for an Agent.
     *
     * @return array{invitation: Invitation}
     *
     * @throws ValidationException|HttpException
     */
    public function resend(AgentProfile $agentProfile, User $admin, ?string $reason = null): array
    {
        $business = BusinessProfile::current();
        $this->senderReadinessService->ensureReady($business);

        return app(PlatformGuard::class)->transaction('mutation', function () use ($agentProfile, $admin, $reason): array {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->where('id', $admin->id)->lockForUpdate()->firstOrFail();
            if ($freshAdmin->account_state !== AccountState::Active || $freshAdmin->user_type !== UserType::Admin) {
                throw new ConflictHttpException('Admin account is not active.');
            }

            if (! $this->authorizationService->allows($freshAdmin, AdminPermission::AgentsManage)) {
                throw new ConflictHttpException('Admin does not have authority to manage agent invitations.');
            }

            $user = $agentProfile->user()->lockForUpdate()->firstOrFail();
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
                $currentInvitation->cancelled_by_user_id = $freshAdmin->id;
                $currentInvitation->cancellation_reason = 'Superseded by invitation resend';
                $currentInvitation->save();
            }

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            $newInvitation = Invitation::create([
                'user_id' => $user->id,
                'target_email' => $user->email,
                'target_email_normalized' => $user->email_normalized,
                'role' => UserType::Agent->value,
                'token_hash' => $tokenHash,
                'generation' => $generation,
                'status' => InvitationStatus::PendingDelivery,
                'delivery_status' => DeliveryStatus::Pending,
                'expires_at' => now()->addHours(24),
                'invited_by_user_id' => $freshAdmin->id,
            ]);

            AuditEvent::record(
                eventType: 'invitation.resent',
                targetType: Invitation::class,
                targetId: $newInvitation->id,
                targetReference: $agentProfile->agent_id,
                payload: [
                    'agent_id' => $agentProfile->agent_id,
                    'generation' => $generation,
                    'target_email_normalized' => $newInvitation->target_email_normalized,
                    'reason' => $reason,
                ],
                actor: $freshAdmin,

                context: ['executor' => self::class, 'required_permission' => 'agents.manage']
            );

            DB::afterCommit(function () use ($newInvitation, $plainToken, $generation): void {
                app(InvitationDeliveryIssues::class)->dispatch($newInvitation->id, $plainToken, $generation);
            });

            return ['invitation' => $newInvitation];
        });
    }

    /**
     * Correct the email address of an invited Agent and issue a new invitation.
     *
     * @return array{invitation: Invitation}
     *
     * @throws ValidationException|HttpException
     */
    public function correctEmail(AgentProfile $agentProfile, User $admin, string $newEmail, string $reason): array
    {
        $business = BusinessProfile::current();
        $this->senderReadinessService->ensureReady($business);

        $newEmail = trim($newEmail);
        $normalizedNewEmail = IdentityNormalizer::normalizeEmail($newEmail);

        return app(PlatformGuard::class)->transaction('mutation', function () use ($agentProfile, $admin, $newEmail, $normalizedNewEmail, $reason): array {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->where('id', $admin->id)->lockForUpdate()->firstOrFail();
            if ($freshAdmin->account_state !== AccountState::Active || $freshAdmin->user_type !== UserType::Admin) {
                throw new ConflictHttpException('Admin account is not active.');
            }

            if (! $this->authorizationService->allows($freshAdmin, AdminPermission::AgentsManage)) {
                throw new ConflictHttpException('Admin does not have authority to manage agent invitations.');
            }

            $user = $agentProfile->user()->lockForUpdate()->firstOrFail();
            if ($user->account_state !== AccountState::Invited) {
                throw new ConflictHttpException('Only accounts in Invited state can have their email corrected.');
            }

            // Uniqueness check for new email
            $existing = User::query()
                ->whereNormalizedEmail($normalizedNewEmail)
                ->where('id', '!=', $user->id)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'email' => ['The new email address is already in use.'],
                ]);
            }

            $oldEmail = $user->email;
            $oldEmailNormalized = $user->email_normalized;

            // Invalidate prior invitations
            Invitation::query()
                ->where('user_id', $user->id)
                ->whereNull('cancelled_at')
                ->update([
                    'status' => InvitationStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $freshAdmin->id,
                    'cancellation_reason' => 'Superseded by email correction: '.$reason,
                ]);

            // Update user email
            $user->email = $newEmail;
            $user->email_normalized = $normalizedNewEmail;
            $user->save();

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            /** @var Invitation|null $lastInvitation */
            $lastInvitation = Invitation::query()->where('user_id', $user->id)->latest('generation')->first();
            $generation = ($lastInvitation !== null ? $lastInvitation->generation : 0) + 1;

            $newInvitation = Invitation::create([
                'user_id' => $user->id,
                'target_email' => $user->email,
                'target_email_normalized' => $user->email_normalized,
                'role' => UserType::Agent->value,
                'token_hash' => $tokenHash,
                'generation' => $generation,
                'status' => InvitationStatus::PendingDelivery,
                'delivery_status' => DeliveryStatus::Pending,
                'expires_at' => now()->addHours(24),
                'invited_by_user_id' => $freshAdmin->id,
            ]);

            AuditEvent::record(
                eventType: 'invitation.email_corrected',
                targetType: Invitation::class,
                targetId: $newInvitation->id,
                targetReference: $agentProfile->agent_id,
                payload: [
                    'agent_id' => $agentProfile->agent_id,
                    'previous_email_normalized' => $oldEmailNormalized,
                    'corrected_email_normalized' => $normalizedNewEmail,
                    'reason' => $reason,
                    'generation' => $generation,
                ],
                actor: $freshAdmin,

                context: ['executor' => self::class, 'required_permission' => 'agents.manage']
            );

            DB::afterCommit(function () use ($newInvitation, $plainToken, $generation): void {
                app(InvitationDeliveryIssues::class)->dispatch($newInvitation->id, $plainToken, $generation);
            });

            return ['invitation' => $newInvitation];
        });
    }

    /**
     * Cancel an active invitation for an invited Agent.
     *
     * @throws HttpException
     */
    public function cancel(AgentProfile $agentProfile, User $admin, string $reason): void
    {
        app(PlatformGuard::class)->transaction('mutation', function () use ($agentProfile, $admin, $reason): void {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->where('id', $admin->id)->lockForUpdate()->firstOrFail();
            if ($freshAdmin->account_state !== AccountState::Active || $freshAdmin->user_type !== UserType::Admin) {
                throw new ConflictHttpException('Admin account is not active.');
            }

            if (! $this->authorizationService->allows($freshAdmin, AdminPermission::AgentsManage)) {
                throw new ConflictHttpException('Admin does not have authority to cancel agent invitations.');
            }

            $user = $agentProfile->user()->lockForUpdate()->firstOrFail();
            if ($user->account_state !== AccountState::Invited) {
                throw new ConflictHttpException('Only accounts in Invited state can have their invitations cancelled.');
            }

            $invitations = Invitation::query()
                ->where('user_id', $user->id)
                ->whereNull('cancelled_at')
                ->lockForUpdate()
                ->get();

            foreach ($invitations as $invitation) {
                $invitation->status = InvitationStatus::Cancelled;
                $invitation->cancelled_at = now();
                $invitation->cancelled_by_user_id = $freshAdmin->id;
                $invitation->cancellation_reason = $reason;
                $invitation->save();
            }

            AuditEvent::record(
                eventType: 'invitation.cancelled',
                targetType: AgentProfile::class,
                targetId: $agentProfile->id,
                targetReference: $agentProfile->agent_id,
                payload: [
                    'agent_id' => $agentProfile->agent_id,
                    'target_email_normalized' => $user->email_normalized,
                    'reason' => $reason,
                ],
                actor: $freshAdmin,

                context: ['executor' => self::class, 'required_permission' => 'agents.manage']
            );
        });
    }
}
