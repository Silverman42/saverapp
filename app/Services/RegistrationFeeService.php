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
use App\Support\FeePercentageCalculator;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationFeeService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function previewPublication(User $admin, array $data): array
    {
        app(PlatformGuard::class)->assertAllowed('read');
        $this->ensureCanManageFees(User::query()->findOrFail($admin->id));
        $terms = $this->publicationTerms($data);
        $catalogue = $this->publicationCatalogue($terms['kind'], DB::transactionLevel() > 0);
        $version = collect($catalogue)->max('version') ?? 0;
        $display = new FeeRule($terms);
        $example = match ($terms['model']) {
            FeeRuleModel::Percentage => ['label' => $terms['basis'] === FeeRuleBasis::GrossWithdrawalDebit
                ? 'At ₦100,000 gross withdrawal debit' : 'At ₦100,000 net cycle contributions',
                'formatted_fee' => MoneyFormatter::formatNaira(FeePercentageCalculator::calculate(10000000, $terms['basis_points'] ?? 0))],
            FeeRuleModel::OneDay => ['label' => 'At ₦2,000 contractual daily contribution', 'formatted_fee' => '₦2,000.00'],
            default => ['label' => 'Configured fee', 'formatted_fee' => MoneyFormatter::formatNaira($terms['amount_kobo'])],
        };

        return ['terms' => [
            'name' => $terms['name'], 'kind' => $terms['kind']->value, 'rule_key' => $terms['rule_key'],
            'model_label' => $terms['model']->displayName(), 'formatted_amount' => $display->formattedAmount(),
            'timing' => $terms['timing']->value, 'basis' => $terms['basis']->value,
            'settlement_source' => $terms['settlement_source']->value, 'currency' => 'NGN',
            'customer_description' => $terms['customer_description'], 'publication_reason' => $terms['publication_reason'],
            'effective_at' => $terms['effective_at']?->toIso8601String() ?? 'On confirmation',
            'current_catalogue_version' => (int) $version, 'next_version' => (int) $version + 1,
        ], 'example' => $example,
            'impact' => 'Applies only to new agreements from the effective time. Issued Customer snapshots and existing obligations retain their original terms.',
            'preview_fingerprint' => $this->publicationFingerprint($admin, $terms, $catalogue)];
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
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $data): FeeRule {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanManageFees($freshAdmin);

            $terms = $this->publicationTerms($data);
            $kind = $terms['kind'];
            $model = $terms['model'];
            $timing = $terms['timing'];
            $basis = $terms['basis'];
            $settlementSource = $terms['settlement_source'];
            $amountKobo = $terms['amount_kobo'];
            $basisPoints = $terms['basis_points'];
            $ruleKey = $terms['rule_key'];
            $name = $terms['name'];
            $customerDescription = $terms['customer_description'];
            $publicationReason = $terms['publication_reason'];
            if (($data['confirmed'] ?? false) !== true) {
                throw ValidationException::withMessages(['confirmed' => ['Confirm the reviewed fee publication.']]);
            }
            $catalogue = $this->publicationCatalogue($kind, true);
            if (! is_string($data['preview_fingerprint'] ?? null)
                || ! hash_equals($this->publicationFingerprint($freshAdmin, $terms, $catalogue), $data['preview_fingerprint'])) {
                throw new ConflictHttpException('The reviewed fee terms or catalogue changed. Review again.');
            }
            $effectiveAt = $terms['effective_at'] ?? Carbon::now();

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

            $audit = AuditEvent::record(
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

                context: ['executor' => self::class, 'required_permission' => $freshAdmin->user_type === UserType::Admin ? 'fees.manage' : null]
            );

            $this->captureRuleNotice($rule, 'published', $freshAdmin, $audit);

            return $rule;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: FeeRuleKind, model: FeeRuleModel, timing: FeeRuleTiming, basis: FeeRuleBasis, settlement_source: FeeSettlementSource, amount_kobo: int, basis_points: int|null, rule_key: string, name: string, customer_description: string, publication_reason: string, effective_at: Carbon|null}
     */
    private function publicationTerms(array $data): array
    {
        $kind = $this->enumValue(FeeRuleKind::class, $data['kind'] ?? FeeRuleKind::Registration->value);
        if (! in_array($kind, [FeeRuleKind::Registration, FeeRuleKind::Plan], true)) {
            throw ValidationException::withMessages(['kind' => ['Choose a registration or plan rule.']]);
        }
        if (isset($data['currency']) && $data['currency'] !== 'NGN') {
            throw ValidationException::withMessages(['currency' => ['Fee rules use NGN.']]);
        }
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
        $amountKobo = $data['amount_kobo'] ?? 0;
        if (! is_int($amountKobo)) {
            throw ValidationException::withMessages(['amount_ngn' => ['Fee amounts require integer kobo.']]);
        }
        $basisPoints = isset($data['basis_points']) && $data['basis_points'] !== '' ? $data['basis_points'] : null;
        if ($basisPoints !== null && ! is_int($basisPoints)) {
            throw ValidationException::withMessages(['basis_points' => ['Fee rates require integer basis points.']]);
        }
        $ruleKey = $kind === FeeRuleKind::Registration ? 'registration' : trim((string) ($data['rule_key'] ?? ''));
        foreach (['name' => 100, 'customer_description' => 500, 'publication_reason' => 500] as $field => $maximum) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '' || mb_strlen(trim($data[$field])) > $maximum) {
                throw ValidationException::withMessages([$field => ['Enter non-empty text within the supported length.']]);
            }
        }
        $name = trim($data['name']);
        $customerDescription = trim($data['customer_description']);
        $publicationReason = trim($data['publication_reason']);

        $this->validateRuleTerms($kind, $model, $timing, $basis, $settlementSource, $amountKobo, $basisPoints, $ruleKey);

        $effectiveAt = null;
        if (isset($data['effective_at']) && $data['effective_at'] !== '') {
            if (! is_string($data['effective_at'])) {
                throw ValidationException::withMessages(['effective_at' => ['Enter a valid future effective time.']]);
            }
            try {
                $effectiveAt = Carbon::parse($data['effective_at'])->utc()->startOfSecond();
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['effective_at' => ['Enter a valid future effective time.']]);
            }
            if ($effectiveAt->lessThan(Carbon::now())) {
                throw ValidationException::withMessages(['effective_at' => ['Fee rules cannot be published retroactively.']]);
            }
        }

        return ['kind' => $kind, 'model' => $model, 'timing' => $timing, 'basis' => $basis,
            'settlement_source' => $settlementSource, 'amount_kobo' => $amountKobo, 'basis_points' => $basisPoints,
            'rule_key' => $ruleKey, 'name' => $name, 'customer_description' => $customerDescription,
            'publication_reason' => $publicationReason, 'effective_at' => $effectiveAt];
    }

    /** @return list<array<string, mixed>> */
    private function publicationCatalogue(FeeRuleKind $kind, bool $lock): array
    {
        $query = FeeRule::query()->where('kind', $kind->value)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $catalogue = [];
        foreach ($query->get() as $rule) {
            $catalogue[] = $rule->getAttributes();
        }

        return $catalogue;
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<array<string, mixed>>  $catalogue
     */
    private function publicationFingerprint(User $admin, array $terms, array $catalogue): string
    {
        return hash_hmac('sha256', json_encode(['fee_rule_publication', $admin->id, $terms, $catalogue], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @return array<string, mixed> */
    public function previewRetirement(User $admin, int $ruleId, string $reason): array
    {
        app(PlatformGuard::class)->assertAllowed('read');
        $this->ensureCanManageFees(User::query()->findOrFail($admin->id));
        $reason = $this->retirementReason($reason);
        $rule = FeeRule::query()->whereIn('kind', [FeeRuleKind::Registration->value, FeeRuleKind::Plan->value])->findOrFail($ruleId);
        $this->assertRetirementAvailable($rule);

        return [
            'rule' => $this->serializeRule($rule),
            'reason' => $reason,
            'impact' => 'Stops new selection immediately. Existing Customer agreements and obligations retain their published fee terms.',
            'preview_fingerprint' => $this->retirementFingerprint($admin, $rule, $reason),
        ];
    }

    /**
     * End reviewed rule applicability without changing its published terms.
     */
    public function retireRule(User $admin, int $ruleId, string $reason, Request $request, string $previewFingerprint, bool $confirmed): FeeRule
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $ruleId, $reason, $previewFingerprint, $confirmed): FeeRule {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanManageFees($freshAdmin);
            if (! $confirmed) {
                throw ValidationException::withMessages(['confirmed' => ['Confirm the reviewed rule retirement.']]);
            }
            $reason = $this->retirementReason($reason);
            $rule = FeeRule::query()->whereIn('kind', [FeeRuleKind::Registration->value, FeeRuleKind::Plan->value])
                ->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $this->assertRetirementAvailable($rule);
            if (! hash_equals($this->retirementFingerprint($freshAdmin, $rule, $reason), $previewFingerprint)) {
                throw new ConflictHttpException('The reviewed rule or retirement reason changed. Review again.');
            }

            $rule->retired_at = now();
            $rule->save();

            $audit = AuditEvent::record(
                eventType: 'fee_rule.retired',
                targetType: FeeRule::class,
                targetId: $rule->id,
                targetReference: "{$rule->kind->value}:{$rule->rule_key}:v{$rule->version}",
                payload: [
                    'kind' => $rule->kind->value,
                    'rule_key' => $rule->rule_key,
                    'version' => $rule->version,
                    'retired_at' => $rule->retired_at->toIso8601String(),
                    'reason' => $reason,
                ],
                actor: $freshAdmin,
                context: ['executor' => self::class, 'required_permission' => $freshAdmin->user_type === UserType::Admin ? 'fees.manage' : null]
            );

            $this->captureRuleNotice($rule, 'retired', $freshAdmin, $audit);

            return $rule;
        }, attempts: 3);
    }

    private function captureRuleNotice(FeeRule $rule, string $action, User $actor, AuditEvent $audit): void
    {
        $eventId = DB::table('fee_rule_events')->insertGetId([
            'fee_rule_id' => $rule->id, 'version' => $rule->version, 'event_type' => $action,
            'actor_user_id' => $actor->id, 'audit_event_id' => $audit->id,
            'effective_at' => $action === 'retired' ? $rule->retired_at : $rule->effective_at,
            'created_at' => now(),
        ]);
        foreach (User::query()->where('user_type', UserType::Admin->value)
            ->where('account_state', AccountState::Active->value)->cursor() as $recipient) {
            if (! $this->authorizationService->allows($recipient, AdminPermission::FeesManage)) {
                continue;
            }
            $intentId = DB::table('fee_rule_notification_intents')->insertGetId([
                'fee_rule_event_id' => $eventId, 'recipient_user_id' => $recipient->id,
                'notification_id' => (string) Str::uuid(), 'audience_type' => 'fee_manager',
                'channel' => 'database', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(NotificationPipeline::class)->capture('fee_rule', $intentId);
        }
    }

    private function retirementReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['Enter a retirement reason of at most 500 characters.']]);
        }

        return $reason;
    }

    private function assertRetirementAvailable(FeeRule $rule): void
    {
        if ($rule->effective_at->isFuture()) {
            throw new ConflictHttpException('A future fee rule must be cancelled before it becomes applicable.');
        }
        if ($rule->retired_at !== null && $rule->retired_at->lessThanOrEqualTo(now())) {
            throw new ConflictHttpException('This fee rule has already ended. Review the current catalogue.');
        }
    }

    private function retirementFingerprint(User $admin, FeeRule $rule, string $reason): string
    {
        return hash_hmac('sha256', json_encode(['fee_rule_retirement', $admin->id, $rule->getAttributes(), $reason], JSON_THROW_ON_ERROR), (string) config('app.key'));
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
            'is_active' => $rule->effective_at->lessThanOrEqualTo(now()) && ($rule->retired_at === null || $rule->retired_at->isFuture()),
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
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $enumClass
     * @return TEnum
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
            $predecessor->retired_at = $effectiveAt->toImmutable();
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
            ->lockForUpdate()
            ->value('effective_at');

        return $next === null ? null : Carbon::parse($next);
    }
}
