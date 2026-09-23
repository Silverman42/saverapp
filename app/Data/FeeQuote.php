<?php

namespace App\Data;

use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;

class FeeQuote
{
    public function __construct(
        public readonly int $ruleId,
        public readonly int $ruleVersion,
        public readonly FeeRuleModel $model,
        public readonly FeeRuleTiming $timing,
        public readonly FeeRuleBasis $basis,
        public readonly int $amountKobo,
        public readonly string $currency,
        public readonly int $basisKobo,
        public readonly string $sourceType,
        public readonly string $sourceId,
    ) {}
}
