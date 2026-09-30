<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
use App\Models\FinancialPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialPeriodService
{
    public function __construct(
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private CollectionReadService $collections,
    ) {}

    public function assertOpen(string $receivedDate, string $timezone, bool $lock = false): FinancialPeriod
    {
        $month = substr($receivedDate, 0, 7).'-01';
        $query = FinancialPeriod::query()->where('business_profile_id', BusinessProfile::current()->id)
            ->where('timezone', $timezone)->whereDate('month', $month);
        $period = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($period === null || $period->status !== 'open') {
            throw new ConflictHttpException('The cash receipt month is not open for posting.');
        }

        return $period;
    }

    public function transition(User $actor, string $month, string $action, ?int $version, string $reason, Request $request): FinancialPeriod
    {
        if (! in_array($action, ['open', 'close', 'reopen'], true)) {
            throw new ConflictHttpException('Choose a valid financial month transition.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ConflictHttpException('A reason is required for this transition.');
        }

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $month, $action, $version, $reason, $request): FinancialPeriod {
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorization->allows($currentActor, AdminPermission::FinancialPeriodsManage)) {
                throw new AuthorizationException;
            }
            if (! $this->freshAuthentication->isFresh($currentActor, $request)) {
                throw new AuthorizationException('Fresh authentication is required.');
            }

            $business = BusinessProfile::query()->lockForUpdate()->sole();
            $firstDay = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', $business->timezone);
            if ($firstDay === null || $firstDay->format('Y-m') !== $month) {
                throw new ConflictHttpException('Choose a valid calendar month.');
            }
            $period = FinancialPeriod::query()->where('business_profile_id', $business->id)
                ->where('timezone', $business->timezone)->whereDate('month', $firstDay->toDateString())
                ->lockForUpdate()->first();
            if ($action === 'open') {
                if ($period !== null) {
                    throw new ConflictHttpException('This month already has a period record.');
                }
                $period = FinancialPeriod::create([
                    'business_profile_id' => $business->id, 'timezone' => $business->timezone,
                    'month' => $firstDay->toDateString(), 'status' => 'open', 'version' => 1,
                    'changed_by_user_id' => $currentActor->id,
                ]);
                $before = null;
            } else {
                if ($period === null || $period->version !== $version
                    || ($action === 'close' && $period->status !== 'open')
                    || ($action === 'reopen' && $period->status !== 'closed')) {
                    throw new ConflictHttpException('The financial month changed. Refresh before continuing.');
                }
                if ($action === 'close') {
                    if (! CarbonImmutable::now($business->timezone)->startOfMonth()->greaterThan($firstDay)) {
                        throw new ConflictHttpException('The month must end before it can close.');
                    }
                    $nextMonth = $firstDay->addMonth()->toDateString();
                    foreach (['cash_executions', 'cash_disbursements'] as $table) {
                        if (DB::table($table)->where('created_at', '>=', $firstDay->utc())->where('created_at', '<', $firstDay->addMonth()->utc())
                            ->whereIn('status', ['processing', 'outcome_unknown'])->lockForUpdate()->exists()) {
                            throw new ConflictHttpException('Resolve every cash handoff outcome before closing its month.');
                        }
                    }
                    if (DB::table('cash_recoveries')->where('created_at', '>=', $firstDay->utc())->where('created_at', '<', $firstDay->addMonth()->utc())
                        ->whereIn('status', ['awaiting_customer', 'confirmed'])->lockForUpdate()->exists()) {
                        throw new ConflictHttpException('Resolve cash return evidence and compensation before closing its month.');
                    }
                    $batches = CollectionBatch::query()->where('timezone', $period->timezone)
                        ->where('received_date', '>=', $firstDay->toDateString())
                        ->where('received_date', '<', $nextMonth)
                        ->orderBy('id')->lockForUpdate()->get(['id', 'status']);
                    if ($batches->contains(static fn (CollectionBatch $batch): bool => $batch->status !== 'reconciled')
                        || $this->collections->hasPendingCorrectionForBatches(DB::table('collection_batches')
                            ->where('timezone', $period->timezone)
                            ->where('received_date', '>=', $firstDay->toDateString())
                            ->where('received_date', '<', $nextMonth))
                        || DB::table('collection_exceptions as exceptions')
                            ->join('collection_batches as batches', 'batches.id', '=', 'exceptions.collection_batch_id')
                            ->where('batches.timezone', $period->timezone)
                            ->where('batches.received_date', '>=', $firstDay->toDateString())
                            ->where('batches.received_date', '<', $nextMonth)
                            ->where('exceptions.status', '!=', 'resolved')
                            ->lockForUpdate()->first(['exceptions.id']) !== null) {
                        throw new ConflictHttpException('Reconcile every cash batch and exception before closing this month.');
                    }
                }
                $before = $period->status;
                $period->status = $action === 'close' ? 'closed' : 'open';
                $period->version++;
                $period->changed_by_user_id = $currentActor->id;
                $period->save();
            }

            AuditEvent::record('financial_period.'.$action, FinancialPeriod::class, $period->id,
                $period->timezone.':'.$month, ['from' => $before, 'to' => $period->status,
                    'version' => $period->version, 'reason' => $reason], $currentActor,
                context: ['executor' => self::class, 'required_permission' => AdminPermission::FinancialPeriodsManage->value]);

            return $period;
        }, attempts: 3);
    }
}
