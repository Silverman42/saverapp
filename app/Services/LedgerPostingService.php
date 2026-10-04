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
use App\Models\ManualCharge;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LedgerPostingService
{
    public function assertCollectionFeeAccounts(LedgerAccountCode $custody, bool $forUpdate = false): void
    {
        $expected = $this->collectionReceiptPattern($custody);
        $this->assertMappedAccounts($expected, $forUpdate);
    }

    public function assertSavingsFeeApplicationAccounts(bool $forUpdate = false): void
    {
        $this->assertMappedAccounts($this->savingsApplicationPattern(), $forUpdate);
    }

    /** @param list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}> $expected */
    private function assertMappedAccounts(array $expected, bool $forUpdate): void
    {
        $accounts = $this->accountsForPattern($expected, $forUpdate);
        foreach ($expected as [$code, $side, $class]) {
            if (! $this->hasRequiredMapping($accounts->get($code->value), $code, $class, 'NGN')) {
                throw new ServiceUnavailableHttpException(null, 'Required fee ledger mapping is unavailable.');
            }
        }
    }

    /** @return list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}> */
    private function savingsApplicationPattern(): array
    {
        return [
            [LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit, LedgerAccountClass::CustomerSavingsLiability],
            [LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, LedgerAccountClass::FeeIncome],
        ];
    }

    /** @return list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}> */
    private function collectionReceiptPattern(LedgerAccountCode $custody): array
    {
        $custodyClass = match ($custody) {
            LedgerAccountCode::AgentReceivable => LedgerAccountClass::AgentReceivable,
            LedgerAccountCode::UnappliedFunds => LedgerAccountClass::UnappliedFunds,
            LedgerAccountCode::BusinessBank, LedgerAccountCode::PaymentClearing => LedgerAccountClass::Asset,
            default => throw new ConflictHttpException('The receipt custody account is unsupported.'),
        };

        return [
            [$custody, LedgerEntrySide::Debit, $custodyClass],
            [LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, LedgerAccountClass::FeeIncome],
        ];
    }

    /**
     * @param  list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}>  $expected
     * @return Collection<string, LedgerAccount>
     */
    private function accountsForPattern(array $expected, bool $forUpdate): Collection
    {
        return LedgerAccount::query()->whereIn('code', array_map(static fn (array $line): string => $line[0]->value, $expected))
            ->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->get()->keyBy(static fn (LedgerAccount $account): string => $account->code->value);
    }

    private function hasRequiredMapping(?LedgerAccount $account, LedgerAccountCode $code, LedgerAccountClass $class, string $currency): bool
    {
        return $account !== null && $account->mapping_status === 'mapped'
            && $account->account_class === $class && $account->normal_balance === $this->normalBalance($code)
            && $account->currency === $currency;
    }

    /**
     * Post an approved fee event as a balanced, immutable group.
     *
     * Callers must already own an authorized fee or collection workflow. This boundary
     * deliberately accepts only mapped, two-line fee patterns and no client commands.
     */
    public function postFee(LedgerPostingCommand $command): LedgerPostingGroup
    {
        $payloadHash = $this->payloadHash($command);

        return app(PlatformGuard::class)->transaction('financial', function () use ($command, $payloadHash): LedgerPostingGroup {
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
            $expected = $this->expectedPattern($command);
            $this->validateCommand($command, $expected);

            $obligation = FeeObligation::query()
                ->whereKey($command->lines[0]->feeObligationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($obligation->customer_profile_id !== $command->customerProfileId) {
                throw new ConflictHttpException('Fee obligation and Customer dimensions do not match.');
            }

            $obligation->setRelation('entries', $obligation->entries()->lockForUpdate()->get());

            $accountCodes = array_map(static fn (array $line): string => $line[0]->value, $expected);
            $accounts = $this->accountsForPattern($expected, true);

            if ($accounts->count() !== count(array_unique($accountCodes))) {
                throw new ConflictHttpException('Required fee ledger accounts are unavailable.');
            }

            foreach ($expected as [$code, $side, $class]) {
                if (! $this->hasRequiredMapping($accounts->get($code->value), $code, $class, $command->currency)) {
                    throw new ConflictHttpException('Fee ledger posting is disabled until every required accounting destination is mapped.');
                }
            }

            $postingAmountKobo = $command->lines[0]->amountKobo;
            $outstandingKobo = $obligation->outstandingAmountKobo();
            if (in_array($command->eventType, [FeeLedgerPostingType::ExternalFeeReceipt, FeeLedgerPostingType::UnappliedFeeApplication, FeeLedgerPostingType::SavingsFeeApplication], true)
                && ($postingAmountKobo > $outstandingKobo
                    || ($command->eventType === FeeLedgerPostingType::SavingsFeeApplication && $postingAmountKobo !== $outstandingKobo))) {
                throw new ConflictHttpException('Fee settlement exceeds the outstanding balance or is not a full savings application.');
            }

            if (in_array($command->eventType, [FeeLedgerPostingType::SavingsFeeRefund, FeeLedgerPostingType::ExternalRefundEntitlement], true)
                && $postingAmountKobo > $this->refundableAmountKobo($obligation)) {
                throw new ConflictHttpException('Fee refund exceeds a retained paid or applied amount.');
            }

            $application = null;
            if ($command->sourceType === 'fee_savings_application') {
                $application = DB::table('fee_savings_applications')->where('operation_reference', $command->sourceId)->lockForUpdate()->first();
                if ($application === null || (int) $application->customer_profile_id !== $command->customerProfileId
                    || (int) $application->fee_obligation_id !== $obligation->id || (int) $application->actor_user_id !== $actor?->id
                    || (int) $application->amount_kobo !== $postingAmountKobo || $application->currency !== 'NGN'
                    || $application->customer_description !== trim($command->customerDescription)
                    || ! ThriftPlan::query()->whereKey($application->thrift_plan_id)->where('customer_profile_id', $customer->id)->exists()) {
                    throw new ConflictHttpException('The savings application has no matching reviewed owner.');
                }
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
                if ($command->sourceType === 'collection_receipt') {
                    app(CollectionReceiptMethod::class)->assertReceipt($receipt);
                    $isReplacement = $receipt->replacement_reversal_id !== null;
                    if (($command->eventType === FeeLedgerPostingType::UnappliedFeeApplication) !== $isReplacement
                        || ($command->lines[0]->accountCode === LedgerAccountCode::AgentReceivable
                            && $command->lines[0]->agentProfileId !== $receipt->recording_agent_profile_id)
                        || $expected !== $this->expectedPattern($command)) {
                        throw new ConflictHttpException('The fee receipt custody or replacement source does not match.');
                    }
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

            $planId = $application === null ? ($receipt->thrift_plan_id ?? $this->planForObligation($obligation)) : (int) $application->thrift_plan_id;
            if ($command->eventType === FeeLedgerPostingType::SavingsFeeRefund) {
                $planId = $this->savingsRefundCycle($obligation, true);
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
                'thrift_plan_id' => $planId,
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
                        ? $planId : null,
                ]);
            }

            $entryType = match ($command->eventType) {
                FeeLedgerPostingType::ExternalFeeReceipt,
                FeeLedgerPostingType::UnappliedFeeApplication,
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
    private function expectedPattern(LedgerPostingCommand $command): array
    {
        return match ($command->eventType) {
            FeeLedgerPostingType::UnappliedFeeApplication => $this->collectionReceiptPattern(LedgerAccountCode::UnappliedFunds),
            FeeLedgerPostingType::ExternalFeeReceipt => $this->externalReceiptPattern($command),
            FeeLedgerPostingType::SavingsFeeApplication => $this->savingsApplicationPattern(),
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

    /** @return list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}> */
    private function externalReceiptPattern(LedgerPostingCommand $command): array
    {
        $parts = explode('-', $command->sourceId, 2);
        if ($command->sourceType !== 'collection_receipt' || ! ctype_digit($parts[0])) {
            throw new ConflictHttpException('An external fee must identify its authoritative receipt.');
        }
        $receipt = CollectionReceipt::query()->whereKey((int) $parts[0])->first();
        if ($receipt === null || $receipt->replacement_reversal_id !== null) {
            throw new ConflictHttpException('An external fee requires a physical receipt.');
        }
        $code = app(CollectionReceiptMethod::class)->assertReceipt($receipt);

        return $this->collectionReceiptPattern($code);
    }

    /**
     * @param  list<array{0: LedgerAccountCode, 1: LedgerEntrySide, 2: LedgerAccountClass}>  $expected
     */
    private function validateCommand(LedgerPostingCommand $command, array $expected): void
    {
        if ($command->currency !== 'NGN'
            || $command->idempotencyKey === ''
            || mb_strlen($command->idempotencyKey) > 120
            || ($command->sourceType !== $this->expectedSourceType($command->eventType)
                && ! ($command->eventType === FeeLedgerPostingType::SavingsFeeApplication && $command->sourceType === 'fee_savings_application'))
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
            FeeLedgerPostingType::ExternalFeeReceipt, FeeLedgerPostingType::UnappliedFeeApplication => 'collection_receipt',
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
            LedgerAccountCode::BusinessCash,
            LedgerAccountCode::BusinessBank,
            LedgerAccountCode::PaymentClearing,
            LedgerAccountCode::BusinessDistributions => LedgerEntrySide::Debit,
            LedgerAccountCode::CustomerSavingsLiability,
            LedgerAccountCode::FeeIncome,
            LedgerAccountCode::RefundPayable,
            LedgerAccountCode::OtherDeductionDestination,
            LedgerAccountCode::UnappliedFunds, LedgerAccountCode::CashRecoveryClearing,
            LedgerAccountCode::PayoutClearing => LedgerEntrySide::Credit,
        };
    }

    public function planForObligation(FeeObligation $obligation): ?int
    {
        $snapshot = $obligation->feeSnapshot;
        if ($snapshot->source_type === 'plan_terms_revision') {
            $origins = PlanTermsRevision::query()->with('plan')->where('fee_snapshot_id', $snapshot->id)
                ->orderBy('revision')->orderBy('id')->get();
            $origin = $origins->first();
            if ($origin === null || $origin->plan === null || $origins->pluck('thrift_plan_id')->unique()->count() !== 1
                || $origin->plan->customer_profile_id !== $obligation->customer_profile_id
                || $snapshot->customer_profile_id !== $obligation->customer_profile_id
                || $snapshot->getRawOriginal('kind') !== 'plan' || $obligation->kind !== 'plan'
                || $obligation->source_type !== $snapshot->source_type || $obligation->source_id !== $snapshot->source_id
                || ! app(PlanFeeSnapshotBinding::class)->isValid($origin->plan, $origin, $snapshot)) {
                throw new ConflictHttpException('The fee has no verified original cycle revision.');
            }

            return $origin->thrift_plan_id;
        }
        $planId = match ($snapshot->source_type) {
            'plan' => ThriftPlan::query()->where('plan_id', $snapshot->source_id)->where('customer_profile_id', $obligation->customer_profile_id)->value('id'),
            'withdrawal' => WithdrawalRequest::query()->where('id', $snapshot->source_id)->where('customer_profile_id', $obligation->customer_profile_id)->value('thrift_plan_id'),
            'manual_charge' => ManualCharge::query()->where('operation_reference', $snapshot->source_id)->where('customer_profile_id', $obligation->customer_profile_id)->value('thrift_plan_id'),
            default => null,
        };

        return $planId;
    }

    public function savingsRefundCycle(FeeObligation $obligation, bool $current = false): ?int
    {
        if ($obligation->kind !== 'registration') {
            return $this->planForObligation($obligation);
        }
        $snapshot = $obligation->feeSnapshot;
        if ($snapshot->getRawOriginal('kind') !== 'registration' || $snapshot->source_type !== 'registration'
            || $snapshot->customer_profile_id !== $obligation->customer_profile_id
            || ! Schema::hasTable('fee_savings_applications')) {
            throw new ConflictHttpException('The registration refund has no verified savings source cycle.');
        }
        $references = $obligation->entries()->where('entry_type', FeeObligationEntryType::Settlement->value)
            ->where('source_type', 'fee_savings_application')->when($current, fn ($query) => $query->sharedLock())
            ->pluck('source_id')->unique()->map(function (mixed $reference): string {
                if (! is_string($reference)) {
                    throw new ConflictHttpException('The retained savings application identity is invalid.');
                }

                return $reference;
            })->values()->all();
        $groups = app(FeeSavingsApplicationService::class)->verifiedPostedSources(array_values($references), $current);
        $cycles = $groups->pluck('thrift_plan_id')->unique();
        if ($references === [] || $groups->count() !== count($references) || $cycles->count() !== 1 || $cycles->first() === null) {
            throw new ConflictHttpException('The registration refund has no single verified savings source cycle.');
        }

        return $cycles->first();
    }

    private function refundableAmountKobo(FeeObligation $obligation): int
    {
        $concessions = app(FeeConcessionPosition::class)->read($obligation);
        $refundable = $obligation->settledAmountKobo() - $concessions['savings_kobo'] - $concessions['external_kobo'];
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
