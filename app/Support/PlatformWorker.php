<?php

namespace App\Support;

use App\Jobs\MaterializeNotificationIntent;
use App\Jobs\ProjectAuditEvent;
use App\Services\PlatformCatalogue;
use App\Services\PlatformDiagnostics;
use App\Services\PlatformGuard;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlatformWorker extends Worker
{
    private bool $ownsPlatformTransaction = false;

    /**
     * Local recovery commits reservation before acquiring its durable lease.
     * Other jobs retain the platform lock through acknowledgement.
     * Paused workers never reserve a job or consume its attempt allowance.
     */
    protected function getNextJob(mixed $connection, mixed $queue): ?Job
    {
        app(PlatformDiagnostics::class)->heartbeat('worker');
        DB::beginTransaction();
        $this->ownsPlatformTransaction = true;
        try {
            app(PlatformGuard::class)->assertAllowed('external', true);
            $job = parent::getNextJob($connection, $queue);
            if ($job !== null && in_array($job->payload()['displayName'] ?? null, [ProjectAuditEvent::class, MaterializeNotificationIntent::class, ...array_keys(PlatformCatalogue::LOCAL_INTENT_JOBS)], true)) {
                DB::commit();
                $this->ownsPlatformTransaction = false;
            }
            if ($job === null) {
                DB::rollBack();
                $this->ownsPlatformTransaction = false;
            }

            return $job;
        } catch (PlatformBlocked) {
            DB::rollBack();
            $this->ownsPlatformTransaction = false;

            return null;
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->ownsPlatformTransaction = false;
            throw $exception;
        }
    }

    public function daemon(mixed $connectionName, mixed $queue, WorkerOptions $options): int
    {
        $this->validateWorker($connectionName, $options);

        return parent::daemon($connectionName, $queue, $options);
    }

    public function runNextJob(mixed $connectionName, mixed $queue, WorkerOptions $options): void
    {
        $this->validateWorker($connectionName, $options);
        parent::runNextJob($connectionName, $queue, $options);
    }

    private function validateWorker(string $connectionName, WorkerOptions $options): void
    {
        $connection = config('queue.connections.'.$connectionName);
        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'database'
            || (($connection['connection'] ?? null) !== null && $connection['connection'] !== config('database.default'))
            || (int) ($connection['retry_after'] ?? 0) <= max(30, $options->timeout) + 5) {
            throw new \LogicException('Recovery workers require the primary database queue and timeout safely below retry_after.');
        }
    }

    protected function runJob(mixed $job, mixed $connectionName, WorkerOptions $options): void
    {
        try {
            parent::runJob($job, $connectionName, $options);
            if ($this->ownsPlatformTransaction) {
                DB::commit();
            }
        } catch (Throwable $exception) {
            if ($this->ownsPlatformTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        } finally {
            $this->ownsPlatformTransaction = false;
        }
    }
}
