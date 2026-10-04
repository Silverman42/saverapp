<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerPayoutDestination;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use App\Support\PayoutProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The verified Customer-owned bank destination. The assigned Agent registers it with the Customer's attestation,
 * the provider's name enquiry is compared with the Customer's name, and an Admin verifies it.
 * The account number is resolved to a provider token and never stored, logged or audited.
 */
class BankPayoutDestinationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private PayoutProvider $provider,
    ) {}

    /** @param array{bank_code: string, account_number: string, attestation: string, registration_reference: string} $data */
    public function register(User $agent, CustomerProfile $customer, array $data): CustomerPayoutDestination
    {
        Gate::forUser($agent)->authorize('initiateWithdrawal', $customer);
        $resolution = $this->provider->resolveAccount($data['bank_code'], $data['account_number']);
        $attestation = trim($data['attestation']);
        $hash = hash('sha256', json_encode([$agent->id, $customer->id, $data['bank_code'], $resolution->fingerprint, $attestation], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('mutation', function () use ($agent, $customer, $data, $resolution, $attestation, $hash): CustomerPayoutDestination {
            $existing = CustomerPayoutDestination::query()->where('registration_reference', $data['registration_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash) || $existing->registered_by_user_id !== $agent->id) {
                    throw new ConflictHttpException('This registration reference belongs to a different destination.');
                }
                Gate::forUser($agent)->authorize('initiateWithdrawal', $customer);

                return $existing;
            }
            $locked = CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($agent)->authorize('initiateWithdrawal', $locked);
            if (CustomerPayoutDestination::query()->where('customer_profile_id', $locked->id)->where('status', 'pending_verification')->exists()) {
                throw new ConflictHttpException('A destination already awaits verification for this Customer.');
            }
            $other = CustomerPayoutDestination::query()->where('account_fingerprint', $resolution->fingerprint)
                ->where('customer_profile_id', '!=', $locked->id)->whereIn('status', ['pending_verification', 'verified'])->exists();
            if ($other) {
                throw new ConflictHttpException('This account is already registered to another Customer.');
            }
            $version = (int) CustomerPayoutDestination::query()->where('customer_profile_id', $locked->id)->max('version') + 1;
            $destination = CustomerPayoutDestination::create([
                'destination_reference' => (string) Str::uuid(), 'registration_reference' => $data['registration_reference'],
                'customer_profile_id' => $locked->id, 'version' => $version, 'status' => 'pending_verification',
                'provider_key' => $this->provider->key(), 'bank_code' => $data['bank_code'], 'bank_name' => $resolution->bankName,
                'account_token' => $resolution->token, 'account_fingerprint' => $resolution->fingerprint,
                'account_mask' => '******'.$resolution->lastFour, 'verified_payee_name' => $resolution->accountName,
                'name_match' => $this->nameMatch($resolution->accountName, (string) $locked->user?->name),
                'registration_attestation' => $attestation, 'registered_by_user_id' => $agent->id, 'payload_hash' => $hash,
            ]);
            $this->audit($destination, 'destination_registered', $agent);

            return $destination;
        }, attempts: 3);
    }

    public function verify(User $admin, CustomerPayoutDestination $destination, string $note, Request $request): CustomerPayoutDestination
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $destination, $note, $request): CustomerPayoutDestination {
            [$admin, $customer, $locked] = $this->lock($admin, $destination, $request);
            if ($locked->status === 'verified') {
                return $locked;
            }
            if ($locked->status !== 'pending_verification') {
                throw new ConflictHttpException('Only a destination awaiting verification can be verified.');
            }
            if ($locked->name_match === 'mismatch') {
                throw new ConflictHttpException('The provider name does not match the Customer. Reject this destination.');
            }
            $current = CustomerPayoutDestination::query()->where('customer_profile_id', $customer->id)->where('status', 'verified')->lockForUpdate()->first();
            if ($current !== null) {
                if (WithdrawalRequest::query()->where('customer_payout_destination_id', $current->id)
                    ->whereIn('state', ['pending_review', 'approved', 'payment_failed', 'payout_processing', 'outcome_unknown'])->exists()) {
                    throw new ConflictHttpException('Cancel, reject or revoke requests that use the current destination before replacing it.');
                }
                $current->forceFill(['status' => 'superseded', 'active_customer_profile_id' => null, 'superseded_at' => now()])->save();
            }
            $locked->forceFill(['status' => 'verified', 'active_customer_profile_id' => $customer->id, 'verified_by_user_id' => $admin->id,
                'verified_at' => now(), 'decision_reason' => trim($note)])->save();
            $this->audit($locked, 'destination_verified', $admin);

            return $locked;
        }, attempts: 3);
    }

    public function reject(User $admin, CustomerPayoutDestination $destination, string $reason, Request $request): CustomerPayoutDestination
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $destination, $reason, $request): CustomerPayoutDestination {
            [$admin, , $locked] = $this->lock($admin, $destination, $request);
            if ($locked->status === 'rejected') {
                return $locked;
            }
            if ($locked->status !== 'pending_verification') {
                throw new ConflictHttpException('Only a destination awaiting verification can be rejected.');
            }
            $locked->forceFill(['status' => 'rejected', 'revoked_by_user_id' => $admin->id, 'revoked_at' => now(), 'decision_reason' => trim($reason)])->save();
            $this->audit($locked, 'destination_rejected', $admin);

            return $locked;
        }, attempts: 3);
    }

    /**
     * Revocation (for fraud or a closed account) invalidates the destination immediately. Live requests that use it are held
     * with a non-pausing reason so they can still expire safely; a payout already in flight must be resolved first.
     */
    public function revoke(User $admin, CustomerPayoutDestination $destination, string $reason, Request $request): CustomerPayoutDestination
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($admin, $destination, $reason, $request): CustomerPayoutDestination {
            [$admin, , $locked] = $this->lock($admin, $destination, $request);
            if ($locked->status === 'revoked') {
                return $locked;
            }
            if ($locked->status !== 'verified') {
                throw new ConflictHttpException('Only a verified destination can be revoked.');
            }
            $requests = WithdrawalRequest::query()->where('customer_payout_destination_id', $locked->id)
                ->whereIn('state', ['pending_review', 'approved', 'payment_failed', 'payout_processing', 'outcome_unknown'])->lockForUpdate()->get();
            if ($requests->contains(fn (WithdrawalRequest $withdrawal): bool => in_array($withdrawal->state, ['payout_processing', 'outcome_unknown'], true))) {
                throw new ConflictHttpException('A payout using this destination is in flight. Resolve it before revoking the destination.');
            }
            $locked->forceFill(['status' => 'revoked', 'active_customer_profile_id' => null, 'revoked_by_user_id' => $admin->id,
                'revoked_at' => now(), 'decision_reason' => trim($reason)])->save();
            foreach ($requests as $withdrawal) {
                if ($withdrawal->held) {
                    continue;
                }
                $withdrawal->held = true;
                $withdrawal->hold_reason = 'destination_invalid';
                $withdrawal->held_at = now();
                $withdrawal->version++;
                $withdrawal->save();
                $event = WithdrawalEvent::create(['withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $admin->id,
                    'event_type' => 'hold_applied', 'from_state' => $withdrawal->state, 'to_state' => $withdrawal->state,
                    'request_version' => $withdrawal->version, 'effective_at' => now()]);
                AuditEvent::record('withdrawal.hold_applied', WithdrawalRequest::class, $withdrawal->id, $withdrawal->withdrawal_id,
                    ['state' => $withdrawal->state, 'version' => $withdrawal->version, 'customer_profile_id' => $withdrawal->customer_profile_id,
                        'destination_reference' => $locked->destination_reference], $admin, context: ['executor' => self::class]);
                app(WithdrawalNoticeService::class)->queue($withdrawal, $event);
            }
            $this->audit($locked, 'destination_revoked', $admin);

            return $locked;
        }, attempts: 3);
    }

    /** @return array{User, CustomerProfile, CustomerPayoutDestination} */
    private function lock(User $admin, CustomerPayoutDestination $destination, Request $request): array
    {
        $admin = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($admin, AdminPermission::CustomersManage) || ! $this->freshAuthentication->isFresh($admin, $request)) {
            throw new AuthorizationException('Fresh Customer-management authority is required.');
        }
        $customer = CustomerProfile::query()->whereKey($destination->customer_profile_id)->lockForUpdate()->firstOrFail();
        Gate::forUser($admin)->authorize('view', $customer);

        return [$admin, $customer, CustomerPayoutDestination::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail()];
    }

    private function audit(CustomerPayoutDestination $destination, string $event, User $actor): void
    {
        AuditEvent::record('withdrawal.'.$event, CustomerPayoutDestination::class, $destination->id, $destination->destination_reference,
            ['customer_profile_id' => $destination->customer_profile_id, 'destination_reference' => $destination->destination_reference,
                'destination_version' => $destination->version, 'status' => $destination->status, 'name_match' => $destination->name_match,
                'account_mask' => $destination->account_mask], $actor,
            context: ['executor' => self::class, 'required_permission' => $actor->user_type === UserType::Admin ? 'customers.manage' : null]);
    }

    /** Compares the provider's verified payee name with the Customer's registered name. */
    public function nameMatch(string $payee, string $customer): string
    {
        $tokens = fn (string $name): array => array_values(array_unique(array_filter(preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii($name))) ?: [])));
        $left = $tokens($payee);
        $right = $tokens($customer);
        if ($left === [] || $right === []) {
            return 'mismatch';
        }
        $shared = count(array_intersect($left, $right));
        if ($shared === count($left) && $shared === count($right)) {
            return 'exact';
        }

        return $shared >= 2 || ($shared >= 1 && min(count($left), count($right)) === 1) ? 'partial' : 'mismatch';
    }
}
