<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CreationAttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\AgentStatusHistory;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CreationAttempt;
use App\Models\Invitation;
use App\Models\User;
use App\Support\IdentityNormalizer;
use App\Support\PhoneNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AgentRegistrationService
{
    public function __construct(
        protected PublicIdGenerator $publicIdGenerator,
        protected AuthorizationService $authorizationService,
        protected InvitationSenderReadinessService $senderReadinessService,
        protected ProfilePhotoService $profilePhotoService,
    ) {}

    /**
     * Compute a deterministic payload fingerprint for an agent registration.
     *
     * @param  array<string, mixed>  $data
     */
    public function fingerprint(array $data): string
    {
        $normalized = [
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => IdentityNormalizer::normalizeEmail((string) ($data['email'] ?? '')),
            'phone' => PhoneNormalizer::normalize((string) ($data['phone'] ?? '')),
            'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'employment_date' => filled($data['employment_date'] ?? null) ? (string) $data['employment_date'] : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Register a new Agent idempotently.
     *
     * @param  array<string, mixed>  $data
     * @return array{agent: AgentProfile, replayed: bool}
     *
     * @throws ValidationException|HttpException
     */
    public function register(User $admin, string $attemptReference, array $data, ?UploadedFile $photo = null): array
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $attemptReference, $data, $photo) {
            return $this->registerAllowed($admin, $attemptReference, $data, $photo);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{agent: AgentProfile, replayed: bool}
     */
    private function registerAllowed(User $admin, string $attemptReference, array $data, ?UploadedFile $photo = null): array
    {
        $business = BusinessProfile::current();

        // 1. Explicitly check invitation sender readiness before accepting registration
        $this->senderReadinessService->ensureReady($business);

        $fingerprint = $this->fingerprint($data);

        // 2. Check existing creation attempt
        $existingAttempt = CreationAttempt::query()
            ->where('attempt_reference', $attemptReference)
            ->first();

        if ($existingAttempt) {
            if ($existingAttempt->user_id !== $admin->id) {
                throw new ConflictHttpException('Creation attempt reference belongs to another user.');
            }

            if ($existingAttempt->operation_type !== 'agent_registration') {
                throw new ConflictHttpException('Creation attempt reference belongs to a different operation.');
            }

            if ($existingAttempt->hasConflictWith($fingerprint)) {
                throw new ConflictHttpException('Changed payload conflicts with previous creation attempt.');
            }

            if ($existingAttempt->isCommitted()) {
                /** @var AgentProfile|null $existingAgent */
                $existingAgent = AgentProfile::with(['user'])->find($existingAttempt->record_id);

                if (! $existingAgent || ! Gate::forUser($admin)->allows('view', $existingAgent)) {
                    abort(404, 'Record unavailable.');
                }

                return [
                    'agent' => $existingAgent,
                    'replayed' => true,
                ];
            }

            if ($existingAttempt->isInProgress()) {
                throw new ConflictHttpException('Creation attempt is currently being processed.');
            }
        }

        // 3. Process photo outside the core transaction if provided
        $photoPath = null;
        if ($photo !== null) {
            $photoPath = $this->profilePhotoService->storePhoto($photo);
        }

        // 4. Atomic transaction
        return app(PlatformGuard::class)->transaction('mutation', function () use (
            $admin,
            $business,
            $attemptReference,
            $fingerprint,
            $data,
            $photoPath,
            $existingAttempt,
        ): array {
            // Lock and re-verify Admin credentials & authority at commit
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->where('id', $admin->id)->lockForUpdate()->firstOrFail();

            if ($freshAdmin->account_state !== AccountState::Active || $freshAdmin->user_type !== UserType::Admin) {
                throw new ConflictHttpException('Admin account is not active.');
            }

            if (! $this->authorizationService->allows($freshAdmin, AdminPermission::AgentsManage)) {
                throw new ConflictHttpException('Admin does not have current authority to manage agents.');
            }

            // Normalization
            $name = trim((string) $data['name']);
            $email = trim((string) $data['email']);
            $emailNormalized = IdentityNormalizer::normalizeEmail($email);
            $phone = trim((string) $data['phone']);
            $phoneNormalized = PhoneNormalizer::normalize($phone);

            if ($phoneNormalized === null) {
                throw ValidationException::withMessages([
                    'phone' => ['The phone number must be in valid international format.'],
                ]);
            }

            // Uniqueness checks under lock
            if (User::query()->whereNormalizedEmail($emailNormalized)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['The email address has already been registered.'],
                ]);
            }

            if (AgentProfile::query()->where('phone_normalized', $phoneNormalized)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['The phone number is already in use by an agent.'],
                ]);
            }

            // Record or update creation attempt as in-progress
            $attempt = $existingAttempt ?? new CreationAttempt([
                'attempt_reference' => $attemptReference,
                'user_id' => $freshAdmin->id,
                'business_id' => $business->business_id,
                'operation_type' => 'agent_registration',
                'payload_fingerprint' => $fingerprint,
            ]);

            $attempt->status = CreationAttemptStatus::InProgress;
            $attempt->save();

            // Create Invited User account with nullable password
            $user = new User([
                'name' => $name,
                'email' => $email,
                'email_normalized' => $emailNormalized,
                'user_type' => UserType::Agent,
                'account_state' => AccountState::Invited,
                'password' => null,
                'permission_version' => 1,
            ]);
            $user->save();

            // Generate immutable public AGT- ID
            $agentId = $this->publicIdGenerator->generateForAgent();

            // Create Inactive Agent profile
            $agentProfile = new AgentProfile([
                'user_id' => $user->id,
                'agent_id' => $agentId,
                'phone' => $phone,
                'phone_normalized' => $phoneNormalized,
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
                'employment_date' => $data['employment_date'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'operational_status' => AgentStatus::Inactive,
                'profile_photo_path' => $photoPath,
                'version' => 1,
                'created_by_user_id' => $freshAdmin->id,
                'updated_by_user_id' => $freshAdmin->id,
            ]);
            $agentProfile->save();

            // Record status history
            AgentStatusHistory::create([
                'agent_profile_id' => $agentProfile->id,
                'from_status' => null,
                'to_status' => AgentStatus::Inactive->value,
                'reason' => 'Initial registration',
                'changed_by_user_id' => $freshAdmin->id,
            ]);

            // Create Invitation challenge
            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            $invitation = Invitation::create([
                'user_id' => $user->id,
                'target_email' => $user->email,
                'target_email_normalized' => $user->email_normalized,
                'role' => UserType::Agent->value,
                'token_hash' => $tokenHash,
                'generation' => 1,
                'status' => InvitationStatus::PendingDelivery,
                'delivery_status' => DeliveryStatus::Pending,
                'expires_at' => now()->addHours(24),
                'invited_by_user_id' => $freshAdmin->id,
            ]);

            // Bind attempt to committed record
            $attempt->status = CreationAttemptStatus::Committed;
            $attempt->record_type = AgentProfile::class;
            $attempt->record_id = $agentProfile->id;
            $attempt->result_summary = [
                'agent_id' => $agentProfile->agent_id,
                'name' => $user->name,
                'email' => $user->email,
                'operational_status' => $agentProfile->operational_status->value,
                'account_state' => $user->account_state->value,
            ];
            $attempt->save();

            // Record material creation audit event
            AuditEvent::record(
                eventType: 'agent.registered',
                targetType: AgentProfile::class,
                targetId: $agentProfile->id,
                targetReference: $agentProfile->agent_id,
                payload: [
                    'name' => $user->name,
                    'email_normalized' => $user->email_normalized,
                    'phone_normalized' => $agentProfile->phone_normalized,
                    'operational_status' => $agentProfile->operational_status->value,
                    'account_state' => $user->account_state->value,
                ],
                actor: $freshAdmin,

                context: ['executor' => self::class, 'required_permission' => 'agents.manage']
            );

            // Safe post-commit queued delivery
            DB::afterCommit(function () use ($invitation, $plainToken): void {
                app(InvitationDeliveryIssues::class)->dispatch($invitation->id, $plainToken, 1);
            });

            return [
                'agent' => $agentProfile->load(['user']),
                'replayed' => false,
            ];
        });
    }
}
