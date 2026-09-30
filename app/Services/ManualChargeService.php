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
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $latest = ChargeCategoryVersion::query()->where('category_key', $terms['category_key'])->orderByDesc('version')->first();
            if ($latest !== null && $latest->kind !== $terms['kind']) {
                throw new ConflictHttpException('A published category cannot change charge kind.');
            }
            $version = ($latest->version ?? 0) + 1;
            $rule = $terms['kind'] === 'manual_fee' ? FeeRule::create([
                'rule_key' => 'manual-'.$terms['category_key'], 'version' => $version, 'kind' => FeeRuleKind::Manual,
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

    public function assess(User $actor, CustomerProfile $customer, ThriftPlan $plan, ChargeCategoryVersion $category, string $reference, int $customerVersion, int $planVersion, string $reason, Request $request): ManualCharge
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $plan, $category, $reference, $customerVersion, $planVersion, $reason, $request): ManualCharge {
            $actor = $this->authorize($actor, $category->kind, $request);
            $customer = CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($customer->id)->exists(), 404);
            $plan = ThriftPlan::query()->whereKey($plan->id)->where('customer_profile_id', $customer->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $customer->id, $plan->id, $category->id, $customerVersion, $planVersion, trim($reason)], JSON_THROW_ON_ERROR));
            $existing = ManualCharge::query()->where('operation_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The charge operation identity belongs to different instructions.');
                }

                return $existing;
            }
            abort_unless(config('fees.manual_charges_enabled', false), 503, 'Manual charge acceptance is not yet certified.');
            app(BusinessSettings::class)->ensureFeature('manual_charges');
            app(CustomerActivityGate::class)->assertAllowed($customer, CustomerActivity::AssessDiscretionaryFee);
            $latest = ChargeCategoryVersion::query()->where('category_key', $category->category_key)->orderByDesc('version')->firstOrFail();
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
                if ($position['cycle_available_kobo'] < $category->amount_kobo) {
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
                ['kind' => $category->kind, 'version' => $category->version, 'amount_kobo' => $category->amount_kobo, 'customer_profile_id' => $customer->id], $actor,
                context: ['executor' => self::class, 'required_permission' => $category->kind === 'manual_fee' ? 'fees.manage' : 'deductions.manage']);

            $intentId = DB::table('manual_charge_notification_intents')->insertGetId([
                'notification_id' => (string) Str::uuid(), 'manual_charge_id' => $charge->id,
                'customer_profile_id' => $customer->id, 'recipient_user_id' => $customer->user_id,
                'audience_type' => 'subject_customer', 'channel' => 'database', 'payload' => '{}',
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(NotificationPipeline::class)->capture('charge', $intentId);
            if ($group !== null) {
                app(LedgerTransactionProjectionService::class)->projectCharge($charge);
            }

            return $charge;
        }, attempts: 3);
    }

    private function authorize(User $actor, string $kind, Request $request): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless(app(AuthorizationService::class)->allows($actor, $kind === 'manual_fee' ? AdminPermission::FeesManage : AdminPermission::DeductionsManage)
            && app(FreshAuthenticationService::class)->isFresh($actor, $request), 403);

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
