<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Settles a correction's held (unapplied) cash into the Customer wallet when its original plan is no longer Active, so there is
 * no plan to record a replacement payment on. The physical tender stays in its original batch custody; only the Customer's claim
 * moves from unapplied funds to the wallet. This consumes the correction exactly once, like a replacement receipt.
 */
class ControlledTenderWalletCreditService
{
    public const SOURCE_TYPE = 'controlled_wallet_credit';

    public const EVENT_TYPE = 'controlled_tender_wallet_credit';

    public function __construct(private CollectionReplacementService $replacements) {}

    /**
     * @return array{reversal_id: string, customer_id: string, plan_id: ?string, plan_status: ?string, amount_kobo: int, preview_fingerprint: string}
     */
    public function preview(User $actor, ReversalRequest $reversal): array
    {
        $customer = CustomerProfile::query()->findOrFail($reversal->customer_profile_id);
        Gate::forUser($actor)->authorize('recordCollection', $customer);
        abort_unless(config('collections.receipt_corrections_enabled', false), 503);
        app(WalletFeature::class)->ensureActive();
        if ($customer->operational_status->isRestricted() || $customer->operational_status->isArchived()) {
            throw new ConflictHttpException('This Customer cannot receive corrected funds while Restricted or Archived.');
        }
        [$source, $amount] = $this->source($reversal, $customer, DB::transactionLevel() > 0);
        $plan = $source->thrift_plan_id === null ? null : ThriftPlan::query()->find($source->thrift_plan_id);
        if ($plan?->status === ThriftPlanStatus::Active) {
            throw new ConflictHttpException('The original plan is still active. Record a replacement payment on it instead.');
        }
        $wallet = $this->walletAccount(DB::transactionLevel() > 0);

        return ['reversal_id' => $reversal->reversal_id, 'customer_id' => $customer->customer_id, 'plan_id' => $plan?->plan_id,
            'plan_status' => $plan?->status->value, 'amount_kobo' => $amount,
            'preview_fingerprint' => hash('sha256', json_encode([$reversal->id, $reversal->version, $reversal->compensation_posting_group_id,
                $source->id, $amount, $plan?->id, $plan?->version, $wallet->version], JSON_THROW_ON_ERROR))];
    }

