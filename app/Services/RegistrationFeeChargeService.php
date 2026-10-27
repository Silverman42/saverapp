<?php

namespace App\Services;

use App\Enums\CreationAttemptStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CreationAttempt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Raises a registration fee again for an existing Customer.
 *
 * Each charge gets its own immutable snapshot and obligation with source "{customer id}:{n}",
 * priced from the current published registration fee rule.
 */
class RegistrationFeeChargeService
{
    public const string OPERATION = 'registration_fee_charge';

    public function __construct(
        private RegistrationFeeService $registrationFees,
        private FeeObligationService $feeObligations,
        private RegistrationFeePaymentService $payments,
    ) {}

    /**
     * @param  array{fee_rule_version: int|string, registration_fee_payment?: string|null, registration_fee_amount_paid?: string|null, reason: string}  $data
     * @return array{obligation: FeeObligation, replayed: bool}
     */
    public function charge(User $actor, CustomerProfile $customer, string $attemptReference, array $data): array
    {
        $paymentOption = (string) ($data['registration_fee_payment'] ?? RegistrationFeePaymentService::PENDING);
        $fingerprint = hash('sha256', json_encode([
            'customer' => $customer->id,
            'fee_rule_version' => (int) $data['fee_rule_version'],
            'payment' => $paymentOption,
            'amount_paid' => filled($data['registration_fee_amount_paid'] ?? null) ? trim((string) $data['registration_fee_amount_paid']) : null,
            'reason' => trim($data['reason']),
        ], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $attemptReference, $data, $paymentOption, $fingerprint): array {
            $existing = CreationAttempt::query()->where('attempt_reference', $attemptReference)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->user_id !== $actor->id || $existing->operation_type !== self::OPERATION || $existing->hasConflictWith($fingerprint)) {
                    throw new ConflictHttpException('This registration fee request conflicts with an earlier request.');
                }
                if ($existing->isCommitted()) {
                    return ['obligation' => FeeObligation::query()->findOrFail($existing->record_id), 'replayed' => true];
                }
            }

            /** @var CustomerProfile $lockedCustomer */
            $lockedCustomer = CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('chargeRegistrationFee', $lockedCustomer);

            if ($lockedCustomer->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Restore the Archived Customer before charging a registration fee.');
            }

            if ($paymentOption !== RegistrationFeePaymentService::PENDING && $actor->user_type !== UserType::Agent) {
                throw ValidationException::withMessages([
                    'registration_fee_payment' => ['Only the assigned Agent can record a registration fee payment. Choose "Pending".'],
                ]);
            }

            $rule = $this->registrationFees->getCurrentRule(forUpdate: true)
                ?? throw new ConflictHttpException('No published registration fee rule is available.');
            if ($rule->version !== (int) $data['fee_rule_version']) {
                throw new ConflictHttpException('Registration fee configuration has changed. Please review the current fee.');
            }

            $sequence = $lockedCustomer->registrationFeeSnapshots()->count() + 1;
            $sourceId = $lockedCustomer->id.':'.$sequence;
            $quote = $this->feeObligations->quote(rule: $rule, basisKobo: 0, sourceType: 'registration', sourceId: $sourceId);
            if ($quote->amountKobo === 0) {
                throw ValidationException::withMessages([
                    'registration_fee_payment' => ['The current registration fee is zero, so there is nothing to charge.'],
                ]);
            }

            $amountCollectedKobo = $this->payments->amountCollectedKobo(
                $paymentOption,
                $data['registration_fee_amount_paid'] ?? null,
                $quote->amountKobo,
            );

            $attempt = $existing ?? new CreationAttempt([
                'attempt_reference' => $attemptReference,
                'user_id' => $actor->id,
                'business_id' => BusinessProfile::current()->business_id,
                'operation_type' => self::OPERATION,
                'payload_fingerprint' => $fingerprint,
            ]);
            $attempt->status = CreationAttemptStatus::InProgress;
            $attempt->save();

            $snapshot = FeeSnapshot::create([
                'customer_profile_id' => $lockedCustomer->id,
                'fee_rule_id' => $rule->id,
                'fee_rule_version' => $rule->version,
                'name' => $rule->name,
                'kind' => 'registration',
                'model' => $rule->model->value,
                'timing' => $rule->timing->value,
                'basis' => $rule->basis->value,
                'settlement_source' => $rule->settlement_source->value,
                'currency' => $rule->currency,
                'amount_kobo' => $quote->amountKobo,
                'basis_points' => $rule->basis_points,
                'basis_amount_kobo' => $quote->basisKobo,
                'customer_description' => $rule->customer_description,
                'source_type' => 'registration',
                'source_id' => $sourceId,
                'acknowledged_at' => null,
            ]);

            $obligation = $this->feeObligations->assessSnapshot($snapshot, $actor)
                ?? throw new ConflictHttpException('The registration fee could not be assessed.');

            $this->payments->collect($actor, $lockedCustomer, $obligation, $amountCollectedKobo, $attemptReference);

            $attempt->status = CreationAttemptStatus::Committed;
            $attempt->record_type = FeeObligation::class;
            $attempt->record_id = $obligation->id;
            $attempt->result_summary = [
                'customer_id' => $lockedCustomer->customer_id,
                'source_id' => $sourceId,
                'fee_amount_kobo' => $quote->amountKobo,
                'fee_payment' => $paymentOption,
                'fee_amount_collected_kobo' => $amountCollectedKobo,
            ];
            $attempt->save();

            AuditEvent::record(
                eventType: 'customer.registration_fee_charged',
                targetType: CustomerProfile::class,
                targetId: $lockedCustomer->id,
                targetReference: $lockedCustomer->customer_id,
                payload: [
                    'fee_obligation_id' => $obligation->id,
                    'source_id' => $sourceId,
                    'fee_version' => $rule->version,
                    'fee_amount_kobo' => $quote->amountKobo,
                    'fee_payment' => $paymentOption,
                    'fee_amount_collected_kobo' => $amountCollectedKobo,
                    'reason' => trim($data['reason']),
                ],
                actor: $actor,
                context: ['executor' => self::class],
            );

            return ['obligation' => $obligation, 'replayed' => false];
        }, attempts: 3);
    }
}
