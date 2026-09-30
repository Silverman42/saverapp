<?php

use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\User;
use App\Services\FeeObligationService;

function reportFeeObligation(User $agent, CustomerProfile $customer, int $amountKobo = 50000): FeeObligation
{
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Registration cash fee', 'kind' => FeeRuleKind::Registration,
        'rule_key' => 'test-registration', 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => $amountKobo, 'customer_description' => 'Registration fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test registration fee.',
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => $customer->customer_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Registration cash fee',
        'kind' => FeeRuleKind::Registration, 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => $amountKobo, 'basis_amount_kobo' => 0,
        'customer_description' => 'Registration fee', 'acknowledged_at' => now(),
    ]);

    return app(FeeObligationService::class)->assessSnapshot($snapshot, $agent);
}
