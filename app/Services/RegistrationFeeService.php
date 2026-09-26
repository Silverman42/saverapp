<?php

namespace App\Services;

use App\Data\FeeQuote;
use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\FeeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationFeeService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
        protected FreshAuthenticationService $freshAuthenticationService,
        protected FeeObligationService $feeObligationService,
    ) {}

    /**
     * Get the currently applicable registration fee rule.
     */
    public function getCurrentRule(bool $forUpdate = false): ?FeeRule
    {
        $query = FeeRule::currentRegistration();
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Get currently selectable plan fee alternatives.
     *
     * @return Collection<int, FeeRule>
     */
    public function getCurrentPlanOptions(): Collection
    {
        return FeeRule::currentPlanOptions()->get();
    }

    /**
     * Generate an authoritative registration fee preview.
     *
     * @return array<string, mixed>
     */
    public function previewFee(?string $sourceType = null, ?string $sourceId = null): array
    {
        $rule = $this->getCurrentRule();

        if (! $rule) {
            return [
                'available' => false,
                'message' => 'No active registration fee rule published. Customer registration is unavailable.',
            ];
        }

        $preview = $this->serializeRule($rule);
        if ($sourceType !== null && $sourceId !== null) {
            $quote = $this->feeObligationService->quote($rule, 0, $sourceType, $sourceId);
            $preview['quote'] = $this->serializeQuote($quote);
        }

        return $preview;
    }

    /**
     * Publish an immutable version of a registration or plan fee rule.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|HttpException
     */
    public function publishRule(User $admin, array $data, Request $request): FeeRule
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $data, $request): FeeRule {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanManageFees($freshAdmin);
            if (! $this->freshAuthenticationService->isFresh($freshAdmin, $request)) {
                throw new ConflictHttpException('Fresh password and authenticator confirmation is required.');
            }

            $kind = $this->enumValue(FeeRuleKind::class, $data['kind'] ?? FeeRuleKind::Registration->value);
            $model = $this->enumValue(FeeRuleModel::class, $data['model'] ?? null);
            $timing = $this->enumValue(
                FeeRuleTiming::class,
                $data['timing'] ?? ($kind === FeeRuleKind::Registration ? FeeRuleTiming::Registration->value : null),
            );
            $basis = $this->enumValue(FeeRuleBasis::class, $data['basis'] ?? FeeRuleBasis::None->value);
            $settlementSource = $this->enumValue(
                FeeSettlementSource::class,
                $data['settlement_source'] ?? $this->defaultSettlementSource($kind, $timing)->value,
            );
            $amountKobo = (int) ($data['amount_kobo'] ?? 0);
            $basisPoints = isset($data['basis_points']) && $data['basis_points'] !== '' ? (int) $data['basis_points'] : null;
            $ruleKey = $kind === FeeRuleKind::Registration ? 'registration' : trim((string) ($data['rule_key'] ?? ''));
            $name = trim((string) ($data['name'] ?? ''));
            $customerDescription = trim((string) ($data['customer_description'] ?? ''));
            $publicationReason = trim((string) ($data['publication_reason'] ?? ''));

            $requiredTermErrors = [];
            if ($name === '') {
                $requiredTermErrors['name'] = ['Enter a fee rule name.'];
            }
            if ($customerDescription === '') {
                $requiredTermErrors['customer_description'] = ['Enter the Customer disclosure.'];
            }
            if ($publicationReason === '') {
                $requiredTermErrors['publication_reason'] = ['Enter the publication reason.'];
            }
            if ($requiredTermErrors !== []) {
                throw ValidationException::withMessages($requiredTermErrors);
            }

            $this->validateRuleTerms($kind, $model, $timing, $basis, $settlementSource, $amountKobo, $basisPoints, $ruleKey);

            $now = Carbon::now();
            $effectiveAt = isset($data['effective_at']) && $data['effective_at'] !== ''
                ? Carbon::parse((string) $data['effective_at'])
                : $now;

            if ($effectiveAt->lessThan($now)) {
                throw ValidationException::withMessages(['effective_at' => ['Fee rules cannot be published retroactively.']]);
            }

            $this->closeApplicableRuleInterval($kind, $ruleKey, $effectiveAt);

            $latestVersion = (int) FeeRule::query()
                ->where('kind', $kind->value)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->value('version');

            $rule = FeeRule::create([
                'version' => $latestVersion + 1,
                'name' => $name,
                'kind' => $kind,
                'rule_key' => $ruleKey,
                'model' => $model,
                'timing' => $timing,
                'basis' => $basis,
                'basis_points' => $basisPoints,
                'settlement_source' => $settlementSource,
                'currency' => 'NGN',
                'amount_kobo' => $amountKobo,
                'customer_description' => $customerDescription,
                'effective_at' => $effectiveAt,
                'retired_at' => $this->nextRuleStart($kind, $ruleKey, $effectiveAt),
                'published_by_user_id' => $freshAdmin->id,
                'publication_reason' => $publicationReason,
            ]);

            AuditEvent::record(
                eventType: 'fee_rule.published',
                targetType: FeeRule::class,
                targetId: $rule->id,
                targetReference: "{$kind->value}:{$ruleKey}:v{$rule->version}",
                payload: [
                    'kind' => $kind->value,
                    'rule_key' => $ruleKey,
                    'version' => $rule->version,
                    'name' => $rule->name,
                    'model' => $rule->model->value,
                    'timing' => $rule->timing->value,
                    'basis' => $rule->basis->value,
                    'basis_points' => $rule->basis_points,
                    'amount_kobo' => $rule->amount_kobo,
                    'currency' => $rule->currency,
                    'effective_at' => $rule->effective_at->toIso8601String(),
                    'retired_at' => $rule->retired_at?->toIso8601String(),
                    'publication_reason' => $rule->publication_reason,
                ],
                actor: $freshAdmin,

                context: ['executor' => self::class, 'required_permission' => $freshAdmin?->user_type === UserType::Admin ? 'fees.manage' : null]
            );

            return $rule;
        }, attempts: 3);
    }

    /**
     * End rule applicability now without changing its published terms.
     */
    public function retireRule(User $admin, int $ruleId, string $reason): FeeRule
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $ruleId, $reason): FeeRule {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanManageFees($freshAdmin);

            $rule = FeeRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            if ($rule->effective_at->isFuture()) {
                throw new ConflictHttpException('A future fee rule must be cancelled before it becomes applicable.');
            }

            if ($rule->retired_at !== null && $rule->retired_at->isPast()) {
                return $rule;
            }

            $rule->retired_at = now();
            $rule->save();

            AuditEvent::record(
                eventType: 'fee_rule.retired',
                targetType: FeeRule::class,
                targetId: $rule->id,
                targetReference: "{$rule->kind->value}:{$rule->rule_key}:v{$rule->version}",
                payload: [
                    'kind' => $rule->kind->value,
                    'rule_key' => $rule->rule_key,
                    'version' => $rule->version,
                    'retired_at' => $rule->retired_at->toIso8601String(),
                    'reason' => trim($reason),
                ],
                actor: $freshAdmin,

                context: ['executor' => self::class, 'required_permission' => $freshAdmin?->user_type === UserType::Admin ? 'fees.manage' : null]
            );

            return $rule;
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRule(FeeRule $rule): array
    {
        return [
            'available' => true,
            'id' => $rule->id,
            'rule_id' => $rule->id,
            'kind' => $rule->kind->value,
            'rule_key' => $rule->rule_key,
            'version' => $rule->version,
            'name' => $rule->name,
            'model' => $rule->model->value,
            'model_label' => $rule->model->displayName(),
            'timing' => $rule->timing->value,
            'basis' => $rule->basis->value,
            'basis_points' => $rule->basis_points,
            'settlement_source' => $rule->settlement_source->value,
            'amount_kobo' => $rule->amount_kobo,
            'formatted_amount' => $rule->formattedAmount(),
            'is_zero' => $rule->isZero(),
            'currency' => $rule->currency,
            'customer_description' => $rule->customer_description,
            'publication_reason' => $rule->publication_reason,
            'effective_at' => $rule->effective_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            'retired_at' => $rule->retired_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            'is_active' => $rule->effective_at->isPast() && ($rule->retired_at === null || $rule->retired_at->isFuture()),
        ];
    }

    /** @return array<string, int|string> */
    private function serializeQuote(FeeQuote $quote): array
    {
        return [
            'rule_id' => $quote->ruleId,
            'rule_version' => $quote->ruleVersion,
            'amount_kobo' => $quote->amountKobo,
            'currency' => $quote->currency,
            'basis_kobo' => $quote->basisKobo,
            'timing' => $quote->timing->value,
            'basis' => $quote->basis->value,
            'source_type' => $quote->sourceType,
            'source_id' => $quote->sourceId,
        ];
    }

    private function ensureCanManageFees(User $admin): void
    {
        if ($admin->account_state !== AccountState::Active || $admin->user_type !== UserType::Admin) {
            throw new ConflictHttpException('Admin account is not active.');
        }

        if (! $this->authorizationService->allows($admin, AdminPermission::FeesManage)) {
            throw new ConflictHttpException('Admin does not have current authority to manage fees.');
        }
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     */
    private function enumValue(string $enumClass, mixed $value): \BackedEnum
    {
        if ($value instanceof $enumClass) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages(['model' => ['A valid fee rule value is required.']]);
        }

        try {
            return $enumClass::from($value);
        } catch (\ValueError) {
            throw ValidationException::withMessages(['model' => ['A valid fee rule value is required.']]);
        }
    }

    private function validateRuleTerms(
        FeeRuleKind $kind,
        FeeRuleModel $model,
        FeeRuleTiming $timing,
        FeeRuleBasis $basis,
        FeeSettlementSource $settlementSource,
        int $amountKobo,
        ?int $basisPoints,
        string $ruleKey,
    ): void {
        if (! preg_match('/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/', $ruleKey) || mb_strlen($ruleKey) > 100) {
            throw ValidationException::withMessages(['rule_key' => ['A stable plan fee option identifier is required.']]);
        }

        if ($amountKobo < 0 || $amountKobo > 999_999_999_999) {
            throw ValidationException::withMessages(['amount_ngn' => ['The fee amount is outside the supported range.']]);
        }

        if ($kind === FeeRuleKind::Registration) {
            if ($model === FeeRuleModel::OneDay || $model === FeeRuleModel::Percentage
                || $timing !== FeeRuleTiming::Registration
                || $basis !== FeeRuleBasis::None
                || $settlementSource !== FeeSettlementSource::ExternalReceipt
                || $basisPoints !== null) {
                throw ValidationException::withMessages(['model' => ['Registration rules support only fixed or explicit zero external fees.']]);
            }

            $this->validateAmountForModel($model, $amountKobo);

            return;
        }

        if (! in_array($timing, [FeeRuleTiming::FirstContribution, FeeRuleTiming::CycleCompletion, FeeRuleTiming::Withdrawal], true)) {
            throw ValidationException::withMessages(['timing' => ['Plan rules must apply to a contribution, cycle completion, or withdrawal event.']]);
        }

        if ($model === FeeRuleModel::OneDay
            && ! in_array($timing, [FeeRuleTiming::FirstContribution, FeeRuleTiming::CycleCompletion], true)) {
            throw ValidationException::withMessages(['timing' => ['One-day rules support first contribution or cycle completion.']]);
        }

        if ($model === FeeRuleModel::Percentage) {
            $expected = match ($timing) {
                FeeRuleTiming::CycleCompletion => FeeRuleBasis::NetCycleContributions,
                FeeRuleTiming::Withdrawal => FeeRuleBasis::GrossWithdrawalDebit,
                default => null,
            };
            if ($expected === null || $basis !== $expected || $basisPoints === null || $basisPoints < 0 || $basisPoints > 10_000) {
                throw ValidationException::withMessages(['basis_points' => ['Choose a supported percentage timing, basis, and rate from 0 to 10,000 basis points.']]);
            }
        } elseif ($model === FeeRuleModel::OneDay) {
            if ($basis !== FeeRuleBasis::ContractualDailyContribution || $basisPoints !== null) {
                throw ValidationException::withMessages(['basis' => ['One-day rules use the contractual daily contribution as their basis.']]);
            }
        } elseif ($basis !== FeeRuleBasis::None || $basisPoints !== null) {
            throw ValidationException::withMessages(['basis' => ['Fixed and no-fee rules do not use a calculation basis.']]);
        }

        if ($model === FeeRuleModel::NoFee) {
            if ($amountKobo !== 0) {
                throw ValidationException::withMessages(['amount_ngn' => ['An explicit no-fee rule must have an amount of zero.']]);
            }
        } elseif ($model === FeeRuleModel::Fixed && $amountKobo < 1) {
            throw ValidationException::withMessages(['amount_ngn' => ['A fixed plan fee must be positive.']]);
        } elseif ($model === FeeRuleModel::OneDay && $amountKobo !== 0) {
            throw ValidationException::withMessages(['amount_ngn' => ['One-day fees use the plan snapshot daily amount, not a configured fee amount.']]);
        } elseif ($model === FeeRuleModel::Percentage && $amountKobo !== 0) {
            throw ValidationException::withMessages(['amount_ngn' => ['Percentage rules store their rate in basis points, not a fixed amount.']]);
        }

        $expectedSettlement = $timing === FeeRuleTiming::Withdrawal
            ? FeeSettlementSource::WithdrawalPayout
            : FeeSettlementSource::SavingsApplication;
        if ($model !== FeeRuleModel::NoFee && $settlementSource !== $expectedSettlement) {
            throw ValidationException::withMessages(['settlement_source' => ['The settlement source does not match the selected fee timing.']]);
        }
    }

    private function validateAmountForModel(FeeRuleModel $model, int $amountKobo): void
    {
        if ($model === FeeRuleModel::NoFee && $amountKobo !== 0) {
            throw ValidationException::withMessages(['amount_ngn' => ['An explicit no-fee rule must have an amount of zero.']]);
        }

        if ($model === FeeRuleModel::Fixed && $amountKobo < 1) {
            throw ValidationException::withMessages(['amount_ngn' => ['A fixed registration fee must be positive.']]);
        }
    }

    private function defaultSettlementSource(FeeRuleKind $kind, FeeRuleTiming $timing): FeeSettlementSource
    {
        if ($kind === FeeRuleKind::Registration) {
            return FeeSettlementSource::ExternalReceipt;
        }

        return $timing === FeeRuleTiming::Withdrawal
            ? FeeSettlementSource::WithdrawalPayout
            : FeeSettlementSource::SavingsApplication;
    }

    private function closeApplicableRuleInterval(FeeRuleKind $kind, string $ruleKey, Carbon $effectiveAt): void
    {
        $rules = FeeRule::query()
            ->where('kind', $kind->value)
            ->where('rule_key', $ruleKey)
            ->orderBy('effective_at')
            ->lockForUpdate()
            ->get();

        $sameStart = $rules->first(fn (FeeRule $rule): bool => $rule->effective_at->equalTo($effectiveAt));
        if ($sameStart !== null) {
            throw new ConflictHttpException('A fee rule already starts at the requested effective time.');
        }

        $predecessors = $rules->filter(fn (FeeRule $rule): bool => $rule->effective_at->lessThan($effectiveAt)
            && ($rule->retired_at === null || $rule->retired_at->greaterThan($effectiveAt)));
        if ($predecessors->count() > 1) {
            throw new ConflictHttpException('Existing fee rule intervals overlap and require reconciliation.');
        }

        $predecessor = $predecessors->last();
        if ($predecessor !== null) {
            $predecessor->retired_at = $effectiveAt;
            $predecessor->save();
        }
    }

    private function nextRuleStart(FeeRuleKind $kind, string $ruleKey, Carbon $effectiveAt): ?Carbon
    {
        $next = FeeRule::query()
            ->where('kind', $kind->value)
            ->where('rule_key', $ruleKey)
            ->where('effective_at', '>', $effectiveAt)
            ->orderBy('effective_at')
            ->value('effective_at');

        return $next === null ? null : Carbon::parse($next);
    }
}
