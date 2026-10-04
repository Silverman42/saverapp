<?php

use App\Enums\CustomerStatus;
use App\Models\BankPayoutAttempt;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BankPayoutService;
use App\Services\FakePayoutProvider;
use App\Services\ManualChargeService;
use App\Services\ReportReadService;
use App\Services\WithdrawalService;
use App\Support\PayoutProvider;
use Illuminate\Http\Request;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/BankPayoutFixtures.php';

/** Run a worker body in a separate process against the isolated database with the sandbox bank rail enabled. */
function bankMysqlWorker(Closure $work): Closure
{
    return static function () use ($work): mixed {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe bank payout worker database.');
        }
        config()->set(['withdrawals.bank_enabled' => true, 'withdrawals.bank_certified' => true, 'withdrawals.bank.provider' => 'fake',
            'withdrawals.bank.callback_secret' => 'test-callback-secret', 'withdrawals.bank.fake_store' => 'file']);

        return $work();
    };
}

/**
 * The evidence fixture fakes and forbids real processes; workers are real processes.
 *
 * @param  list<Closure>  $workers
 * @return array<int, mixed>
 */
function bankMysqlRun(array $workers): array
{
    Facade::clearResolvedInstances();
    app()->forgetInstance(ProcessFactory::class);

    return Concurrency::driver('process')->run($workers);
}

function bankMysqlRequest(): Request
{
    $request = Request::create('/withdrawals/bank', 'POST');
    $session = new Store('bank-worker', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);

    return $request;
}

function bankMysqlReconcile(int $attemptId): Closure
{
    return bankMysqlWorker(static function () use ($attemptId): string {
        app(BankPayoutService::class)->reconcileAttempt($attemptId);

        return 'reconciled';
    });
}

function bankMysqlRestrict(int $customerId): Closure
{
    return bankMysqlWorker(static function () use ($customerId): string {
        DB::transaction(function () use ($customerId): void {
            $locked = CustomerProfile::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['operational_status' => CustomerStatus::Restricted])->save();
            app(WithdrawalService::class)->applyCustomerStatus($locked, CustomerStatus::Restricted);
        });

        return 'restricted';
    });
}

function bankMysqlAssess(int $adminId, int $customerId, int $planId, int $categoryId): Closure
{
    return bankMysqlWorker(static function () use ($adminId, $customerId, $planId, $categoryId): string {
        config()->set('fees.manual_charges_enabled', true);
        $request = Request::create('/admin/charges', 'POST');
        $session = new Store('bank-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        $profile = CustomerProfile::findOrFail($customerId);
        $thriftPlan = ThriftPlan::findOrFail($planId);
        try {
            app(ManualChargeService::class)->assess(User::findOrFail($adminId), $profile, $thriftPlan, ChargeCategoryVersion::findOrFail($categoryId),
                (string) Str::uuid(), $profile->version, $thriftPlan->version, 'Confirmed deduction race instruction.', $request);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    });
}

function bankMysqlStart(int $adminId, int $withdrawalId, string $reference, int $version): Closure
{
    return bankMysqlWorker(static function () use ($adminId, $withdrawalId, $reference, $version): string {
        try {
            $request = Request::create('/withdrawals/bank', 'POST');
            $session = new Store('bank-worker', new ArraySessionHandler(600));
            $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
                'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
            $request->setLaravelSession($session);
            app(BankPayoutService::class)->start(User::findOrFail($adminId), WithdrawalRequest::findOrFail($withdrawalId), $reference, $version, $request);

            return 'started';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    });
}

/** @param array{body: string, headers: array<string, string>} $signed */
function bankMysqlCallback(array $signed): Closure
{
    return bankMysqlWorker(static function () use ($signed): string {
        return app(BankPayoutService::class)->ingestCallback(app(PayoutProvider::class)->verifyCallback($signed['body'], $signed['headers']));
    });
}

function bankMysqlCallbackFor(BankPayoutAttempt $attempt, string $type, string $eventId, array $extra = []): array
{
    return FakePayoutProvider::signedCallback(callbackEvent($attempt, $type, $eventId, $extra));
}

/** Start from an approved request without running the queued dispatch, then send the transfer once in the parent. */
function bankMysqlSubmitted(object $test, string $digit): array
{
    Queue::fake();
    [$admin, $agent, $customer, $plan, $withdrawal] = bankPayoutFixture($test, $digit);
    $reference = startBankPayout($test, $admin, $withdrawal);
    $attempt = bankAttempt($reference);
    app(BankPayoutService::class)->dispatch($attempt->id);

    return [$admin, $customer, $plan, $withdrawal, $attempt->fresh()];
}

function bankMysqlPostings(): int
{
    return LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->count();
}

/** Read the withdrawals report's posted-payout section in a worker process. */
function bankMysqlPostedPayoutsReader(int $adminId): Closure
{
    return bankMysqlWorker(static function () use ($adminId): array {
        $section = app(ReportReadService::class)->read(User::findOrFail($adminId), 'withdrawals', [
            'page_size' => 25, 'group' => '', 'from' => now('Africa/Lagos')->startOfMonth()->toDateString(), 'to' => now('Africa/Lagos')->toDateString(),
        ])['sections']['posted_payouts'];

        return ['status' => $section['status'], 'total' => $section['total'] ?? null,
            'paid' => collect($section['metrics'])->firstWhere('code', 'amount_paid')['value'] ?? null];
    });
}
