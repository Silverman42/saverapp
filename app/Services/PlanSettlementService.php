<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\FeeRuleTiming;
use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\FeeObligation;
use App\Models\FinancialWorkflowSupplement;
use App\Models\PlanLifecycleEvent;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PlanSettlementService
{
    /** @return array<string, mixed> */
    public function preview(User $actor, ThriftPlan $plan, string $action = 'close'): array
    {
        Gate::forUser($actor)->authorize('managePlan', $plan->customerProfile);
        abort_unless(config('collections.settlement_enabled', false), 503, 'Plan settlement acceptance is not yet certified.');
        app(BusinessSettings::class)->ensureFeature('plan_creation');
        $customer = $plan->customerProfile;
        $position = app(WithdrawalBalanceService::class)->position($customer, $plan, DB::transactionLevel() > 0);
        $blockers = [];
        $snapshots = $plan->termsRevisions()->pluck('fee_snapshot_id');
        $obligations = FeeObligation::query()->whereIn('fee_snapshot_id', $snapshots)->get();
        foreach ($obligations as $obligation) {
            if ($obligation->outstandingAmountKobo() > 0) {
                $blockers[] = 'Outstanding cycle fee '.$obligation->id;
            }
        }
        $snapshot = $plan->currentTermsRevision()?->feeSnapshot;
        if ($snapshot === null || (! $snapshot->isZero() && $snapshot->timing === FeeRuleTiming::CycleCompletion && $snapshot->obligation === null)) {
            $blockers[] = 'Cycle fee disposition is unavailable.';
        }
        if ($position['cycle_liability_kobo'] !== 0 || $position['cycle_reservations_kobo'] !== 0) {
            $blockers[] = 'Cycle savings or reservations remain.';
        }
        $unapplied = DB::table('reversal_requests as r')->join('ledger_posting_groups as g', 'g.id', '=', 'r.compensation_posting_group_id')
            ->where('g.thrift_plan_id', $plan->id)->where('g.event_type', 'receipt_reclassification')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('collection_receipts as replacements')->whereColumn('replacements.replacement_reversal_id', 'r.id'))->exists();
        if ($unapplied) {
            $blockers[] = 'Controlled unapplied cycle tender remains.';
        }
        $batchIds = CollectionReceipt::query()->where('thrift_plan_id', $plan->id)->whereNull('replacement_reversal_id')->pluck('collection_batch_id')->unique();
        foreach (CollectionBatch::query()->whereIn('id', $batchIds)->get() as $batch) {
            if ($batch->status !== 'reconciled' || (int) $batch->receipts()->sum('tender_amount_kobo') !== (int) $batch->remittances()->sum('amount_kobo')
                || DB::table('collection_exceptions')->where('collection_batch_id', $batch->id)->where('status', '!=', 'resolved')->exists()) {
                $blockers[] = 'Original custody and reconciliation remain unresolved.';
            }
        }
        if (DB::table('withdrawal_requests')->where('thrift_plan_id', $plan->id)->whereNotIn('state', ['posted', 'rejected', 'cancelled', 'expired'])->exists()
            || DB::table('reversal_requests')->where('customer_profile_id', $customer->id)->where('state', 'pending_review')->exists()
            || DB::table('cash_disbursements')->where('customer_profile_id', $customer->id)->whereIn('status', ['processing', 'outcome_unknown'])->exists()) {
            $blockers[] = 'Pending financial owner work remains.';
        }
        $refund = DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')->where('a.code', LedgerAccountCode::RefundPayable->value)
            ->whereIn('e.fee_obligation_id', $obligations->pluck('id'))->selectRaw("COALESCE(SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -CAST(amount_kobo AS SIGNED) END), 0) as total")->value('total');
        if ((int) $refund !== 0) {
            $blockers[] = 'Cycle refund payable remains.';
        }
        if (FeeObligation::query()->where('customer_profile_id', $customer->id)->where('kind', '!=', 'registration')->whereNotIn('fee_snapshot_id', $snapshots)
            ->whereNotIn('source_type', ['plan', 'withdrawal', 'manual_charge'])->exists()) {
            $blockers[] = 'Financial obligation attribution is unavailable.';
        }
        $resolvedIds = FinancialWorkflowSupplement::query()->where('thrift_plan_id', $plan->id)->where('kind', 'closed_exception_resolved')->get()->pluck('facts.event_id')->all();
        $exceptions = $plan->lifecycleEvents()->where('event_type', 'receipt_compensated')->where('payload->closed_plan_exception', true)->whereNotIn('id', $resolvedIds)->pluck('id')->all();
        if ($action !== 'resolve_exception' && $exceptions !== []) {
            $blockers[] = 'Post-closure correction exception remains.';
        }
        $facts = ['plan_version' => $plan->version, 'customer_version' => $customer->version,
            'assignment_version' => $customer->currentAssignment?->version, 'position' => $position,
            'fee_history' => $obligations->map(fn ($fee): array => [$fee->id, $fee->entries()->pluck('id')->all()])->all(),
            'exceptions' => $exceptions, 'blockers' => array_values(array_unique($blockers)),
            'ledger_watermark' => DB::table('ledger_posting_groups')->max('id'), 'supplement_watermark' => FinancialWorkflowSupplement::query()->max('id')];

        return [...$facts, 'action' => $action, 'can_close' => $blockers === [], 'preview_fingerprint' => hash('sha256', json_encode([$plan->id, $action, $facts], JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, mixed> $data */
    public function confirm(User $actor, ThriftPlan $plan, string $action, array $data): ThriftPlan
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $plan, $action, $data): ThriftPlan {
            $context = app(CustomerActionAuthorizationGuard::class)->lockAndAuthorize($actor, $plan->customer_profile_id, 'managePlan');
            $plan = ThriftPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $plan->id, $action, $data], JSON_THROW_ON_ERROR));
            $existing = FinancialWorkflowSupplement::query()->where('operation_reference', $data['attempt_reference'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('Changed settlement instructions conflict with this attempt.');
                }

                return $plan;
            }
            $quote = $this->preview($actor, $plan, $action);
            if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint']) || $context->customerProfile->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Settlement dependencies changed. Review the current preview.');
            }
            $prior = $plan->status;
            if ($action === 'prepare_termination') {
                if (! in_array($prior, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true) || $context->customerProfile->operational_status === CustomerStatus::Restricted) {
                    throw new ConflictHttpException('This cycle cannot prepare early termination.');
                }
                $snapshot = $plan->currentTermsRevision()->feeSnapshot;
                $principal = app(PlanFeeCorrectionService::class)->preview($plan)['principal_kobo'];
                if ($principal > 0 && $snapshot->timing === FeeRuleTiming::CycleCompletion) {
                    app(FeeObligationService::class)->assessSnapshot($snapshot, $actor);
                }
                app(PlanFeeCorrectionService::class)->synchronize($plan, $actor, $data['attempt_reference'], true);
                $plan->status = ThriftPlanStatus::Paused;
                $plan->version++;
                $plan->save();
            } elseif ($action === 'close') {
                if (! $quote['can_close'] || ($prior !== ThriftPlanStatus::Completed
                    && (! in_array($prior, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true) || ! $plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')->exists()))) {
                    throw new ConflictHttpException('All settlement gates must pass before a separate closure.');
                }
                $plan->update(['status' => ThriftPlanStatus::Closed, 'open_customer_profile_id' => null, 'version' => $plan->version + 1]);
            } elseif ($action === 'resolve_exception') {
                if ($prior !== ThriftPlanStatus::Closed || ! $quote['can_close'] || $quote['exceptions'] === []) {
                    throw new ConflictHttpException('Only fully settled closed-cycle exceptions can be resolved.');
                }
                $plan->version++;
                $plan->save();
            } else {
                throw new ConflictHttpException('Unsupported settlement action.');
            }
            $eventType = match ($action) {
                'prepare_termination' => 'early_termination_prepared', 'resolve_exception' => 'closed_exception_resolved', default => 'closed'
            };
            $event = PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => $eventType, 'from_status' => $prior, 'to_status' => $plan->status,
                'actor_user_id' => $actor->id, 'assignment_version' => $context->currentAssignment?->version, 'plan_version' => $plan->version,
                'reason' => $data['reason'], 'customer_explanation' => $data['customer_explanation'], 'payload' => $quote, 'effective_at' => now()]);
            app(ThriftPlanService::class)->createNotificationIntents($context->customerProfile, $plan, $event, $context->currentAssignment?->agentProfile?->user_id);
            FinancialWorkflowSupplement::create(['operation_reference' => $data['attempt_reference'], 'payload_hash' => $hash, 'kind' => $eventType,
                'customer_profile_id' => $plan->customer_profile_id, 'thrift_plan_id' => $plan->id, 'actor_user_id' => $actor->id,
                'facts' => ['event_id' => $action === 'resolve_exception' ? $quote['exceptions'][0] : $event->id, 'gate_fingerprint' => $quote['preview_fingerprint']], 'evidence' => $data['reason'], 'created_at' => now()]);
            if ($action === 'resolve_exception') {
                foreach (array_slice($quote['exceptions'], 1) as $exceptionId) {
                    FinancialWorkflowSupplement::create(['operation_reference' => (string) Str::uuid(), 'payload_hash' => $hash, 'kind' => $eventType,
                        'customer_profile_id' => $plan->customer_profile_id, 'thrift_plan_id' => $plan->id, 'actor_user_id' => $actor->id,
                        'facts' => ['event_id' => $exceptionId, 'resolution_operation' => $data['attempt_reference'], 'gate_fingerprint' => $quote['preview_fingerprint']],
                        'evidence' => $data['reason'], 'created_at' => now()]);
                }
            }
            AuditEvent::record('thrift_plan.'.$eventType, ThriftPlan::class, $plan->id, $plan->plan_id,
                ['customer_profile_id' => $plan->customer_profile_id, 'version' => $plan->version, 'gate_fingerprint' => $quote['preview_fingerprint']], $actor, context: ['executor' => self::class]);

            return $plan;
        }, attempts: 3);
    }
}
