<?php

namespace App\Support;

use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentEligibilityReason;

/**
 * Immutable agent eligibility result value object.
 */
final readonly class AgentEligibilityResult
{
    public function __construct(
        public bool $isEligible,
        public AgentEligibilityCapability $capability,
        public ?AgentEligibilityReason $reasonCode = null,
        public ?string $message = null,
    ) {}

    public static function eligible(AgentEligibilityCapability $capability): self
    {
        return new self(
            isEligible: true,
            capability: $capability,
            reasonCode: null,
            message: null,
        );
    }

    public static function ineligible(
        AgentEligibilityCapability $capability,
        AgentEligibilityReason $reason,
        ?string $message = null,
    ): self {
        return new self(
            isEligible: false,
            capability: $capability,
            reasonCode: $reason,
            message: $message ?? $reason->message(),
        );
    }

    public function isEligible(): bool
    {
        return $this->isEligible;
    }
}
