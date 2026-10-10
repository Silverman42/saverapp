<?php

namespace App\Notifications\Auth;

/**
 * A lock notification whose delivery outcome is recorded on its authentication lock.
 */
interface ReportsLockDelivery
{
    public function lockId(): ?int;
}
