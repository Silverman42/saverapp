<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\BusinessProfile;
use App\Services\ReportCatalogue;
use App\Services\ReportReadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, ReportCatalogue $catalogue, ReportReadService $reports): Response
    {
        $reports->scopeSummary($request->user());

        return Inertia::render('reports/Index', [
            'catalogue' => $catalogue->forViewer($request->user()),
            'timezone' => BusinessProfile::current()->timezone,
        ]);
    }

    public function show(string $report, ReportRequest $request, ReportReadService $reports, ReportCatalogue $catalogue): Response
    {
        $reports->scopeSummary($request->user());
        $filters = $request->filters(BusinessProfile::current()->timezone);

        return Inertia::render('reports/Show', [
            'definition' => $catalogue->get($request->user(), $report),
            'filters' => $filters,
            'scopeSummary' => fn (): array => $reports->scopeSummary($request->user()),
            'report' => fn (): array => $reports->read($request->user(), $report, $filters, $request->drillDownBasis()),
        ]);
    }
}
