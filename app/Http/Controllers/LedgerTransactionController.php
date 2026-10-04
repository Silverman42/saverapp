<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Services\AuthorizationService;
use App\Services\LedgerIntegrityIncidentService;
use App\Services\LedgerTransactionReadService;
use App\Services\ResourceScopeService;
use App\Services\ReversalCapabilityRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LedgerTransactionController extends Controller
{
    public function index(Request $request, LedgerTransactionReadService $transactions): Response
    {
        $timezone = BusinessProfile::current()->timezone;
        $today = CarbonImmutable::now($timezone);
        $filters = $request->validate([
            'customer' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Z0-9-]+\z/'],
            'type' => ['nullable', Rule::in(['contribution', 'remittance', 'withdrawal', 'reversal', 'deduction', 'fee_application', 'fee_refund', 'external_refund_payment', 'earnings_draw'])],
            'reference' => ['nullable', 'string', 'max:40', 'regex:/\ATXN-[0-9-]+\z/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page_size' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $filters['from'] ??= $today->subDays(365)->toDateString();
        $filters['to'] ??= $today->toDateString();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone);
        if ($start === null || $end === null || $start->greaterThan($end) || $start->diffInDays($end) > 365) {
            abort(422, 'Choose an inclusive date range of at most 366 dates.');
        }

        $result = $transactions->search($request->user(), $filters);
        AuditEvent::record('ledger.transactions_viewed', 'ledger_transaction', null, null, [
            'status' => $result['status'], 'result_count' => $result['total'],
            'projection_version' => $result['state']['version'],
        ], $request->user(),
            context: ['executor' => self::class]
        );

        $canResolve = $request->user()->user_type === UserType::Admin && app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage);

        return Inertia::render('ledger/Index', [
            'result' => $result,
            'filters' => $filters,
            'timezone' => $timezone,
            'incidents' => $canResolve ? app(LedgerIntegrityIncidentService::class)->open() : [],
            'can_resolve_incidents' => $canResolve,
        ]);
    }

    public function resolveIncident(string $reference, Request $request, LedgerIntegrityIncidentService $incidents): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:1', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/'], 'confirmed' => ['required', 'accepted']]);
        $incidents->resolve($request->user(), $reference, $data['note'], $request);

        return redirect()->route('transactions.index');
    }

    public function show(string $transaction, Request $request, LedgerTransactionReadService $transactions): Response
    {
        try {
            $detail = $transactions->detail($request->user(), $transaction);
        } catch (NotFoundHttpException $exception) {
            AuditEvent::record('ledger.transaction_viewed', 'ledger_transaction', null, $transaction, ['customer_id' => null, 'status' => 'denied'],
                $request->user(), context: ['executor' => self::class, 'outcome' => 'Denied']);

            throw $exception;
        }
        AuditEvent::record('ledger.transaction_viewed', 'ledger_transaction', null, $transaction, [
            'customer_id' => $detail['customer_id'], 'status' => $detail['status'],
        ], $request->user(),
            context: ['executor' => self::class]
        );

        return Inertia::render('ledger/Show', [
            'transaction' => $detail,
            'reversal_original' => $this->reversalOriginal($transaction, $detail, $request),
        ]);
    }

    /** @param array<string, mixed> $detail */
    private function reversalOriginal(string $reference, array $detail, Request $request): ?string
    {
        $customer = CustomerProfile::query()->where('customer_id', $detail['customer_id'])->first();
        if ($customer === null || ! Gate::forUser($request->user())->allows('initiateReversal', $customer)) {
            return null;
        }
        $root = DB::table('ledger_transaction_references')->where('transaction_reference', $reference)->first();
        if ($root === null || ! in_array($root->root_type, ['withdrawal', 'collection_receipt', 'manual_charge', 'fee_savings_application'], true)) {
            return null;
        }
        $id = $root->root_type === 'collection_receipt' ? CollectionReceipt::query()->where('id', $root->root_id)->value('savings_posting_group_id') : null;
        if ($root->root_type === 'collection_receipt' && $id === null) {
            $id = DB::table('collection_fee_components')->where('collection_receipt_id', $root->root_id)->orderBy('id')->value('ledger_posting_group_id');
        }
        $group = in_array($root->root_type, ['withdrawal', 'manual_charge', 'fee_savings_application'], true)
            ? LedgerPostingGroup::query()->where('source_type', $root->root_type)->where('source_id', $root->root_id)->first()
            : LedgerPostingGroup::query()->where('id', $id)->first();

        return $group !== null && app(ReversalCapabilityRegistry::class)->resolve($group) !== null ? $group->posting_reference : null;
    }

    public function balance(string $customer, Request $request, ResourceScopeService $scope,
        LedgerTransactionReadService $transactions): JsonResponse
    {
        $profile = $scope->forCustomers($request->user())->where('customer_id', $customer)->firstOrFail();

        $balance = $transactions->balance($request->user(), $profile);
        AuditEvent::record('ledger.balance_viewed', CustomerProfile::class, $profile->id, $profile->customer_id, [
            'status' => $balance['status'],
        ], $request->user(),
            context: ['executor' => self::class]
        );

        return response()->json($balance);
    }
}
