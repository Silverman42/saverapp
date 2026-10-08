<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Http\Requests\StoreCashRemittanceRequest;
use App\Models\AuditEvent;
use App\Models\CashRemittance;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FinancialWorkflowSupplement;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionExceptionNoticeService;
use App\Services\CollectionExceptionResolution;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlatformGuard;
use App\Support\Toast;
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
        $receiptTotals = DB::table('collection_receipts')->whereNull('replacement_reversal_id')->where('collection_batch_id', $batch->id)
            ->selectRaw('COUNT(*) as receipt_count, COALESCE(SUM(tender_amount_kobo), 0) as tender_kobo')
            ->selectRaw('COALESCE(SUM(savings_amount_kobo), 0) as savings_kobo, COALESCE(SUM(fee_amount_kobo), 0) as fees_kobo')
            ->first();
        $expected = (int) $receiptTotals->tender_kobo;
        $savings = (int) $receiptTotals->savings_kobo;
        $fees = (int) $receiptTotals->fees_kobo;
        $position = app(CollectionBatchPosition::class)->read($batch);
        $remitted = $position['received_kobo'];
        if ($expected !== $savings + $fees) {
            throw new ConflictHttpException('Batch tender components do not match recorded cash.');
        }
        $canManage = $auth->allows($request->user(), AdminPermission::ReconciliationManage);
        $resolutions = $canManage ? FinancialWorkflowSupplement::query()->where('collection_batch_id', $batch->id)
            ->where('kind', 'collection_exception_resolution')->orderByDesc('id')->paginate(25, ['*'], 'resolution_page')->withQueryString() : null;
        $resolutions?->getCollection()->each(fn (FinancialWorkflowSupplement $proof) => app(CollectionExceptionResolution::class)->assertRecorded($proof, $batch));
        $receiptReferences = $resolutions === null ? collect() : CollectionReceipt::query()
            ->whereIn('id', $resolutions->getCollection()->map(fn (FinancialWorkflowSupplement $proof) => $proof->facts['receipt_id']))->pluck('receipt_reference', 'id');
        $reversalReferences = $resolutions === null ? collect() : ReversalRequest::query()
            ->whereIn('id', $resolutions->pluck('reversal_request_id')->filter())->pluck('reversal_id', 'id');
        $resolutions?->through(function (FinancialWorkflowSupplement $proof) use ($receiptReferences, $reversalReferences): array {
            return [
                'id' => $proof->id, 'exception_id' => $proof->facts['exception_id'], 'kind' => $proof->facts['resolution_kind'],
                'receipt_reference' => $receiptReferences->get($proof->facts['receipt_id']),
                'reversal_id' => $reversalReferences->get($proof->reversal_request_id),
                'outstanding_kobo' => $proof->facts['outstanding_kobo'], 'cause' => $proof->evidence,
                'recorded_at' => $proof->created_at?->toIso8601String(),
            ];
        });
        $settlements = $canManage ? DB::table('collection_settlements')->where('collection_batch_id', $batch->id)->orderByDesc('id')
            ->paginate(25, ['id', 'settlement_reference', 'amount_kobo', 'settled_date', 'bank_reference'], 'settlement_page')->withQueryString() : null;
        $settlementFiles = $settlements === null ? collect() : DB::table('collection_settlement_files')
            ->whereIn('collection_settlement_id', $settlements->pluck('id'))->get(['id', 'collection_settlement_id'])->groupBy('collection_settlement_id');
        $settlements?->through(static fn ($settlement): array => [
            'reference' => $settlement->settlement_reference, 'amount_kobo' => (int) $settlement->amount_kobo,
            'date' => $settlement->settled_date, 'bank_reference' => $settlement->bank_reference,
            'files' => $settlementFiles->get($settlement->id, collect())->map(static fn ($file): array => ['id' => $file->id])->all(),
        ]);

        return Inertia::render('collections/Batch', [
            'batch' => [
                'id' => $batch->id, 'date' => $batch->received_date, 'revision' => $batch->revision,
                'timezone' => $batch->timezone,
                'status' => $batch->status, 'version' => $batch->version,
                'method_identity' => $batch->method_identity, 'custody_account_code' => $batch->custody_account_code,
                'method_label' => $batch->collection_method_version_id === null ? 'Cash' : DB::table('collection_method_versions')->where('id', $batch->collection_method_version_id)->value('label'),
                'settlement_pending' => $position['settlement_pending'],
                'cash_handoff_allowed' => $batch->custody_account_code === 'agent_receivable_ngn',
                'expected_kobo' => $expected, 'remitted_kobo' => $remitted,
                'savings_kobo' => $savings, 'fees_kobo' => $fees,
                'outstanding_kobo' => $expected - $remitted,
                'receipt_count' => (int) $receiptTotals->receipt_count,
            ],
            'receipts' => $canManage ? $batch->receipts()->orderByDesc('id')->paginate(25, ['*'], 'receipt_page')
                ->withQueryString()->through(fn ($receipt): array => [
                    'id' => $receipt->receipt_reference, 'tender_kobo' => $receipt->tender_amount_kobo,
                ]) : null,
            'remittances' => $batch->remittances()->orderByDesc('id')->paginate(25, ['*'], 'remittance_page')
                ->withQueryString()->through(fn (CashRemittance $remittance): array => [
                    'reference' => $remittance->handoff_reference, 'amount_kobo' => $remittance->amount_kobo,
                ]),
            'exceptions' => CollectionException::query()->where('collection_batch_id', $batch->id)
                ->orderByDesc('id')->paginate(25, ['*'], 'exception_page')->withQueryString()
                ->through(fn (CollectionException $exception): array => [
                    'id' => $exception->id, 'kind' => $exception->kind, 'status' => $exception->status,
                    'amount_kobo' => $exception->amount_kobo, 'reason' => $canManage ? $exception->reason : null,
                ]),
            'can_manage' => $canManage,
            'resolution_records' => $resolutions,
            'settlement_banks' => $canManage && $batch->custody_account_code === 'payment_clearing_ngn'
                ? DB::table('collection_method_versions')->where('method_key', 'transfer')->where('custody_account_code', 'business_bank_ngn')
                    ->where('effective_at', '<=', now())->orderByDesc('version')->get(['id', 'label', 'version', 'destination_key'])->toArray() : [],
            'settlements' => $settlements,
        ]);
    }

    public function remit(CollectionBatch $batch, StoreCashRemittanceRequest $request, AuthorizationService $auth,
        CollectionService $collections, CollectionLedgerService $ledger, LedgerTransactionProjectionService $transactions): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validated();
        $amount = $collections->amountToKobo($data['amount_ngn']);
        app(PlatformGuard::class)->transaction('financial', function () use ($batch, $request, $data, $amount, $ledger, $transactions): void {
            $this->lockBatchCustomers($batch, $request->user());
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($current->custody_account_code !== 'agent_receivable_ngn') {
                throw new ConflictHttpException('This batch requires its bank or settlement evidence, not an Agent cash handoff.');
            }
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
            $outstanding = app(CollectionBatchPosition::class)->read($current)['outstanding_kobo'];
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
            $transactions->projectRemittance($remittance->id);
            $current->status = 'in_review';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.remittance_confirmed', CashRemittance::class, $remittance->id,
                $remittance->handoff_reference, ['batch_id' => $current->id, 'amount_kobo' => $amount,
                    'version' => $current->version, 'agent_profile_id' => $current->agent_profile_id,
                    'currency' => 'NGN', 'received_date' => $remittance->handoff_date,
                    'posting_group_id' => $remittance->ledger_posting_group_id], $request->user(),
                context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage',
                    'source_version' => $current->version, 'correlation_reference' => 'remittance:'.$remittance->id]
            );
        }, attempts: 3);

        Toast::success('Remittance recorded', 'The cash remittance was recorded.');

        return redirect()->route('collection-batches.show', $batch);
    }

    public function review(CollectionBatch $batch, Request $request, AuthorizationService $auth,
        CollectionReadService $collections): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'batch_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        app(PlatformGuard::class)->transaction('mutation', function () use ($batch, $request, $data, $collections): void {
            $this->lockBatchCustomers($batch, $request->user());
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== (int) $data['batch_version'] || in_array($current->status, ['open', 'reconciled'], true)) {
                throw new ConflictHttpException('Batch review changed or is unavailable.');
            }
            $expected = (int) $current->receipts()->sum('tender_amount_kobo');
            $position = app(CollectionBatchPosition::class)->read($current);
            if ($position['settlement_pending']) {
                throw new ConflictHttpException('Bank settlement evidence is required before this capture batch can reconcile.');
            }
            $remitted = $position['received_kobo'];
            $outstanding = $expected - $remitted;
            if ($outstanding < 0) {
                throw new ConflictHttpException('Cash custody integrity is unavailable.');
            }
            app(CollectionExceptionResolution::class)->assertResolvedCases($current);
            $openCases = CollectionException::query()->where('collection_batch_id', $current->id)
                ->where('status', '!=', 'resolved')->exists();
            if ($collections->hasPendingCorrectionForBatches(DB::table('collection_batches')->where('id', $current->id))) {
                throw new ConflictHttpException('A pending receipt correction blocks batch closure.');
            }
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
                $exceptionEventId = DB::table('collection_exception_events')->insertGetId([
                    'collection_exception_id' => $exception->id, 'actor_user_id' => $request->user()->id,
                    'batch_version' => $current->version, 'event_type' => 'opened',
                    'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(CollectionExceptionNoticeService::class)->queue($exceptionEventId);
            }
            $current->status = $outcome;
            $current->version++;
            $current->save();
            AuditEvent::record('collection.batch_reviewed', CollectionBatch::class, $current->id,
                (string) $current->id, ['outcome' => $outcome, 'outstanding_kobo' => $outstanding,
                    'version' => $current->version, 'reason' => trim($data['reason'])], $request->user(),
                context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage',
                    'source_version' => $current->version, 'correlation_reference' => 'batch:'.$current->id.':'.$current->version]
            );
        }, attempts: 3);

        Toast::success('Batch reviewed', 'The collection batch review was recorded.');

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
        app(PlatformGuard::class)->transaction('mutation', function () use ($batch, $request, $data, $amount): void {
            $this->lockBatchCustomers($batch, $request->user());
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'open' || $current->version !== (int) $data['batch_version']) {
                throw new ConflictHttpException('Batch changed before exception reporting.');
            }
            $exception = CollectionException::create([
                'collection_batch_id' => $current->id, 'opened_by_user_id' => $request->user()->id,
                'kind' => $data['kind'], 'amount_kobo' => $amount, 'reason' => trim($data['reason']),
            ]);
            $exceptionEventId = DB::table('collection_exception_events')->insertGetId([
                'collection_exception_id' => $exception->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => 'opened',
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(CollectionExceptionNoticeService::class)->queue($exceptionEventId);
            $current->status = 'exception';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_opened', CollectionException::class, $exception->id,
                (string) $exception->id, ['batch_id' => $current->id, 'kind' => $data['kind'],
                    'amount_kobo' => $amount], $request->user(),
                context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage']
            );
        }, attempts: 3);

        Toast::success('Exception reported', 'The batch exception was recorded.');

        return redirect()->route('collection-batches.show', $batch);
    }

    public function progressException(CollectionBatch $batch, CollectionException $exception, Request $request,
        AuthorizationService $auth): RedirectResponse
    {
        if (! $auth->allows($request->user(), AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'batch_version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:investigating,awaiting_action'],
            'reason' => ['required', 'string', 'min:1', 'max:500', 'regex:/\S/'],
            'confirmed' => ['required', 'accepted'],
        ]);
        app(PlatformGuard::class)->transaction('mutation', function () use ($batch, $exception, $request, $data): void {
            $this->lockBatchCustomers($batch, $request->user());
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $currentException = CollectionException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($currentException->status) {
                'open', 'reopened', 'awaiting_action' => ['investigating'],
                'investigating' => ['awaiting_action'],
                default => [],
            };
            if ($currentException->collection_batch_id !== $current->id || $current->status === 'open'
                || $current->version !== (int) $data['batch_version'] || ! in_array($data['status'], $allowed, true)) {
                throw new ConflictHttpException('The exception investigation or batch changed. Reload before proceeding.');
            }
            $previous = $currentException->status;
            $currentException->status = $data['status'];
            $currentException->save();
            $exceptionEventId = DB::table('collection_exception_events')->insertGetId([
                'collection_exception_id' => $currentException->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => $data['status'],
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(CollectionExceptionNoticeService::class)->queue($exceptionEventId);
            $current->status = 'exception';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_progressed', CollectionException::class, $currentException->id,
                (string) $currentException->id, ['batch_id' => $current->id, 'from_status' => $previous, 'to_status' => $data['status']],
                $request->user(), context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage']);
        }, attempts: 3);

        Toast::success('Exception updated', 'The batch exception progress was recorded.');

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
            'reason' => ['required', 'string', 'min:1', 'max:500', 'regex:/\S/'],
            'resolution_kind' => ['sometimes', 'in:remittance,verified_match,approved_correction'],
            'receipt_reference' => ['required_if:resolution_kind,verified_match,approved_correction', 'nullable', 'string', 'max:80'],
            'reversal_id' => ['required_if:resolution_kind,approved_correction', 'nullable', 'string', 'max:80'],
            'confirmed' => ['required', 'accepted'],
        ]);
        app(PlatformGuard::class)->transaction('mutation', function () use ($batch, $exception, $request, $data): void {
            $this->lockBatchCustomers($batch, $request->user());
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $currentException = CollectionException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($currentException->collection_batch_id !== $current->id || ! in_array($currentException->status, ['open', 'investigating', 'awaiting_action', 'reopened'], true)
                || $current->version !== (int) $data['batch_version']) {
                throw new ConflictHttpException('Exception or batch changed before resolution.');
            }
            $kind = $data['resolution_kind'] ?? 'remittance';
            $proof = null;
            if ($kind === 'remittance') {
                $outstanding = app(CollectionBatchPosition::class)->read($current)['outstanding_kobo'];
                if ($currentException->kind !== 'cash_shortage') {
                    throw new ConflictHttpException('This exception needs its owning correction workflow before it can close.');
                }
                if ($outstanding !== 0) {
                    throw new ConflictHttpException('The outstanding Agent receivable must be remitted before this shortage can close.');
                }
            } else {
                $proof = app(CollectionExceptionResolution::class)->record($current, $currentException, $request->user(), $data);
            }
            $currentException->status = 'resolved';
            $currentException->save();
            $exceptionEventId = DB::table('collection_exception_events')->insertGetId([
                'collection_exception_id' => $currentException->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => 'resolved',
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(CollectionExceptionNoticeService::class)->queue($exceptionEventId);
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_resolved', CollectionException::class, $currentException->id,
                (string) $currentException->id, ['batch_id' => $current->id, 'reason' => trim($data['reason']), 'resolution_kind' => $kind, 'supplement_id' => $proof?->id], $request->user(),
                context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage']
            );
        }, attempts: 3);

        Toast::success('Exception resolved', 'The batch exception was resolved.');

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
        app(PlatformGuard::class)->transaction('mutation', function () use ($batch, $exception, $request, $data): void {
            $this->lockBatchCustomers($batch, $request->user());
            $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $currentException = CollectionException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($currentException->collection_batch_id !== $current->id || $currentException->status !== 'resolved'
                || $current->version !== (int) $data['batch_version'] || $current->status === 'open') {
                throw new ConflictHttpException('Exception or batch changed before reopening.');
            }
            $currentException->status = 'reopened';
            $currentException->save();
            $exceptionEventId = DB::table('collection_exception_events')->insertGetId([
                'collection_exception_id' => $currentException->id, 'actor_user_id' => $request->user()->id,
                'batch_version' => $current->version, 'event_type' => 'reopened',
                'reason' => trim($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(CollectionExceptionNoticeService::class)->queue($exceptionEventId);
            $current->status = 'exception';
            $current->version++;
            $current->save();
            AuditEvent::record('collection.exception_reopened', CollectionException::class, $currentException->id,
                (string) $currentException->id, ['batch_id' => $current->id, 'reason' => trim($data['reason'])], $request->user(),
                context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage']
            );
        }, attempts: 3);

        Toast::success('Exception reopened', 'The batch exception was reopened.');

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

    private function lockBatchCustomers(CollectionBatch $batch, User $actor): void
    {
        $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! app(AuthorizationService::class)->allows($lockedActor, AdminPermission::ReconciliationManage)) {
            throw new AuthorizationException;
        }
        $customerIds = $batch->receipts()->pluck('customer_profile_id')->unique()->all();
        foreach (CustomerProfile::query()->whereIn('id', $customerIds)->orderBy('id')->lockForUpdate()->get() as $customer) {
            if ($customer->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Restore affected Archived Customers before changing reconciliation evidence.');
            }
        }
    }
}
