<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerActivity;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ManualChargeService
{
    /** @param array<string, mixed> $terms */
    public function publish(User $actor, array $terms, Request $request): ChargeCategoryVersion
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $terms, $request): ChargeCategoryVersion {
            $actor = $this->authorize($actor, $terms['kind'], $request);
            $hash = hash('sha256', json_encode([$actor->id, $terms['category_key'], $terms['kind'], trim($terms['purpose']), trim($terms['customer_description']), $terms['amount_kobo']], JSON_THROW_ON_ERROR));
            $existing = ChargeCategoryVersion::query()->where('publication_reference', $terms['publication_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The publication identity belongs to different category terms.');
                }

                return $existing;
            }
            if (! in_array($terms['kind'], ['manual_fee', 'deduction'], true) || $terms['amount_kobo'] < 1
                || $terms['amount_kobo'] > 999999999999 || trim($terms['purpose']) === '' || trim($terms['customer_description']) === '') {
                abort(422, 'Complete the controlled charge terms.');
            }
            $destination = $terms['kind'] === 'manual_fee' ? LedgerAccountCode::FeeIncome : LedgerAccountCode::OtherDeductionDestination;
            $account = $this->mapped($destination);
            $latest = ChargeCategoryVersion::query()->where('category_key', $terms['category_key'])->orderByDesc('version')->lockForUpdate()->first();
            if ($latest !== null && $latest->kind !== $terms['kind']) {
                throw new ConflictHttpException('A published category cannot change charge kind.');
            }
            $version = ($latest->version ?? 0) + 1;
            $ruleVersion = $terms['kind'] === 'manual_fee' ? FeeRule::query()->where('kind', FeeRuleKind::Manual)
                ->orderByDesc('version')->lockForUpdate()->value('version') : null;
            $rule = $terms['kind'] === 'manual_fee' ? FeeRule::create([
                'rule_key' => 'manual-'.$terms['category_key'], 'version' => ($ruleVersion ?? 0) + 1, 'kind' => FeeRuleKind::Manual,
                'name' => $terms['category_key'], 'model' => FeeRuleModel::Fixed, 'timing' => FeeRuleTiming::Manual,
                'basis' => FeeRuleBasis::None, 'settlement_source' => FeeSettlementSource::ExternalReceipt,
                'amount_kobo' => $terms['amount_kobo'], 'currency' => 'NGN', 'customer_description' => $terms['customer_description'],
                'effective_at' => now(), 'published_by_user_id' => $actor->id, 'publication_reason' => $terms['purpose'],
            ]) : null;
            $category = ChargeCategoryVersion::create([...$terms, 'version' => $version,
                'payload_hash' => $hash, 'destination_code' => $destination->value, 'destination_mapping_version' => $account->version,
                'fee_rule_id' => $rule?->id, 'published_by_user_id' => $actor->id]);
            AuditEvent::record('charge.category_published', ChargeCategoryVersion::class, $category->id, $category->category_key,
                ['kind' => $category->kind, 'version' => $version, 'amount_kobo' => $category->amount_kobo], $actor,
                context: ['executor' => self::class, 'required_permission' => $category->kind === 'manual_fee' ? 'fees.manage' : 'deductions.manage']);

            return $category;
        }, attempts: 3);
    }

    /** @return array{status: string, charge_reference: string} */
    public function status(User $actor, string $reference): array
    {
        return DB::transaction(function () use ($actor, $reference): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $charge = ManualCharge::query()->where('operation_reference', $reference)->where('actor_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $category = ChargeCategoryVersion::query()->whereKey($charge->charge_category_version_id)->sharedLock()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor,
                $category->kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage), 403);
            abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($charge->customer_profile_id)->exists(), 404);
            if ($category->kind === 'manual_fee') {
                $fee = $charge->fee_obligation_id === null ? null : FeeObligation::query()->whereKey($charge->fee_obligation_id)->sharedLock()->first();
                if ($fee === null || $fee->source_type !== 'manual_charge' || $fee->source_id !== $reference
                    || $fee->customer_profile_id !== $charge->customer_profile_id || $fee->kind !== 'manual') {
                    throw new ConflictHttpException('The retained manual fee assessment is unavailable.');
                }
                if (Schema::hasTable('fee_savings_applications') && DB::table('fee_savings_applications')->where('operation_reference', $reference)->exists()) {
                    app(FeeSavingsApplicationService::class)->assertPosted($reference, true);
                }
            } else {
                $group = LedgerPostingGroup::query()->with('entries.account')->find($charge->ledger_posting_group_id);
                if ($group === null || $group->source_type !== 'manual_charge' || $group->source_id !== $reference
                    || $group->event_type !== 'other_deduction' || $group->currency !== 'NGN'
                    || $group->customer_profile_id !== $charge->customer_profile_id || $group->thrift_plan_id !== $charge->thrift_plan_id
                    || $group->actor_user_id !== $actor->id || $group->entries->count() !== 2
                    || $group->entries->where('side', LedgerEntrySide::Debit)->count() !== 1
                    || $group->entries->where('side', LedgerEntrySide::Credit)->count() !== 1) {
                    throw new ConflictHttpException('The retained deduction journal is unavailable.');
                }
                foreach ($group->entries as $entry) {
                    $savings = $entry->side === LedgerEntrySide::Debit;
                    if ($entry->amount_kobo !== $charge->amount_kobo || $entry->customer_profile_id !== $charge->customer_profile_id
                        || $entry->thrift_plan_id !== ($savings ? $charge->thrift_plan_id : null)
                        || $entry->account->code !== ($savings ? LedgerAccountCode::CustomerSavingsLiability : LedgerAccountCode::OtherDeductionDestination)) {
                        throw new ConflictHttpException('The retained deduction journal is unavailable.');
                    }
                }
            }

            return ['status' => 'confirmed', 'charge_reference' => $reference];
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, CustomerProfile $customer, ThriftPlan $plan, ChargeCategoryVersion $category, array $data, Request $request): array
    {
        return DB::transaction(fn (): array => $this->quote($actor, $customer, $plan, $category, $data, $request));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function quote(User $actor, CustomerProfile $customer, ThriftPlan $plan, ChargeCategoryVersion $category, array $data, Request $request, ?CarbonImmutable $expires = null): array
    {
        $actor = $this->authorize($actor, $category->kind, $request);
        abort_unless(config('fees.manual_charges_enabled', false), 503, 'Manual charge acceptance is not yet certified.');
        app(BusinessSettings::class)->ensureFeature('manual_charges');
        $customer = CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($customer->id)->exists(), 404);
        $plan = ThriftPlan::query()->whereKey($plan->id)->where('customer_profile_id', $customer->id)->lockForUpdate()->firstOrFail();
        app(CustomerActivityGate::class)->assertAllowed($customer, CustomerActivity::AssessDiscretionaryFee);
        $latest = ChargeCategoryVersion::query()->where('category_key', $category->category_key)->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $mode = $data['mode'] ?? '';
        $permittedModes = $category->kind === 'manual_fee' ? ['assessment_only', 'assess_and_apply'] : ['deduction'];
        if (! in_array($category->kind, ['manual_fee', 'deduction'], true) || ! in_array($mode, $permittedModes, true)
            || $latest->id !== $category->id || ! $plan->status->isOpen()
            || ($data['customer_version'] ?? null) !== $customer->version || ($data['plan_version'] ?? null) !== $plan->version
            || ! is_string($data['reason'] ?? null) || trim($data['reason']) === '' || mb_strlen($data['reason']) > 500
            || $category->amount_kobo < 1 || $category->amount_kobo > 999999999999
            || trim($category->purpose) === '' || trim($category->customer_description) === '') {
            throw new ConflictHttpException('Charge terms or Customer cycle changed. Review again.');
        }
        $destinationCode = $category->kind === 'manual_fee' ? LedgerAccountCode::FeeIncome : LedgerAccountCode::OtherDeductionDestination;
        $destination = $this->mapped($destinationCode);
        if ($category->destination_code !== $destinationCode->value || $category->destination_mapping_version !== $destination->version) {
            throw new ConflictHttpException('The approved category destination changed. Publish and review new terms.');
        }
        $rule = null;
        if ($category->kind === 'manual_fee') {
            $rule = FeeRule::query()->whereKey($category->fee_rule_id)->sharedLock()->first();
            if ($rule === null || $rule->kind !== FeeRuleKind::Manual || $rule->model !== FeeRuleModel::Fixed
                || $rule->timing !== FeeRuleTiming::Manual || $rule->basis !== FeeRuleBasis::None
                || $rule->currency !== 'NGN' || $rule->amount_kobo !== $category->amount_kobo
                || $rule->customer_description !== $category->customer_description
                || $rule->settlement_source !== FeeSettlementSource::ExternalReceipt) {
                throw new ConflictHttpException('The approved manual fee pricing source is unavailable.');
            }
        }
        if ($mode === 'assess_and_apply' && ! app(FeeSavingsApplicationService::class)->available()) {
            throw new ConflictHttpException('Reviewed savings fee application is unavailable.');
        }
        $position = app(WithdrawalBalanceService::class)->position($customer, $plan, true);
        $debit = $mode === 'assessment_only' ? 0 : $category->amount_kobo;
        $liability = $debit > 0 ? $this->mapped(LedgerAccountCode::CustomerSavingsLiability) : null;
        if ($debit > min($position['available_kobo'], $position['cycle_available_kobo'])) {
            throw new ConflictHttpException('Insufficient unreserved savings for this charge.');
        }
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        $period = app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $quote = ['actor_user_id' => $actor->id, 'customer_profile_id' => $customer->id, 'customer_version' => $customer->version,
            'thrift_plan_id' => $plan->id, 'plan_version' => $plan->version, 'category_id' => $category->id,
            'category_version' => $category->version, 'kind' => $category->kind, 'mode' => $mode,
            'purpose' => $category->purpose, 'customer_description' => $category->customer_description,
            'amount_kobo' => $category->amount_kobo, 'amount' => MoneyFormatter::formatNaira($category->amount_kobo),
            'destination' => $destination->display_name ?? ($category->kind === 'manual_fee' ? 'Fee income' : 'Approved other deduction destination'),
            'destination_code' => $destinationCode->value, 'destination_version' => $destination->version,
            'savings_mapping_version' => $liability?->version, 'rule_id' => $rule?->id, 'rule_version' => $rule?->version,
            'business_version' => $business->version, 'business_timezone' => $business->timezone, 'occurred_on' => $date,
            'period_id' => $period->id, 'period_version' => $period->version, 'position' => $position,
            'posted_savings' => MoneyFormatter::formatNaira($position['cycle_liability_kobo']),
            'reserved_savings' => MoneyFormatter::formatNaira($position['cycle_reservations_kobo']),
            'available_savings' => MoneyFormatter::formatNaira($position['cycle_available_kobo']),
            'remaining_savings' => MoneyFormatter::formatNaira($position['cycle_liability_kobo'] - $debit),
            'remaining_available' => MoneyFormatter::formatNaira($position['cycle_available_kobo'] - $debit),
            'remaining_fee' => MoneyFormatter::formatNaira($mode === 'assessment_only' ? $category->amount_kobo : 0),
            'reason' => trim($data['reason']), 'quote_expires_at' => ($expires ?? CarbonImmutable::now()->addMinutes(10))->utc()->toIso8601String()];
        $quote['preview_fingerprint'] = hash_hmac('sha256', json_encode($quote, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $quote;
    }

    /** @param array<string, mixed>|null $review */
    public function assess(User $actor, CustomerProfile $customer, ThriftPlan $plan, ChargeCategoryVersion $category, string $reference, int $customerVersion, int $planVersion, string $reason, Request $request, ?array $review = null): ManualCharge
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $plan, $category, $reference, $customerVersion, $planVersion, $reason, $request, $review): ManualCharge {
            $actor = $this->authorize($actor, $category->kind, $request);
            $customer = CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($customer->id)->exists(), 404);
            $plan = ThriftPlan::query()->whereKey($plan->id)->where('customer_profile_id', $customer->id)->lockForUpdate()->firstOrFail();
            $instructions = [$actor->id, $customer->id, $plan->id, $category->id, $customerVersion, $planVersion, trim($reason)];
            if ($review !== null) {
                $instructions[] = $review;
            }
            $hash = hash('sha256', json_encode($instructions, JSON_THROW_ON_ERROR));
            $existing = ManualCharge::query()->where('operation_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The charge operation identity belongs to different instructions.');
                }

                if (($review['mode'] ?? null) === 'assess_and_apply') {
                    $group = app(FeeSavingsApplicationService::class)->assertPosted($reference, true);
                    $application = DB::table('fee_savings_applications')->where('operation_reference', $reference)->sole();
                    if ((int) $application->fee_obligation_id !== $existing->fee_obligation_id || $group->customer_profile_id !== $existing->customer_profile_id) {
                        throw new ConflictHttpException('The combined charge payment source is unavailable.');
                    }
                }

                return $existing;
            }
            if ($review !== null) {
                try {
                    $expires = CarbonImmutable::parse($review['quote_expires_at'] ?? '')->utc();
                } catch (\Exception) {
                    throw new ConflictHttpException('Review the current charge again.');
                }
                if ($expires->lessThanOrEqualTo(now()) || $expires->greaterThan(now()->addMinutes(10))) {
                    throw new ConflictHttpException('The charge review expired. Review again.');
                }
                $quote = $this->quote($actor, $customer, $plan, $category,
                    ['mode' => $review['mode'], 'customer_version' => $customerVersion, 'plan_version' => $planVersion, 'reason' => $reason], $request, $expires);
                if (! hash_equals($quote['preview_fingerprint'], $review['preview_fingerprint'] ?? '')) {
                    throw new ConflictHttpException('Charge or savings sources changed. Review again.');
                }
            }
            abort_unless(config('fees.manual_charges_enabled', false), 503, 'Manual charge acceptance is not yet certified.');
            app(BusinessSettings::class)->ensureFeature('manual_charges');
            app(CustomerActivityGate::class)->assertAllowed($customer, CustomerActivity::AssessDiscretionaryFee);
            $latest = ChargeCategoryVersion::query()->where('category_key', $category->category_key)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($latest->id !== $category->id || $customer->version !== $customerVersion || $plan->version !== $planVersion
                || in_array($plan->status->value, ['closed', 'cancelled'], true) || trim($reason) === '') {
                throw new ConflictHttpException('Charge terms or Customer cycle changed. Review again.');
            }
            $business = BusinessProfile::current();
            $date = now($business->timezone)->toDateString();
            app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
            $approvedDestination = $this->mapped(LedgerAccountCode::from($category->destination_code));
            if ($approvedDestination->version !== $category->destination_mapping_version) {
                throw new ConflictHttpException('The approved category destination changed. Publish and review new terms.');
            }
            $obligation = null;
            $group = null;
            $originalPosition = null;
            if ($category->kind === 'manual_fee') {
                $rule = FeeRule::query()->findOrFail($category->fee_rule_id);
                $snapshot = FeeSnapshot::create(['customer_profile_id' => $customer->id, 'source_type' => 'manual_charge', 'source_id' => $reference,
                    'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version, 'name' => $rule->name, 'kind' => $rule->kind,
                    'model' => $rule->model, 'timing' => $rule->timing, 'basis' => $rule->basis,
                    'settlement_source' => $rule->settlement_source, 'currency' => 'NGN', 'amount_kobo' => $rule->amount_kobo,
                    'basis_amount_kobo' => 0, 'customer_description' => $rule->customer_description]);
                $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $actor);
            } else {
                $position = app(WithdrawalBalanceService::class)->position($customer, $plan, true);
                $originalPosition = $position;
                if (min($position['available_kobo'], $position['cycle_available_kobo']) < $category->amount_kobo) {
                    throw new ConflictHttpException('Insufficient unreserved savings for this deduction.');
                }
                $liability = $this->mapped(LedgerAccountCode::CustomerSavingsLiability);
                $destination = $this->mapped(LedgerAccountCode::OtherDeductionDestination);
                $group = LedgerPostingGroup::create(['posting_reference' => 'DED-'.Str::uuid(), 'idempotency_key' => 'manual-charge-'.$reference,
                    'payload_hash' => $hash, 'source_type' => 'manual_charge', 'source_id' => $reference, 'event_type' => 'other_deduction',
                    'currency' => 'NGN', 'actor_user_id' => $actor->id, 'customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id,
                    'occurred_at' => now(), 'occurred_on' => $date, 'business_timezone' => $business->timezone,
                    'schema_version' => 1, 'committed_at' => now(), 'metadata' => ['category_version_id' => $category->id, 'destination_version' => $destination->version]]);
                foreach ([[$liability, LedgerEntrySide::Debit], [$destination, LedgerEntrySide::Credit]] as $index => [$account, $side]) {
                    LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                        'ledger_account_id' => $account->id, 'side' => $side, 'amount_kobo' => $category->amount_kobo,
                        'customer_profile_id' => $customer->id, 'thrift_plan_id' => $side === LedgerEntrySide::Debit ? $plan->id : null]);
                }
            }
            $charge = ManualCharge::create(['operation_reference' => $reference, 'payload_hash' => $hash, 'customer_profile_id' => $customer->id,
                'thrift_plan_id' => $plan->id, 'charge_category_version_id' => $category->id, 'actor_user_id' => $actor->id,
                'amount_kobo' => $category->amount_kobo, 'fee_obligation_id' => $obligation?->id, 'ledger_posting_group_id' => $group?->id, 'reason' => trim($reason)]);
            AuditEvent::record('charge.assessed', ManualCharge::class, $charge->id, $reference,
                ['kind' => $category->kind, 'version' => $category->version, 'amount_kobo' => $category->amount_kobo, 'customer_profile_id' => $customer->id, 'reason' => trim($reason)], $actor,
                context: ['executor' => self::class, 'correlation_reference' => $reference, 'required_permission' => $category->kind === 'manual_fee' ? 'fees.manage' : 'deductions.manage']);

            if ($group !== null) {
                app(LedgerTransactionProjectionService::class)->projectCharge($charge);
            }

            if (($review['mode'] ?? null) === 'assess_and_apply' && $obligation !== null) {
                $applications = app(FeeSavingsApplicationService::class);
                $payment = ['plan_id' => $plan->plan_id, 'reason' => trim($reason), 'customer_description' => $category->customer_description];
                $paymentQuote = $applications->preview($actor, $obligation->id, $payment);
                $applications->apply($actor, $obligation->id, [...$payment, 'attempt_reference' => $reference, 'confirmed' => true,
                    'preview_fingerprint' => $paymentQuote['preview_fingerprint'], 'quote_expires_at' => $paymentQuote['quote_expires_at']], $request);
            }

            app(ManualChargeNotice::class)->capture($charge, $originalPosition);

            return $charge;
        }, attempts: 3);
    }

    private function authorize(User $actor, string $kind, Request $request): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless(app(AuthorizationService::class)->allows($actor, $kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage), 403);

        return $actor;
    }

    private function mapped(LedgerAccountCode $code): LedgerAccount
    {
        $account = LedgerAccount::query()->where('code', $code->value)->lockForUpdate()->sole();
        $class = match ($code) {
            LedgerAccountCode::FeeIncome => LedgerAccountClass::FeeIncome,
            LedgerAccountCode::CustomerSavingsLiability => LedgerAccountClass::CustomerSavingsLiability,
            LedgerAccountCode::OtherDeductionDestination => LedgerAccountClass::OtherDeductionDestination,
            default => throw new ConflictHttpException('Unsupported charge destination.'),
        };
        if ($account->mapping_status !== 'mapped' || $account->account_class !== $class || $account->normal_balance !== LedgerEntrySide::Credit || $account->currency !== 'NGN') {
            throw new ConflictHttpException('The approved charge destination is unavailable.');
        }

        return $account;
    }
}
