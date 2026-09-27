<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\Invitation;
use App\Models\User;
use App\Support\IdentityNormalizer;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CustomerRecoveryService
{
    public function __construct(private CustomerActionAuthorizationGuard $guard, private AuthorizationService $authorization,
        private CustomerHandoverNotifications $notices, private EmailReservationService $emails) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, CustomerProfile $customer, string $action, array $input, ?string $reference = null): array
    {
        abort_unless(in_array($action, ['request', 'verify', 'approve', 'reject', 'cancel', 'reissue'], true), 404);
        $data = $this->validate($action, $input);
        $hash = hash('sha256', json_encode([$actor->id, $customer->id, $action, $reference, $data], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $customer, $action, $data, $reference, $hash): array {
            $context = $this->guard->lockAndAuthorize($actor, $customer->id, 'view');
            $actor = $context->actor;
            $customer = $context->customerProfile;
            $securityAdmin = $this->authorization->allows($actor, AdminPermission::SecurityOperationsManage);
            if (in_array($action, ['request', 'verify'], true)) {
                abort_unless($actor->user_type === UserType::Agent && app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($actor), 403);
            } elseif ($action !== 'cancel' || $actor->user_type !== UserType::Agent) {
                $this->requireReviewer($actor);
            }
            $existing = DB::table('customer_handover_operations')->where('attempt_reference', $data['attempt_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->actor_user_id !== $actor->id || $existing->customer_profile_id !== $customer->id || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This reference belongs to different recovery input.');
                }

                $result = json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
                ksort($result);

                return $result;
            }
            $user = User::query()->whereKey($customer->user_id)->lockForUpdate()->firstOrFail();
            if ($user->user_type !== UserType::Customer || (! in_array($action, ['reject', 'cancel'], true) && ($user->account_state !== AccountState::Active || $user->email_verified_at === null))) {
                throw new ConflictHttpException('Recovery cannot activate an invited, suspended or deactivated account.');
            }
            if (in_array($action, ['request', 'verify'], true)) {
                Validator::make($data, ['verified_at' => ['before_or_equal:now', 'after_or_equal:'.now()->subDay()->toIso8601String()]])->validate();
                if ($customer->version !== (int) $data['version'] || $context->currentAssignment?->version !== (int) $data['assignment_version']) {
                    throw new ConflictHttpException('Customer assignment changed. Refresh and verify again.');
                }
            }
            if ($action === 'request') {
                $this->expireForCustomer($customer);
                if (CustomerRecovery::query()->where('open_customer_id', $customer->id)->exists()) {
                    throw new ConflictHttpException('Resolve the existing recovery first.');
                }
                $this->limit($customer, 'auth.customer_recovery_requested', 3);
                $normalized = IdentityNormalizer::normalizeEmail($data['email']);
                if ($normalized === $user->email_normalized) {
                    throw new ConflictHttpException('Use a different email address.');
                }
                $this->emails->assertAvailable($data['email']);
                $recovery = CustomerRecovery::create(['reference' => (string) Str::uuid(),
                    'customer_profile_id' => $customer->id, 'requested_by_user_id' => $actor->id,
                    'verified_assignment_id' => $context->currentAssignment->id, 'open_customer_id' => $customer->id,
                    'state' => 'awaiting_approval', 'version' => 1, 'previous_email' => $user->email,
                    'proposed_email' => $data['email'], 'proposed_email_normalized' => $normalized, 'request_expires_at' => now()->addDays(7)]);
                $this->event($customer, $actor, $recovery, 'requested', $this->verification($data, $context->currentAssignment->id));
            } else {
                $recovery = CustomerRecovery::query()->where('reference', $reference)->where('customer_profile_id', $customer->id)->lockForUpdate()->firstOrFail();
                if ($recovery->version !== (int) $data['recovery_version']) {
                    throw new ConflictHttpException('The recovery changed. Refresh and review again.');
                }
                $unapproved = in_array($recovery->state, ['verification_required', 'awaiting_approval'], true);
                if ($unapproved && $recovery->request_expires_at->lte(now())) {
                    throw new ConflictHttpException('The recovery request expired.');
                }
                if ($action === 'verify') {
                    abort_unless($unapproved, 409);
                    $recovery->forceFill(['verified_assignment_id' => $context->currentAssignment->id, 'state' => 'awaiting_approval', 'version' => $recovery->version + 1])->save();
                    $this->event($customer, $actor, $recovery, 'verified', $this->verification($data, $context->currentAssignment->id));
                } elseif ($action === 'approve') {
                    if ($recovery->state !== 'awaiting_approval' || $recovery->verified_assignment_id !== $context->currentAssignment?->id
                        || $actor->id === $recovery->requested_by_user_id || ! hash_equals($recovery->previous_email, $user->email)) {
                        throw new ConflictHttpException('Current-Agent verification and a separate reviewer are required.');
                    }
                    $this->emails->assertAvailable($recovery->proposed_email, $user->id);
                    $user->forceFill(['password' => null, 'remember_token' => Str::random(60), 'recovery_pending' => true,
                        'lifecycle_access_version' => $user->lifecycle_access_version + 1, 'two_factor_secret' => null,
                        'two_factor_confirmed_at' => null, 'two_factor_pending_secret' => null, 'two_factor_pending_purpose' => null,
                        'two_factor_pending_expires_at' => null, 'two_factor_last_used_timestep' => null,
                        'two_factor_pending_last_used_timestep' => null, 'recovery_codes_acknowledged_at' => null])->save();
                    $user->revokeAllSessions();
                    $user->revokeAllTrustedDevices();
                    DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->delete();
                    DB::table('password_reset_tokens')->whereIn('email', [$user->email, $user->email_normalized])->delete();
                    DB::table('pending_email_changes')->where('user_id', $user->id)->delete();
                    foreach (Invitation::query()->where('user_id', $user->id)->whereIn('status', ['pending_delivery', 'sent', 'opened', 'delivery_failed'])->get() as $invitation) {
                        $invitation->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id,
                            'cancellation_reason' => 'Approved Customer recovery'])->save();
                    }
                    $recovery->forceFill(['approved_by_user_id' => $actor->id, 'approved_at' => now()]);
                    $this->issue($customer, $actor, $user, $recovery, 'approved', $data['reason']);
                } elseif ($action === 'reissue') {
                    if (! in_array($recovery->state, ['awaiting_activation', 'activation_expired'], true) || ! $user->recovery_pending
                        || $recovery->approved_at === null || ! hash_equals($recovery->previous_email, $user->email)) {
                        throw new ConflictHttpException('An unchanged approved recovery is required.');
                    }
                    $this->limit($customer, 'auth.customer_recovery_reissued', 5);
                    $this->emails->assertAvailable($recovery->proposed_email, $user->id);
                    $this->issue($customer, $actor, $user, $recovery, 'reissued', $data['reason']);
                } else {
                    if (! $unapproved && ! ($action === 'cancel' && $securityAdmin && in_array($recovery->state, ['awaiting_activation', 'activation_expired'], true))) {
                        throw new ConflictHttpException('This recovery decision is unavailable.');
                    }
                    if ($actor->user_type === UserType::Agent) {
                        abort_unless(app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($actor), 403);
                    }
                    $recovery->forceFill(['state' => $action === 'reject' ? 'rejected' : 'cancelled', 'version' => $recovery->version + 1,
                        'open_customer_id' => null, 'proposed_email_normalized' => null, 'activation_token_hash' => null, 'activation_expires_at' => null])->save();
                    $this->event($customer, $actor, $recovery, $action === 'reject' ? 'rejected' : 'cancelled', ['reason' => $data['reason']]);
                }
            }
            $result = ['reference' => $recovery->reference, 'version' => $recovery->version, 'state' => $recovery->state, 'attempt_reference' => $data['attempt_reference']];
            ksort($result);
            DB::table('customer_handover_operations')->insert(['attempt_reference' => $data['attempt_reference'], 'actor_user_id' => $actor->id,
                'customer_profile_id' => $customer->id, 'action' => 'recovery_'.$action, 'payload_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }

    public function handover(CustomerProfile $customer, User $actor): void
    {
        foreach (CustomerRecovery::query()->where('customer_profile_id', $customer->id)->whereIn('state', ['awaiting_approval', 'verification_required'])->lockForUpdate()->get() as $recovery) {
            $recovery->forceFill(['state' => 'verification_required', 'verified_assignment_id' => null, 'version' => $recovery->version + 1])->save();
            $this->event($customer, $actor, $recovery, 'handover', ['assignment_id' => $customer->currentAssignment->id]);
        }
    }

    /** @param array<string, mixed> $input */
    public function activate(string $reference, #[\SensitiveParameter] array $input): void
    {
        $data = Validator::make($input, ['token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', PasswordPolicy::ruleForUserType(UserType::Customer)]])->validate();
        app(PlatformGuard::class)->transaction('mutation', function () use ($reference, $data): void {
            $found = CustomerRecovery::query()->where('reference', $reference)->first();
            if ($found === null) {
                $this->invalidChallenge();
            }
            $customer = CustomerProfile::query()->whereKey($found->customer_profile_id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($customer->user_id)->lockForUpdate()->firstOrFail();
            $recovery = CustomerRecovery::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($recovery->state !== 'awaiting_activation' || $recovery->activation_expires_at?->lte(now()) !== false
                || $recovery->activation_token_hash === null || ! hash_equals($recovery->activation_token_hash, hash('sha256', $data['token']))
                || $user->account_state !== AccountState::Active || ! $user->recovery_pending || ! hash_equals($recovery->previous_email, $user->email)) {
                $this->invalidChallenge();
            }
            $this->emails->assertAvailable($recovery->proposed_email, $user->id);
            $user->forceFill(['email' => $recovery->proposed_email, 'email_verified_at' => now(), 'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60), 'recovery_pending' => false, 'lifecycle_access_version' => $user->lifecycle_access_version + 1])->save();
            $user->revokeAllSessions();
            DB::afterCommit(static fn () => app(AuthenticationAbuseService::class)->clearRecoveryActivationFailures($user, $recovery->previous_email));
            $recovery->forceFill(['state' => 'completed', 'version' => $recovery->version + 1, 'completed_at' => now(),
                'activation_token_hash' => null, 'activation_expires_at' => null, 'open_customer_id' => null, 'proposed_email_normalized' => null])->save();
            $this->event($customer, null, $recovery, 'completed', []);
        }, attempts: 3);
    }

    public function expireForCustomer(CustomerProfile $customer): void
    {
        foreach (CustomerRecovery::query()->where('customer_profile_id', $customer->id)->whereNotNull('open_customer_id')->lockForUpdate()->get() as $recovery) {
            if (in_array($recovery->state, ['verification_required', 'awaiting_approval'], true) && $recovery->request_expires_at->lte(now())) {
                $recovery->forceFill(['state' => 'expired', 'open_customer_id' => null, 'proposed_email_normalized' => null, 'version' => $recovery->version + 1])->save();
                $this->event($customer, null, $recovery, 'expired', []);
            } elseif ($recovery->state === 'awaiting_activation' && $recovery->activation_expires_at?->lte(now())) {
                $recovery->forceFill(['state' => 'activation_expired', 'activation_token_hash' => null, 'version' => $recovery->version + 1])->save();
                $this->event($customer, null, $recovery, 'expired', []);
            }
        }
    }

    /** @return array<string, mixed> */
    public function lookup(User $actor, CustomerProfile $customer, string $reference): array
    {
        $this->authorizeView($actor, $customer);
        $operation = DB::table('customer_handover_operations')->where('attempt_reference', $reference)
            ->where('actor_user_id', $actor->id)->where('customer_profile_id', $customer->id)->where('action', 'like', 'recovery_%')->first();
        abort_if($operation === null, 404, 'Operation unavailable.');

        $result = json_decode($operation->result, true, flags: JSON_THROW_ON_ERROR);
        ksort($result);

        return $result;
    }

    public function authorizeView(User $actor, CustomerProfile $customer): void
    {
        abort_unless(($actor->user_type === UserType::Admin && $this->authorization->allows($actor, AdminPermission::SecurityOperationsManage))
            || ($actor->user_type === UserType::Agent && app(AgentEligibilityService::class)->canReadAssignedCustomers($actor)
                && $customer->currentAssignment?->agentProfile?->user_id === $actor->id), 404);
    }

    private function requireReviewer(User $actor): void
    {
        abort_unless($this->authorization->allows($actor, AdminPermission::SecurityOperationsManage), 403);
        $request = request();
        abort_unless($request->hasSession() && $request->user()?->id === $actor->id
            && app(FreshAuthenticationService::class)->isFresh($actor, $request), 403, 'Fresh security authentication is required.');
    }

    private function issue(CustomerProfile $customer, User $actor, User $user, CustomerRecovery $recovery, string $action, string $reason): void
    {
        $token = Str::random(64);
        $recovery->forceFill(['state' => 'awaiting_activation', 'version' => $recovery->version + 1,
            'activation_token_hash' => hash('sha256', $token), 'activation_expires_at' => now()->addMinutes(30)])->save();
        $eventId = $this->event($customer, $actor, $recovery, $action, ['reason' => $reason]);
        $this->notices->notice($eventId, $user, 'recovery_address', 'mail', 'recovery_activation', $customer,
            ['title' => 'Activate your recovered account', 'message' => 'An authorized recovery was approved. Verify your email and choose your own password within 30 minutes.',
                'email' => $recovery->proposed_email, 'recovery_reference' => $recovery->reference,
                'token_hash' => $recovery->activation_token_hash, 'token' => $token, 'url' => route('customer-recovery.activation', $recovery->reference)]);
    }

    /** @param array<string, mixed> $details */
    private function event(CustomerProfile $customer, ?User $actor, CustomerRecovery $recovery, string $action, array $details): int
    {
        $eventId = $this->notices->event($customer, $actor, 'auth.customer_recovery_'.$action, $recovery->version,
            ['recovery_reference' => $recovery->reference, 'state' => $recovery->state, ...$details], $recovery->id);
        $message = 'Customer account recovery is '.str_replace('_', ' ', $recovery->state).'. Review the authorized recovery workflow for next steps.';
        $agent = $customer->fresh()->currentAssignment?->agentProfile?->user;
        if ($agent !== null) {
            $this->notices->notice($eventId, $agent, 'current_agent', 'database', 'recovery_update', $customer,
                ['title' => 'Customer recovery updated', 'message' => $message]);
        }
        foreach (User::query()->where('user_type', 'admin')->where('account_state', 'active')->get() as $admin) {
            if ($this->authorization->allows($admin, AdminPermission::SecurityOperationsManage) && $admin->id !== $actor?->id) {
                $this->notices->notice($eventId, $admin, 'security_operations_admin', 'database', 'recovery_update', $customer,
                    ['title' => 'Customer recovery updated', 'message' => 'A Customer recovery requires authorized review.']);
            }
        }
        if (in_array($action, ['requested', 'approved', 'reissued', 'rejected', 'completed', 'cancelled', 'expired'], true)) {
            $this->notices->notice($eventId, $customer->user, 'recovery_address', 'mail', 'recovery_security', $customer,
                ['title' => 'Account recovery update', 'message' => $message.' Contact the business if you did not request this.',
                    'email' => $recovery->previous_email, 'recovery_reference' => $recovery->reference]);
            if (in_array($action, ['requested', 'rejected', 'cancelled', 'expired'], true)) {
                $this->notices->notice($eventId, $customer->user, 'recovery_address', 'mail', 'recovery_proposed_security', $customer,
                    ['title' => 'Account recovery update', 'message' => $message.' Contact the business if you did not request this.',
                        'email' => $recovery->proposed_email, 'recovery_reference' => $recovery->reference]);
            }
            if ($action === 'completed') {
                $this->notices->notice($eventId, $customer->user, 'recovery_address', 'mail', 'recovery_completion', $customer,
                    ['title' => 'Account recovery complete', 'message' => 'Your account email and password were recovered. Sign in to continue.',
                        'email' => $recovery->proposed_email, 'recovery_reference' => $recovery->reference]);
            }
        }

        return $eventId;
    }

    private function limit(CustomerProfile $customer, string $eventType, int $daily): void
    {
        $events = DB::table('customer_handover_events')->where('customer_profile_id', $customer->id)->where('event_type', $eventType);
        if ((clone $events)->where('effective_at', '>', now()->subMinute())->exists()
            || (clone $events)->where('effective_at', '>', now()->subDay())->count() >= $daily) {
            throw new ConflictHttpException('Recovery rate limit reached. Try again later.');
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function verification(array $data, int $assignmentId): array
    {
        return ['in_person' => true, 'record_compared' => true, 'verified_at' => $data['verified_at'],
            'procedure_reference' => $data['procedure_reference'], 'notes' => $data['notes'], 'assignment_id' => $assignmentId];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(string $action, array $input): array
    {
        foreach (['reason', 'email', 'notes', 'procedure_reference'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }
        $rules = ['attempt_reference' => ['required', 'uuid'], 'confirmed' => ['accepted']];
        if (in_array($action, ['request', 'verify'], true)) {
            $rules += ['version' => ['required', 'integer', 'min:1'], 'assignment_version' => ['required', 'integer', 'min:1'],
                'in_person' => ['accepted'], 'record_compared' => ['accepted'], 'verified_at' => ['required', 'date'],
                'procedure_reference' => ['required', 'string', 'max:150'], 'notes' => ['required', 'string', 'max:2000']];
        }
        if ($action === 'request') {
            $rules['email'] = ['required', 'email:rfc', 'max:255'];
        } else {
            $rules += ['recovery_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']];
        }

        return Validator::make($input, $rules)->validate();
    }

    private function invalidChallenge(): never
    {
        throw ValidationException::withMessages(['token' => ['This recovery link is unavailable or expired. Contact your service Agent.']]);
    }
}
