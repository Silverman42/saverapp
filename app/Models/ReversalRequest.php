<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/** @property CarbonImmutable|null $reviewed_at */
#[Fillable([
    'reversal_id', 'customer_profile_id', 'original_posting_group_id',
    'live_original_posting_group_id', 'posted_original_posting_group_id',
    'compensation_posting_group_id', 'requested_by_user_id',
    'initiating_agent_profile_id', 'assignment_id', 'reviewed_by_user_id',
    'state', 'version', 'reason_category', 'internal_reason',
    'customer_explanation', 'evidence_text', 'decision_reason', 'dependency_fingerprint',
    'dependency_snapshot', 'original_amount_kobo', 'currency', 'reviewed_at',
])]
class ReversalRequest extends Model
{
    protected static function booted(): void
    {
        static::updating(function (ReversalRequest $request): void {
            if ($request->isDirty([
                'reversal_id', 'customer_profile_id', 'original_posting_group_id',
                'requested_by_user_id', 'initiating_agent_profile_id', 'assignment_id',
                'reason_category', 'internal_reason', 'customer_explanation', 'evidence_text',
                'dependency_fingerprint', 'dependency_snapshot', 'original_amount_kobo', 'currency',
            ])) {
                throw new RuntimeException('Submitted reversal terms are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Reversal requests cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'dependency_snapshot' => 'array', 'original_amount_kobo' => 'integer',
            'version' => 'integer', 'reviewed_at' => 'immutable_datetime',
            'evidence_text' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reversal_id';
    }

    /** @return HasMany<ReversalEvidenceFile, $this> */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(ReversalEvidenceFile::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<LedgerPostingGroup, $this> */
    public function originalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(LedgerPostingGroup::class, 'original_posting_group_id');
    }

    /** @return HasMany<ReversalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ReversalEvent::class)->orderBy('effective_at')->orderBy('id');
    }
}
