<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\FinancialPeriod;
use App\Services\AuthorizationService;
use App\Services\FinancialPeriodService;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialPeriodController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        if (! $authorization->allows($request->user(), AdminPermission::FinancialPeriodsManage)) {
            throw new AuthorizationException;
        }
        $business = BusinessProfile::current();
        $periods = FinancialPeriod::query()->where('business_profile_id', $business->id)
            ->orderByDesc('month')->orderByDesc('id')->paginate(24);
        $periods->through(static fn (FinancialPeriod $period): array => [
            'month' => $period->month->format('Y-m'), 'timezone' => $period->timezone,
            'status' => $period->status, 'version' => $period->version,
        ]);

        return Inertia::render('admin/financial-periods/Index', [
            'timezone' => $business->timezone,
            'current_month' => now($business->timezone)->format('Y-m'),
            'periods' => $periods,
        ]);
    }

    public function open(Request $request, FinancialPeriodService $periods): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        $periods->transition($request->user(), $data['month'], 'open', null, trim($data['reason']), $request);

        Toast::success('Period opened', 'The financial month is open for posting.');

        return redirect()->route('admin.financial-periods.index');
    }

    public function close(string $month, Request $request, FinancialPeriodService $periods): RedirectResponse
    {
        return $this->change($month, 'close', $request, $periods);
    }

    public function reopen(string $month, Request $request, FinancialPeriodService $periods): RedirectResponse
    {
        return $this->change($month, 'reopen', $request, $periods);
    }

    private function change(string $month, string $action, Request $request, FinancialPeriodService $periods): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        $periods->transition($request->user(), $month, $action, (int) $data['version'], trim($data['reason']), $request);

        Toast::success($action === 'close' ? 'Period closed' : 'Period reopened', $action === 'close' ? 'No more cash can be posted to this month.' : 'The financial month is open for posting again.');

        return redirect()->route('admin.financial-periods.index');
    }

    private function authorizeManager(Request $request): void
    {
        if (! app(AuthorizationService::class)->allows($request->user(), AdminPermission::FinancialPeriodsManage)) {
            throw new AuthorizationException;
        }
    }
}
