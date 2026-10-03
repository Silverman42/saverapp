<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewFeeRulePublicationRequest;
use App\Http\Requests\PreviewFeeRuleRetirementRequest;
use App\Http\Requests\RetireFeeRuleRequest;
use App\Http\Requests\StoreFeeRuleRequest;
use App\Models\FeeRule;
use App\Services\AuthorizationService;
use App\Services\RegistrationFeeService;
use App\Support\MoneyAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            ->map(fn (FeeRule $rule): array => $feeService->serializeRule($rule) + [
                'published_by' => $rule->publishedBy->name ?? 'Unknown',
            ]);

        $planRules = FeeRule::query()
            ->where('kind', FeeRuleKind::Plan->value)
            ->with('publishedBy')
            ->orderBy('rule_key')
            ->orderByDesc('version')
            ->get()
            ->map(fn (FeeRule $rule): array => $feeService->serializeRule($rule) + [
                'published_by' => $rule->publishedBy->name ?? 'Unknown',
            ]);

        return Inertia::render('admin/fees/RegistrationFee', [
            'current_rule' => $currentRule ? $feeService->serializeRule($currentRule) + [
                'published_by' => $currentRule->publishedBy->name ?? 'Unknown',
            ] : null,
            'rules' => $rules,
            'plan_options' => $feeService->getCurrentPlanOptions()->map(fn (FeeRule $rule): array => $feeService->serializeRule($rule)),
            'plan_rules' => $planRules,
        ]);
    }

    /**
     * Publish a new registration fee rule.
     */
    public function store(StoreFeeRuleRequest $request, RegistrationFeeService $feeService): RedirectResponse
    {
        $viewer = $request->user();
        if (! $this->authorizationService->allows($viewer, AdminPermission::FeesManage)) {
            abort(403, 'Unauthorized to publish fee rules.');
        }

        $validated = $request->validated();

        $feeService->publishRule($viewer, [
            ...$this->publicationData($request),
            'confirmed' => $request->boolean('confirmed'),
            'preview_fingerprint' => $validated['preview_fingerprint'],
        ], $request);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ($validated['kind'] ?? FeeRuleKind::Registration->value) === FeeRuleKind::Plan->value
                ? 'Plan fee option published successfully.'
                : 'Registration fee rule published successfully.',
        ]);

        return redirect()->route('admin.fees.registration.index');
    }

    public function previewRetirement(PreviewFeeRuleRetirementRequest $request, FeeRule $feeRule, RegistrationFeeService $feeService): JsonResponse
    {
        return response()->json($feeService->previewRetirement($request->user(), $feeRule->id, $request->validated('reason')));
    }

    public function retire(RetireFeeRuleRequest $request, FeeRule $feeRule, RegistrationFeeService $feeService): RedirectResponse
    {
        $feeService->retireRule($request->user(), $feeRule->id, $request->validated('reason'), $request, $request->validated('preview_fingerprint'), $request->boolean('confirmed'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fee rule retired. Existing agreements retain their fee terms.']);

        return redirect()->route('admin.fees.registration.index');
    }

    public function preview(PreviewFeeRulePublicationRequest $request, RegistrationFeeService $feeService): JsonResponse
    {
        return response()->json($feeService->previewPublication($request->user(), $this->publicationData($request)));
    }

    /** @return array<string, mixed> */
    private function publicationData(StoreFeeRuleRequest $request): array
    {
        $validated = $request->validated();
        $model = FeeRuleModel::from($validated['model']);
        try {
            $amountKobo = filled($validated['amount_ngn'] ?? null)
                ? MoneyAmount::parseNairaToKobo((string) $validated['amount_ngn'])
                : 0;
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_ngn' => [$exception->getMessage()]]);
        }

        return [
            'currency' => $validated['currency'] ?? 'NGN',
            'kind' => $validated['kind'] ?? FeeRuleKind::Registration->value,
            'rule_key' => $validated['rule_key'] ?? 'registration',
            'timing' => $validated['timing'] ?? null,
            'basis' => $validated['basis'] ?? null,
            'settlement_source' => $validated['settlement_source'] ?? null,
            'basis_points' => isset($validated['basis_points']) ? (int) $validated['basis_points'] : null,
            'effective_at' => $validated['effective_at'] ?? null,
            'name' => $validated['name'],
            'model' => $model,
            'amount_kobo' => $amountKobo,
            'customer_description' => $validated['customer_description'],
            'publication_reason' => $validated['publication_reason'],
        ];
    }
}
