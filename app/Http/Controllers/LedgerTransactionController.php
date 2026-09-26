<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Services\LedgerTransactionReadService;
use App\Services\ResourceScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LedgerTransactionController extends Controller
{
    public function index(Request $request, LedgerTransactionReadService $transactions): Response
    {
        $timezone = BusinessProfile::current()->timezone;
        $today = CarbonImmutable::now($timezone);
        $filters = $request->validate([
            'customer' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Z0-9-]+\z/'],
            'type' => ['nullable', Rule::in(['contribution', 'remittance'])],
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

        return Inertia::render('ledger/Index', [
            'result' => $result,
            'filters' => $filters,
            'timezone' => $timezone,
        ]);
    }

    public function show(string $transaction, Request $request, LedgerTransactionReadService $transactions): Response
    {
        $detail = $transactions->detail($request->user(), $transaction);
        AuditEvent::record('ledger.transaction_viewed', 'ledger_transaction', null, $transaction, [
            'customer_id' => $detail['customer_id'], 'status' => $detail['status'],
        ], $request->user(),
            context: ['executor' => self::class]
        );

        return Inertia::render('ledger/Show', [
            'transaction' => $detail,
        ]);
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
