<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\ChargeCategoryVersion;
use App\Models\LedgerAccount;
use App\Models\WithdrawalRequest;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The server-authorized withdrawal-time deduction D. It is zero unless the deduction contract is explicitly enabled
 * and names a published fixed-amount deduction category whose destination mapping is still the published one.
 */
class WithdrawalDeductionContract
{
    public function enabled(): bool
    {
        return config('withdrawals.deduction_enabled') === true && is_string(config('withdrawals.deduction_category_key'))
            && config('withdrawals.deduction_category_key') !== '';
    }

    /** @return array{amount_kobo: int, category_version_id: int|null, description: string|null} */
    public function quote(bool $forUpdate = false): array
    {
        if (! $this->enabled()) {
            return ['amount_kobo' => 0, 'category_version_id' => null, 'description' => null];
        }
        $category = ChargeCategoryVersion::query()->where('category_key', config('withdrawals.deduction_category_key'))
            ->orderByDesc('version')->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();

        return $this->quoteFor($category);
    }

    /** @return array{amount_kobo: int, category_version_id: int|null, description: string|null} */
    private function quoteFor(?ChargeCategoryVersion $category): array
    {
        if ($category === null || $category->kind !== 'deduction' || $category->destination_code !== LedgerAccountCode::OtherDeductionDestination->value
            || $category->amount_kobo < 1 || $category->amount_kobo > 999999999999) {
            throw new ConflictHttpException('The withdrawal deduction contract is unavailable.');
        }
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->first();
        if ($account === null || $account->mapping_status !== 'mapped' || $account->version !== $category->destination_mapping_version) {
            throw new ConflictHttpException('The deduction destination mapping changed.');
        }

        return ['amount_kobo' => $category->amount_kobo, 'category_version_id' => $category->id,
            'description' => $category->customer_description];
    }

    /**
     * A submitted request keeps its frozen D. Approval and posting fail closed when the contract that authorized it changed.
     */
    public function assertUnchanged(WithdrawalRequest $withdrawal, bool $forUpdate = false): void
    {
        if ($withdrawal->deduction_amount_kobo === 0) {
            return;
        }
        $current = $this->quote($forUpdate);
        if (! $this->enabled() || $current['category_version_id'] !== $withdrawal->deduction_category_version_id
            || $current['amount_kobo'] !== $withdrawal->deduction_amount_kobo) {
            throw new ConflictHttpException('The withdrawal deduction contract changed. Reject and request a new quote.');
        }
    }
}
