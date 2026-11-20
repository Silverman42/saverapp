<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\CashDisbursement;
use App\Models\CollectionBatch;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Explains to staff why a Paused page that is waiting to end cannot close yet, with a link to fix each issue
 * when the viewer is allowed to act on it.
 */
class PlanClosureBlockerReadService
{
    public function __construct(
        private PlanSettlementService $settlement,
        private AuthorizationService $authorization,
        private WalletFeature $wallet,
    ) {}

    /**
     * @return list<array{code: string, title: string, description: string, actions: list<array{label: string, href: string}>}>
     */
    public function read(User $viewer, ThriftPlan $plan): array
    {
        if ($viewer->user_type === UserType::Customer || ! $this->isWaitingToEnd($plan)) {
            return [];
        }
        try {
            $quote = $this->settlement->evaluate($plan, 'close');
        } catch (HttpException|RuntimeException) {
            return [$this->issue('unavailable', 'Closure checks are unavailable',
                'The checks that decide whether this page can close could not run. Try again shortly, or contact an administrator.')];
        }

        return array_map(fn (array $detail): array => $this->describe($viewer, $plan, $detail['code'], $detail['ids']), $quote['blocker_details']);
    }

    private function isWaitingToEnd(ThriftPlan $plan): bool
    {
        return $plan->status === ThriftPlanStatus::Paused
            && ($plan->closure_pending_at !== null || $plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')->exists());
    }

    /**
     * @param  list<int>  $ids
     * @return array{code: string, title: string, description: string, actions: list<array{label: string, href: string}>}
     */
    private function describe(User $viewer, ThriftPlan $plan, string $code, array $ids): array
    {
        $customer = $plan->customerProfile;

        return match ($code) {
            'unapplied_tender' => $this->issue($code, 'Corrected payments are waiting to be re-recorded',
                $this->wallet->active()
                    ? 'A payment on this page was corrected, and the cash is held aside. This page is no longer active, so add the held amount to the customer\'s wallet.'
                    : 'A payment on this page was corrected, and the cash is held aside until a replacement payment is recorded for it.',
                $this->replacementActions($viewer, $ids)),
            'custody_unresolved' => $this->batchIssue($viewer, $code, $ids),
            'fee_outstanding' => $this->issue($code, 'Service charge is still owed',
                'This page has an unpaid service charge. It must be paid or waived before the page can close.',
                $this->feeActions($viewer)),
            'refund_payable' => $this->issue($code, 'A service charge refund is still due',
                'The customer is owed a service charge refund that has not been paid out yet.',
                $this->feeActions($viewer)),
            'cycle_balance_remains' => $this->issue($code, 'Savings are still on this page',
                'Some savings or reserved funds remain on this page. They must be paid out before it can close.',
                Gate::forUser($viewer)->allows('initiateWithdrawal', $customer)
                    ? [['label' => 'Withdraw remaining savings', 'href' => route('customers.withdrawals.create', $customer->customer_id)]] : []),
            'owner_work_pending' => $this->issue($code, 'Another money request is still in progress',
                'A withdrawal, correction or cash handover for this customer has not finished yet.',
                $this->pendingWorkActions($viewer, $plan)),
            'payout_return_unresolved' => $this->issue($code, 'A returned payout needs handling',
                'A bank payout from this page came back and has not been resolved yet. Contact an administrator.'),
            'closed_exception' => $this->issue($code, 'A correction after closure needs review',
                'A payment was corrected after this page closed and still needs an administrator to resolve it.'),
            default => $this->issue($code, 'Service charge records need attention',
                'The service charge records for this page could not be confirmed. Contact an administrator.'),
        };
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{label: string, href: string}>
     */
    private function replacementActions(User $viewer, array $ids): array
    {
        $reversals = ReversalRequest::query()->with('customerProfile')->whereKey($ids)->orderBy('id')->get();

        return $reversals->map(function (ReversalRequest $reversal) use ($viewer, $reversals): ?array {
            $suffix = $reversals->count() > 1 ? ' ('.MoneyFormatter::formatNaira((int) ($reversal->dependency_snapshot['summary']['controlled_kobo'] ?? $reversal->original_amount_kobo)).')' : '';
            if (config('collections.receipt_corrections_enabled', false) && Gate::forUser($viewer)->allows('recordCollection', $reversal->customerProfile)) {
                return $this->wallet->active()
                    ? ['label' => 'Add held payment to wallet'.$suffix, 'href' => route('reversals.wallet-credit', $reversal)]
                    : ['label' => 'Record replacement payment'.$suffix, 'href' => route('reversals.replacement', $reversal)];
            }

            return Gate::forUser($viewer)->allows('view', $reversal->customerProfile)
                ? ['label' => 'View correction'.$suffix, 'href' => route('reversals.show', $reversal)] : null;
        })->filter()->values()->all();
    }

    /**
     * Explains an unreconciled batch, saying when a batch that is still collecting becomes ready for remittance and review.
     *
     * @param  list<int>  $ids
     * @return array{code: string, title: string, description: string, actions: list<array{label: string, href: string}>}
     */
    private function batchIssue(User $viewer, string $code, array $ids): array
    {
        $batches = CollectionBatch::query()->whereKey($ids)->orderBy('id')->get();
        $open = $batches->firstWhere('status', 'open');
        if ($open === null) {
            return $this->issue($code, 'Collection batch is not reconciled',
                'Cash collected for this page sits in a batch that has not been fully remitted and reviewed as reconciled.',
                $this->batchActions($viewer, $batches));
        }

        return $this->issue($code, 'Collection batch is still open', (string) $open->openBatchNotice(), $this->batchActions($viewer, $batches));
    }

    /**
     * @param  Collection<int, CollectionBatch>  $batches
     * @return list<array{label: string, href: string}>
     */
    private function batchActions(User $viewer, Collection $batches): array
    {
        return $batches
            ->filter(fn (CollectionBatch $batch): bool => $viewer->user_type === UserType::Admin
                || ($viewer->user_type === UserType::Agent && $viewer->agentProfile?->id === $batch->agent_profile_id))
            ->map(fn (CollectionBatch $batch): array => ['label' => 'Open batch #'.$batch->id, 'href' => route('collection-batches.show', $batch)])
            ->values()->all();
    }

    /** @return list<array{label: string, href: string}> */
    private function feeActions(User $viewer): array
    {
        return $this->authorization->allows($viewer, AdminPermission::FeesManage)
            ? [['label' => 'Open fee register', 'href' => route('admin.fees.index')]] : [];
    }

    /** @return list<array{label: string, href: string}> */
    private function pendingWorkActions(User $viewer, ThriftPlan $plan): array
    {
        $customer = $plan->customerProfile;
        if (! Gate::forUser($viewer)->allows('view', $customer)) {
            return [];
        }
        $actions = WithdrawalRequest::query()->where('thrift_plan_id', $plan->id)
            ->whereNotIn('state', ['posted', 'rejected', 'cancelled', 'expired'])->orderBy('id')->get()
            ->map(fn (WithdrawalRequest $withdrawal): array => ['label' => 'Open withdrawal', 'href' => route('withdrawals.show', $withdrawal)])->all();
        $reviewLabel = $this->authorization->allows($viewer, AdminPermission::ReversalsReview) ? 'Review correction' : 'View correction';
        foreach (ReversalRequest::query()->where('customer_profile_id', $customer->id)->where('state', 'pending_review')->orderBy('id')->get() as $reversal) {
            $actions[] = ['label' => $reviewLabel, 'href' => route('reversals.show', $reversal)];
        }
        if (CashDisbursement::query()->where('customer_profile_id', $customer->id)->whereIn('status', ['processing', 'outcome_unknown'])->exists()
            && $this->authorization->allows($viewer, AdminPermission::CashExecute)) {
            $actions[] = ['label' => 'Open cash handovers', 'href' => route('cash-disbursements.index')];
        }

        return $actions;
    }

    /**
     * @param  list<array{label: string, href: string}>  $actions
     * @return array{code: string, title: string, description: string, actions: list<array{label: string, href: string}>}
     */
    private function issue(string $code, string $title, string $description, array $actions = []): array
    {
        return ['code' => $code, 'title' => $title, 'description' => $description, 'actions' => $actions];
    }
}
