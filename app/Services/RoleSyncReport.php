<?php

namespace App\Services;

class RoleSyncReport
{
    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $issues = [],
        public readonly array $details = [],
    ) {}

    /**
     * Determine if role synchronization is valid with zero detected drift.
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Get the list of detected drift issues.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * Get inspection details for the audit.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
