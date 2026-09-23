<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Http\Controllers\Controller;
use App\Models\FeeRule;
use App\Services\AuthorizationService;
use App\Services\RegistrationFeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationFeeRuleController extends Controller
{
    public function __construct(
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Display current registration fee rule and publication history.
     */
    public function index(Request $request, RegistrationFeeService $feeService): Response
    {
        $viewer = $request->user();
        if (! $this->authorizationService->allows($viewer, AdminPermission::FeesManage)) {
            abort(403, 'Unauthorized to view fee management.');
        }

        $currentRule = $feeService->getCurrentRule();
        $rules = FeeRule::query()
            ->where('kind', FeeRuleKind::Registration->value)
            ->with(['publishedBy'])
            ->latest('version')
            ->get()
            ->map(fn (FeeRule $rule): array => [
                'id' => $rule->id,
                'version' => $rule->version,
                'name' => $rule->name,
                'model' => $rule->model->value,
                'model_label' => $rule->model->displayName(),
                'amount_kobo' => $rule->amount_kobo,
                'formatted_amount' => $rule->formattedAmount(),
                'currency' => $rule->currency,
                'customer_description' => $rule->customer_description,
                'publication_reason' => $rule->publication_reason,
                'effective_at' => $rule->effective_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'retired_at' => $rule->retired_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'is_active' => $rule->retired_at === null,
                'published_by' => $rule->publishedBy?->name ?? 'Unknown',
            ]);

        return Inertia::render('admin/fees/RegistrationFee', [
            'current_rule' => $currentRule ? [
                'id' => $currentRule->id,
                'version' => $currentRule->version,
                'name' => $currentRule->name,
                'model' => $currentRule->model->value,
                'model_label' => $currentRule->model->displayName(),
                'amount_kobo' => $currentRule->amount_kobo,
                'formatted_amount' => $currentRule->formattedAmount(),
                'currency' => $currentRule->currency,
                'customer_description' => $currentRule->customer_description,
                'publication_reason' => $currentRule->publication_reason,
                'effective_at' => $currentRule->effective_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'published_by' => $currentRule->publishedBy?->name ?? 'Unknown',
            ] : null,
            'rules' => $rules,
        ]);
    }

    /**
     * Publish a new registration fee rule.
     */
    public function store(Request $request, RegistrationFeeService $feeService): RedirectResponse
    {
        $viewer = $request->user();
        if (! $this->authorizationService->allows($viewer, AdminPermission::FeesManage)) {
            abort(403, 'Unauthorized to publish fee rules.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'model' => ['required', 'string', 'in:fixed,no_fee'],
            'amount_ngn' => ['required_if:model,fixed', 'nullable', 'numeric', 'min:0.01'],
            'customer_description' => ['required', 'string', 'min:1', 'max:500'],
            'publication_reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $model = FeeRuleModel::from($validated['model']);
        $amountKobo = $model === FeeRuleModel::Fixed
            ? (int) round(((float) $validated['amount_ngn']) * 100)
            : 0;

        $feeService->publishRule($viewer, [
            'name' => $validated['name'],
            'model' => $model,
            'amount_kobo' => $amountKobo,
            'customer_description' => $validated['customer_description'],
            'publication_reason' => $validated['publication_reason'],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Registration fee rule published successfully.',
        ]);

        return redirect()->route('admin.fees.registration.index');
    }
}
