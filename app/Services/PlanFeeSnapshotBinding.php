<?php

namespace App\Services;

use App\Enums\FeeRuleKind;
use App\Models\FeeSnapshot;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;

class PlanFeeSnapshotBinding
{
    public function isValid(ThriftPlan $plan, PlanTermsRevision $terms, FeeSnapshot $snapshot, bool $useLoadedTerms = false): bool
    {
        if ($terms->thrift_plan_id !== $plan->id || $terms->fee_snapshot_id !== $snapshot->id
            || $snapshot->customer_profile_id !== $plan->customer_profile_id
            || $snapshot->kind !== FeeRuleKind::Plan || $snapshot->currency !== 'NGN') {
            return false;
        }

        $origin = $useLoadedTerms && $plan->relationLoaded('termsRevisions')
            ? $plan->termsRevisions->where('fee_snapshot_id', $snapshot->id)->sortBy('revision')->first()
            : PlanTermsRevision::query()->where('thrift_plan_id', $plan->id)
                ->where('fee_snapshot_id', $snapshot->id)->orderBy('revision')->first();
        if ($origin === null || $origin->revision > $terms->revision
            || ! match ($snapshot->source_type) {
                'plan' => $snapshot->source_id === $plan->plan_id,
                'plan_terms_revision' => $snapshot->source_id === $plan->plan_id.'-R'.$origin->revision,
                default => false,
            }) {
            return false;
        }

        foreach (['contribution_amount_kobo', 'currency', 'start_date', 'contribution_days', 'frequency', 'timezone', 'expected_gross_kobo'] as $attribute) {
            if ($origin->getRawOriginal($attribute) !== $terms->getRawOriginal($attribute)) {
                return false;
            }
        }

        return true;
    }
}
