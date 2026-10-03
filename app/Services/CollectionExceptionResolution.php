<?php

namespace App\Services;

use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\CollectionReceipt;
use App\Models\FinancialWorkflowSupplement;
use App\Models\ReversalRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionExceptionResolution
{
    public function assertResolvedCases(CollectionBatch $batch): void
    {
        foreach (CollectionException::query()->where('collection_batch_id', $batch->id)->where('status', 'resolved')->lazyById(100) as $exception) {
            $event = DB::table('collection_exception_events')->where('collection_exception_id', $exception->id)->latest('id')->first();
            if ($event?->event_type !== 'resolved' || blank($event->reason)) {
                throw new ConflictHttpException('A resolved exception has no durable resolution event.');
            }
            $audit = $this->canonical($exception);
            if ($audit['actor_id'] !== (int) $event->actor_user_id
                || CarbonImmutable::parse($audit['occurred_at'])->lessThan(CarbonImmutable::parse($event->created_at))) {
                throw new ConflictHttpException('The exception resolution event and approval do not reconcile.');
            }
            $safe = $audit['safe_changes'];
            $kind = $safe['resolution_kind'] ?? 'remittance';
            if ($kind === 'remittance') {
                if ($exception->kind !== 'cash_shortage' || app(CollectionBatchPosition::class)->read($batch)['outstanding_kobo'] !== 0) {
                    throw new ConflictHttpException('The resolved shortage has no complete verified custody handoff.');
                }

                continue;
            }
            $proof = FinancialWorkflowSupplement::query()->whereKey($safe['supplement_id'] ?? null)
                ->where('kind', 'collection_exception_resolution')->first();
            if ($proof === null || ($proof->facts['exception_id'] ?? null) !== $exception->id
                || ($proof->facts['resolution_kind'] ?? null) !== $kind
                || ($proof->facts['batch_version'] ?? null) !== (int) $event->batch_version) {
                throw new ConflictHttpException('The resolved exception owner proof is unavailable.');
            }
            $this->assertRecorded($proof, $batch);
        }
    }

    public function assertRecorded(FinancialWorkflowSupplement $proof, CollectionBatch $batch): void
    {
        $facts = $proof->facts;
        $ciphertext = $proof->getRawOriginal('evidence');
        if (! is_string($ciphertext)) {
            throw new ConflictHttpException('The protected resolution cause is unavailable.');
        }
        try {
            $cause = Crypt::decryptString($ciphertext);
        } catch (DecryptException $exception) {
            throw new ConflictHttpException('The protected resolution cause is unavailable.', $exception);
        }
        if ($proof->kind !== 'collection_exception_resolution' || $proof->collection_batch_id !== $batch->id || blank($cause)
            || ! hash_equals($proof->payload_hash, AuditProjection::digest(['facts' => $facts, 'actor_id' => $proof->actor_user_id, 'cause' => $cause]))
            || ! is_string($facts['resolution_kind'] ?? null) || ! is_int($facts['exception_id'] ?? null) || ! is_int($facts['receipt_id'] ?? null) || ! is_int($facts['batch_version'] ?? null)) {
            throw new ConflictHttpException('The recorded exception proof is incomplete or has changed.');
        }
        $exception = CollectionException::query()->whereKey($facts['exception_id'])->first();
        $receipt = CollectionReceipt::query()->whereKey($facts['receipt_id'])->first();
        $event = DB::table('collection_exception_events')->where('collection_exception_id', $facts['exception_id'])
            ->where('batch_version', $facts['batch_version'])->where('event_type', 'resolved')->first();
        if ($exception === null || $receipt === null || $event === null || $exception->collection_batch_id !== $batch->id
            || $receipt->collection_batch_id !== $batch->id || $receipt->replacement_reversal_id !== null
            || $proof->customer_profile_id !== $receipt->customer_profile_id || $proof->thrift_plan_id !== $receipt->thrift_plan_id
            || $exception->kind !== ($facts['exception_kind'] ?? null) || $exception->amount_kobo !== ($facts['exception_amount_kobo'] ?? null)
            || $receipt->collection_payment_evidence_id !== ($facts['evidence_id'] ?? null)
            || $receipt->collection_evidence_review_id !== ($facts['evidence_review_id'] ?? null)
            || (int) $event->actor_user_id !== $proof->actor_user_id || trim($event->reason) !== $cause
            || ! is_int($facts['expected_kobo'] ?? null) || ! is_int($facts['received_kobo'] ?? null) || ! is_int($facts['outstanding_kobo'] ?? null)
            || $facts['expected_kobo'] !== (int) $batch->receipts()->sum('tender_amount_kobo')
            || $facts['received_kobo'] < 0 || $facts['outstanding_kobo'] < 0
            || $facts['expected_kobo'] !== $facts['received_kobo'] + $facts['outstanding_kobo']
            || ($facts['settlement_pending'] ?? null) !== ($batch->custody_account_code === 'payment_clearing_ngn' && $facts['outstanding_kobo'] > 0)) {
            throw new ConflictHttpException('The resolution case, source and custody snapshot do not reconcile.');
        }
        $audit = $this->canonical($exception, $proof->id);
        if (($audit['safe_changes']['resolution_kind'] ?? null) !== $facts['resolution_kind']
            || $audit['actor_id'] !== $proof->actor_user_id || ($audit['safe_changes']['batch_id'] ?? null) !== $batch->id) {
            throw new ConflictHttpException('The resolution proof has no matching canonical authority.');
        }
        $this->assertSource($receipt, $exception, $facts['resolution_kind'], $proof->reversal_request_id);
        $reversal = $proof->reversal_request_id === null ? null : ReversalRequest::query()->find($proof->reversal_request_id);
        if (($facts['reversal_id'] ?? null) !== $proof->reversal_request_id
            || ($facts['compensation_posting_group_id'] ?? null) !== $reversal?->compensation_posting_group_id) {
            throw new ConflictHttpException('The recorded correction identity does not reconcile.');
        }
    }

    /** @return array<string, mixed> */
    private function canonical(CollectionException $exception, ?int $proofId = null): array
    {
        $query = DB::table('canonical_audit_events')->where('event_type', 'collection.exception_resolved')
            ->where('target_type', CollectionException::class)->where('target_id', $exception->id);
        if ($proofId !== null) {
            $query->where('content->safe_changes->supplement_id', $proofId);
        }
        $row = $query->latest('id')->first();
        $content = $row === null || ! is_string($row->content) ? null : json_decode($row->content, true);
        if (! is_array($content) || ! hash_equals($row->content_hash, AuditProjection::digest($content))
            || ($content['event_type'] ?? null) !== 'collection.exception_resolved' || ($content['target_id'] ?? null) !== $exception->id
            || ($content['target_type'] ?? null) !== CollectionException::class || ($content['actor_type'] ?? null) !== 'admin'
            || ($content['outcome'] ?? null) !== 'Succeeded' || ($content['legacy_evidence'] ?? null) !== false
            || ($content['authority']['required_permission'] ?? null) !== 'reconciliation.manage'
            || ($content['authority']['evidence'] ?? null) !== 'owner_workflow' || ! is_int($content['actor_id'] ?? null)
            || ! is_string($content['occurred_at'] ?? null) || ! is_array($content['safe_changes'] ?? null)
            || ($content['safe_changes']['batch_id'] ?? null) !== $exception->collection_batch_id) {
            throw new ConflictHttpException('The resolution canonical authority is missing or invalid.');
        }

        return $content;
    }

    private function assertSource(CollectionReceipt $receipt, CollectionException $exception, string $kind, ?int $reversalId): ?ReversalRequest
    {
        if ($exception->kind === 'overage' || $exception->amount_kobo < 1 || $exception->amount_kobo > $receipt->tender_amount_kobo
            || ($kind === 'verified_match' && $reversalId !== null)) {
            throw new ConflictHttpException('The recorded resolution has no supported original receipt source.');
        }
        app(CollectionReceiptMethod::class)->assertReceipt($receipt);
        if ($receipt->method !== 'cash') {
            $files = DB::table('collection_evidence_files')->where('collection_payment_evidence_id', $receipt->collection_payment_evidence_id)->get();
            $required = DB::table('collection_method_versions')->where('id', $receipt->collection_method_version_id)->value('attachment_required');
            if ($required && $files->isEmpty()) {
                throw new ConflictHttpException('The matched receipt has no required protected supporting evidence.');
            }
            foreach ($files as $file) {
                app(CollectionEvidenceFiles::class)->fileBytes($file);
            }
        }
        $reversal = null;
        if ($kind === 'verified_match') {
            if ($exception->kind !== 'missing_transfer' || $receipt->method === 'cash'
                || $exception->amount_kobo !== $receipt->tender_amount_kobo) {
                throw new ConflictHttpException('A complete independently verified noncash receipt is required for this match.');
            }
        } elseif ($kind === 'approved_correction') {
            if (! in_array($exception->kind, ['cash_shortage', 'missing_transfer'], true)) {
                throw new ConflictHttpException('This exception has no approved receipt-correction resolution contract.');
            }
            $reversal = ReversalRequest::query()->whereKey($reversalId)->first();
            if ($reversal === null) {
                throw new ConflictHttpException('The approved receipt correction is unavailable.');
            }
            app(ReversalService::class)->assertApprovedReceiptCorrection($reversal, $receipt);
        } else {
            throw new ConflictHttpException('Choose a verified match or a separately approved correction.');
        }

        return $reversal;
    }

    /** @param array<string, mixed> $data */
    public function record(CollectionBatch $batch, CollectionException $exception, User $actor, array $data): FinancialWorkflowSupplement
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Exception resolution requires the guarded reconciliation transaction.');
        }
        $receipt = CollectionReceipt::query()->where('receipt_reference', $data['receipt_reference'])->lockForUpdate()->first();
        if ($exception->collection_batch_id !== $batch->id || $exception->kind === 'overage'
            || $receipt === null || $receipt->collection_batch_id !== $batch->id || $receipt->replacement_reversal_id !== null
            || $exception->amount_kobo < 1 || $exception->amount_kobo > $receipt->tender_amount_kobo) {
            throw new ConflictHttpException('The exception has no supported original receipt in this batch.');
        }
        $reversal = $data['resolution_kind'] === 'approved_correction'
            ? ReversalRequest::query()->where('reversal_id', $data['reversal_id'])->lockForUpdate()->first() : null;
        if ($data['resolution_kind'] === 'approved_correction' && $reversal === null) {
            throw new ConflictHttpException('The approved receipt correction is unavailable.');
        }
        $this->assertSource($receipt, $exception, $data['resolution_kind'], $reversal?->id);
        $position = app(CollectionBatchPosition::class)->read($batch);
        $facts = ['exception_id' => $exception->id, 'exception_kind' => $exception->kind, 'exception_amount_kobo' => $exception->amount_kobo,
            'batch_version' => $batch->version, 'resolution_kind' => $data['resolution_kind'], 'receipt_id' => $receipt->id,
            'evidence_id' => $receipt->collection_payment_evidence_id, 'evidence_review_id' => $receipt->collection_evidence_review_id,
            'reversal_id' => $reversal?->id, 'compensation_posting_group_id' => $reversal?->compensation_posting_group_id,
            'expected_kobo' => $position['expected_kobo'], 'received_kobo' => $position['received_kobo'],
            'outstanding_kobo' => $position['outstanding_kobo'], 'settlement_pending' => $position['settlement_pending']];

        return FinancialWorkflowSupplement::create([
            'operation_reference' => (string) Str::uuid(), 'payload_hash' => AuditProjection::digest(['facts' => $facts, 'actor_id' => $actor->id, 'cause' => trim($data['reason'])]),
            'kind' => 'collection_exception_resolution', 'customer_profile_id' => $receipt->customer_profile_id,
            'thrift_plan_id' => $receipt->thrift_plan_id, 'collection_batch_id' => $batch->id,
            'reversal_request_id' => $reversal?->id, 'actor_user_id' => $actor->id,
            'facts' => $facts, 'evidence' => trim($data['reason']), 'created_at' => now(),
        ]);
    }
}
