<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionSettlementService
{
    public function __construct(private CollectionEvidenceFiles $files) {}

    /** @param array<string, mixed> $data
     * @param  list<UploadedFile>  $uploads
     */
    public function record(User $actor, CollectionBatch $batch, array $data, array $uploads): string
    {
        $this->authorize($actor);
        $amount = app(CollectionService::class)->amountToKobo($data['amount_ngn']);
        if ($amount < 1 || count($uploads) < 1 || count($uploads) > 3 || ! ($data['confirmed'] ?? false)
            || trim($data['reason']) === '' || trim($data['source_attestation']) === '') {
            throw ValidationException::withMessages(['files' => 'Confirm the actual bank amount and attach one to three settlement evidence files.']);
        }
        $prepared = [];
        try {
            foreach ($uploads as $upload) {
                $prepared[] = $this->files->prepareFile($upload);
            }
            $payload = [...$data, 'bank_reference' => strtoupper(trim($data['bank_reference'])),
                'source_attestation' => trim($data['source_attestation']), 'reason' => trim($data['reason'])];
            $hash = AuditProjection::digest(['actor' => $actor->id, 'batch' => $batch->id, 'data' => $payload,
                'checksums' => array_column($prepared, 'checksum')]);

            return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $batch, $payload, $amount, $hash, $prepared): string {
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $this->authorize($actor);
                $customers = $batch->receipts()->pluck('customer_profile_id')->unique()->all();
                foreach (CustomerProfile::query()->whereIn('id', $customers)->orderBy('id')->lockForUpdate()->get() as $customer) {
                    if ($customer->operational_status === CustomerStatus::Archived) {
                        throw new ConflictHttpException('Restore affected Archived Customers before changing settlement evidence.');
                    }
                }
                $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $existing = DB::table('collection_settlements')->where('settlement_reference', $payload['settlement_reference'])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        throw new ConflictHttpException('The settlement reference belongs to another request.');
                    }
                    $this->assertPosted($existing);

                    return $existing->settlement_reference;
                }
                if ($current->custody_account_code !== 'payment_clearing_ngn' || ! in_array($current->status, ['ready_for_review', 'in_review', 'exception'], true) || $current->version !== (int) $payload['batch_version']) {
                    throw new ConflictHttpException('Refresh a frozen clearing batch before recording its actual bank settlement.');
                }
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $payload['settled_date'], $current->timezone);
                if ($date === null || $date->format('Y-m-d') !== $payload['settled_date']
                    || $date->isAfter(CarbonImmutable::now($current->timezone)->startOfDay())
                    || $date->toDateString() < $current->received_date) {
                    throw new ConflictHttpException('Settlement must be dated between capture and today in the batch timezone.');
                }
                app(FinancialPeriodService::class)->assertOpen($payload['settled_date'], $current->timezone, true);
                if (app(CollectionReadService::class)->hasPendingCorrectionForBatches(DB::table('collection_batches')->where('id', $current->id))) {
                    throw new ConflictHttpException('Resolve pending collection corrections before changing custody settlement.');
                }
                $position = app(CollectionBatchPosition::class)->read($current);
                if ($amount > $position['outstanding_kobo']) {
                    throw new ConflictHttpException('Actual bank settlement exceeds outstanding clearing custody.');
                }
                $bank = app(CollectionMethodCatalogue::class)->settlementDestination((int) $payload['bank_method_version_id'], true);
                $referenceHash = AuditProjection::digest(['method' => 'transfer', 'destination' => $bank->destination_key, 'reference' => $payload['bank_reference']]);
                if (DB::table('collection_bank_reference_claims')->where('reference_hash', $referenceHash)->exists()) {
                    throw new ConflictHttpException('This bank reference is already claimed by payment or settlement evidence.');
                }
                DB::table('collection_bank_reference_claims')->insert(['reference_hash' => $referenceHash,
                    'source_type' => 'settlement', 'source_reference' => $payload['settlement_reference'], 'created_at' => now()]);
                $accounts = LedgerAccount::query()->whereIn('code', ['business_bank_ngn', 'payment_clearing_ngn'])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy(fn (LedgerAccount $account): string => $account->code->value);
                $method = DB::table('collection_method_versions')->where('id', $current->collection_method_version_id)->first();
                foreach ($accounts as $account) {
                    if ($account->account_class !== LedgerAccountClass::Asset || $account->normal_balance !== LedgerEntrySide::Debit
                        || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN' || $account->retired_at !== null
                        || $account->effective_at?->isFuture()) {
                        throw new ConflictHttpException('Bank and clearing mappings must be available before settlement.');
                    }
                }
                if ($accounts->count() !== 2 || $method === null
                    || $accounts['payment_clearing_ngn']->version !== (int) $method->mapping_version) {
                    throw new ConflictHttpException('The captured clearing mapping changed.');
                }
                $reference = $payload['settlement_reference'];
                $group = LedgerPostingGroup::create([
                    'posting_reference' => 'SET-'.Str::uuid(), 'idempotency_key' => 'collection-settlement-'.$reference,
                    'payload_hash' => $hash, 'source_type' => 'collection_settlement', 'source_id' => $reference,
                    'event_type' => 'clearing_settlement', 'currency' => 'NGN', 'actor_user_id' => $actor->id,
                    'customer_profile_id' => null, 'occurred_at' => $date->utc(), 'occurred_on' => $payload['settled_date'],
                    'business_timezone' => $current->timezone, 'schema_version' => 1, 'correlation_id' => 'collection-settlement-'.$reference,
                    'committed_at' => now(), 'metadata' => ['batch_id' => $current->id],
                ]);
                foreach (['business_bank_ngn' => LedgerEntrySide::Debit, 'payment_clearing_ngn' => LedgerEntrySide::Credit] as $index => $side) {
                    LedgerEntry::create(['ledger_posting_group_id' => $group->id,
                        'line_number' => $side === LedgerEntrySide::Debit ? 1 : 2, 'ledger_account_id' => $accounts[$index]->id,
                        'side' => $side, 'amount_kobo' => $amount]);
                }
                $id = DB::table('collection_settlements')->insertGetId([
                    'settlement_reference' => $reference, 'payload_hash' => $hash, 'bank_reference_hash' => $referenceHash,
                    'collection_batch_id' => $current->id, 'batch_version' => $current->version,
                    'bank_method_version_id' => $bank->id, 'clearing_mapping_version' => $accounts['payment_clearing_ngn']->version,
                    'bank_mapping_version' => $bank->mapping_version, 'confirmed_by_user_id' => $actor->id,
                    'ledger_posting_group_id' => $group->id, 'bank_reference' => $payload['bank_reference'],
                    'settled_date' => $payload['settled_date'], 'timezone' => $current->timezone, 'amount_kobo' => $amount,
                    'source_attestation' => Crypt::encryptString($payload['source_attestation']), 'reason' => Crypt::encryptString($payload['reason']),
                    'created_at' => now(),
                ]);
                foreach ($prepared as $file) {
                    DB::table('collection_settlement_files')->insert(['collection_settlement_id' => $id, ...$file]);
                }
                $current->increment('version');
                AuditEvent::record('collection.settlement_confirmed', 'collection_settlement', $id, $reference,
                    ['batch_id' => $current->id, 'amount_kobo' => $amount, 'method_version_id' => $bank->id, 'file_count' => count($prepared)], $actor,
                    context: ['executor' => self::class, 'required_permission' => AdminPermission::ReconciliationManage->value]);
                app(LedgerTransactionProjectionService::class)->projectSettlement($id);

                return $reference;
            }, attempts: 3);
        } finally {
            foreach ($prepared as $file) {
                $this->files->removeUnreferencedFile($file['storage_path']);
            }
        }
    }

    public function authorize(User $actor): void
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::ReconciliationManage), 403);
        abort_unless(app(FreshAuthenticationService::class)->isFresh($actor, request()), 403);
    }

    public function assertPosted(\stdClass $settlement, bool $verifyFiles = false): LedgerPostingGroup
    {
        $group = LedgerPostingGroup::query()->whereKey($settlement->ledger_posting_group_id)->with('entries.account')->firstOrFail();
        $bank = DB::table('collection_method_versions')->where('id', $settlement->bank_method_version_id)->first();
        $batch = CollectionBatch::query()->whereKey($settlement->collection_batch_id)->firstOrFail();
        $clearing = DB::table('collection_method_versions')->where('id', $batch->collection_method_version_id)->first();
        $claim = DB::table('collection_bank_reference_claims')->where('reference_hash', $settlement->bank_reference_hash)->first();
        if ($bank === null || $bank->method_key !== 'transfer' || $bank->custody_account_code !== 'business_bank_ngn'
            || $batch->custody_account_code !== 'payment_clearing_ngn' || $batch->timezone !== $settlement->timezone
            || $clearing === null || (int) $clearing->mapping_version !== (int) $settlement->clearing_mapping_version
            || (int) $bank->mapping_version !== (int) $settlement->bank_mapping_version
            || $claim?->source_type !== 'settlement' || $claim->source_reference !== $settlement->settlement_reference
            || $group->event_type !== 'clearing_settlement' || $group->source_type !== 'collection_settlement'
            || $group->source_id !== $settlement->settlement_reference || $group->currency !== 'NGN'
            || $group->payload_hash !== $settlement->payload_hash || $group->customer_profile_id !== null
            || $group->actor_user_id !== (int) $settlement->confirmed_by_user_id
            || $group->entries->where('side', LedgerEntrySide::Debit)->count() !== 1
            || $group->entries->where('side', LedgerEntrySide::Credit)->count() !== 1
            || $group->occurred_on?->toDateString() !== $settlement->settled_date || $group->business_timezone !== $settlement->timezone
            || $group->entries->count() !== 2 || (int) $settlement->amount_kobo < 1) {
            throw new ConflictHttpException('Settlement and its authoritative custody posting do not reconcile.');
        }
        $referenceHash = AuditProjection::digest(['method' => 'transfer', 'destination' => $bank->destination_key, 'reference' => $settlement->bank_reference]);
        if (! hash_equals($referenceHash, $settlement->bank_reference_hash)) {
            throw new ConflictHttpException('The settlement bank reference does not match its destination claim.');
        }
        foreach ($group->entries as $line) {
            $expected = $line->side === LedgerEntrySide::Debit ? LedgerAccountCode::BusinessBank : LedgerAccountCode::PaymentClearing;
            if ($line->account->code !== $expected || $line->amount_kobo !== (int) $settlement->amount_kobo
                || $line->account->currency !== 'NGN' || $line->customer_profile_id !== null || $line->agent_profile_id !== null
                || $line->account->account_class !== LedgerAccountClass::Asset || $line->account->normal_balance !== LedgerEntrySide::Debit
                || $line->fee_obligation_id !== null || $line->thrift_plan_id !== null) {
                throw new ConflictHttpException('Settlement cannot change Customer savings, fees or Agent responsibility.');
            }
        }
        if ($verifyFiles) {
            $files = DB::table('collection_settlement_files')->where('collection_settlement_id', $settlement->id)->get();
            if ($files->isEmpty()) {
                throw new ConflictHttpException('Settlement evidence is unavailable.');
            }
            foreach ($files as $file) {
                $this->files->fileBytes($file);
            }
        }

        return $group;
    }
}
