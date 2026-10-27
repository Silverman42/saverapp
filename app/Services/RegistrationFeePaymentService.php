<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Records the registration-fee payment an Agent collects when a registration fee is raised.
 *
 * The payment is an ordinary fee-only cash collection receipt, so it settles the
 * obligation through the existing ledger and custody path.
 */
class RegistrationFeePaymentService
{
    public const string PAID = 'paid';

    public const string PART = 'part';

    public const string PENDING = 'pending';

    public function __construct(
        private CollectionService $collections,
    ) {}

    /**
     * Resolve how much the Agent collected now, in kobo, for the chosen payment option.
     *
     * @throws ValidationException
     */
    public function amountCollectedKobo(string $option, ?string $amountNgn, int $feeAmountKobo): int
    {
        if ($option === self::PENDING || $feeAmountKobo === 0) {
            return 0;
        }

        if ($option === self::PAID) {
            return $feeAmountKobo;
        }

        $amountKobo = $this->collections->amountToKobo(trim((string) $amountNgn));

        if ($amountKobo >= $feeAmountKobo) {
            throw ValidationException::withMessages([
                'registration_fee_amount_paid' => ['A part payment must be less than the full registration fee. Choose "Paid in full" instead.'],
            ]);
        }

        return $amountKobo;
    }

    /**
     * Record a cash fee-only receipt against the obligation for the collected amount.
     *
     * Runs inside the caller's financial transaction. The receipt attempt reference is
     * derived from the caller's attempt reference so a retried transaction stays idempotent.
     */
    public function collect(User $agent, CustomerProfile $customer, FeeObligation $obligation, int $amountKobo, string $attemptReference): void
    {
        if ($amountKobo <= 0) {
            return;
        }

        $customer = $customer->fresh(['currentAssignment']);
        $assignment = $customer->currentAssignment;
        $business = BusinessProfile::current();

        $data = [
            'attempt_reference' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'registration-fee-payment:'.$attemptReference)->toString(),
            'customer_version' => $customer->version,
            'assignment_version' => $assignment?->version,
            'business_version' => $business->version,
            'plan_id' => null,
            'plan_version' => null,
            'received_date' => CarbonImmutable::now($business->timezone)->toDateString(),
            'savings_ngn' => '0.00',
            'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => number_format($amountKobo / 100, 2, '.', '')]],
            'allocations' => [],
            'method' => 'cash',
            'late_reason' => '',
            'notes' => 'Registration fee collected when the fee was raised.',
            'confirmed' => true,
        ];

        try {
            $preview = $this->collections->preview($agent, $customer, $data);
            $data['preview_fingerprint'] = $preview['preview_fingerprint'];
            $this->collections->record($agent, $customer, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'registration_fee_payment' => ['The registration fee payment could not be recorded: '.collect($exception->errors())->flatten()->first()],
            ]);
        }
    }
}
