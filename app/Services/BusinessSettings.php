<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessConfigurationDraft;
use App\Models\BusinessConfigurationEvent;
use App\Models\BusinessConfigurationVersion;
use App\Models\BusinessProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BusinessSettings
{
    public function __construct(
        private BusinessSettingsCatalogue $catalogue,
        private BusinessSettingsReadiness $readiness,
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private NotificationPipeline $notifications,
    ) {}

    public function authorize(User $actor, bool $write = false): void
    {
        abort_unless($actor->account_state === AccountState::Active && $actor->user_type === UserType::Admin
            && $actor->getRoleNames()->all() === ['admin'], 403);
        if ($write) {
            abort_unless($this->authorization->allows($actor, AdminPermission::BusinessSettingsManage), 403);
        }
    }

    public function import(): BusinessConfigurationVersion
    {
        return app(PlatformGuard::class)->transaction('mutation', function (): BusinessConfigurationVersion {
            $profile = $this->lockedProfile();
            if ($profile->getAttribute('effective_configuration_id') !== null) {
                return $this->effectiveVersion($profile);
            }
            $values = $this->catalogue->initialValues($profile);
            $values = $this->catalogue->validatePatch($values, $values, true);
            $version = BusinessConfigurationVersion::create([
                'business_profile_id' => $profile->id, 'version' => $profile->version, 'base_version' => $profile->version,
                'values' => $values, 'values_hash' => $this->catalogue->hash($values), 'changed_codes' => array_keys($values),
                'dependency_hash' => $this->readiness->hash(), 'actor_user_id' => null, 'reason' => 'Trusted configuration import; prior versions are not reconstructed.',
                'source' => 'trusted_import', 'requested_effective_at' => now(),
            ]);
            DB::table('business_configuration_work')->insert(['configuration_id' => $version->id, 'status' => 'effective', 'effective_at' => now(), 'updated_at' => now()]);
            DB::table('business_profiles')->where('id', $profile->id)->update(['effective_configuration_id' => $version->id]);
            $this->event('imported', $version, null);

            return $version;
        }, attempts: 3);
    }

    /** @return array{version: int, configuration_id: int|null, values: array<string, mixed>, source: string} */
    public function resolve(bool $lock = false): array
    {
        $profile = $lock ? $this->lockedProfile() : BusinessProfile::current();
        if ($profile->getAttribute('effective_configuration_id') === null) {
            return ['version' => $profile->version, 'configuration_id' => null, 'values' => $this->catalogue->initialValues($profile), 'source' => 'legacy_unverified'];
        }
        $version = $this->effectiveVersion($profile);
        $values = $version->values;
        if (! is_array($values) || ! hash_equals($version->values_hash, $this->catalogue->hash($values))) {
            throw new LogicException('Effective configuration integrity could not be verified.');
        }

        return ['version' => $version->version, 'configuration_id' => $version->id, 'values' => $values, 'source' => $version->source];
    }

    /** @return array<string, mixed> */
    public function collectionLimits(): array
    {
        $snapshot = $this->resolve(DB::transactionLevel() > 0);
        if ($snapshot['configuration_id'] !== null) {
            $this->assertNoPending(['receipt_minimum_kobo', 'receipt_maximum_kobo', 'late_lookback_days']);
        } else {
            $snapshot['values']['receipt_minimum_kobo'] = 1;
        }

        return $snapshot;
    }

    public function ensureFeature(string $code): void
    {
        $snapshot = $this->resolve(DB::transactionLevel() > 0);
        if ($snapshot['configuration_id'] === null) {
            return;
        }
        $this->assertNoPending([$code]);
        abort_unless(($snapshot['values'][$code] ?? false) === true
            && ($this->readiness->checks()[$code]['state'] ?? '') === 'Ready to enable', 503, 'This capability is unavailable until its owner release gates pass.');
    }

    /** @param list<string> $codes */
    private function assertNoPending(array $codes): void
    {
        $pending = BusinessConfigurationVersion::query()->join('business_configuration_work as w', 'w.configuration_id', '=', 'business_configuration_versions.id')
            ->whereIn('w.status', ['scheduled', 'propagation_pending', 'blocked'])->where('requested_effective_at', '<=', now())
            ->select('business_configuration_versions.*')->get();
        foreach ($pending as $version) {
            abort_if(array_intersect($codes, $version->changed_codes) !== [], 503, 'Configuration activation is pending; refresh after owner acknowledgement.');
        }
    }

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function saveDraft(User $actor, array $patch, int $base, string $operation, ?int $draftId = null, ?int $revision = null): array
    {
        return $this->operation($actor, 'save', $operation, compact('patch', 'base', 'draftId', 'revision'), function (User $current) use ($patch, $base, $draftId, $revision): array {
            $snapshot = $this->resolve(true);
            abort_if($snapshot['configuration_id'] === null, 503, 'Import the trusted business configuration first.');
            $this->expectVersion($base, $snapshot['version']);
            $normalized = $this->catalogue->validatePatch($patch, $snapshot['values']);
            if ($draftId === null) {
                $draft = BusinessConfigurationDraft::create(['business_profile_id' => BusinessProfile::current()->id, 'actor_user_id' => $current->id,
                    'base_version' => $base, 'revision' => 1, 'patch' => $normalized, 'status' => 'draft']);
            } else {
                $draft = $this->ownedDraft($current, $draftId);
                $this->expectVersion((int) $revision, $draft->revision);
                $draft->update(['patch' => $normalized, 'base_version' => $base, 'revision' => $draft->revision + 1, 'preview' => null]);
            }
            $this->draftAudit('draft_saved', $draft, $current);

            return ['draft_id' => $draft->id, 'revision' => $draft->revision];
        });
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, int $draftId, int $revision, ?string $effectiveAt): array
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $draftId, $revision, $effectiveAt): array {
            $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($current, true);
            $snapshot = $this->resolve(true);
            $draft = $this->ownedDraft($current, $draftId);
            $this->expectVersion($revision, $draft->revision);
            $this->expectVersion($draft->base_version, $snapshot['version']);
            $patch = $this->catalogue->validatePatch($draft->patch ?? [], $snapshot['values']);
            if ($patch === []) {
                throw ValidationException::withMessages(['patch' => 'Change at least one setting before previewing.']);
            }
            $at = $effectiveAt === null ? null : CarbonImmutable::parse($effectiveAt)->utc();
            $financial = array_intersect(array_keys($patch), ['receipt_minimum_kobo', 'receipt_maximum_kobo', 'late_lookback_days']) !== [];
            if ($at !== null && $at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['effective_at' => 'Choose a future effective time.']);
            }
            if ($financial && ($at === null || $at->setTimezone((string) $snapshot['values']['timezone'])->format('H:i:s') !== '00:00:00')) {
                throw ValidationException::withMessages(['effective_at' => 'Collection limits must activate at a future business midnight.']);
            }
            $diff = [];
            foreach ($patch as $code => $value) {
                $diff[$code] = ['before' => $snapshot['values'][$code], 'after' => $value];
            }
            $preview = ['reference' => (string) Str::uuid(), 'revision' => $revision, 'base_version' => $snapshot['version'],
                'patch_hash' => $this->catalogue->hash($patch), 'dependency_hash' => $this->readiness->hash(),
                'effective_at' => $at?->toIso8601String(), 'expires_at' => now()->addMinutes(10)->toIso8601String(), 'diff' => $diff,
                'effects' => ['Changes apply to new operations after activation.', 'Existing plans, receipts, batches and rendered notices retain their captured values.',
                    'Required consumers acknowledge the bundle before it becomes effective.', 'No financial posting, account creation or historical migration is performed.']];
            $draft->update(['preview' => $preview]);
            $this->draftAudit('previewed', $draft, $current);

            return $preview;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function publish(User $actor, int $draftId, int $revision, string $previewReference, string $reason, string $operation, Request $request): array
    {
        $reason = $this->reason($reason);

        return $this->operation($actor, 'publish', $operation, compact('draftId', 'revision', 'previewReference', 'reason'), function (User $current) use ($draftId, $revision, $previewReference, $reason, $request): array {
            $this->requireFresh($current, $request);
            $profile = $this->lockedProfile();
            $snapshot = $this->resolve(true);
            $draft = $this->ownedDraft($current, $draftId);
            $this->expectVersion($revision, $draft->revision);
            $this->expectVersion($draft->base_version, $snapshot['version']);
            $patch = $this->catalogue->validatePatch($draft->patch ?? [], $snapshot['values']);
            $preview = $draft->preview;
            if (! is_array($preview) || $preview['reference'] !== $previewReference || CarbonImmutable::parse($preview['expires_at'])->isPast()
                || $preview['patch_hash'] !== $this->catalogue->hash($patch) || $preview['dependency_hash'] !== $this->readiness->hash()) {
                throw new ConflictHttpException('The impact preview is stale. Preview again before confirming.');
            }
            if (DB::table('business_configuration_work')->whereIn('status', ['scheduled', 'propagation_pending', 'blocked'])->exists()) {
                throw new ConflictHttpException('Resolve the existing scheduled or pending bundle before publishing another.');
            }
            $at = $preview['effective_at'] === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($preview['effective_at']);
            if ($preview['effective_at'] !== null && $at->lessThanOrEqualTo(now())) {
                throw new ConflictHttpException('The scheduled time has elapsed. Preview again.');
            }
            $values = array_replace($snapshot['values'], $patch);
            $next = (int) BusinessConfigurationVersion::query()->where('business_profile_id', $profile->id)->max('version') + 1;
            $version = BusinessConfigurationVersion::create(['business_profile_id' => $profile->id, 'version' => $next, 'base_version' => $snapshot['version'],
                'values' => $values, 'values_hash' => $this->catalogue->hash($values), 'changed_codes' => array_keys($patch),
                'dependency_hash' => $preview['dependency_hash'], 'actor_user_id' => $current->id, 'actor_label' => $current->name, 'reason' => $reason, 'source' => 'publication', 'requested_effective_at' => $at]);
            DB::table('business_configuration_work')->insert(['configuration_id' => $version->id, 'status' => $at->isFuture() ? 'scheduled' : 'propagation_pending', 'updated_at' => now()]);
            $draft->update(['status' => 'published', 'preview' => null]);
            $this->event('published', $version, $current, $request);
            if (! $at->isFuture()) {
                $this->activate($version->id);
            }

            return ['configuration_id' => $version->id, 'version' => $next];
        });
    }

    /** @return array<string, mixed> */
    public function cancel(User $actor, int $configurationId, string $reason, string $operation, Request $request): array
    {
        $reason = $this->reason($reason);

        return $this->operation($actor, 'cancel', $operation, compact('configurationId', 'reason'), function (User $current) use ($configurationId, $reason, $request): array {
            $this->requireFresh($current, $request);
            $version = BusinessConfigurationVersion::query()->findOrFail($configurationId);
            $work = DB::table('business_configuration_work')->where('configuration_id', $configurationId)->lockForUpdate()->firstOrFail();
            if (! in_array($work->status, ['scheduled', 'propagation_pending', 'blocked'], true)) {
                throw new ConflictHttpException('An effective or already cancelled version cannot be cancelled.');
            }
            DB::table('business_configuration_work')->where('configuration_id', $configurationId)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->event('cancelled', $version, $current, $request, $reason);

            return ['configuration_id' => $configurationId, 'status' => 'cancelled'];
        });
    }

    /** @return array<string, mixed> */
    public function discard(User $actor, int $draftId, int $revision, string $operation): array
    {
        return $this->operation($actor, 'discard', $operation, compact('draftId', 'revision'), function (User $current) use ($draftId, $revision): array {
            $draft = $this->ownedDraft($current, $draftId);
            $this->expectVersion($revision, $draft->revision);
            $draft->update(['status' => 'discarded', 'preview' => null]);
            $this->draftAudit('draft_discarded', $draft, $current);

            return ['draft_id' => $draftId, 'status' => 'discarded'];
        });
    }

    /** @return array<string, mixed> */
    public function rollbackDraft(User $actor, int $configurationId, string $operation): array
    {
        return $this->operation($actor, 'rollback', $operation, compact('configurationId'), function (User $current) use ($configurationId): array {
            $previous = BusinessConfigurationVersion::query()->findOrFail($configurationId);
            $snapshot = $this->resolve(true);
            $patch = [];
            foreach ($this->catalogue->definitions() as $code => $definition) {
                if (($previous->values[$code] ?? null) !== ($snapshot['values'][$code] ?? null)) {
                    if (! $definition['editable']) {
                        throw new ConflictHttpException('This rollback includes a fixed or unavailable setting.');
                    }
                    $patch[$code] = $previous->values[$code];
                }
            }
            $normalized = $this->catalogue->validatePatch($patch, $snapshot['values']);
            $draft = BusinessConfigurationDraft::create(['business_profile_id' => BusinessProfile::current()->id,
                'actor_user_id' => $current->id, 'base_version' => $snapshot['version'], 'revision' => 1,
                'patch' => $normalized, 'status' => 'draft']);
            $this->draftAudit('draft_saved', $draft, $current);

            return ['draft_id' => $draft->id, 'revision' => $draft->revision];
        });
    }

    public function activate(int $configurationId): bool
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($configurationId): bool {
            $profile = $this->lockedProfile();
            $version = BusinessConfigurationVersion::query()->findOrFail($configurationId);
            $work = DB::table('business_configuration_work')->where('configuration_id', $configurationId)->lockForUpdate()->firstOrFail();
            if (! in_array($work->status, ['scheduled', 'propagation_pending', 'blocked'], true) || $version->requested_effective_at->isFuture()) {
                return false;
            }
            $failure = null;
            if ($version->base_version !== $profile->version) {
                $failure = 'stale_base';
            } elseif (! hash_equals($version->dependency_hash, $this->readiness->hash())) {
                $failure = 'dependency_changed';
            } else {
                foreach ($this->readiness->consumers($version->changed_codes) as $consumer) {
                    if (! $this->readiness->acknowledge($consumer, $version->dependency_hash)) {
                        $failure = 'consumer_unavailable';
                        break;
                    }
                    DB::table('business_configuration_acknowledgements')->insertOrIgnore(['configuration_id' => $version->id,
                        'consumer' => $consumer, 'dependency_hash' => $version->dependency_hash, 'created_at' => now()]);
                }
            }
            if ($failure !== null) {
                DB::table('business_configuration_work')->where('configuration_id', $configurationId)->update(['status' => 'blocked', 'failure_code' => $failure, 'updated_at' => now()]);
                if ($work->failure_code !== $failure) {
                    $this->event('activation_failed', $version, null);
                }

                return false;
            }
            $values = $version->values;
            if (! is_array($values) || ! hash_equals($version->values_hash, $this->catalogue->hash($values))) {
                throw new LogicException('Configuration integrity failed.');
            }
            $profileValues = array_intersect_key($values, array_flip(['display_name', 'legal_name', 'support_email', 'support_phone', 'address', 'timezone']));
            DB::table('business_profiles')->where('id', $profile->id)->update([...$profileValues, 'effective_configuration_id' => $version->id, 'version' => $version->version, 'updated_at' => now()]);
            if ($profile->getAttribute('effective_configuration_id') !== null) {
                DB::table('business_configuration_work')->where('configuration_id', $profile->getAttribute('effective_configuration_id'))->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            DB::table('business_configuration_work')->where('configuration_id', $configurationId)->update(['status' => 'effective', 'failure_code' => null, 'effective_at' => now(), 'updated_at' => now()]);
            $this->event('effective', $version, null);

            return true;
        }, attempts: 3);
    }

    public function drain(int $limit = 100): int
    {
        $ids = BusinessConfigurationVersion::query()->join('business_configuration_work as w', 'w.configuration_id', '=', 'business_configuration_versions.id')
            ->whereIn('w.status', ['scheduled', 'propagation_pending', 'blocked'])->where('requested_effective_at', '<=', now())->orderBy('version')
            ->limit(max(1, min(1000, $limit)))->pluck('business_configuration_versions.id');
        $count = 0;
        foreach ($ids as $id) {
            $count += (int) $this->activate((int) $id);
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function workspace(User $actor): array
    {
        $this->authorize($actor);
        $manager = $this->authorization->allows($actor, AdminPermission::BusinessSettingsManage);
        $snapshot = $this->resolve();
        $safeValues = $snapshot['values'];
        if (! $manager) {
            unset($safeValues['legal_name'], $safeValues['address']);
        }
        $versions = BusinessConfigurationVersion::query()->orderByDesc('version')->limit(50)->get();
        $workByVersion = DB::table('business_configuration_work')->whereIn('configuration_id', $versions->modelKeys())->get()->keyBy('configuration_id');
        $history = $versions->map(function (BusinessConfigurationVersion $version) use ($workByVersion): array {
            $work = $workByVersion->get($version->id);

            return ['id' => $version->id, 'version' => $version->version, 'base_version' => $version->base_version, 'state' => $work?->status,
                'effective_at' => $work?->effective_at, 'requested_effective_at' => $version->requested_effective_at->toIso8601String(),
                'actor' => $version->actor_label ?? 'System',
                'changed_codes' => $version->changed_codes, 'failure_code' => $work?->failure_code];
        })->all();
        $drafts = $manager ? BusinessConfigurationDraft::query()->where('actor_user_id', $actor->id)->where('status', 'draft')->orderByDesc('id')->limit(20)->get()
            ->map(fn (BusinessConfigurationDraft $draft): array => ['id' => $draft->id, 'revision' => $draft->revision, 'base_version' => $draft->base_version, 'patch' => $draft->patch, 'preview' => $draft->preview])->all() : [];

        return ['business_reference' => BusinessProfile::current()->business_id, 'version' => $snapshot['version'], 'initialized' => $snapshot['configuration_id'] !== null,
            'values' => $safeValues, 'definitions' => $this->catalogue->definitions(), 'can_manage' => $manager, 'readiness' => $this->readiness->checks(), 'history' => $history, 'drafts' => $drafts];
    }

    public function scope(User $actor): string
    {
        $this->authorize($actor);

        return hash('sha256', json_encode([$actor->id, $actor->permission_version, $this->authorization->allows($actor, AdminPermission::BusinessSettingsManage), BusinessProfile::current()->version], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function result(User $actor, string $operation): array
    {
        $this->authorize($actor, true);
        $record = DB::table('business_configuration_operations')->where('actor_user_id', $actor->id)->where('operation_id', $operation)->first();
        abort_if($record === null, 404);

        return json_decode(Crypt::decryptString($record->result), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload
     * @param  callable(User): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function operation(User $actor, string $action, string $operation, array $payload, callable $callback): array
    {
        if (! Str::isUuid($operation)) {
            throw ValidationException::withMessages(['operation_id' => 'Use a UUID operation reference.']);
        }

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $action, $operation, $payload, $callback): array {
            $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($current, true);
            $profile = $this->lockedProfile();
            $hash = $this->catalogue->hash(['action' => $action, 'payload' => $payload]);
            $record = DB::table('business_configuration_operations')->where('actor_user_id', $current->id)->where('operation_id', $operation)->first();
            if ($record !== null) {
                if (! hash_equals($record->input_hash, $hash)) {
                    throw new ConflictHttpException('This operation reference was already used for different input.');
                }

                return json_decode(Crypt::decryptString($record->result), true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $callback($current);
            DB::table('business_configuration_operations')->insert(['actor_user_id' => $current->id, 'business_profile_id' => $profile->id,
                'operation_id' => $operation, 'action' => $action, 'input_hash' => $hash,
                'result' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }

    private function lockedProfile(): BusinessProfile
    {
        $profiles = BusinessProfile::query()->lockForUpdate()->get();
        if ($profiles->count() !== 1) {
            throw new LogicException('Exactly one trusted business profile is required.');
        }

        return $profiles->sole();
    }

    private function effectiveVersion(BusinessProfile $profile): BusinessConfigurationVersion
    {
        $version = BusinessConfigurationVersion::query()->where('business_profile_id', $profile->id)->where('id', $profile->getAttribute('effective_configuration_id'))->firstOrFail();
        if ($version->version !== $profile->version) {
            throw new LogicException('Effective configuration pointer is inconsistent.');
        }

        return $version;
    }

    private function ownedDraft(User $actor, int $id): BusinessConfigurationDraft
    {
        return BusinessConfigurationDraft::query()->where('actor_user_id', $actor->id)->where('status', 'draft')->lockForUpdate()->findOrFail($id);
    }

    private function expectVersion(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new ConflictHttpException('The configuration or draft changed. Refresh and preview again.');
        }
    }

    private function requireFresh(User $actor, Request $request): void
    {
        if (! $this->freshAuthentication->isFresh($actor, $request)) {
            throw new ConflictHttpException('Fresh password and authenticator confirmation is required.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || preg_match('/[\p{C}<>]/u', $reason)) {
            throw ValidationException::withMessages(['reason' => 'Enter a plain-text reason of 1–500 characters.']);
        }

        return $reason;
    }

    private function draftAudit(string $action, BusinessConfigurationDraft $draft, User $actor): void
    {
        AuditEvent::record('business_settings.'.$action, BusinessConfigurationDraft::class, $draft->id, null,
            ['version' => $draft->revision, 'base_version' => $draft->base_version, 'changed_fields' => array_keys($draft->patch ?? [])], $actor,
            ['executor' => self::class, 'required_permission' => AdminPermission::BusinessSettingsManage->value]);
    }

    private function event(string $action, BusinessConfigurationVersion $version, ?User $actor, ?Request $request = null, ?string $reason = null): void
    {
        $audit = AuditEvent::record('business_settings.'.$action, BusinessConfigurationVersion::class, $version->id, 'CFG-'.$version->version,
            ['version' => $version->version, 'base_version' => $version->base_version, 'changed_fields' => $version->changed_codes,
                'reason' => $reason ?? $version->reason], $actor, ['executor' => self::class,
                    'required_permission' => $actor === null ? null : AdminPermission::BusinessSettingsManage->value,
                    'fresh_authentication' => $request === null ? null : hash_hmac('sha256', json_encode([$request->session()->getId(), $request->session()->get('auth.password_confirmed_at'), $request->session()->get('auth.mfa_confirmed_at')], JSON_THROW_ON_ERROR), (string) config('app.key'))]);
        $event = BusinessConfigurationEvent::create(['configuration_id' => $version->id, 'version' => $version->version,
            'event_type' => $action, 'actor_user_id' => $actor?->id, 'audit_event_id' => $audit->id, 'changed_codes' => $version->changed_codes]);
        if ($action === 'imported') {
            return;
        }
        foreach (User::query()->where('user_type', UserType::Admin->value)->where('account_state', AccountState::Active->value)->cursor() as $recipient) {
            if (! $this->authorization->allows($recipient, AdminPermission::BusinessSettingsManage)) {
                continue;
            }
            $id = DB::table('business_settings_notification_intents')->insertGetId(['business_configuration_event_id' => $event->id,
                'recipient_user_id' => $recipient->id, 'notification_id' => (string) Str::uuid(), 'audience_type' => 'settings_manager',
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->notifications->capture('business_settings', $id);
        }
    }
}
