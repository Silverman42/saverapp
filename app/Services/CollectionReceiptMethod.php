<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionReceiptMethod
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, CustomerProfile $customer, array $data, int $tenderKobo, string $date, string $timezone, ?ReversalRequest $replacement = null): array
    {
        if ($replacement !== null) {
            $source = app(CollectionReplacementService::class)->lockSource($replacement, $customer, $tenderKobo, DB::transactionLevel() > 0);
            $this->assertReceipt($source);

            return [
                'method' => $source->method, 'method_label' => $source->method_label,
                'custody_account_code' => $source->custody_account_code,
                'collection_method_version_id' => $source->collection_method_version_id,
                'collection_payment_evidence_id' => null, 'collection_evidence_review_id' => $source->collection_evidence_review_id,
                'method_reference' => $source->method_reference, 'original_agent_profile_id' => $source->recording_agent_profile_id,
            ];
        }
        $key = $data['method'] ?? 'cash';
        if ($key === 'cash') {
            if (filled($data['evidence_reference'] ?? null) || filled($data['collection_method_version_id'] ?? null)) {
                throw new ConflictHttpException('Cash cannot consume noncash payment evidence.');
            }
            app(BusinessSettings::class)->ensureFeature('collection_cash');

            return ['method' => 'cash', 'method_label' => 'Cash', 'custody_account_code' => LedgerAccountCode::AgentReceivable->value,
                'collection_method_version_id' => null, 'collection_payment_evidence_id' => null, 'collection_evidence_review_id' => null, 'method_reference' => null,
                'original_agent_profile_id' => $customer->currentAssignment?->agent_profile_id];
        }
        if (! in_array($key, ['transfer', 'pos', 'other'], true) || blank($data['evidence_reference'] ?? null)) {
            throw new ConflictHttpException('Choose a supported method and independently verified payment evidence.');
        }
        app(BusinessSettings::class)->ensureFeature('collection_'.$key);
        $evidence = app(CollectionPaymentEvidenceService::class)->authorized($actor, $data['evidence_reference']);
        $lock = DB::transactionLevel() > 0;
        if ($lock) {
            $evidence = DB::table('collection_payment_evidence')->where('id', $evidence->id)->lockForUpdate()->firstOrFail();
        }
        $method = app(CollectionMethodCatalogue::class)->resolve((int) ($data['collection_method_version_id'] ?? 0), $lock);
        $review = DB::table('collection_evidence_reviews')->where('collection_payment_evidence_id', $evidence->id)->orderByDesc('version')->first();
        if ($method->method_key !== $key || $method->id !== $evidence->collection_method_version_id
            || $evidence->customer_profile_id !== $customer->id || (int) $evidence->amount_kobo !== $tenderKobo
            || $evidence->received_date !== $date || $evidence->timezone !== $timezone || $review === null || $review->outcome !== 'verified'
            || DB::table('collection_receipts')->where('collection_payment_evidence_id', $evidence->id)->exists()) {
            throw new ConflictHttpException('The payment evidence is pending, rejected, consumed or does not match this receipt.');
        }
        foreach (DB::table('collection_evidence_files')->where('collection_payment_evidence_id', $evidence->id)->get() as $file) {
            app(CollectionPaymentEvidenceService::class)->fileBytes($file);
        }

        return ['method' => $key, 'method_label' => $method->label, 'custody_account_code' => $method->custody_account_code,
            'collection_method_version_id' => $method->id, 'collection_payment_evidence_id' => $evidence->id, 'collection_evidence_review_id' => $review->id,
            'method_reference' => $evidence->method_reference, 'original_agent_profile_id' => $evidence->recording_agent_profile_id];
    }

    public function assertReceipt(CollectionReceipt $receipt): LedgerAccountCode
    {
        $code = LedgerAccountCode::tryFrom($receipt->custody_account_code);
        if (! in_array($code, [LedgerAccountCode::AgentReceivable, LedgerAccountCode::BusinessBank, LedgerAccountCode::PaymentClearing], true)) {
            throw new ConflictHttpException('The receipt custody pattern is unsupported.');
        }
        if ($receipt->method === 'cash') {
            if ($code !== LedgerAccountCode::AgentReceivable || $receipt->collection_method_version_id !== null || $receipt->collection_payment_evidence_id !== null) {
                throw new ConflictHttpException('The cash receipt has an invalid method identity.');
            }

            return $code;
        }
        $method = DB::table('collection_method_versions')->where('id', $receipt->collection_method_version_id)->first();
        $review = DB::table('collection_evidence_reviews')->where('id', $receipt->collection_evidence_review_id)->first();
        $evidence = $review === null ? null : DB::table('collection_payment_evidence')->where('id', $review->collection_payment_evidence_id)->first();
        if ($method === null || $review === null || $evidence === null || $review->outcome !== 'verified'
            || $method->method_key !== $receipt->method || $method->custody_account_code !== $code->value
            || $method->id !== $evidence->collection_method_version_id || $evidence->customer_profile_id !== $receipt->customer_profile_id
            || $evidence->method_reference !== $receipt->method_reference
            || $evidence->recording_agent_profile_id !== $receipt->recording_agent_profile_id
            || ($receipt->replacement_reversal_id === null && ($receipt->collection_payment_evidence_id !== $evidence->id
                || (int) $evidence->amount_kobo !== $receipt->tender_amount_kobo
                || $evidence->received_date !== $receipt->received_date || $evidence->timezone !== $receipt->timezone))) {
            throw new ConflictHttpException('The receipt has no matching independently verified payment evidence.');
        }
        if ($receipt->replacement_reversal_id !== null) {
            $original = DB::table('collection_receipts')->where('collection_payment_evidence_id', $evidence->id)->first();
            $reversal = ReversalRequest::query()->findOrFail($receipt->replacement_reversal_id);
            $dependencies = $reversal->getAttribute('dependency_snapshot');
            $source = CollectionReceipt::query()->whereKey(is_array($dependencies) ? ($dependencies['summary']['receipt_id'] ?? null) : null)->first();
            if ($original === null || (int) $original->tender_amount_kobo !== (int) $evidence->amount_kobo
                || $original->collection_batch_id !== $receipt->collection_batch_id || $receipt->collection_payment_evidence_id !== null
                || $source === null || $source->collection_batch_id !== $receipt->collection_batch_id
                || $source->collection_evidence_review_id !== $receipt->collection_evidence_review_id
                || $source->collection_method_version_id !== $receipt->collection_method_version_id
                || $source->recording_agent_profile_id !== $receipt->recording_agent_profile_id
                || app(CollectionReplacementService::class)->controlledAmount($reversal, $source) !== $receipt->tender_amount_kobo) {
                throw new ConflictHttpException('The replacement must preserve the original consumed payment and custody batch.');
            }
        }
        if ($code === LedgerAccountCode::BusinessBank) {
            $referenceHash = AuditProjection::digest(['method' => 'transfer', 'destination' => $method->destination_key, 'reference' => $evidence->method_reference]);
            $claim = DB::table('collection_bank_reference_claims')->where('reference_hash', $referenceHash)->first();
            if ($claim?->source_type !== 'payment_evidence' || $claim->source_reference !== $evidence->evidence_reference) {
                throw new ConflictHttpException('The receipt bank reference has no unique payment evidence claim.');
            }
        }

        return $code;
    }
}
