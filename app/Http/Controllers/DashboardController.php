<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Models\BusinessProfile;
use App\Services\DashboardReadService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(DashboardRequest $request, DashboardReadService $dashboard): Response
    {
        $timezone = BusinessProfile::current()->timezone;
        $filters = $request->filters($timezone);

        return Inertia::render('Dashboard', [
            'filters' => $filters,
            'scopeSummary' => fn (): array => $dashboard->scopeSummary($request->user()),
            'dashboard' => fn (): array => $dashboard->read($request->user(), $filters),
        ]);
    }
}
