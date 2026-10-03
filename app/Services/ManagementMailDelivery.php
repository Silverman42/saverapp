<?php

namespace App\Services;

use App\Jobs\DeliverAgentLifecycleNotificationIntent;
use App\Jobs\DeliverAgentStatusNotificationIntent;
use App\Jobs\DeliverChargeNotificationIntent;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Jobs\DeliverCustomerHandoverNotice;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Jobs\DeliverFinancialCashNotificationIntent;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Jobs\DeliverProfileNotificationIntent;
use App\Models\AuditEvent;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;
use ValueError;

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
                $applicationSending = in_array($family, ['fee_application', 'charge', 'financial_cash'], true) && $owner?->status === 'sending';
                $planSending = $family === 'plan' && $owner?->status === 'sending';
                if ($planSending && ! DB::table('plan_notification_intents')->where('id', $dispatch->owner_id)
                    ->where('attempted_at', '<=', now()->subMinutes(5))->exists()) {
                    return;
                }
                if ($applicationSending && ! $this->isInterruptedMail($family, $dispatch->owner_id)) {
                    return;
                }
                if ($owner === null || ($owner->status !== 'pending' && ! $applicationSending && ! $planSending)) {
                    DB::table('management_mail_dispatches')->where('id', $dispatch->id)->update(['status' => 'complete', 'updated_at' => now()]);

                    return;
                }
                try {
                    match ($family) {
                        'collection' => DeliverCollectionNotificationIntent::dispatch($dispatch->owner_id),
                        'plan' => DeliverPlanNotificationIntent::dispatch($dispatch->owner_id),
                        'profile' => DeliverProfileNotificationIntent::dispatch($dispatch->owner_id),
                        'customer_status' => DeliverCustomerStatusNotificationIntent::dispatch($dispatch->owner_id),
                        'agent_status' => DeliverAgentStatusNotificationIntent::dispatch($dispatch->owner_id),
                        'agent_lifecycle' => DeliverAgentLifecycleNotificationIntent::dispatch($dispatch->owner_id),
                        'handover' => DeliverCustomerHandoverNotice::dispatch($dispatch->owner_id),
                        'fee_application' => DeliverFeeApplicationNotificationIntent::dispatch($dispatch->owner_id),
                        'charge' => DeliverChargeNotificationIntent::dispatch($dispatch->owner_id),
                        'financial_cash' => DeliverFinancialCashNotificationIntent::dispatch($dispatch->owner_id),
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
        $claim = function () use ($family, $ownerId, $table, $authorize): bool {
            $owner = DB::table($table)->where('id', $ownerId)->lockForUpdate()->first();
            if ($owner === null || $owner->channel !== 'mail' || $owner->status !== 'pending') {
                return false;
            }
            if (! $authorize()) {
                if ($family === 'fee_application') {
                    $this->recordFeeApplicationOutcome($owner, 'suppressed');
                } elseif ($family === 'charge') {
                    $this->recordChargeOutcome($owner, 'suppressed');
                } elseif ($family === 'financial_cash') {
                    $this->recordFinancialCashOutcome($owner, 'suppressed');
                }
                DB::table($table)->where('id', $ownerId)->update(['status' => 'suppressed', 'suppressed_at' => now(), 'updated_at' => now()]);

                return false;
            }
            DB::table('management_delivery_attempts')->insert(['owner_family' => $family, 'owner_id' => $ownerId,
                'outcome' => 'acceptance_unknown', 'transport_kind' => in_array(config('mail.default'), ['log', 'array'], true) ? 'local' : 'external', 'started_at' => now()]);
            DB::table($table)->where('id', $ownerId)->update(['status' => 'sending', 'updated_at' => now()]);

            return true;
        };
        $claimed = in_array($family, ['fee_application', 'charge', 'financial_cash'], true) ? app(PlatformGuard::class)->transaction('external', $claim, attempts: 3)
            : DB::transaction($claim, attempts: 3);
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
            if ($family === 'fee_application') {
                $deliveryAudit = $this->recordFeeApplicationOutcome($owner, $outcome);
            } elseif ($family === 'charge') {
                $deliveryAudit = $this->recordChargeOutcome($owner, $outcome);
            } elseif ($family === 'financial_cash') {
                $deliveryAudit = $this->recordFinancialCashOutcome($owner, $outcome);
            } else {
                $deliveryAudit = AuditEvent::record($subjectFamily.'.delivery_state_recorded', $subjectFamily, $subjectId, null,
                    ['notification_reference' => $owner->notification_id, 'channel' => 'mail', 'outcome' => $outcome], null,
                    ['executor' => self::class, 'operation_id' => 'mail-state:'.$family.':'.$ownerId]);
            }
            DB::table('management_delivery_attempts')->where('owner_family', $family)->where('owner_id', $ownerId)
                ->update(['outcome' => $outcome, 'finished_at' => now()]);
            DB::table($table)->where('id', $ownerId)->update(['status' => match ($outcome) {
                'transport_accepted' => 'delivered', 'suppressed' => 'suppressed', default => 'unknown',
            }, 'delivered_at' => $outcome === 'transport_accepted' ? now() : null,
                'suppressed_at' => $outcome === 'suppressed' ? now() : null, 'updated_at' => now()]);
            if ($outcome === 'acceptance_unknown') {
                app(FeeOperationalIssues::class)->delivery($family, $ownerId, 'mail', 'acceptance_unknown',
                    'acceptance_unknown', $deliveryAudit->id);
            }
        }, attempts: 3);
    }

    public function resolveInterrupted(string $family, int $ownerId): void
    {
        if (! in_array($family, ['fee_application', 'charge', 'financial_cash'], true)) {
            throw new InvalidArgumentException('Unsupported interrupted mail owner.');
        }
        $table = NotificationCatalogue::OWNERS[$family]['table'];
        DB::transaction(function () use ($family, $ownerId, $table): void {
            $owner = DB::table($table)->where('id', $ownerId)->lockForUpdate()->first();
            if ($owner === null || $owner->channel !== 'mail' || $owner->status !== 'sending') {
                return;
            }
            if (! $this->isInterruptedMail($family, $ownerId)) {
                return;
            }
            if ($family === 'fee_application') {
                $deliveryAudit = $this->recordFeeApplicationOutcome($owner, 'acceptance_unknown');
            } elseif ($family === 'charge') {
                $deliveryAudit = $this->recordChargeOutcome($owner, 'acceptance_unknown');
            } else {
                $deliveryAudit = $this->recordFinancialCashOutcome($owner, 'acceptance_unknown');
            }
            DB::table($table)->where('id', $ownerId)->update(['status' => 'unknown', 'updated_at' => now()]);
            DB::table('management_delivery_attempts')->where('owner_family', $family)->where('owner_id', $ownerId)
                ->update(['outcome' => 'acceptance_unknown', 'finished_at' => now()]);
            app(FeeOperationalIssues::class)->delivery($family, $ownerId, 'mail', 'acceptance_unknown',
                'acceptance_unknown', $deliveryAudit->id);
        }, attempts: 3);
    }

    private function isInterruptedMail(string $family, int $ownerId): bool
    {
        return DB::table('management_delivery_attempts')->where('owner_family', $family)->where('owner_id', $ownerId)
            ->where('outcome', 'acceptance_unknown')->where('started_at', '<=', now()->subMinutes(5))->exists();
    }

    private function recordFinancialCashOutcome(stdClass $owner, string $outcome): AuditEvent
    {
        try {
            $descriptor = app(NotificationCatalogue::class)->describe('financial_cash', $owner);
            $audit = DB::table('audit_events')->where('id', $descriptor['audit_event_id'])->first();
        } catch (DecryptException|InvalidArgumentException|ConflictHttpException|JsonException|ValueError) {
            $descriptor = null;
            $audit = null;
        }
        $event = DB::table('financial_cash_events')->where('id', $owner->financial_cash_event_id)->first();
        $family = $event?->event_type === 'refund_authorized' ? 'fee' : 'cash_disbursement';

        return AuditEvent::record($family.'.delivery_state_recorded', $audit->target_type ?? $family, $audit?->target_id, $audit?->target_reference,
            ['notification_reference' => $owner->notification_id, 'channel' => 'mail', 'outcome' => $outcome,
                'source_event_id' => $audit === null ? null : $descriptor['source_id'],
                'customer_profile_id' => $audit === null ? null : $descriptor['customer_profile_id'],
                'source_audit_event_id' => $audit?->id], null,
            ['executor' => self::class, 'operation_id' => 'mail-state:financial_cash:'.$owner->id,
                'outcome' => match ($outcome) {
                    'transport_accepted' => 'Succeeded', 'suppressed' => 'Denied', default => 'Failed'
                }]);
    }

    private function recordChargeOutcome(stdClass $owner, string $outcome): AuditEvent
    {
        try {
            $descriptor = app(NotificationCatalogue::class)->describe('charge', $owner);
        } catch (DecryptException|InvalidArgumentException|ConflictHttpException|JsonException|ValueError) {
            $descriptor = null;
        }

        return AuditEvent::record('charge.delivery_state_recorded', ManualCharge::class, $descriptor['source_id'] ?? null,
            $descriptor['reference'] ?? null, ['notification_reference' => $owner->notification_id, 'channel' => 'mail', 'outcome' => $outcome,
                'manual_charge_id' => $descriptor['source_id'] ?? null, 'customer_profile_id' => $descriptor['customer_profile_id'] ?? null,
                'source_audit_event_id' => $descriptor['audit_event_id'] ?? null], null,
            ['executor' => self::class, 'operation_id' => 'mail-state:charge:'.$owner->id,
                'outcome' => match ($outcome) {
                    'transport_accepted' => 'Succeeded', 'suppressed' => 'Denied', default => 'Failed'
                }]);
    }

    private function recordFeeApplicationOutcome(stdClass $owner, string $outcome): AuditEvent
    {
        try {
            $descriptor = app(NotificationCatalogue::class)->describe('fee_application', $owner);
            $source = DB::table('ledger_posting_groups')->where('posting_reference', $descriptor['reference'])->first();
        } catch (InvalidArgumentException|ConflictHttpException|JsonException|ValueError) {
            $descriptor = null;
            $source = null;
        }

        return AuditEvent::record('fee_application.delivery_state_recorded', LedgerPostingGroup::class, $source?->id, $source?->posting_reference,
            ['notification_reference' => $owner->notification_id, 'channel' => 'mail', 'outcome' => $outcome,
                'application_id' => $source === null ? null : $descriptor['source_id'],
                'customer_profile_id' => $source?->customer_profile_id, 'source_audit_event_id' => $source === null ? null : $descriptor['audit_event_id']], null,
            ['executor' => self::class, 'operation_id' => 'mail-state:fee_application:'.$owner->id,
                'outcome' => match ($outcome) {
                    'transport_accepted' => 'Succeeded', 'suppressed' => 'Denied', default => 'Failed'
                }]);
    }
}
