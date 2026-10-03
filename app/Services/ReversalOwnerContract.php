<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;

interface ReversalOwnerContract
{
    /**
     * Return exact authoritative dependencies and gross amount, or throw when incomplete.
     *
     * @return array{fingerprint: string, gross_kobo: int, summary: array<string, mixed>, dependencies: list<array<string, mixed>>}
     */
    public function preview(LedgerPostingGroup $original, CustomerProfile $customer, bool $forUpdate): array;

    /**
     * Post every counter-entry and required owner event in the caller's transaction.
     *
     * @param  array{fingerprint: string, gross_kobo: int, summary: array<string, mixed>, dependencies: list<array<string, mixed>>}  $preview
     */
    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup|FinancialWorkflowSupplement;
}
