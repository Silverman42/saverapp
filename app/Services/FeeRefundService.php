<?php

namespace App\Services;

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\AdminPermission;
use App\Enums\FeeLedgerPostingType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FeeRefundService
{
    public function authorizeRefund(User $actor, FeeObligation $obligation, string $reference, string $kind, int $amountKobo, string $reason, Request $request): FeeRefund
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $obligation, $reference, $kind, $amountKobo, $reason): FeeRefund {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::FeesManage), 403);
            $customer = CustomerProfile::query()->whereKey($obligation->customer_profile_id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($customer->id)->exists(), 404);
            $obligation = FeeObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $obligation->id, $kind, $amountKobo, trim($reason)], JSON_THROW_ON_ERROR));
            $existing = FeeRefund::query()->where('refund_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This refund identity belongs to different entitlement instructions.');
                }

                return $existing;
            }
            abort_unless(config('fees.refunds_enabled', false), 503, 'Refund acceptance is not yet certified.');
            app(BusinessSettings::class)->ensureFeature('fee_refunds');
            if (! in_array($kind, ['savings', 'external'], true) || $amountKobo < 1 || $amountKobo > 999999999999
                || trim($reason) === '' || $customer->operational_status->value === 'archived') {
                throw new ConflictHttpException('A positive retained fee and reviewed refund purpose are required.');
            }
            $retained = app(FeeConcessionPosition::class)->retainedSources($obligation, forUpdate: true)[$kind.'_kobo'];
            $position = app(FinancialCashPosition::class)->read(forUpdate: true);
            if ($amountKobo > $retained || $amountKobo > $position['undrawn_earnings_kobo'] || $amountKobo > $position['free_cash_kobo']) {
                throw new ConflictHttpException('The refund exceeds retained earnings or verified free cash.');
            }
            $business = BusinessProfile::current();
            app(FinancialPeriodService::class)->assertOpen(now($business->timezone)->toDateString(), $business->timezone, true);
            $type = $kind === 'external' ? FeeLedgerPostingType::ExternalRefundEntitlement : FeeLedgerPostingType::SavingsFeeRefund;
            $destination = $kind === 'external' ? LedgerAccountCode::RefundPayable : LedgerAccountCode::CustomerSavingsLiability;
            $group = app(LedgerPostingService::class)->postFee(new LedgerPostingCommand($type, 'refund-'.$reference,
                $kind === 'external' ? 'external_refund_entitlement' : 'fee_refund', $reference, 'NGN', $actor, $customer->id, now()->toImmutable(), [
                    new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Debit, $amountKobo, $customer->id, null, $obligation->id),
                    new LedgerPostingLine($destination, LedgerEntrySide::Credit, $amountKobo, $customer->id, null, $obligation->id),
                ], 'Approved fee concession: '.$obligation->customer_description));
            $refund = FeeRefund::create(['refund_reference' => $reference, 'payload_hash' => $hash, 'customer_profile_id' => $customer->id,
                'fee_obligation_id' => $obligation->id, 'actor_user_id' => $actor->id, 'amount_kobo' => $amountKobo, 'kind' => $kind,
                'reason' => trim($reason), 'ledger_posting_group_id' => $group->id]);
            AuditEvent::record('fee.refund_authorized', FeeRefund::class, $refund->id, $reference,
                ['customer_profile_id' => $customer->id, 'amount_kobo' => $amountKobo, 'source_type' => $kind, 'source_id' => $reference, 'reason' => trim($reason)], $actor,
                context: ['executor' => self::class, 'correlation_reference' => $reference, 'required_permission' => 'fees.manage']);

            app(FinancialCashNotice::class)->queue($actor, $refund, 'refund_authorized');
            app(LedgerTransactionProjectionService::class)->projectRefund($refund);

            return $refund;
        }, attempts: 3);
    }
}
