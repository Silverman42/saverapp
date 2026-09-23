<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\CreationAttemptStatus;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\DeliveryStatus;
use App\Enums\FeeObligationStatus;
use App\Enums\Gender;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Jobs\DeliverCustomerInvitationJob;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CreationAttempt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\FeeObligation;
use App\Models\FeeSnapshot;
use App\Models\Invitation;
use App\Models\User;
use App\Support\IdentityNormalizer;
use App\Support\InternalReferenceNormalizer;
use App\Support\PhoneNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerRegistrationService
{
    public function __construct(
        protected PublicIdGenerator $publicIdGenerator,
        protected AgentEligibilityService $agentEligibilityService,
        protected RegistrationFeeService $registrationFeeService,
        protected InvitationSenderReadinessService $senderReadinessService,
        protected ProfilePhotoService $profilePhotoService,
    ) {}

    /**
     * Compute a deterministic payload fingerprint for a customer registration.
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
            'gender' => filled($data['gender'] ?? null) ? (string) $data['gender'] : null,
            'occupation' => filled($data['occupation'] ?? null) ? trim((string) $data['occupation']) : null,
            'internal_reference' => InternalReferenceNormalizer::normalize($data['internal_reference'] ?? null),
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'fee_rule_version' => (int) ($data['fee_rule_version'] ?? 0),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Register a new Customer idempotently.
     *
     * @param  array<string, mixed>  $data
     * @return array{customer: CustomerProfile, replayed: bool}
     *
     * @throws ValidationException|HttpException
     */
    public function register(User $agent, string $attemptReference, array $data, ?UploadedFile $photo = null): array
    {
        $business = BusinessProfile::current();

        // 1. Check invitation sender readiness
        $this->senderReadinessService->ensureReady($business);

        $fingerprint = $this->fingerprint($data);

        // 2. Check existing creation attempt
        $existingAttempt = CreationAttempt::query()
            ->where('attempt_reference', $attemptReference)
            ->first();

        if ($existingAttempt) {
            if ($existingAttempt->user_id !== $agent->id) {
                throw new ConflictHttpException('Creation attempt reference belongs to another user.');
            }

            if ($existingAttempt->operation_type !== 'customer_registration') {
                throw new ConflictHttpException('Creation attempt reference belongs to a different operation.');
            }

            if ($existingAttempt->hasConflictWith($fingerprint)) {
                throw new ConflictHttpException('Changed payload conflicts with previous creation attempt.');
            }

            if ($existingAttempt->isCommitted()) {
                /** @var CustomerProfile|null $existingCustomer */
                $existingCustomer = CustomerProfile::with(['user', 'currentAssignment'])->find($existingAttempt->record_id);

                if (! $existingCustomer || ! Gate::forUser($agent)->allows('view', $existingCustomer)) {
                    abort(404, 'Record unavailable.');
                }

                return [
                    'customer' => $existingCustomer,
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
        return DB::transaction(function () use (
            $agent,
            $business,
            $attemptReference,
            $fingerprint,
            $data,
            $photoPath,
            $existingAttempt,
        ): array {
            // Lock and re-verify Agent credentials & eligibility at commit
            /** @var User $freshAgent */
            $freshAgent = User::query()->where('id', $agent->id)->lockForUpdate()->firstOrFail();

            if ($freshAgent->account_state !== AccountState::Active || $freshAgent->user_type !== UserType::Agent) {
                throw new ConflictHttpException('Agent account is not active.');
            }

            if (! $this->agentEligibilityService->canReceiveAssignment($freshAgent)) {
                throw new ConflictHttpException('Agent is not currently eligible to register customers.');
            }

            // Verify Authoritative Registration Fee Rule
            $currentRule = $this->registrationFeeService->getCurrentRule();
            if ($currentRule === null) {
                throw new ConflictHttpException('No published registration fee rule is available. Registration is blocked.');
            }

            $previewedVersion = (int) ($data['fee_rule_version'] ?? 0);
            if ($currentRule->version !== $previewedVersion) {
                throw new ConflictHttpException('Registration fee configuration has changed since preview. Please reconfirm before registration.');
            }

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

            $internalReference = filled($data['internal_reference'] ?? null) ? trim((string) $data['internal_reference']) : null;
            $internalReferenceNormalized = InternalReferenceNormalizer::normalize($internalReference);

            // Uniqueness checks under lock
            if (User::query()->whereNormalizedEmail($emailNormalized)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['The email address has already been registered.'],
                ]);
            }

            if (CustomerProfile::query()->where('phone_normalized', $phoneNormalized)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['The phone number is already in use by a customer.'],
                ]);
            }

            if ($internalReferenceNormalized !== null && CustomerProfile::query()->where('internal_reference_normalized', $internalReferenceNormalized)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'internal_reference' => ['The internal reference number is already in use.'],
                ]);
            }

            // Parse optional Next of Kin
            $nextOfKin = null;
            if (isset($data['next_of_kin']) && is_array($data['next_of_kin'])) {
                $nokData = $data['next_of_kin'];
                $hasAnyNok = filled($nokData['full_name'] ?? null)
                    || filled($nokData['relationship'] ?? null)
                    || filled($nokData['phone'] ?? null)
                    || filled($nokData['address'] ?? null);

                if ($hasAnyNok) {
                    $nokPhone = trim((string) ($nokData['phone'] ?? ''));
                    $nokPhoneNormalized = PhoneNormalizer::normalize($nokPhone);

                    if (blank($nokData['full_name'] ?? null) || blank($nokData['relationship'] ?? null) || $nokPhoneNormalized === null) {
                        throw ValidationException::withMessages([
                            'next_of_kin.full_name' => blank($nokData['full_name'] ?? null) ? ['Next of kin full name is required when contact details are provided.'] : [],
                            'next_of_kin.relationship' => blank($nokData['relationship'] ?? null) ? ['Next of kin relationship is required when contact details are provided.'] : [],
                            'next_of_kin.phone' => $nokPhoneNormalized === null ? ['Next of kin phone must be in valid international format.'] : [],
                        ]);
                    }

                    $nextOfKin = [
                        'full_name' => trim((string) $nokData['full_name']),
                        'relationship' => trim((string) $nokData['relationship']),
                        'phone' => $nokPhone,
                        'address' => filled($nokData['address'] ?? null) ? trim((string) $nokData['address']) : null,
                    ];
                }
            }

            // Record or update creation attempt as in-progress
            $attempt = $existingAttempt ?? new CreationAttempt([
                'attempt_reference' => $attemptReference,
                'user_id' => $freshAgent->id,
                'business_id' => $business->business_id,
                'operation_type' => 'customer_registration',
                'payload_fingerprint' => $fingerprint,
            ]);

            $attempt->status = CreationAttemptStatus::InProgress;
            $attempt->save();

            // Create Invited User account with nullable password
            $user = new User([
                'name' => $name,
                'email' => $email,
                'email_normalized' => $emailNormalized,
                'user_type' => UserType::Customer,
                'account_state' => AccountState::Invited,
                'password' => null,
                'permission_version' => 1,
            ]);
            $user->save();

            // Generate immutable public CUS- ID
            $customerId = $this->publicIdGenerator->generateForCustomer();

            $genderValue = filled($data['gender'] ?? null) ? Gender::tryFrom($data['gender']) : null;

            // Create Active Customer profile
            $customerProfile = new CustomerProfile([
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'phone' => $phone,
                'phone_normalized' => $phoneNormalized,
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
                'gender' => $genderValue,
                'occupation' => filled($data['occupation'] ?? null) ? trim((string) $data['occupation']) : null,
                'internal_reference' => $internalReference,
                'internal_reference_normalized' => $internalReferenceNormalized,
                'next_of_kin' => $nextOfKin,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'operational_status' => CustomerStatus::Active,
                'photo_path' => $photoPath,
                'version' => 1,
                'created_by_user_id' => $freshAgent->id,
                'updated_by_user_id' => $freshAgent->id,
            ]);
            $customerProfile->save();

            // Create initial self-assignment
            $agentProfile = $freshAgent->agentProfile;
            if ($agentProfile === null) {
                throw new ConflictHttpException('Agent profile not found for user.');
            }

            CustomerAssignment::create([
                'customer_profile_id' => $customerProfile->id,
                'agent_profile_id' => $agentProfile->id,
                'assigned_by_user_id' => $freshAgent->id,
                'reason' => 'Initial registration self-assignment',
                'status' => CustomerAssignmentStatus::Current,
                'is_current' => 1,
                'effective_at' => now(),
                'version' => 1,
            ]);

            // Record status history
            CustomerStatusHistory::create([
                'customer_profile_id' => $customerProfile->id,
                'from_status' => null,
                'to_status' => CustomerStatus::Active->value,
                'reason' => 'Initial registration',
                'changed_by_user_id' => $freshAgent->id,
            ]);

            // Create immutable FeeSnapshot
            $feeSnapshot = FeeSnapshot::create([
                'customer_profile_id' => $customerProfile->id,
                'fee_rule_id' => $currentRule->id,
                'fee_rule_version' => $currentRule->version,
                'name' => $currentRule->name,
                'kind' => 'registration',
                'model' => $currentRule->model->value,
                'currency' => $currentRule->currency,
                'amount_kobo' => $currentRule->amount_kobo,
                'customer_description' => $currentRule->customer_description,
                'acknowledged_at' => null,
            ]);

            // If non-zero fee, create payable FeeObligation
            if ($currentRule->amount_kobo > 0) {
                FeeObligation::create([
                    'customer_profile_id' => $customerProfile->id,
                    'fee_snapshot_id' => $feeSnapshot->id,
                    'kind' => 'registration',
                    'amount_kobo' => $currentRule->amount_kobo,
                    'currency' => $currentRule->currency,
                    'status' => FeeObligationStatus::Pending,
                    'due_condition' => 'upon_registration',
                    'customer_description' => $currentRule->customer_description,
                    'created_by_user_id' => $freshAgent->id,
                ]);
            }

            // Create Invitation challenge (7-day validity for customer)
            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            $invitation = Invitation::create([
                'user_id' => $user->id,
                'target_email' => $user->email,
                'target_email_normalized' => $user->email_normalized,
                'role' => UserType::Customer->value,
                'token_hash' => $tokenHash,
                'generation' => 1,
                'status' => InvitationStatus::PendingDelivery,
                'delivery_status' => DeliveryStatus::Pending,
                'expires_at' => now()->addDays(7),
                'invited_by_user_id' => $freshAgent->id,
            ]);

            // Bind attempt to committed record
            $attempt->status = CreationAttemptStatus::Committed;
            $attempt->record_type = CustomerProfile::class;
            $attempt->record_id = $customerProfile->id;
            $attempt->result_summary = [
                'customer_id' => $customerProfile->customer_id,
                'name' => $user->name,
                'email' => $user->email,
                'operational_status' => $customerProfile->operational_status->value,
                'account_state' => $user->account_state->value,
                'fee_version' => $currentRule->version,
                'fee_amount_kobo' => $currentRule->amount_kobo,
            ];
            $attempt->save();

            // Record material creation audit event
            AuditEvent::record(
                eventType: 'customer.registered',
                targetType: CustomerProfile::class,
                targetId: $customerProfile->id,
                targetReference: $customerProfile->customer_id,
                payload: [
                    'name' => $user->name,
                    'email_normalized' => $user->email_normalized,
                    'phone_normalized' => $customerProfile->phone_normalized,
                    'operational_status' => $customerProfile->operational_status->value,
                    'account_state' => $user->account_state->value,
                    'fee_version' => $currentRule->version,
                    'fee_amount_kobo' => $currentRule->amount_kobo,
                ],
                actor: $freshAgent,
            );

            // Safe post-commit queued delivery
            DB::afterCommit(function () use ($invitation, $plainToken): void {
                DeliverCustomerInvitationJob::dispatch($invitation->id, $plainToken, 1);
            });

            return [
                'customer' => $customerProfile->load(['user', 'currentAssignment.agentProfile.user']),
                'replayed' => false,
            ];
        });
    }
}
