<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['method_identity', 'custody_account_code', 'collection_method_version_id', 'business_version', 'agent_profile_id', 'received_date', 'timezone', 'revision', 'predecessor_batch_id', 'status', 'version', 'frozen_at', 'closes_at'])]
class CollectionBatch extends Model
{
    protected function casts(): array
    {
        return ['collection_method_version_id' => 'integer', 'business_version' => 'integer', 'revision' => 'integer', 'version' => 'integer', 'frozen_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CollectionReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(CollectionReceipt::class)->whereNull('replacement_reversal_id');
    }

    /**
     * For a batch that is still open, when it becomes ready for remittance and review; null once it has been frozen.
     */
    public function openBatchNotice(): ?string
    {
        if ($this->status !== 'open') {
            return null;
        }
        $day = CarbonImmutable::parse((string) $this->received_date, $this->timezone);
        $closesAt = ($this->closes_at ?? $day->addDay())->setTimezone($this->timezone);
        if (! $closesAt->isFuture()) {
            return 'Batch #'.$this->id.' stopped collecting at '.$this->closingLabel($closesAt, $day).' and will be ready for remittance and review within a minute or two.';
        }

        return 'Batch #'.$this->id.' is still collecting for '.$day->format('j M Y').'. It can be remitted and reviewed from '.$this->closingLabel($closesAt, $day).' ('.$this->timezone.' time).';
    }

    private function closingLabel(CarbonImmutable $closesAt, CarbonImmutable $day): string
    {
        return $closesAt->format('H:i') === '00:00' && $closesAt->toDateString() === $day->addDay()->toDateString()
            ? 'midnight on '.$closesAt->format('j M Y')
            : $closesAt->format('H:i \o\n j M Y');
    }

    /** @return HasMany<CashRemittance, $this> */
    public function remittances(): HasMany
    {
        return $this->hasMany(CashRemittance::class);
    }
}
