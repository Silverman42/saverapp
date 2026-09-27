<?php

namespace App\Services;

use App\Jobs\DeliverAgentLifecycleNotificationIntent;
use App\Jobs\DeliverAgentStatusNotificationIntent;
use App\Jobs\DeliverCustomerHandoverNotice;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Jobs\DeliverProfileNotificationIntent;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class ManagementMailDelivery
{
    public function register(string $family, int $ownerId): void
    {
        DB::table('management_mail_dispatches')->insertOrIgnore([
            'owner_family' => $family, 'owner_id' => $ownerId,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function drain(int $limit): void
    {
        DB::table('management_mail_dispatches')->where('status', 'pending')->orderBy('id')->limit($limit)
            ->get()->each(function (object $dispatch): void {
                $family = $dispatch->owner_family;
                $table = NotificationCatalogue::OWNERS[$family]['table'];
                $owner = DB::table($table)->where('id', $dispatch->owner_id)->first(['status']);
                if ($owner === null || $owner->status !== 'pending') {
                    DB::table('management_mail_dispatches')->where('id', $dispatch->id)->update(['status' => 'complete', 'updated_at' => now()]);

                    return;
                }
                try {
                    match ($family) {
                        'profile' => DeliverProfileNotificationIntent::dispatch($dispatch->owner_id),
                        'customer_status' => DeliverCustomerStatusNotificationIntent::dispatch($dispatch->owner_id),
                        'agent_status' => DeliverAgentStatusNotificationIntent::dispatch($dispatch->owner_id),
                        'agent_lifecycle' => DeliverAgentLifecycleNotificationIntent::dispatch($dispatch->owner_id),
                        'handover' => DeliverCustomerHandoverNotice::dispatch($dispatch->owner_id),
                    };
                    DB::table('management_mail_dispatches')->where('id', $dispatch->id)->update([
                        'dispatch_count' => DB::raw('dispatch_count + 1'), 'last_dispatch_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (Throwable) {
                    // Retain registered dispatch work for the next scheduled drain.
                }
            });
    }

    /** @param callable(): bool $authorize
     * @param  callable(): void  $send
     */
    public function deliver(string $family, int $ownerId, callable $authorize, callable $send): void
    {
        $table = NotificationCatalogue::OWNERS[$family]['table'];
        $claimed = DB::transaction(function () use ($family, $ownerId, $table, $authorize): bool {
            $owner = DB::table($table)->where('id', $ownerId)->lockForUpdate()->first();
            if ($owner === null || $owner->channel !== 'mail' || $owner->status !== 'pending') {
                return false;
            }
            if (! $authorize()) {
                DB::table($table)->where('id', $ownerId)->update(['status' => 'suppressed', 'suppressed_at' => now(), 'updated_at' => now()]);

                return false;
            }
            DB::table('management_delivery_attempts')->insert(['owner_family' => $family, 'owner_id' => $ownerId,
                'outcome' => 'acceptance_unknown', 'transport_kind' => in_array(config('mail.default'), ['log', 'array'], true) ? 'local' : 'external', 'started_at' => now()]);
            DB::table($table)->where('id', $ownerId)->update(['status' => 'sending', 'updated_at' => now()]);

            return true;
        }, attempts: 3);
        if (! $claimed) {
            return;
        }
        $outcome = 'acceptance_unknown';
        try {
            if (! $authorize()) {
                $outcome = 'suppressed';
            } else {
                $send();
                $outcome = 'transport_accepted';
            }
        } catch (Throwable) {
            // An external result is unverified; retain the claim instead of resending.
        }
        DB::transaction(function () use ($family, $ownerId, $table, $outcome): void {
            $owner = DB::table($table)->where('id', $ownerId)->lockForUpdate()->firstOrFail();
            $subjectFamily = ($owner->customer_profile_id ?? null) !== null || ($owner->subject_type ?? null) === 'customer' ? 'customer' : 'agent';
            $subjectId = $owner->customer_profile_id ?? $owner->agent_profile_id ?? $owner->subject_id ?? null;
            AuditEvent::record($subjectFamily.'.delivery_state_recorded', $subjectFamily, $subjectId, null,
                ['notification_reference' => $owner->notification_id, 'channel' => 'mail', 'outcome' => $outcome], null,
                ['executor' => self::class, 'operation_id' => 'mail-state:'.$family.':'.$ownerId]);
            DB::table('management_delivery_attempts')->where('owner_family', $family)->where('owner_id', $ownerId)
                ->update(['outcome' => $outcome, 'finished_at' => now()]);
            DB::table($table)->where('id', $ownerId)->update(['status' => match ($outcome) {
                'transport_accepted' => 'delivered', 'suppressed' => 'suppressed', default => 'unknown',
            }, 'delivered_at' => $outcome === 'transport_accepted' ? now() : null,
                'suppressed_at' => $outcome === 'suppressed' ? now() : null, 'updated_at' => now()]);
        }, attempts: 3);
    }
}
