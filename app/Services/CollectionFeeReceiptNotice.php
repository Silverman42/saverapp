<?php

namespace App\Services;

use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class CollectionFeeReceiptNotice
{
    public function capture(CollectionReceipt $receipt, AuditEvent $receiptAudit): ?string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Fee receipt notice capture requires its original financial transaction.');
        }
        if ($receipt->fee_amount_kobo === 0) {
            return null;
        }
        $source = DB::table('collection_receipts')->where('id', $receipt->id)->firstOrFail();
        $audit = AuditEvent::query()->whereKey($receiptAudit->id)->firstOrFail();
        $context = app(CollectionFeeReceiptNotificationSource::class)->captureContext($source, $audit);
        $ciphertext = Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR));
        $assignment = DB::table('customer_assignments')->where('id', $context['assignment_id'])->firstOrFail();
        $agent = DB::table('agent_profiles')->where('id', $context['agent_profile_id'])->firstOrFail();
        $recipient = User::query()->whereKey($agent->user_id)->firstOrFail();
        if (! $assignment->is_current || ! app(AgentEligibilityService::class)->canReadAssignedCustomers($recipient)) {
            return $ciphertext;
        }
        DB::table('collection_notification_intents')->insertOrIgnore([
            'notification_id' => (string) Str::uuid(), 'collection_receipt_id' => $receipt->id,
            'recipient_user_id' => $recipient->id, 'customer_profile_id' => $receipt->customer_profile_id,
            'agent_profile_id' => $context['agent_profile_id'], 'assignment_id' => $context['assignment_id'],
            'audience_type' => 'current_agent', 'channel' => 'database', 'context_ciphertext' => $ciphertext,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (int) DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)
            ->where('recipient_user_id', $recipient->id)->where('channel', 'database')->sole()->id;
        app(NotificationPipeline::class)->capture('collection', $id, false);
        DB::afterCommit(static fn () => app(NotificationPipeline::class)->dispatchRecoverably(
            static fn () => DeliverCollectionNotificationIntent::dispatch($id)->afterCommit()));

        return $ciphertext;
    }
}
