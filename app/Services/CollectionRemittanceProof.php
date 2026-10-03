<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\CashRemittance;
use App\Models\CollectionBatch;
use App\Models\LedgerPostingGroup;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionRemittanceProof
{
    public function assertPosted(CashRemittance $remittance, ?CollectionBatch $batch = null): LedgerPostingGroup
    {
        $batch ??= CollectionBatch::query()->find($remittance->collection_batch_id);
        $remittance->loadMissing('postingGroup.entries.account');
        $group = $remittance->postingGroup;
        if ($batch === null || $group === null || $batch->id !== $remittance->collection_batch_id
            || $batch->custody_account_code !== 'agent_receivable_ngn' || $batch->agent_profile_id !== $remittance->agent_profile_id
            || $remittance->amount_kobo < 1 || blank($remittance->handoff_reference)
            || blank($remittance->receiving_location) || blank($remittance->source_attestation)
            || $group->source_type !== 'cash_remittance' || $group->source_id !== (string) $remittance->id
            || $group->event_type !== 'cash_remittance' || $group->currency !== 'NGN'
            || $group->actor_user_id !== $remittance->confirmed_by_user_id || $group->customer_profile_id !== null || $group->thrift_plan_id !== null
            || ($group->metadata['agent_profile_id'] ?? null) !== $batch->agent_profile_id
            || $group->occurred_on?->toDateString() !== $remittance->handoff_date || $group->business_timezone !== $batch->timezone
            || $group->occurred_at === null || $group->schema_version < 1
            || ! in_array($batch->timezone, timezone_identifiers_list(), true)
            || ! hash_equals($group->payload_hash, hash('sha256', json_encode(['cash_remittance', 'cash_remittance', (string) $remittance->id,
                null, $remittance->agent_profile_id, $remittance->amount_kobo], JSON_THROW_ON_ERROR)))) {
            throw new ConflictHttpException('The cash handoff journal does not reconcile with its original source.');
        }
        if (! $group->occurred_at->equalTo(CarbonImmutable::parse($remittance->handoff_date, $batch->timezone)->startOfDay()->utc())
            || $group->entries->count() !== 2 || $group->entries->where('side', LedgerEntrySide::Debit)->count() !== 1
            || $group->entries->where('side', LedgerEntrySide::Credit)->count() !== 1) {
            throw new ConflictHttpException('The cash handoff posting has no complete balanced custody journal.');
        }
        foreach ($group->entries as $line) {
            $debit = $line->side === LedgerEntrySide::Debit;
            if ($line->amount_kobo !== $remittance->amount_kobo || $line->customer_profile_id !== null
                || $line->fee_obligation_id !== null || $line->thrift_plan_id !== null
                || $line->agent_profile_id !== ($debit ? null : $remittance->agent_profile_id)
                || $line->account->code !== ($debit ? LedgerAccountCode::BusinessCash : LedgerAccountCode::AgentReceivable)
                || $line->account->account_class !== ($debit ? LedgerAccountClass::Asset : LedgerAccountClass::AgentReceivable)
                || $line->account->currency !== 'NGN' || $line->account->normal_balance !== LedgerEntrySide::Debit
                || $line->account->mapping_status !== 'mapped' || $line->account->version < 1) {
                throw new ConflictHttpException('The cash handoff journal contains inconsistent custody dimensions.');
            }
        }

        return $group;
    }
}
