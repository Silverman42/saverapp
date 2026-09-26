<?php

namespace App\Services;

use App\Enums\PlatformMode;
use App\Support\PlatformBlocked;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

class PlatformGuard
{
    public function __construct(private PlatformState $state, private PlatformCatalogue $catalogue) {}

    public function assertAllowed(string $operation, bool $lock = false): void
    {
        $this->catalogue->definition($operation);
        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('A platform lock requires an owning transaction.');
        }
        $mode = PlatformMode::from($this->state->current($lock)->mode);
        if ($mode === PlatformMode::Unavailable
            || ($mode === PlatformMode::ReadOnly && $operation !== 'read')
            || ($mode === PlatformMode::FinancialFreeze && $operation === 'financial')) {
            throw new PlatformBlocked('platform_operation_paused', $mode);
        }
    }

    /** @template TResult
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function transaction(string $operation, callable $callback, int $attempts = 1): mixed
    {
        $this->assertAllowed($operation);

        return DB::transaction(function () use ($operation, $callback): mixed {
            $this->assertAllowed($operation, true);

            return $callback();
        }, $attempts);
    }

    /**
     * Preserve the owner's durable failure evidence before propagating a job error.
     * External work is never retried by this transaction wrapper.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function work(string $operation, callable $callback): mixed
    {
        $failure = null;
        $result = $this->transaction($operation, function () use ($callback, &$failure): mixed {
            try {
                return $callback();
            } catch (QueryException|PlatformBlocked $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $failure = $exception;

                return null;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }
}
