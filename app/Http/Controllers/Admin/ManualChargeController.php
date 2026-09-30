<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\ChargeCategoryVersion;
use App\Models\ThriftPlan;
use App\Services\AuthorizationService;
use App\Services\ManualChargeService;
use App\Services\ResourceScopeService;
use App\Support\MoneyAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ManualChargeController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, ResourceScopeService $scope): Response
    {
        $fees = $authorization->allows($request->user(), AdminPermission::FeesManage);
        $deductions = $authorization->allows($request->user(), AdminPermission::DeductionsManage);
        abort_unless($fees || $deductions, 403);
        $customer = filled($request->query('customer')) ? $scope->forCustomers($request->user())
            ->where('customer_id', $request->query('customer'))->with('user')->firstOrFail() : null;
        $categories = ChargeCategoryVersion::query()->whereIn('kind', array_filter([$fees ? 'manual_fee' : null, $deductions ? 'deduction' : null]))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('charge_category_versions as newer')
                ->whereColumn('newer.category_key', 'charge_category_versions.category_key')->whereColumn('newer.version', '>', 'charge_category_versions.version'))
            ->orderBy('category_key')->get();

        return Inertia::render('admin/fees/Charges', ['categories' => $categories, 'can_fees' => $fees, 'can_deductions' => $deductions,
            'customer' => $customer === null ? null : ['customer_id' => $customer->customer_id, 'name' => $customer->user->name, 'version' => $customer->version],
            'plans' => $customer === null ? [] : ThriftPlan::query()->where('customer_profile_id', $customer->id)->whereIn('status', ['active', 'paused', 'completed'])
                ->get(['plan_id', 'version']), 'enabled' => config('fees.manual_charges_enabled', false)]);
    }

    public function publish(Request $request, ManualChargeService $service): RedirectResponse
    {
        $this->strict($request, ['publication_reference', 'category_key', 'kind', 'purpose', 'customer_description', 'amount_ngn', 'confirmed']);
        $data = $request->validate(['publication_reference' => ['required', 'uuid'], 'category_key' => ['required', 'regex:/\A[a-z][a-z0-9_-]{0,79}\z/'], 'kind' => ['required', 'in:manual_fee,deduction'],
            'purpose' => ['required', 'string', 'max:500'], 'customer_description' => ['required', 'string', 'max:500'],
            'amount_ngn' => ['required', 'string', 'max:14'], 'confirmed' => ['required', 'accepted']]);
        try {
            $amount = MoneyAmount::parseNairaToKobo($data['amount_ngn']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_ngn' => $exception->getMessage()]);
        }
        $service->publish($request->user(), ['publication_reference' => $data['publication_reference'], 'category_key' => $data['category_key'], 'kind' => $data['kind'],
            'purpose' => $data['purpose'], 'customer_description' => $data['customer_description'], 'amount_kobo' => $amount], $request);

        return redirect()->route('admin.charges.index');
    }

    public function assess(Request $request, ResourceScopeService $scope, ManualChargeService $service): RedirectResponse
    {
        $this->strict($request, ['operation_reference', 'customer_id', 'plan_id', 'category_id', 'customer_version', 'plan_version', 'reason', 'confirmed']);
        $data = $request->validate(['operation_reference' => ['required', 'uuid'], 'customer_id' => ['required', 'string'], 'plan_id' => ['required', 'string'],
            'category_id' => ['required', 'integer'], 'customer_version' => ['required', 'integer', 'min:1'], 'plan_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']]);
        $customer = $scope->forCustomers($request->user())->where('customer_id', $data['customer_id'])->firstOrFail();
        $plan = ThriftPlan::query()->where('customer_profile_id', $customer->id)->where('plan_id', $data['plan_id'])->firstOrFail();
        $service->assess($request->user(), $customer, $plan, ChargeCategoryVersion::query()->where('id', $data['category_id'])->firstOrFail(), $data['operation_reference'],
            $data['customer_version'], $data['plan_version'], $data['reason'], $request);

        return redirect()->route('admin.charges.index', ['customer' => $customer->customer_id]);
    }

    /** @param list<string> $allowed */
    private function strict(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->except('_token')), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected charge instructions were rejected.']);
        }
    }
}
