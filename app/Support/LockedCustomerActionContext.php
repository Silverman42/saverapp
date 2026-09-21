<?php

namespace App\Support;

use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;

/**
 * Immutable context containing models locked inside an active transaction.
 */
final readonly class LockedCustomerActionContext
{
    public function __construct(
        public User $actor,
        public CustomerProfile $customerProfile,
        public ?CustomerAssignment $currentAssignment = null,
        public ?AgentProfile $currentAgentProfile = null,
        public ?AgentProfile $targetAgentProfile = null,
    ) {}
}
