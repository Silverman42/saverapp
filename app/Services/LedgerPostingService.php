<?php

namespace App\Services;

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\AccountState;
use App\Enums\CustomerStatus;
use App\Enums\FeeLedgerPostingType;
use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LedgerPostingService
{
    /**
     * Post an approved fee event as a balanced, immutable group.
     *
     * Callers must already own an authorized fee or collection workflow. This boundary
     * deliberately accepts only mapped, two-line fee patterns and no client commands.
     */
    public function postFee(LedgerPostingCommand $command): LedgerPostingGroup
    {
        $expected = $this->expectedPattern($command->eventType);
        $this->validateCommand($command, $expected);
        $payloadHash = $this->payloadHash($command);

        return app(PlatformGuard::class)->transaction('financial', function () use ($command, $expected, $payloadHash): LedgerPostingGroup {
            $actor = $command->actor === null
                ? null
                : User::query()->whereKey($command->actor->id)->lockForUpdate()->firstOrFail();
            if ($actor !== null && $actor->account_state !== AccountState::Active) {
                throw new ConflictHttpException('Inactive actors cannot post fee ledger entries.');
            }

            $customer = CustomerProfile::query()->whereKey($command->customerProfileId)->lockForUpdate()->firstOrFail();

            $existing = LedgerPostingGroup::query()
                ->where('idempotency_key', $command->idempotencyKey)
                ->orWhere(function ($query) use ($command): void {
                    $query->where('source_type', $command->sourceType)
                        ->where('source_id', $command->sourceId);
                })
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->idempotency_key !== $command->idempotencyKey
                    || $existing->source_type !== $command->sourceType
                    || $existing->source_id !== $command->sourceId
                    || ! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ConflictHttpException('A different ledger posting already owns this source or attempt identity.');
                }

                return $existing->load('entries.account');
            }

            if ($customer->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Restore the Archived Customer before posting a fee.');
            }

            $obligation = FeeObligation::query()
                ->whereKey($command->lines[0]->feeObligationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($obligation->customer_profile_id !== $command->customerProfileId) {
                throw new ConflictHttpException('Fee obligation and Customer dimensions do not match.');
            }

            $accountCodes = array_map(static fn (array $line): string => $line[0]->value, $expected);
            $accounts = LedgerAccount::query()
                ->whereIn('code', $accountCodes)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (LedgerAccount $account): string => $account->code->value);

            if ($accounts->count() !== count(array_unique($accountCodes))) {
                throw new ConflictHttpException('Required fee ledger accounts are unavailable.');
            }

            foreach ($accounts as $account) {
                $definition = collect($expected)->first(fn (array $line): bool => $line[0] === $account->code);
                $expectedNormalBalance = $this->normalBalance($account->code);
                if ($account->mapping_status !== 'mapped'
                    || $account->account_class !== $definition[2]
                    || $account->normal_balance !== $expectedNormalBalance
                    || $account->currency !== $command->currency) {
                    throw new ConflictHttpException('Fee ledger posting is disabled until every required accounting destination is mapped.');
                }
            }

            $postingAmountKobo = $command->lines[0]->amountKobo;
            $outstandingKobo = $obligation->outstandingAmountKobo();
            if (in_array($command->eventType, [FeeLedgerPostingType::ExternalFeeReceipt, FeeLedgerPostingType::SavingsFeeApplication], true)
                && ($postingAmountKobo > $outstandingKobo
                    || ($command->eventType === FeeLedgerPostingType::SavingsFeeApplication && $postingAmountKobo !== $outstandingKobo))) {
                throw new ConflictHttpException('Fee settlement exceeds the outstanding balance or is not a full savings application.');
            }

            if (in_array($command->eventType, [FeeLedgerPostingType::SavingsFeeRefund, FeeLedgerPostingType::ExternalRefundEntitlement], true)
                && $postingAmountKobo > $this->refundableAmountKobo($obligation)) {
                throw new ConflictHttpException('Fee refund exceeds a retained paid or applied amount.');
            }

            $receipt = null;
            if (in_array($command->sourceType, ['collection_receipt', 'fee_application'], true)) {
                $parts = explode('-', $command->sourceId, 2);
                if (! ctype_digit($parts[0])
                    || ($command->sourceType === 'collection_receipt'
                        && (($parts[1] ?? null) !== (string) $command->lines[0]->feeObligationId))) {
                    throw new ConflictHttpException('The fee posting has an invalid collection source.');
                }
                $receipt = CollectionReceipt::query()->whereKey((int) $parts[0])->first();
                if ($receipt === null || (int) $receipt->customer_profile_id !== $command->customerProfileId) {
                    throw new ConflictHttpException('The fee posting has no authoritative Customer receipt.');
                }
            }
            if ($receipt === null && $command->occurredAt === null) {
                throw new ConflictHttpException('The fee event has no authoritative occurrence date.');
            }
            $effectiveAt = $command->occurredAt ?? now();
            if ($receipt !== null) {
                $timezone = $receipt->timezone;
                $occurredOn = $receipt->received_date;
                $occurredAt = CarbonImmutable::parse($occurredOn, $timezone)->startOfDay()->utc();
            } else {
                $timezone = BusinessProfile::current()->timezone;
                $occurredOn = CarbonImmutable::instance($effectiveAt)->setTimezone($timezone)->toDateString();
                $occurredAt = $command->occurredAt;
            }

            $postingReference = 'FEE-'.Str::uuid();
            $group = LedgerPostingGroup::create([
                'posting_reference' => $postingReference,
                'idempotency_key' => $command->idempotencyKey,
                'payload_hash' => $payloadHash,
                'source_type' => $command->sourceType,
                'source_id' => $command->sourceId,
                'event_type' => $command->eventType->value,
                'currency' => $command->currency,
                'actor_user_id' => $actor?->id,
                'customer_profile_id' => $command->customerProfileId,
                'occurred_at' => $occurredAt,
                'occurred_on' => $occurredOn,
                'business_timezone' => $timezone,
                'schema_version' => 1,
                'correlation_id' => $receipt === null
                    ? $command->sourceType.'-'.$command->sourceId
                    : 'collection-receipt-'.$receipt->id,
                'thrift_plan_id' => $receipt?->thrift_plan_id,
                'committed_at' => now(),
                'metadata' => $command->metadata,
            ]);

            foreach ($command->lines as $index => $line) {
                LedgerEntry::create([
                    'ledger_posting_group_id' => $group->id,
                    'line_number' => $index + 1,
                    'ledger_account_id' => $accounts[$line->accountCode->value]->id,
                    'side' => $line->side,
                    'amount_kobo' => $line->amountKobo,
                    'customer_profile_id' => $line->customerProfileId,
                    'agent_profile_id' => $line->agentProfileId,
                    'fee_obligation_id' => $line->feeObligationId,
                    'thrift_plan_id' => $line->accountCode === LedgerAccountCode::CustomerSavingsLiability
                        ? $receipt?->thrift_plan_id : null,
                ]);
            }

            $entryType = match ($command->eventType) {
                FeeLedgerPostingType::ExternalFeeReceipt,
                FeeLedgerPostingType::SavingsFeeApplication => FeeObligationEntryType::Settlement,
                FeeLedgerPostingType::SavingsFeeRefund => FeeObligationEntryType::SavingsRefund,
                FeeLedgerPostingType::ExternalRefundEntitlement => FeeObligationEntryType::ExternalRefundEntitlement,
                FeeLedgerPostingType::OtherDeduction => null,
            };

            if ($entryType !== null) {
                FeeObligationEntry::create([
                    'fee_obligation_id' => $obligation->id,
                    'entry_type' => $entryType,
                    'amount_kobo' => $postingAmountKobo,
                    'currency' => $command->currency,
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'idempotency_key' => 'fee-ledger-'.$command->eventType->value.'-'.hash('sha256', $command->idempotencyKey),
                    'actor_user_id' => $actor?->id,
                    'reason' => null,
                    'customer_description' => trim($command->customerDescription),
                    'ledger_posting_reference' => $group->posting_reference,
                ]);
            }

            AuditEvent::record(
                eventType: 'ledger.fee_posted',
                targetType: LedgerPostingGroup::class,
                targetId: $group->id,
                targetReference: $group->posting_reference,
                payload: [
                    'posting_reference' => $group->posting_reference,
                    'event_type' => $command->eventType->value,
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'currency' => $command->currency,
                    'amount_kobo' => $command->lines[0]->amountKobo,
                    'customer_profile_id' => $command->customerProfileId,
                    'fee_obligation_id' => $command->lines[0]->feeObligationId,
                    'line_count' => count($command->lines),
                ],
                actor: $actor,

                context: ['executor' => self::class]
            );

            return $group->load('entries.account');
        }, attempts: 3);
    }

    /**
     * @return list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}>
     */
    private function expectedPattern(FeeLedgerPostingType $eventType): array
    {
        return match ($eventType) {
            FeeLedgerPostingType::ExternalFeeReceipt => [
                [LedgerAccountCode::AgentReceivable, LedgerEntrySide::Debit, LedgerAccountClass::AgentReceivable],
                [LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, LedgerAccountClass::FeeIncome],
            ],
            FeeLedgerPostingType::SavingsFeeApplication => [
                [LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit, LedgerAccountClass::CustomerSavingsLiability],
                [LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, LedgerAccountClass::FeeIncome],
            ],
            FeeLedgerPostingType::SavingsFeeRefund => [
                [LedgerAccountCode::FeeIncome, LedgerEntrySide::Debit, LedgerAccountClass::FeeIncome],
                [LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Credit, LedgerAccountClass::CustomerSavingsLiability],
            ],
            FeeLedgerPostingType::ExternalRefundEntitlement => [
                [LedgerAccountCode::FeeIncome, LedgerEntrySide::Debit, LedgerAccountClass::FeeIncome],
                [LedgerAccountCode::RefundPayable, LedgerEntrySide::Credit, LedgerAccountClass::RefundPayable],
            ],
            FeeLedgerPostingType::OtherDeduction => throw new ConflictHttpException('Other deductions remain disabled pending approved purposes and accounting destinations.'),
        };
    }

    /**
     * @param  list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}>  $expected
     */
    private function validateCommand(LedgerPostingCommand $command, array $expected): void
    {
        if ($command->currency !== 'NGN'
            || $command->idempotencyKey === ''
            || mb_strlen($command->idempotencyKey) > 120
            || $command->sourceType !== $this->expectedSourceType($command->eventType)
            || ! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/', $command->sourceId)
            || $command->customerProfileId === null
            || trim($command->customerDescription) === ''
            || mb_strlen($command->customerDescription) > 500
            || count($command->lines) !== 2) {
            throw new ConflictHttpException('Fee ledger command has an unsupported source, currency, or dimension.');
        }

        $amount = null;
        $debits = 0;
        $credits = 0;

        foreach ($command->lines as $index => $line) {
            if ($line->amountKobo < 1
                || $line->amountKobo > 999_999_999_999
                || $line->accountCode !== $expected[$index][0]
                || $line->side !== $expected[$index][1]
                || $line->customerProfileId !== $command->customerProfileId
                || $line->feeObligationId === null
                || $line->feeObligationId < 1
                || ($line->accountCode === LedgerAccountCode::AgentReceivable && $line->agentProfileId === null)
                || ($line->accountCode !== LedgerAccountCode::AgentReceivable && $line->agentProfileId !== null)) {
                throw new ConflictHttpException('Fee ledger lines do not match the approved posting pattern.');
            }

            if ($index === 0) {
                $amount = $line->amountKobo;
            } elseif ($line->amountKobo !== $amount) {
                throw new ConflictHttpException('Fee ledger debits and credits must balance exactly.');
            }

            if ($line->side === LedgerEntrySide::Debit) {
                $debits += $line->amountKobo;
            } else {
                $credits += $line->amountKobo;
            }

        }

        if ($debits !== $credits
            || $command->lines[0]->feeObligationId !== $command->lines[1]->feeObligationId) {
            throw new ConflictHttpException('Fee ledger debits and credits must balance for one obligation.');
        }
    }

    private function expectedSourceType(FeeLedgerPostingType $eventType): string
    {
        return match ($eventType) {
            FeeLedgerPostingType::ExternalFeeReceipt => 'collection_receipt',
            FeeLedgerPostingType::SavingsFeeApplication => 'fee_application',
            FeeLedgerPostingType::SavingsFeeRefund => 'fee_refund',
            FeeLedgerPostingType::ExternalRefundEntitlement => 'external_refund_entitlement',
            FeeLedgerPostingType::OtherDeduction => 'disabled',
        };
    }

    private function normalBalance(LedgerAccountCode $accountCode): LedgerEntrySide
    {
        return match ($accountCode) {
            LedgerAccountCode::AgentReceivable,
            LedgerAccountCode::BusinessCash => LedgerEntrySide::Debit,
            LedgerAccountCode::CustomerSavingsLiability,
            LedgerAccountCode::FeeIncome,
            LedgerAccountCode::RefundPayable,
            LedgerAccountCode::OtherDeductionDestination => LedgerEntrySide::Credit,
        };
    }

    private function refundableAmountKobo(FeeObligation $obligation): int
    {
        $totals = array_fill_keys([
            FeeObligationEntryType::Settlement->value,
            FeeObligationEntryType::SavingsRefund->value,
            FeeObligationEntryType::ExternalRefundEntitlement->value,
        ], 0);

        foreach ($obligation->entries()
            ->whereIn('entry_type', array_keys($totals))
            ->get(['entry_type', 'amount_kobo']) as $entry) {
            $entryType = $entry->entry_type->value;
            if (! array_key_exists($entryType, $totals)) {
                throw new ConflictHttpException('Fee refund history contains an unsupported entry.');
            }
            if ($entry->amount_kobo > PHP_INT_MAX - $totals[$entryType]) {
                throw new \OverflowException('Fee refund history exceeds the supported integer range.');
            }
            $totals[$entryType] += $entry->amount_kobo;
        }

        $refundable = $totals[FeeObligationEntryType::Settlement->value]
            - $totals[FeeObligationEntryType::SavingsRefund->value]
            - $totals[FeeObligationEntryType::ExternalRefundEntitlement->value];
        if ($refundable < 0) {
            throw new ConflictHttpException('Fee refund history exceeds the amount retained from the Customer.');
        }

        return $refundable;
    }

    private function payloadHash(LedgerPostingCommand $command): string
    {
        $payload = [
            'event_type' => $command->eventType->value,
            'idempotency_key' => $command->idempotencyKey,
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'currency' => $command->currency,
            'actor_id' => $command->actor?->id,
            'customer_profile_id' => $command->customerProfileId,
            'occurred_at' => $command->occurredAt?->format(DATE_ATOM),
            'customer_description' => trim($command->customerDescription),
            'lines' => array_map(static fn (LedgerPostingLine $line): array => [
                'account_code' => $line->accountCode->value,
                'side' => $line->side->value,
                'amount_kobo' => $line->amountKobo,
                'customer_profile_id' => $line->customerProfileId,
                'agent_profile_id' => $line->agentProfileId,
                'fee_obligation_id' => $line->feeObligationId,
            ], $command->lines),
            'metadata' => $command->metadata,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
