<?php

namespace App\Services;

use App\Models\CashDisbursement;
use App\Models\CustomerProfile;
use App\Models\FeeRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialCashNotice
{
    public function queue(User $actor, CashDisbursement|FeeRefund $source, string $eventType, ?string $eventReference = null, ?int $amountKobo = null): void
    {
        $disbursement = $source instanceof CashDisbursement;
        $reference = $eventReference ?? ($disbursement ? $source->execution_reference : $source->refund_reference);
        $customerId = $source->customer_profile_id;
        if ($customerId === null) {
            if (! $source instanceof CashDisbursement || $source->kind !== 'earnings_draw') {
                throw new \LogicException('A business cash notice requires an identified earnings draw recipient.');
            }
            $recipientId = $source->recipient_user_id;
        } else {
            $recipientId = CustomerProfile::query()->whereKey($customerId)->sole()->user_id;
        }
        $eventId = DB::table('financial_cash_events')->insertGetId([
            'event_type' => $eventType, 'operation_reference' => $reference, 'customer_profile_id' => $customerId,
            'actor_user_id' => $actor->id, 'cash_disbursement_id' => $disbursement ? $source->id : null,
            'fee_refund_id' => $disbursement ? $source->fee_refund_id : $source->id, 'kind' => $disbursement ? $source->kind : 'fee_refund',
            'amount_kobo' => $amountKobo ?? $source->amount_kobo, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intentId = DB::table('financial_cash_notification_intents')->insertGetId([
            'notification_id' => (string) Str::uuid(), 'financial_cash_event_id' => $eventId, 'customer_profile_id' => $customerId,
            'recipient_user_id' => $recipientId, 'audience_type' => $customerId === null ? 'cash_executor' : 'subject_customer',
            'channel' => 'database', 'payload' => '{}', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(NotificationPipeline::class)->capture('financial_cash', $intentId);
    }
}
