<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Requests\StoreCashRemittanceRequest;
use App\Models\AuditEvent;
use App\Models\CashRemittance;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Services\AuthorizationService;
use App\Services\CollectionLedgerService;
use App\Services\CollectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        if ($actor->user_type === UserType::Customer) {
            throw new AuthorizationException;
        }
        $query = CollectionBatch::query()->orderByDesc('received_date')->orderByDesc('id');
        if ($actor->user_type === UserType::Agent) {
            $query->where('agent_profile_id', $actor->agentProfile->id);
        }

        return Inertia::render('collections/Batches', [
            'batches' => $query->paginate(25)->through(fn (CollectionBatch $batch): array => [
                'id' => $batch->id, 'date' => $batch->received_date,
                'revision' => $batch->revision, 'status' => $batch->status,
            ]),
        ]);
    }

    public function show(CollectionBatch $batch, Request $request, AuthorizationService $auth): Response
    {
        $this->mayView($request, $batch);
        $batch->load(['receipts', 'remittances']);
        $expected = (int) $batch->receipts->sum('tender_amount_kobo');
        $savings = (int) $batch->receipts->sum('savings_amount_kobo');
        $fees = (int) $batch->receipts->sum('fee_amount_kobo');
        $remitted = (int) $batch->remittances->sum('amount_kobo');
        if ($expected !== $savings + $fees) {
            throw new ConflictHttpException('Batch tender components do not match recorded cash.');
        }
        $canManage = $auth->allows($request->user(), AdminPermission::ReconciliationManage);

        return Inertia::render('collections/Batch', [
            'batch' => [
                'id' => $batch->id, 'date' => $batch->received_date, 'revision' => $batch->revision,
                'status' => $batch->status, 'version' => $batch->version,
                'expected_kobo' => $expected, 'remitted_kobo' => $remitted,
                'savings_kobo' => $savings, 'fees_kobo' => $fees,
                'outstanding_kobo' => $expected - $remitted,
                'receipts' => $canManage ? $batch->receipts->map(fn ($receipt): array => [
                    'id' => $receipt->receipt_reference, 'tender_kobo' => $receipt->tender_amount_kobo,
                ]) : [],
                'remittances' => $batch->remittances->map(fn (CashRemittance $remittance): array => [
                    'reference' => $remittance->handoff_reference, 'amount_kobo' => $remittance->amount_kobo,
                ]),
                'exceptions' => CollectionException::query()->where('collection_batch_id', $batch->id)
                    ->orderBy('id')->get()->map(fn (CollectionException $exception): array => [
                        'id' => $exception->id, 'kind' => $exception->kind, 'status' => $exception->status,
                        'amount_kobo' => $exception->amount_kobo, 'reason' => $exception->reason,
                    ]),
            ],
            'can_manage' => $canManage,
        ]);
    }

    public function remit(CollectionBatch $batch, StoreCashRemittanceRequest $request, AuthorizationService $auth,
        CollectionService $collections, CollectionLedgerService $ledger): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validated();
        $amount = $collections->amountToKobo($data['amount_ngn']);
        DB::transaction(function () use ($batch, $request, $data, $amount, $ledger): void {
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $existing = CashRemittance::query()->where('handoff_reference', $data['handoff_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->collection_batch_id !== $current->id || $existing->amount_kobo !== $amount
                    || $existing->confirmed_by_user_id !== $request->user()->id
                    || $existing->handoff_date !== $data['handoff_date']
                    || $existing->receiving_location !== trim($data['receiving_location'])
                    || $existing->source_attestation !== trim($data['source_attestation'])) {
                    throw new ConflictHttpException('Handoff reference belongs to another remittance.');
                }

                return;
            }
            if ($current->status === 'open' || $current->status === 'reconciled'
                || $current->version !== (int) $data['batch_version']) {
                throw new ConflictHttpException('Batch is not ready for remittance.');
            }
            $outstanding = (int) $current->receipts()->sum('tender_amount_kobo')
                - (int) $current->remittances()->sum('amount_kobo');
            if ($amount > $outstanding) {
                throw new ConflictHttpException('Remittance exceeds this batch’s outstanding amount.');
            }
            $remittance = CashRemittance::create([
                'collection_batch_id' => $current->id, 'agent_profile_id' => $current->agent_profile_id,
                'confirmed_by_user_id' => $request->user()->id,
                'handoff_reference' => $data['handoff_reference'], 'amount_kobo' => $amount,
                'handoff_date' => $data['handoff_date'],
                'receiving_location' => trim($data['receiving_location']),
                'source_attestation' => trim($data['source_attestation']),
            ]);
            $remittance->update(['ledger_posting_group_id' => $ledger->postCashRemittance(
                $remittance->id, $current->agent_profile_id, $amount, $request->user())->id]);
            $current->status = 'in_review';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.remittance_confirmed', CashRemittance::class, $remittance->id,
                $remittance->handoff_reference, ['batch_id' => $current->id, 'amount_kobo' => $amount], $request->user());
        }, attempts: 3);

        return redirect()->route('collection-batches.show', $batch);
    }

    public function review(CollectionBatch $batch, Request $request, AuthorizationService $auth): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'batch_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        DB::transaction(function () use ($batch, $request, $data): void {
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== (int) $data['batch_version'] || in_array($current->status, ['open', 'reconciled'], true)) {
                throw new ConflictHttpException('Batch review changed or is unavailable.');
            }
            $expected = (int) $current->receipts()->sum('tender_amount_kobo');
            $remitted = (int) $current->remittances()->sum('amount_kobo');
            $outstanding = $expected - $remitted;
            if ($outstanding < 0) {
                throw new ConflictHttpException('Cash custody integrity is unavailable.');
            }
            $openCases = CollectionException::query()->where('collection_batch_id', $current->id)
                ->where('status', '!=', 'resolved')->exists();
            $outcome = $outstanding === 0 && ! $openCases ? 'reconciled' : 'exception';
            DB::table('collection_batch_reviews')->insert([
                'collection_batch_id' => $current->id, 'reviewed_by_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'outcome' => $outcome,
                'expected_kobo' => $expected, 'remitted_kobo' => $remitted,
                'outstanding_kobo' => $outstanding, 'reason' => trim($data['reason']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($outcome === 'exception' && $outstanding > 0 && ! $openCases) {
                $exception = CollectionException::create([
                    'collection_batch_id' => $current->id, 'opened_by_user_id' => $request->user()->id,
                    'kind' => 'cash_shortage', 'amount_kobo' => $outstanding, 'reason' => trim($data['reason']),
                ]);
                DB::table('collection_exception_events')->insert([
                    'collection_exception_id' => $exception->id, 'actor_user_id' => $request->user()->id,
                    'batch_version' => $current->version, 'event_type' => 'opened',
                    'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $current->status = $outcome;
            $current->version++;
            $current->save();
            AuditEvent::record('collection.batch_reviewed', CollectionBatch::class, $current->id,
                (string) $current->id, ['outcome' => $outcome, 'outstanding_kobo' => $outstanding], $request->user());
        }, attempts: 3);

        return redirect()->route('collection-batches.show', $batch);
    }

    public function reportException(CollectionBatch $batch, Request $request, AuthorizationService $auth,
        CollectionService $collections): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'batch_version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:overage,missing_transfer'],
            'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $amount = $collections->amountToKobo($data['amount_ngn']);
        DB::transaction(function () use ($batch, $request, $data, $amount): void {
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'open' || $current->version !== (int) $data['batch_version']) {
                throw new ConflictHttpException('Batch changed before exception reporting.');
            }
            $exception = CollectionException::create([
                'collection_batch_id' => $current->id, 'opened_by_user_id' => $request->user()->id,
                'kind' => $data['kind'], 'amount_kobo' => $amount, 'reason' => trim($data['reason']),
            ]);
            DB::table('collection_exception_events')->insert([
                'collection_exception_id' => $exception->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => 'opened',
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $current->status = 'exception';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_opened', CollectionException::class, $exception->id,
                (string) $exception->id, ['batch_id' => $current->id, 'kind' => $data['kind'],
                    'amount_kobo' => $amount], $request->user());
        }, attempts: 3);

        return redirect()->route('collection-batches.show', $batch);
    }

    public function resolveException(CollectionBatch $batch, CollectionException $exception, Request $request,
        AuthorizationService $auth): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'batch_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        DB::transaction(function () use ($batch, $exception, $request, $data): void {
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $currentException = CollectionException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($currentException->collection_batch_id !== $current->id || $currentException->status !== 'open'
                || $current->version !== (int) $data['batch_version']) {
                throw new ConflictHttpException('Exception or batch changed before resolution.');
            }
            $outstanding = (int) $current->receipts()->sum('tender_amount_kobo')
                - (int) $current->remittances()->sum('amount_kobo');
            if ($currentException->kind !== 'cash_shortage') {
                throw new ConflictHttpException('This exception needs its owning correction workflow before it can close.');
            }
            if ($outstanding !== 0) {
                throw new ConflictHttpException('The outstanding Agent receivable must be remitted before this shortage can close.');
            }
            $currentException->status = 'resolved';
            $currentException->save();
            DB::table('collection_exception_events')->insert([
                'collection_exception_id' => $currentException->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => 'resolved',
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_resolved', CollectionException::class, $currentException->id,
                (string) $currentException->id, ['batch_id' => $current->id, 'reason' => trim($data['reason'])], $request->user());
        }, attempts: 3);

        return redirect()->route('collection-batches.show', $batch);
    }

    public function reopenException(CollectionBatch $batch, CollectionException $exception, Request $request,
        AuthorizationService $auth): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'batch_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        DB::transaction(function () use ($batch, $exception, $request, $data): void {
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $currentException = CollectionException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($currentException->collection_batch_id !== $current->id || $currentException->status !== 'resolved'
                || $current->version !== (int) $data['batch_version'] || $current->status === 'open') {
                throw new ConflictHttpException('Exception or batch changed before reopening.');
            }
            $currentException->status = 'open';
            $currentException->save();
            DB::table('collection_exception_events')->insert([
                'collection_exception_id' => $currentException->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => 'reopened',
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $current->status = 'exception';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_reopened', CollectionException::class, $currentException->id,
                (string) $currentException->id, ['batch_id' => $current->id, 'reason' => trim($data['reason'])], $request->user());
        }, attempts: 3);

        return redirect()->route('collection-batches.show', $batch);
    }

    private function mayView(Request $request, CollectionBatch $batch): void
    {
        $actor = $request->user();
        if ($actor->user_type === UserType::Admin || ($actor->user_type === UserType::Agent
            && $actor->agentProfile?->id === $batch->agent_profile_id)) {
            return;
        }
        throw new AuthorizationException;
    }
}
