<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\Gender;
use App\Enums\UserType;
use App\Jobs\DeliverProfileNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\ProfileChangeHistory;
use App\Models\ProfileNotificationIntent;
use App\Models\User;
use App\Support\InternalReferenceNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProfileManagementService
{
    public function __construct(
        protected CustomerActionAuthorizationGuard $customerActionAuthorizationGuard,
        protected ProfilePhotoService $profilePhotoService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateCustomer(User $actor, CustomerProfile $profile, array $input, ?UploadedFile $photo = null): CustomerProfile
    {
        $isCustomer = $actor->user_type === UserType::Customer && $actor->id === $profile->user_id;
        $allowed = $isCustomer
            ? ['address', 'gender', 'occupation', 'next_of_kin', 'photo', 'remove_photo', 'version']
            : ['address', 'gender', 'occupation', 'next_of_kin', 'notes', 'internal_reference', 'photo', 'remove_photo', 'version', 'reason'];

        $this->rejectUnexpectedFields($input, $allowed);
        $validated = $this->validateCustomerInput($input, $isCustomer);
        $newPhotoPath = $photo ? $this->profilePhotoService->storePhoto($photo) : null;
        $oldPhotoPath = null;

        try {
            $updated = DB::transaction(function () use ($actor, $profile, $validated, $newPhotoPath, &$oldPhotoPath): CustomerProfile {
                $context = $this->customerActionAuthorizationGuard->lockAndAuthorize(
                    actor: $actor,
                    customerProfileId: $profile->id,
                    ability: 'update',
                    expectedCustomerVersion: (int) $validated['version'],
                );
                $lockedProfile = $context->customerProfile;
                $oldPhotoPath = $lockedProfile->photo_path;
                $before = [];
                $after = [];

                foreach (['address', 'gender', 'occupation', 'next_of_kin', 'notes', 'internal_reference'] as $field) {
                    if (! array_key_exists($field, $validated)) {
                        continue;
                    }

                    $value = $this->normalizeCustomerField($field, $validated[$field]);
                    $current = $lockedProfile->{$field};
                    $currentValue = $current instanceof \BackedEnum ? $current->value : $current;
                    $comparisonValue = $value instanceof \BackedEnum ? $value->value : $value;

                    if ($currentValue !== $comparisonValue) {
                        $before[$field] = $currentValue;
                        $after[$field] = $comparisonValue;
                        $lockedProfile->{$field} = $value;
                    }
                }

                if (filled($validated['internal_reference'] ?? null)) {
                    $normalizedReference = InternalReferenceNormalizer::normalize($validated['internal_reference']);
                    $duplicateExists = CustomerProfile::query()
                        ->where('internal_reference_normalized', $normalizedReference)
                        ->where('id', '!=', $lockedProfile->getKey())
                        ->lockForUpdate()
                        ->exists();

                    if ($duplicateExists) {
                        throw ValidationException::withMessages([
                            'internal_reference' => ['The internal reference number is already in use.'],
                        ]);
                    }
                }

                if (array_key_exists('name', $validated)) {
                    throw new AuthorizationException('Customer names must use the dedicated name correction workflow.');
                }

                if ($newPhotoPath !== null) {
                    $before['photo'] = $oldPhotoPath;
                    $after['photo'] = $newPhotoPath;
                    $lockedProfile->photo_path = $newPhotoPath;
                } elseif (($validated['remove_photo'] ?? false) && $oldPhotoPath !== null) {
                    $before['photo'] = $oldPhotoPath;
                    $after['photo'] = null;
                    $lockedProfile->photo_path = null;
                }

                if (array_intersect(array_keys($after), ['notes', 'internal_reference']) !== [] && blank($validated['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => ['A reason is required when changing notes or the internal reference.']]);
                }

                if ($before === []) {
                    return $lockedProfile->refresh();
                }

                $fromVersion = $lockedProfile->version;
                $lockedProfile->version++;
                $lockedProfile->updated_by_user_id = $context->actor->id;
                $lockedProfile->save();

                $history = $this->recordChange(
                    eventType: 'customer.profile_updated',
                    targetType: CustomerProfile::class,
                    targetId: $lockedProfile->id,
                    subjectUser: $lockedProfile->user,
                    actor: $context->actor,
                    changedFields: array_keys($after),
                    before: $before,
                    after: $after,
                    reason: $validated['reason'] ?? null,
                    fromVersion: $fromVersion,
                    toVersion: $lockedProfile->version,
                    targetReference: $lockedProfile->customer_id,
                );

                $this->queueCustomerNotices($history, $lockedProfile, $context->actor, array_keys($after));

                return $lockedProfile->refresh();
            });
        } catch (QueryException $exception) {
            if ($newPhotoPath !== null) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw new ConflictHttpException('The Customer profile changed while you were editing it. Refresh and try again.', $exception);
        } catch (\Throwable $exception) {
            if ($newPhotoPath !== null) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw $exception;
        }

        if ($oldPhotoPath !== null && $oldPhotoPath !== $newPhotoPath && (($validated['remove_photo'] ?? false) || $newPhotoPath !== null)) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateAgent(User $actor, AgentProfile $profile, array $input, ?UploadedFile $photo = null): AgentProfile
    {
        $isAgent = $actor->user_type === UserType::Agent && $actor->id === $profile->user_id;
        $allowed = $isAgent
            ? ['address', 'photo', 'remove_photo', 'version']
            : ['name', 'address', 'employment_date', 'notes', 'photo', 'remove_photo', 'version', 'reason'];

        $this->rejectUnexpectedFields($input, $allowed);
        $validated = $this->validateAgentInput($input, $isAgent);
        $newPhotoPath = $photo ? $this->profilePhotoService->storePhoto($photo) : null;
        $oldPhotoPath = null;

        try {
            $updated = DB::transaction(function () use ($actor, $profile, $validated, $newPhotoPath, &$oldPhotoPath): AgentProfile {
                $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $lockedProfile = AgentProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

                if ($lockedProfile->version !== (int) $validated['version']) {
                    throw new ConflictHttpException('The Agent profile changed while you were editing it. Refresh and try again.');
                }

                Gate::forUser($lockedActor)->authorize('update', $lockedProfile);
                $oldPhotoPath = $lockedProfile->profile_photo_path;
                $before = [];
                $after = [];

                foreach (['address', 'notes', 'employment_date'] as $field) {
                    if (! array_key_exists($field, $validated)) {
                        continue;
                    }

                    $value = $field === 'employment_date'
                        ? (filled($validated[$field]) ? $validated[$field] : null)
                        : (filled($validated[$field]) ? trim((string) $validated[$field]) : null);
                    $current = $lockedProfile->{$field};
                    $currentValue = $current instanceof \DateTimeInterface ? $current->toDateString() : $current;

                    if ($currentValue !== $value) {
                        $before[$field] = $currentValue;
                        $after[$field] = $value;
                        $lockedProfile->{$field} = $value;
                    }
                }

                $agentUser = User::query()->whereKey($lockedProfile->user_id)->lockForUpdate()->firstOrFail();
                if (array_key_exists('name', $validated) && trim((string) $validated['name']) !== $agentUser->name) {
                    $before['name'] = $agentUser->name;
                    $after['name'] = trim((string) $validated['name']);
                    $agentUser->name = $after['name'];
                }

                if ($newPhotoPath !== null) {
                    $before['photo'] = $oldPhotoPath;
                    $after['photo'] = $newPhotoPath;
                    $lockedProfile->profile_photo_path = $newPhotoPath;
                } elseif (($validated['remove_photo'] ?? false) && $oldPhotoPath !== null) {
                    $before['photo'] = $oldPhotoPath;
                    $after['photo'] = null;
                    $lockedProfile->profile_photo_path = null;
                }

                if (array_intersect(array_keys($after), ['name', 'notes', 'employment_date']) !== [] && blank($validated['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => ['A reason is required for this Agent profile change.']]);
                }

                if ($before === []) {
                    return $lockedProfile->refresh();
                }

                $fromVersion = $lockedProfile->version;
                $lockedProfile->version++;
                $lockedProfile->updated_by_user_id = $lockedActor->id;
                $lockedProfile->save();
                if (array_key_exists('name', $after)) {
                    $agentUser->save();
                }

                $history = $this->recordChange(
                    eventType: 'agent.profile_updated',
                    targetType: AgentProfile::class,
                    targetId: $lockedProfile->id,
                    subjectUser: $agentUser,
                    actor: $lockedActor,
                    changedFields: array_keys($after),
                    before: $before,
                    after: $after,
                    reason: $validated['reason'] ?? null,
                    fromVersion: $fromVersion,
                    toVersion: $lockedProfile->version,
                    targetReference: $lockedProfile->agent_id,
                );

                if ($lockedActor->id !== $agentUser->id && $agentUser->account_state === AccountState::Active) {
                    $this->createNotificationIntent(
                        history: $history,
                        recipient: $agentUser,
                        audienceType: 'subject_agent',
                        channel: 'database',
                        purpose: 'agent_profile_changed',
                        targetType: 'agent',
                        targetId: $lockedProfile->id,
                        payload: [
                            'title' => 'Your profile was updated',
                            'message' => 'An authorized administrator updated permitted profile details.',
                            'fields' => $this->displayFieldNames(array_keys($after)),
                            'url' => route('agents.show', $lockedProfile->agent_id),
                        ],
                    );
                }

                return $lockedProfile->refresh();
            });
        } catch (QueryException $exception) {
            if ($newPhotoPath !== null) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw new ConflictHttpException('The Agent profile changed while you were editing it. Refresh and try again.', $exception);
        } catch (\Throwable $exception) {
            if ($newPhotoPath !== null) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw $exception;
        }

        if ($oldPhotoPath !== null && $oldPhotoPath !== $newPhotoPath && (($validated['remove_photo'] ?? false) || $newPhotoPath !== null)) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return $updated;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function validateCustomerInput(array $input, bool $isCustomer): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:1'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'next_of_kin' => ['sometimes', 'nullable', 'array'],
            'next_of_kin.full_name' => ['nullable', 'string', 'max:150'],
            'next_of_kin.relationship' => ['nullable', 'string', 'max:50'],
            'next_of_kin.phone' => ['nullable', 'string', 'max:50'],
            'next_of_kin.address' => ['nullable', 'string', 'max:500'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['sometimes', 'boolean'],
        ];

        if (! $isCustomer) {
            $rules['notes'] = ['sometimes', 'nullable', 'string', 'max:2000'];
            $rules['internal_reference'] = ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-\/]*$/'];
            $rules['reason'] = ['nullable', 'string', 'min:3', 'max:500'];
        }

        $validated = validator($input, $rules)->validate();

        if (isset($validated['next_of_kin']) && is_array($validated['next_of_kin'])) {
            try {
                $validated['next_of_kin'] = CustomerProfile::sanitizeNextOfKin($validated['next_of_kin']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['next_of_kin' => [$exception->getMessage()]]);
            }
        }

        return $validated;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function validateAgentInput(array $input, bool $isAgent): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:1'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['sometimes', 'boolean'],
        ];

        if (! $isAgent) {
            $rules['name'] = ['sometimes', 'required', 'string', 'min:1', 'max:150'];
            $rules['employment_date'] = ['sometimes', 'nullable', 'date', 'before_or_equal:today'];
            $rules['notes'] = ['sometimes', 'nullable', 'string', 'max:2000'];
            $rules['reason'] = ['nullable', 'string', 'min:3', 'max:500'];
        }

        return validator($input, $rules)->validate();
    }

    /** @param array<string, mixed> $input
     * @param  array<int, string>  $allowed
     */
    protected function rejectUnexpectedFields(array $input, array $allowed): void
    {
        $unexpected = array_diff(array_keys($input), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'profile' => ['The request contains fields that cannot be changed here.'],
            ]);
        }
    }

    protected function normalizeCustomerField(string $field, mixed $value): mixed
    {
        if ($field === 'gender') {
            return filled($value) ? Gender::from((string) $value) : null;
        }

        if ($field === 'next_of_kin') {
            return $value;
        }

        if ($field === 'internal_reference') {
            return filled($value) ? trim((string) $value) : null;
        }

        return filled($value) ? trim((string) $value) : null;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $changedFields
     */
    public function recordChange(
        string $eventType,
        string $targetType,
        int $targetId,
        ?User $subjectUser,
        User $actor,
        array $changedFields,
        array $before,
        array $after,
        ?string $reason,
        ?int $fromVersion,
        ?int $toVersion,
        string $targetReference,
    ): ProfileChangeHistory {
        $operationId = (string) Str::uuid();
        $auditEvent = AuditEvent::record(
            eventType: $eventType,
            targetType: $targetType,
            targetId: $targetId,
            targetReference: $targetReference,
            payload: [
                'operation_id' => $operationId,
                'changed_fields' => $changedFields,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'outcome' => 'succeeded',
            ],
            actor: $actor,
        );

        return ProfileChangeHistory::create([
            'operation_id' => $operationId,
            'event_type' => $eventType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'subject_user_id' => $subjectUser?->id,
            'actor_id' => $actor->id,
            'actor_type' => $actor->user_type->value,
            'audit_event_id' => $auditEvent->id,
            'changed_fields' => $changedFields,
            'before_values' => $before,
            'after_values' => $after,
            'reason' => filled($reason) ? trim($reason) : null,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'created_at' => now(),
        ]);
    }

    /** @param array<int, string> $changedFields */
    protected function queueCustomerNotices(ProfileChangeHistory $history, CustomerProfile $profile, User $actor, array $changedFields): void
    {
        $personalFields = array_diff($changedFields, ['notes', 'internal_reference']);
        if ($personalFields === []) {
            return;
        }

        $subjectUser = $profile->user;
        if ($actor->user_type !== UserType::Customer && $subjectUser?->account_state === AccountState::Active) {
            $this->createNotificationIntent(
                history: $history,
                recipient: $subjectUser,
                audienceType: 'subject_customer',
                channel: 'database',
                purpose: 'customer_profile_changed',
                targetType: 'customer',
                targetId: $profile->id,
                payload: [
                    'title' => 'Your Customer profile was updated',
                    'message' => 'An authorized staff member updated permitted personal details.',
                    'fields' => $this->displayFieldNames($personalFields),
                    'url' => route('customers.show', $profile->customer_id),
                ],
            );
        }

        $assignedAgentUser = $profile->currentAssignment?->agentProfile?->user;
        if ($assignedAgentUser !== null && $assignedAgentUser->id !== $actor->id && $assignedAgentUser->account_state === AccountState::Active) {
            $this->createNotificationIntent(
                history: $history,
                recipient: $assignedAgentUser,
                audienceType: 'current_agent',
                channel: 'database',
                purpose: 'customer_profile_changed',
                targetType: 'customer',
                targetId: $profile->id,
                payload: [
                    'title' => 'An assigned Customer profile changed',
                    'message' => 'A permitted profile update was made for a Customer currently assigned to you.',
                    'fields' => $this->displayFieldNames($personalFields),
                    'url' => route('customers.show', $profile->customer_id),
                ],
            );
        }
    }

    /** @param array<string, mixed> $payload */
    public function createNotificationIntent(
        ProfileChangeHistory $history,
        User $recipient,
        string $audienceType,
        string $channel,
        string $purpose,
        string $targetType,
        int $targetId,
        array $payload,
    ): void {
        $intent = ProfileNotificationIntent::create([
            'notification_id' => (string) Str::uuid(),
            'profile_change_history_id' => $history->id,
            'recipient_user_id' => $recipient->id,
            'audience_type' => $audienceType,
            'channel' => $channel,
            'purpose' => $purpose,
            'subject_type' => $targetType,
            'subject_id' => $targetId,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        DB::afterCommit(static function () use ($intent): void {
            DeliverProfileNotificationIntent::dispatch($intent->id)->afterCommit();
        });
    }

    /** @param array<int, string> $fields
     * @return array<int, string>
     */
    public function displayFieldNames(array $fields): array
    {
        return array_map(static fn (string $field): string => match ($field) {
            'next_of_kin' => 'next of kin contact',
            'internal_reference' => 'internal reference',
            'employment_date' => 'engagement date',
            default => str_replace('_', ' ', $field),
        }, $fields);
    }
}
