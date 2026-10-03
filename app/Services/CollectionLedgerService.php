<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CollectionLedgerService
{
    public function assertSavingsAccounts(LedgerAccountCode $custody, bool $forUpdate = false): void
    {
        $custodyClass = match ($custody) {
            LedgerAccountCode::AgentReceivable => LedgerAccountClass::AgentReceivable,
            LedgerAccountCode::UnappliedFunds => LedgerAccountClass::UnappliedFunds,
            LedgerAccountCode::BusinessBank, LedgerAccountCode::PaymentClearing => LedgerAccountClass::Asset,
            default => throw new ConflictHttpException('The receipt custody account is unsupported.'),
        };
        $accounts = $this->accountsForCodes([$custody, LedgerAccountCode::CustomerSavingsLiability], $forUpdate);
        foreach ([[$custody, $custodyClass], [LedgerAccountCode::CustomerSavingsLiability, LedgerAccountClass::CustomerSavingsLiability]] as [$code, $class]) {
            if (! $this->hasRequiredMapping($accounts->get($code->value), $code, $class)) {
                throw new ServiceUnavailableHttpException(null, 'Required cash ledger mapping is unavailable.');
            }
        }
    }

    /**
     * @param  list<LedgerAccountCode>  $codes
     * @return Collection<string, LedgerAccount>
     */
    private function accountsForCodes(array $codes, bool $forUpdate): Collection
    {
        return LedgerAccount::query()->whereIn('code', array_map(static fn (LedgerAccountCode $code): string => $code->value, $codes))
            ->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->get()->keyBy(static fn (LedgerAccount $account): string => $account->code->value);
    }

    private function hasRequiredMapping(?LedgerAccount $account, LedgerAccountCode $code, LedgerAccountClass $class): bool
    {
        $normalSide = in_array($code, [LedgerAccountCode::BusinessCash, LedgerAccountCode::BusinessBank, LedgerAccountCode::PaymentClearing, LedgerAccountCode::AgentReceivable], true)
            ? LedgerEntrySide::Debit : LedgerEntrySide::Credit;

        return $account !== null && $account->account_class === $class && $account->normal_balance === $normalSide
            && $account->mapping_status === 'mapped' && $account->currency === 'NGN' && $account->version >= 1;
    }

    public function postCashSavings(int $receiptId, int $customerId, int $agentId, int $amountKobo, User $actor): LedgerPostingGroup
    {
        return $this->post('cash_contribution', 'collection_receipt', (string) $receiptId,
            'collection-savings-'.$receiptId, $customerId, $agentId, $amountKobo,
            LedgerAccountCode::AgentReceivable, LedgerAccountClass::AgentReceivable,
            LedgerAccountCode::CustomerSavingsLiability, LedgerAccountClass::CustomerSavingsLiability, $actor);
    }

    public function postCashRemittance(int $remittanceId, int $agentId, int $amountKobo, User $actor): LedgerPostingGroup
    {
        return $this->post('cash_remittance', 'cash_remittance', (string) $remittanceId,
            'collection-remittance-'.$remittanceId, null, $agentId, $amountKobo,
            LedgerAccountCode::BusinessCash, LedgerAccountClass::Asset,
            LedgerAccountCode::AgentReceivable, LedgerAccountClass::AgentReceivable, $actor);
    }

    private function post(
        string $eventType, string $sourceType, string $sourceId, string $idempotencyKey,
        ?int $customerId, int $agentId, int $amountKobo,
        LedgerAccountCode $debitCode, LedgerAccountClass $debitClass,
        LedgerAccountCode $creditCode, LedgerAccountClass $creditClass, User $actor,
    ): LedgerPostingGroup {
        if (DB::transactionLevel() === 0 || $amountKobo < 1 || $amountKobo > 999_999_999_999) {
            throw new LogicException('Collection postings require a valid amount in the owning transaction.');
        }

        app(PlatformGuard::class)->assertAllowed('financial', true);

        if ($customerId !== null) {
            $customer = CustomerProfile::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
            $existing = LedgerPostingGroup::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null && $customer->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Restore the Archived Customer before posting a contribution.');
            }
        }

        $source = $sourceType === 'collection_receipt'
            ? DB::table('collection_receipts')->where('id', $sourceId)->first()
            : DB::table('cash_remittances')->where('id', $sourceId)->first();
        if ($source === null) {
            throw new ConflictHttpException('The authoritative cash source is unavailable.');
        }
        $sourceData = (array) $source;
        if ($sourceType === 'collection_receipt') {
            $receipt = (new CollectionReceipt)->newFromBuilder($sourceData);
            $replacement = $receipt->replacement_reversal_id !== null;
            $custody = app(CollectionReceiptMethod::class)->assertReceipt($receipt);
            $debitCode = $replacement ? LedgerAccountCode::UnappliedFunds : $custody;
            $debitClass = match ($debitCode) {
                LedgerAccountCode::AgentReceivable => LedgerAccountClass::AgentReceivable,
                LedgerAccountCode::UnappliedFunds => LedgerAccountClass::UnappliedFunds,
                default => LedgerAccountClass::Asset,
            };
            $eventType = $replacement ? 'unapplied_replacement' : ($receipt->method === 'cash' ? 'cash_contribution' : 'noncash_contribution');
            if ((int) $sourceData['customer_profile_id'] !== $customerId
                || (int) $sourceData['recording_agent_profile_id'] !== $agentId
                || $receipt->savings_amount_kobo !== $amountKobo) {
                throw new ConflictHttpException('Collection source dimensions changed.');
            }
            $occurredOn = $sourceData['received_date'];
            $timezone = $sourceData['timezone'];
            $planId = $sourceData['thrift_plan_id'];
            $correlationId = 'collection-receipt-'.$sourceId;
        } else {
            $batch = DB::table('collection_batches')->where('id', $sourceData['collection_batch_id'])->first();
            if ($batch === null || $batch->custody_account_code !== LedgerAccountCode::AgentReceivable->value) {
                throw new ConflictHttpException('A bank or clearing receipt cannot be remitted as Agent cash.');
            }
            if ((int) $sourceData['agent_profile_id'] !== $agentId || (int) $sourceData['amount_kobo'] !== $amountKobo
                || (int) $sourceData['confirmed_by_user_id'] !== $actor->id || $customerId !== null) {
                throw new ConflictHttpException('Remittance source dimensions changed.');
            }
            $occurredOn = $sourceData['handoff_date'];
            $timezone = DB::table('collection_batches')->where('id', $sourceData['collection_batch_id'])->value('timezone');
            if (! is_string($timezone)) {
                throw new ConflictHttpException('The authoritative remittance timezone is unavailable.');
            }
            $planId = null;
            $correlationId = 'cash-remittance-'.$sourceId;
        }

        $accounts = $this->accountsForCodes([$debitCode, $creditCode], true);
        foreach ([[$debitCode, $debitClass], [$creditCode, $creditClass]] as [$code, $class]) {
            if (! $this->hasRequiredMapping($accounts->get($code->value), $code, $class)) {
                throw new ConflictHttpException('Required cash ledger mapping is unavailable.');
            }
        }

        if (LedgerPostingGroup::query()->where('idempotency_key', $idempotencyKey)
            ->orWhere(fn ($query) => $query->where('source_type', $sourceType)->where('source_id', $sourceId))->exists()) {
            throw new ConflictHttpException('This financial source already has a posting.');
        }

        $group = LedgerPostingGroup::create([
            'posting_reference' => 'COL-'.Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', json_encode([$eventType, $sourceType, $sourceId, $customerId, $agentId, $amountKobo], JSON_THROW_ON_ERROR)),
            'source_type' => $sourceType, 'source_id' => $sourceId, 'event_type' => $eventType,
            'currency' => 'NGN', 'actor_user_id' => $actor->id, 'customer_profile_id' => $customerId,
            'occurred_at' => CarbonImmutable::parse($occurredOn, $timezone)->startOfDay()->utc(),
            'occurred_on' => $occurredOn, 'business_timezone' => $timezone,
            'schema_version' => 1, 'correlation_id' => $correlationId, 'thrift_plan_id' => $planId,
            'committed_at' => now(), 'metadata' => ['agent_profile_id' => $agentId],
        ]);
        foreach ([[$debitCode, LedgerEntrySide::Debit], [$creditCode, LedgerEntrySide::Credit]] as $index => [$code, $side]) {
            LedgerEntry::create([
                'ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                'ledger_account_id' => $accounts[$code->value]->id, 'side' => $side,
                'amount_kobo' => $amountKobo, 'customer_profile_id' => $customerId,
                'agent_profile_id' => $code === LedgerAccountCode::AgentReceivable ? $agentId : null,
                'fee_obligation_id' => null,
                'thrift_plan_id' => $code === LedgerAccountCode::CustomerSavingsLiability ? $planId : null,
            ]);
        }
        AuditEvent::record('ledger.collection_posted', LedgerPostingGroup::class, $group->id, $group->posting_reference, [
            'event_type' => $eventType, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'customer_profile_id' => $customerId, 'agent_profile_id' => $agentId, 'amount_kobo' => $amountKobo,
        ], $actor,
            context: ['executor' => self::class]
        );

        return $group;
    }
}
