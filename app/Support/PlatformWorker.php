<?php

namespace App\Support;

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
     * Hold the platform lock from before reservation through acknowledgement.
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
