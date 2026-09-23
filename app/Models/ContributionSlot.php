<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** @property int $thrift_plan_id @property int $plan_terms_revision_id @property int $ordinal @property string $due_date @property int $expected_amount_kobo @property int|null $active_ordinal */
#[Fillable(['thrift_plan_id', 'plan_terms_revision_id', 'ordinal', 'due_date', 'expected_amount_kobo', 'active_ordinal', 'superseded_at'])]
class ContributionSlot extends Model
{
    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'expected_amount_kobo' => 'integer',
            'active_ordinal' => 'integer',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ContributionSlot $slot): void {
            if ($slot->isDirty(['thrift_plan_id', 'plan_terms_revision_id', 'ordinal', 'due_date', 'expected_amount_kobo'])) {
                throw new RuntimeException('Contribution slot terms are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException('Contribution slots cannot be deleted.');
        });
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }

    /** @return BelongsTo<PlanTermsRevision, $this> */
    public function termsRevision(): BelongsTo
    {
        return $this->belongsTo(PlanTermsRevision::class, 'plan_terms_revision_id');
    }
}