    /** @param array{attempt_reference: string, preview_fingerprint: string} $data */
    public function credit(User $actor, ReversalRequest $reversal, array $data): LedgerPostingGroup
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reversal, $data): LedgerPostingGroup {
            $customer = CustomerProfile::query()->whereKey($reversal->customer_profile_id)->lockForUpdate()->firstOrFail();
            $reversal = ReversalRequest::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();
            $existing = LedgerPostingGroup::query()->where('source_type', self::SOURCE_TYPE)->where('source_id', (string) $reversal->id)->first();
            if ($existing !== null) {
                Gate::forUser($actor)->authorize('recordCollection', $customer);
                if (($existing->metadata['attempt_reference'] ?? null) !== $data['attempt_reference']) {
                    throw new ConflictHttpException('This correction was already credited to the wallet.');
                }

                return $existing;
            }
            $quote = $this->preview($actor, $reversal);
            if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint'])) {
                throw new ConflictHttpException('The held funds changed. Review the current amount.');
            }
            [$source, $amount] = $this->source($reversal, $customer, true);
            $unapplied = LedgerAccount::query()->whereKey($this->unappliedCredit($reversal)->ledger_account_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->walletAccount(true);
            $business = BusinessProfile::current();
            $date = now($business->timezone)->toDateString();
            app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
            $group = LedgerPostingGroup::create([
                'posting_reference' => 'CWC-'.Str::uuid(), 'idempotency_key' => 'controlled-wallet-credit-'.$reversal->id,
                'payload_hash' => $quote['preview_fingerprint'], 'source_type' => self::SOURCE_TYPE, 'source_id' => (string) $reversal->id,
                'event_type' => self::EVENT_TYPE, 'currency' => 'NGN', 'actor_user_id' => $actor->id,
                'customer_profile_id' => $customer->id, 'occurred_at' => now(), 'occurred_on' => $date,
                'business_timezone' => $business->timezone, 'schema_version' => 1, 'correlation_id' => 'reversal-'.$reversal->id, 'committed_at' => now(),
                'metadata' => ['attempt_reference' => $data['attempt_reference'], 'reversal_request_id' => $reversal->id,
                    'source_receipt_id' => $source->id, 'source_plan_id' => $source->thrift_plan_id,
                    'unapplied_mapping_version' => $unapplied->version, 'wallet_mapping_version' => $wallet->version],
            ]);
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => 1, 'ledger_account_id' => $unapplied->id,
                'side' => LedgerEntrySide::Debit, 'amount_kobo' => $amount, 'customer_profile_id' => $customer->id]);
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => 2, 'ledger_account_id' => $wallet->id,
                'side' => LedgerEntrySide::Credit, 'amount_kobo' => $amount, 'customer_profile_id' => $customer->id]);
            FinancialWorkflowSupplement::create(['operation_reference' => $data['attempt_reference'], 'payload_hash' => $quote['preview_fingerprint'],
                'kind' => 'receipt_replaced_to_wallet', 'customer_profile_id' => $customer->id, 'thrift_plan_id' => $source->thrift_plan_id,
                'collection_batch_id' => $source->collection_batch_id, 'reversal_request_id' => $reversal->id, 'actor_user_id' => $actor->id,
                'facts' => ['posting_group_id' => $group->id, 'consumed_kobo' => $amount, 'source_receipt_id' => $source->id,
                    'original_custodian_id' => $source->recording_agent_profile_id],
                'evidence' => 'Held correction funds credited to the Customer wallet because the original plan is no longer active.', 'created_at' => now()]);
            AuditEvent::record('reversal.replacement_wallet_credited', ReversalRequest::class, $reversal->id, $reversal->reversal_id,
                ['customer_profile_id' => $customer->id, 'state' => $reversal->state, 'version' => $reversal->version,
                    'compensation_posting_group_id' => $reversal->compensation_posting_group_id, 'amount_kobo' => $amount,
                    'posting_reference' => $group->posting_reference],
                $actor, context: ['executor' => self::class, 'correlation_reference' => $data['attempt_reference']]);
            if ($source->thrift_plan_id !== null) {
                app(PlanPageClosureService::class)->closeIfPending($source->thrift_plan_id);
            }
            app(LedgerTransactionProjectionService::class)->projectOwnedLedgerMovement($group);

            return $group;
        }, attempts: 3);
    }

    /** The public reference of the plan the corrected receipt was originally recorded on. */
    public function sourcePlanReference(ReversalRequest $reversal): ?string
    {
        $dependencies = $reversal->getAttribute('dependency_snapshot');
        $planId = CollectionReceipt::query()->whereKey(is_array($dependencies) ? ($dependencies['summary']['receipt_id'] ?? null) : null)->value('thrift_plan_id');

        return $planId === null ? null : ThriftPlan::query()->whereKey($planId)->value('plan_id');
    }

    /** @return array{0: CollectionReceipt, 1: int} */
    private function source(ReversalRequest $reversal, CustomerProfile $customer, bool $forUpdate): array
    {
        $dependencies = $reversal->getAttribute('dependency_snapshot');
        $receipt = CollectionReceipt::query()->whereKey(is_array($dependencies) ? ($dependencies['summary']['receipt_id'] ?? null) : null)->first();
        if ($receipt === null) {
            throw new ConflictHttpException('This correction has no unconsumed controlled receipt.');
        }
        $amount = $this->replacements->controlledAmount($reversal, $receipt);

        return [$this->replacements->lockSource($reversal, $customer, $amount, $forUpdate), $amount];
    }

    private function unappliedCredit(ReversalRequest $reversal): LedgerEntry
    {
        return LedgerEntry::query()->where('ledger_posting_group_id', $reversal->compensation_posting_group_id)
            ->where('side', LedgerEntrySide::Credit)
            ->whereIn('ledger_account_id', LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->select('id'))
            ->sole();
    }

    private function walletAccount(bool $forUpdate): LedgerAccount
    {
        $wallet = LedgerAccount::query()->where('code', LedgerAccountCode::CustomerWalletLiability->value)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($wallet === null || $wallet->mapping_status !== 'mapped' || $wallet->currency !== 'NGN'
            || $wallet->account_class !== LedgerAccountClass::CustomerWalletLiability || $wallet->normal_balance !== LedgerEntrySide::Credit) {
            throw new ConflictHttpException('The Customer wallet account is unavailable.');
        }

        return $wallet;
    }
}
