<?php

namespace App\Jobs;

use App\Services\BankPayoutService;
use App\Support\PlatformBlocked;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one already-committed, prepared bank transfer. The job owns the external boundary: it holds no database
 * transaction while the provider is called. It never retries by itself; the reconciler owns retries with the same idempotency key.
 */
class DispatchBankPayout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $attemptId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bank-payout-'.$this->attemptId))->expireAfter(300)];
    }

    public function handle(BankPayoutService $payouts): void
    {
        try {
            $payouts->dispatch($this->attemptId);
        } catch (PlatformBlocked $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
