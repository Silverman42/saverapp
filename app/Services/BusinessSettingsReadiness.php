<?php

namespace App\Services;

class BusinessSettingsReadiness
{
    /** @return array<string, array{state: string, owner: string, blocker: string, version: int}> */
    public function checks(): array
    {
        $blocked = [
            'timezone' => ['Modules 06/07/11/12', 'Timezone boundary and cross-zone receipt certification is unavailable. Africa/Lagos remains supported.'],
            'collection_cash' => ['Modules 07/10', 'Approved custody mapping, evidence, period and reconciliation release certification is unavailable.'],
            'collection_transfer' => ['Modules 07/10', 'Approved bank mapping and actual-receipt/finality evidence contract is unavailable.'],
            'collection_pos' => ['Modules 07/10', 'Approved terminal clearing and settlement contract is unavailable.'],
            'withdrawal_cash' => ['Modules 08/10', 'Certified executor, funding, evidence, finality and recovery contracts are unavailable.'],
            'withdrawal_transfer' => ['Modules 08/10', 'Certified bank executor, destination, idempotency and unknown-outcome recovery are unavailable.'],
            'customer_registration' => ['Modules 04/05/13', 'End-to-end registration fee and verified sender release certification remains outstanding.'],
            'plan_creation' => ['Modules 05/06', 'Complete fee settlement and plan lifecycle release certification remains outstanding.'],
            'collections' => ['Module 07', 'At least one owner-certified collection method and period contract is required.'],
            'payout_execution' => ['Module 08', 'No production payout method is certified.'],
            'reversal_posting' => ['Module 09', 'No production compensation owner is certified.'],
            'statement_pdf' => ['Module 10', 'Issued artifact rendering, storage, retention and recovery are unavailable.'],
            'report_exports' => ['Module 12', 'File rendering, storage, reproducible cutoff and recovery are unavailable.'],
            'manual_charges' => ['Module 05', 'Controlled category and deduction integrated acceptance remains outstanding.'],
            'fee_refunds' => ['Module 05', 'Retained-fee and cash-backed entitlement integrated acceptance remains outstanding.'],
            'cash_disbursements' => ['Modules 05/08/10', 'Cash refund and earnings draw integrated acceptance remains outstanding.'],
            'transactional_email' => ['Module 13', 'Sender changes and global channel policy require verified provider authority. Existing mandatory email remains owner-controlled.'],
            'logo' => ['Asset owner', 'Approved image decoding, scanning, metadata removal and protected storage are unavailable.'],
            'emergency_recovery' => ['Authentication', 'Business emergency-key issuance and assisted recovery remain blocked. TOTP recovery codes are separate.'],
            'retention_restore' => ['Modules 14/16', 'Approved legal retention, key custody and isolated recovery certification are unavailable.'],
        ];
        $checks = [];
        foreach ($blocked as $code => [$owner, $blocker]) {
            $checks[$code] = ['state' => 'Unavailable', 'owner' => $owner, 'blocker' => $blocker, 'version' => 1];
        }
        foreach (app(FinancialReleaseEvidenceService::class)->checks() as $capability => $check) {
            $checks[$capability] = $check;
        }
        if (app()->environment(['local', 'testing']) && config('collections.local_certified') === true) {
            foreach (['collection_cash', 'collections'] as $code) {
                $checks[$code] = ['state' => 'Ready to enable', 'owner' => 'Module 07 local cash release',
                    'blocker' => 'Local cash certification only; production release remains unavailable.', 'version' => 2];
            }
        }
        $checks['profile'] = ['state' => 'Ready to enable', 'owner' => 'Module 15', 'blocker' => '', 'version' => 1];
        $checks['presentation'] = ['state' => 'Ready to enable', 'owner' => 'Modules 11/12', 'blocker' => '', 'version' => 1];
        $checks['collection_limits'] = ['state' => 'Ready to enable', 'owner' => 'Module 07', 'blocker' => 'Limits do not certify or enable collection methods.', 'version' => 1];

        return $checks;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->checks(), JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $codes
     * @return list<string>
     */
    public function consumers(array $codes): array
    {
        $consumers = ['profile', 'presentation'];
        if (array_intersect($codes, ['receipt_minimum_kobo', 'receipt_maximum_kobo', 'late_lookback_days']) !== []) {
            $consumers[] = 'collection_limits';
        }

        return $consumers;
    }

    public function acknowledge(string $consumer, string $dependencyHash): bool
    {
        return hash_equals($this->hash(), $dependencyHash)
            && ($this->checks()[$consumer]['state'] ?? '') === 'Ready to enable';
    }
}
