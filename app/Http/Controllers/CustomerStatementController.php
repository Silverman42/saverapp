<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FinancialArtifact;
use App\Services\ResourceScopeService;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerStatementController extends Controller
{
    public function preview(string $customer, Request $request, ResourceScopeService $scope,
        StatementPreviewService $statements): Response
    {
        $profile = $scope->forCustomers($request->user())->where('customer_id', $customer)
            ->with('user')->firstOrFail();
        $timezone = BusinessProfile::current()->timezone;
        $today = CarbonImmutable::now($timezone);
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $dates['from'] ?? $today->startOfMonth()->toDateString();
        $to = $dates['to'] ?? $today->toDateString();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        if ($start === null || $end === null || $start->greaterThan($end) || $start->diffInDays($end) > 365) {
            abort(422, 'Choose an inclusive statement period of at most 366 dates.');
        }

        $preview = $statements->preview($request->user(), $profile, $from, $to, $timezone);
        AuditEvent::record('ledger.statement_previewed', CustomerProfile::class, $profile->id, $profile->customer_id, [
            'from' => $from, 'to' => $to, 'status' => $preview['status'],
            'ledger_watermark' => $preview['ledger_watermark'] ?? null,
        ], $request->user(),
            context: ['executor' => self::class]
        );

        return Inertia::render('ledger/StatementPreview', [
            'customer' => ['id' => $profile->customer_id, 'name' => $profile->user?->name],
            'preview' => $preview,
            'from' => $from, 'to' => $to,
            'issued_statements' => FinancialArtifact::query()->where('kind', 'statement')->where('customer_profile_id', $profile->id)->latest('id')->limit(25)->get()->map(fn ($artifact): array => [...$artifact->only(['artifact_reference', 'status', 'issued_at']),
                'superseded' => FinancialArtifact::query()->where('supersedes_artifact_id', $artifact->id)->exists()]),
        ]);
    }
}
