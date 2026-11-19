<?php

namespace App\Services;

use RuntimeException;

/**
 * A named reason an automatic wallet sweep cannot post yet. It rolls back the attempt; the sweep stays pending for retry.
 */
class SweepBlocked extends RuntimeException {}
