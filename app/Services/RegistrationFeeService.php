<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\FeeRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationFeeService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Get the current published registration fee rule.
     */
    public function getCurrentRule(): ?FeeRule
    {
        return FeeRule::currentRegistration()->first();
    }

    /**
     * Generate an authoritative fee preview for customer registration.
     *
     * @return array<string, mixed>
     */
    public function previewFee(): array
    {
        $rule = $this->getCurrentRule();

        if (! $rule) {
            return [
                'available' => false,
                'message' => 'No active registration fee rule published. Customer registration is unavailable.',
            ];
        }

        return [
            'available' => true,
            'rule_id' => $rule->id,
            'version' => $rule->version,
            'name' => $rule->name,
            'model' => $rule->model->value,
            'amount_kobo' => $rule->amount_kobo,
            'formatted_amount' => $rule->formattedAmount(),
            'currency' => $rule->currency,
            'customer_description' => $rule->customer_description,
            'is_zero' => $rule->isZero(),
        ];
    }

    /**
     * Publish a new registration fee rule.
     *
     * @param  array{
     *     name: string,
     *     model: FeeRuleModel|string,
     *     amount_kobo?: int,
     *     customer_description: string,
     *     publication_reason: string,
     * }  $data
     *
     * @throws ValidationException|HttpException
     */
    public function publishRule(User $admin, array $data): FeeRule
    {
        return DB::transaction(function () use ($admin, $data): FeeRule {
            /** @var User $freshAdmin */
            $freshAdmin = User::query()->where('id', $admin->id)->lockForUpdate()->firstOrFail();

            if ($freshAdmin->account_state !== AccountState::Active || $freshAdmin->user_type !== UserType::Admin) {
                throw new ConflictHttpException('Admin account is not active.');
            }

            if (! $this->authorizationService->allows($freshAdmin, AdminPermission::FeesManage)) {
                throw new ConflictHttpException('Admin does not have current authority to manage fee rules.');
            }

            $model = $data['model'] instanceof FeeRuleModel ? $data['model'] : FeeRuleModel::from($data['model']);
            $amountKobo = (int) ($data['amount_kobo'] ?? 0);

            if ($model === FeeRuleModel::NoFee && $amountKobo !== 0) {
                throw ValidationException::withMessages([
                    'amount_kobo' => ['An explicit no-fee rule must have an amount of 0 kobo.'],
                ]);
            }

            if ($model === FeeRuleModel::Fixed && $amountKobo <= 0) {
                throw ValidationException::withMessages([
                    'amount_kobo' => ['A fixed fee rule must have a positive amount greater than 0 kobo.'],
                ]);
            }

            if ($amountKobo > 999_999_999_999) {
                throw ValidationException::withMessages([
                    'amount_kobo' => ['Fee amount exceeds allowable operational maximum.'],
                ]);
            }

            // Retire currently active registration rules
            FeeRule::query()
                ->where('kind', FeeRuleKind::Registration->value)
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->update(['retired_at' => now()]);

            $latestVersion = (int) FeeRule::query()
                ->where('kind', FeeRuleKind::Registration->value)
                ->max('version');

            $rule = FeeRule::create([
                'version' => $latestVersion + 1,
                'name' => trim($data['name']),
                'kind' => FeeRuleKind::Registration,
                'model' => $model,
                'currency' => 'NGN',
                'amount_kobo' => $amountKobo,
                'customer_description' => trim($data['customer_description']),
                'effective_at' => now(),
                'retired_at' => null,
                'published_by_user_id' => $freshAdmin->id,
                'publication_reason' => trim($data['publication_reason']),
            ]);

            AuditEvent::record(
                eventType: 'fee_rule.published',
                targetType: FeeRule::class,
                targetId: $rule->id,
                targetReference: "v{$rule->version}",
                payload: [
                    'version' => $rule->version,
                    'name' => $rule->name,
                    'model' => $rule->model->value,
                    'amount_kobo' => $rule->amount_kobo,
                    'currency' => $rule->currency,
                    'publication_reason' => $rule->publication_reason,
                ],
                actor: $freshAdmin,
            );

            return $rule;
        });
    }
}
