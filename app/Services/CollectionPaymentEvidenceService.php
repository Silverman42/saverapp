<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionPaymentEvidenceService
{
    public function __construct(
        private CollectionMethodCatalogue $methods,
        private CollectionEvidenceFiles $files,
        private CustomerActionAuthorizationGuard $guard,
    ) {}

    /** @param array<string, mixed> $data
     * @param  list<UploadedFile>  $uploads
     */
    public function store(User $actor, CustomerProfile $customer, array $data, array $uploads): string
    {
        Gate::forUser($actor)->authorize('recordCollection', $customer);
        $method = $this->methods->resolve((int) $data['collection_method_version_id']);
        $amount = app(CollectionService::class)->amountToKobo((string) $data['amount_ngn']);
        $timezone = BusinessProfile::current()->timezone;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['received_date'], $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $limits = app(BusinessSettings::class)->collectionLimits()['values'];
        if ($date === null || $date->format('Y-m-d') !== $data['received_date'] || $date->gt($today)
            || $date->lt($today->subDays($limits['late_lookback_days']))
            || $amount < $limits['receipt_minimum_kobo'] || $amount > $limits['receipt_maximum_kobo']) {
            throw ValidationException::withMessages(['received_date' => 'Payment date or amount is outside the configured collection limits.']);
        }
        if (count($uploads) > 3 || ($method->attachment_required && $uploads === [])) {
            throw ValidationException::withMessages(['files' => 'Attach one to three payment evidence files.']);
        }
        $files = [];
        try {
            foreach ($uploads as $upload) {
                $files[] = $this->files->prepareFile($upload);
            }
            $payload = [
                'actor' => $actor->id, 'customer' => $customer->id, 'customer_version' => (int) $data['customer_version'],
                'assignment_version' => (int) $data['assignment_version'], 'method_version' => $method->id,
                'method_reference' => strtoupper(trim($data['method_reference'])), 'received_date' => $data['received_date'],
                'timezone' => $timezone, 'amount_kobo' => $amount, 'source_attestation' => trim($data['source_attestation']),
                'checksums' => array_column($files, 'checksum'),
            ];
            $hash = AuditProjection::digest($payload);

            return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $customer, $data, $method, $payload, $hash, $files): string {
                $context = $this->guard->lockAndAuthorize($actor, $customer->id, 'recordCollection', (int) $data['customer_version'], (int) $data['assignment_version']);
                $existing = DB::table('collection_payment_evidence')->where('evidence_reference', $data['evidence_reference'])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        throw new ConflictHttpException('The evidence reference already identifies another request.');
                    }

                    return $existing->evidence_reference;
                }
                $this->methods->resolve($method->id, true);
                app(FinancialPeriodService::class)->assertOpen($payload['received_date'], $payload['timezone'], true);
                $referenceHash = AuditProjection::digest(['method' => $method->method_key, 'destination' => $method->destination_key, 'reference' => $payload['method_reference']]);
                if (DB::table('collection_payment_evidence')->where('reference_hash', $referenceHash)->exists()) {
                    throw new ConflictHttpException('This payment reference already has evidence. Reuse its existing evidence record.');
                }
                if ($method->custody_account_code === 'business_bank_ngn') {
                    $bankReferenceHash = AuditProjection::digest(['method' => 'transfer', 'destination' => $method->destination_key, 'reference' => $payload['method_reference']]);
                    if (DB::table('collection_bank_reference_claims')->where('reference_hash', $bankReferenceHash)->exists()) {
                        throw new ConflictHttpException('This bank reference is already claimed by payment or settlement evidence.');
                    }
                    DB::table('collection_bank_reference_claims')->insert([
                        'reference_hash' => $bankReferenceHash, 'source_type' => 'payment_evidence',
                        'source_reference' => $data['evidence_reference'], 'created_at' => now(),
                    ]);
                }
                abort_unless($context->currentAssignment !== null && $context->currentAgentProfile !== null, 403);
                $id = DB::table('collection_payment_evidence')->insertGetId([
                    'evidence_reference' => $data['evidence_reference'], 'payload_hash' => $hash, 'reference_hash' => $referenceHash,
                    'customer_profile_id' => $customer->id, 'assignment_id' => $context->currentAssignment->id,
                    'recording_agent_profile_id' => $context->currentAgentProfile->id, 'recorded_by_user_id' => $actor->id,
                    'collection_method_version_id' => $method->id, 'method_reference' => $payload['method_reference'],
                    'received_date' => $payload['received_date'], 'timezone' => $payload['timezone'], 'amount_kobo' => $payload['amount_kobo'],
                    'source_attestation' => Crypt::encryptString($payload['source_attestation']), 'created_at' => now(),
                ]);
                foreach ($files as $file) {
                    DB::table('collection_evidence_files')->insert(['collection_payment_evidence_id' => $id, ...$file]);
                }
                AuditEvent::record('collection.evidence_submitted', 'collection_payment_evidence', $id, $data['evidence_reference'],
                    ['method_version_id' => $method->id, 'amount_kobo' => $payload['amount_kobo'], 'file_count' => count($files)], $context->actor);

                return $data['evidence_reference'];
            }, attempts: 3);
        } finally {
            foreach ($files as $file) {
                $this->files->removeUnreferencedFile($file['storage_path']);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function review(User $actor, string $reference, array $data): int
    {
        $evidence = $this->authorized($actor, $reference);
        abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::ReconciliationManage), 403);
        abort_unless(app(FreshAuthenticationService::class)->isFresh($actor, request()), 403);
        if ($data['outcome'] === 'verified') {
            foreach (DB::table('collection_evidence_files')->where('collection_payment_evidence_id', $evidence->id)->get() as $file) {
                $this->fileBytes($file);
            }
        }

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $evidence, $reference, $data): int {
            $context = $this->guard->lockAndAuthorize($actor, $evidence->customer_profile_id, 'view');
            abort_unless(app(AuthorizationService::class)->allows($context->actor, AdminPermission::ReconciliationManage), 403);
            abort_unless(app(FreshAuthenticationService::class)->isFresh($context->actor, request()), 403);
            DB::table('collection_payment_evidence')->where('id', $evidence->id)->lockForUpdate()->first();
            $hash = AuditProjection::digest(['actor' => $actor->id, 'evidence' => $evidence->id, ...$data]);
            $existing = DB::table('collection_evidence_reviews')->where('operation_reference', $data['operation_reference'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The review reference already identifies another request.');
                }

                return $existing->id;
            }
            if ($actor->id === $evidence->recorded_by_user_id || DB::table('collection_receipts')->where('collection_payment_evidence_id', $evidence->id)->exists()) {
                throw new ConflictHttpException('Evidence must be reviewed independently before it funds a receipt.');
            }
            $version = (int) DB::table('collection_evidence_reviews')->where('collection_payment_evidence_id', $evidence->id)->max('version');
            if ($version !== (int) $data['expected_version']) {
                throw new ConflictHttpException('The payment review has changed. Refresh before reviewing.');
            }
            if ($data['outcome'] === 'verified') {
                if ($context->customerProfile->operational_status === CustomerStatus::Archived) {
                    throw new ConflictHttpException('Restore the Archived Customer before verifying new payment evidence.');
                }
                app(FinancialPeriodService::class)->assertOpen($evidence->received_date, $evidence->timezone, true);
                $method = $this->methods->resolve($evidence->collection_method_version_id, true);
                if (strtoupper(trim($data['verified_reference'])) !== $evidence->method_reference
                    || app(CollectionService::class)->amountToKobo($data['verified_amount_ngn']) !== (int) $evidence->amount_kobo
                    || $data['verified_destination_key'] !== $method->destination_key) {
                    throw ValidationException::withMessages(['verified_reference' => 'The independent receipt evidence must match the payment reference, exact amount and configured destination.']);
                }
            }
            $id = DB::table('collection_evidence_reviews')->insertGetId([
                'operation_reference' => $data['operation_reference'], 'payload_hash' => $hash,
                'collection_payment_evidence_id' => $evidence->id, 'version' => $version + 1, 'outcome' => $data['outcome'],
                'reviewed_by_user_id' => $actor->id, 'reason' => Crypt::encryptString($data['reason']), 'created_at' => now(),
            ]);
            AuditEvent::record('collection.evidence_reviewed', 'collection_payment_evidence', $evidence->id, $reference,
                ['review_id' => $id, 'version' => $version + 1, 'outcome' => $data['outcome']], $actor);

            return $id;
        }, attempts: 3);
    }

    public function authorized(User $actor, string $reference): \stdClass
    {
        $evidence = DB::table('collection_payment_evidence')->where('evidence_reference', $reference)->first();
        abort_if($evidence === null, 404);
        $customer = CustomerProfile::query()->findOrFail($evidence->customer_profile_id);
        Gate::forUser($actor)->authorize('view', $customer);
        abort_unless($actor->user_type === UserType::Agent || app(AuthorizationService::class)->allows($actor, AdminPermission::ReconciliationManage), 403);

        return $evidence;
    }

    public function unresolved(): Builder
    {
        $latest = DB::table('collection_evidence_reviews')->select('collection_payment_evidence_id')
            ->selectRaw('MAX(id) AS review_id')->groupBy('collection_payment_evidence_id');

        return DB::table('collection_payment_evidence as evidence')
            ->leftJoinSub($latest, 'latest_review', 'latest_review.collection_payment_evidence_id', '=', 'evidence.id')
            ->leftJoin('collection_evidence_reviews as evidence_review', 'evidence_review.id', '=', 'latest_review.review_id')
            ->whereNotIn('evidence.id', DB::table('collection_receipts')->whereNotNull('collection_payment_evidence_id')->select('collection_payment_evidence_id'))
            ->where(static function (Builder $query): void {
                $query->whereNull('evidence_review.outcome')->orWhere('evidence_review.outcome', '!=', 'rejected');
            });
    }

    /** @return array<string, mixed> */
    public function details(User $actor, string $reference): array
    {
        $evidence = $this->authorized($actor, $reference);
        $method = DB::table('collection_method_versions')->where('id', $evidence->collection_method_version_id)->first();
        $review = DB::table('collection_evidence_reviews')->where('collection_payment_evidence_id', $evidence->id)->orderByDesc('version')->first();

        $customer = CustomerProfile::query()->with('user')->whereKey($evidence->customer_profile_id)->sole();

        return [
            'customer_id' => $customer->customer_id, 'customer_name' => $customer->user?->name,
            'collection_method_version_id' => (int) $evidence->collection_method_version_id,
            'recorded_by_user_id' => (int) $evidence->recorded_by_user_id,
            'consumed' => DB::table('collection_receipts')->where('collection_payment_evidence_id', $evidence->id)->exists(),
            'review_operation_reference' => $review?->operation_reference,
            'evidence_reference' => $reference, 'customer_profile_id' => $evidence->customer_profile_id,
            'method_label' => $method?->label, 'method_key' => $method?->method_key, 'destination_key' => $method?->destination_key,
            'method_reference' => $evidence->method_reference, 'received_date' => $evidence->received_date,
            'amount_kobo' => (int) $evidence->amount_kobo, 'source_attestation' => Crypt::decryptString($evidence->source_attestation),
            'status' => $review->outcome ?? 'pending', 'review_version' => (int) ($review->version ?? 0),
            'review_reason' => $review === null ? null : Crypt::decryptString($review->reason),
            'files' => DB::table('collection_evidence_files')->where('collection_payment_evidence_id', $evidence->id)->get(['id', 'mime_type', 'byte_size'])->toArray(),
        ];
    }

    public function file(User $actor, string $reference, int $fileId): \stdClass
    {
        $evidence = $this->authorized($actor, $reference);
        $file = DB::table('collection_evidence_files')->where('collection_payment_evidence_id', $evidence->id)->where('id', $fileId)->first();
        abort_if($file === null, 404);

        return $file;
    }

    public function fileBytes(\stdClass $file): string
    {
        return $this->files->fileBytes($file);
    }
}
