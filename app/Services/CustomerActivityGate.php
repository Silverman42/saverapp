<?php

namespace App\Services;

use App\Enums\CustomerActivity;
use App\Enums\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CustomerActivityGate
{
    /** Lock and return the authoritative Customer profile within the posting transaction. */
    public function assertAllowed(CustomerProfile $customer, CustomerActivity $activity): CustomerProfile
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer activity must be checked inside the posting transaction.');
        }

        $lockedCustomer = CustomerProfile::query()
            ->whereKey($customer->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->allows($lockedCustomer->operational_status, $activity)) {
            throw ValidationException::withMessages([
                'customer_status' => [sprintf(
                    '%s is not permitted while this Customer is %s.',
                    $this->activityLabel($activity),
                    $lockedCustomer->operational_status->displayName(),
                )],
            ]);
        }

        return $lockedCustomer;
    }

    public function allows(CustomerStatus $status, CustomerActivity $activity): bool
    {
        return match ($status) {
            CustomerStatus::Active => true,
            CustomerStatus::Inactive => in_array($activity, [
                CustomerActivity::InitiateWithdrawal,
                CustomerActivity::ApproveWithdrawal,
                CustomerActivity::PostPayout,
                CustomerActivity::SettleExistingSavings,
                CustomerActivity::ApplyAgreedFee,
                CustomerActivity::PostCorrectiveReversal,
                CustomerActivity::ReconcileHistoricalCollections,
            ], true),
            CustomerStatus::Restricted => in_array($activity, [
                CustomerActivity::PostCorrectiveReversal,
                CustomerActivity::ReconcileHistoricalCollections,
            ], true),
            CustomerStatus::Archived => false,
        };
    }

    /** Return null when the Customer has no effective history at the requested time. */
    public function statusAt(CustomerProfile $customer, CarbonInterface $at): ?CustomerStatus
    {
        $value = CustomerStatusHistory::query()
            ->where('customer_profile_id', $customer->id)
            ->where('created_at', '<=', $at->copy()->utc())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('to_status');

        if ($value instanceof CustomerStatus) {
            return $value;
        }

        return is_string($value) ? CustomerStatus::tryFrom($value) : null;
    }

    protected function activityLabel(CustomerActivity $activity): string
    {
        return match ($activity) {
            CustomerActivity::CreatePlan => 'Creating a thrift plan',
            CustomerActivity::AmendPlan => 'Amending a thrift plan',
            CustomerActivity::RecordContribution => 'Recording a contribution',
            CustomerActivity::InitiateWithdrawal => 'Initiating a withdrawal',
            CustomerActivity::ApproveWithdrawal => 'Approving a withdrawal',
            CustomerActivity::PostPayout => 'Posting a payout',
            CustomerActivity::SettleExistingSavings => 'Settling existing savings',
            CustomerActivity::ApplyAgreedFee => 'Applying an agreed fee',
            CustomerActivity::AssessDiscretionaryFee => 'Assessing a discretionary fee',
            CustomerActivity::PostCorrectiveReversal => 'Posting a corrective reversal',
            CustomerActivity::ReconcileHistoricalCollections => 'Reconciling historical collections',
        };
    }
}
