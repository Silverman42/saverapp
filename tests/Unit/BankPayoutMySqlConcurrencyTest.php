<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BankPayoutService;
use App\Services\FakePayoutProvider;
use App\Services\ManualChargeService;
use App\Services\WithdrawalReversalOwner;
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
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../BankPayoutFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    config()->set('withdrawals.bank.fake_store', 'file');
});

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

test('mysql a callback racing the reconciler posts the transfer exactly once', function (): void {
    [, , , $withdrawal, $attempt] = bankMysqlSubmitted($this, '3');
    expect($attempt->status)->toBe('submitted');
    $signed = bankMysqlCallbackFor($attempt, 'succeeded', 'evt-race');

    $outcomes = bankMysqlRun([
        bankMysqlCallback($signed),
        bankMysqlReconcile($attempt->id),
    ]);

    expect($outcomes)->toContain('reconciled')->and(bankMysqlPostings())->toBe(1)
        ->and(BankPayoutAttempt::query()->sole()->ledger_posting_group_id)->not->toBeNull()
        ->and($withdrawal->fresh()->state)->toBe('posted')
        ->and(DB::table('withdrawal_reservations')->where('status', 'consumed')->count())->toBe(1)
        ->and(DB::table('withdrawal_events')->where('event_type', 'bank_posted')->count())->toBe(1);
});

test('mysql the same callback delivered twice at once is applied once', function (): void {
    [, , , $withdrawal, $attempt] = bankMysqlSubmitted($this, '3');
    $signed = bankMysqlCallbackFor($attempt, 'succeeded', 'evt-twice');

    $outcomes = bankMysqlRun([bankMysqlCallback($signed), bankMysqlCallback($signed)]);

    expect(array_values(array_diff($outcomes, ['duplicate'])))->toBe(['applied'])
        ->and(DB::table('bank_payout_callbacks')->count())->toBe(1)
        ->and(bankMysqlPostings())->toBe(1)->and($withdrawal->fresh()->state)->toBe('posted');
});

test('mysql two executors starting the same approved request create one attempt', function (): void {
    Queue::fake();
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '1');
    $version = $withdrawal->version;

    $outcomes = bankMysqlRun([
        bankMysqlStart($admin->id, $withdrawal->id, (string) Str::uuid(), $version),
        bankMysqlStart($admin->id, $withdrawal->id, (string) Str::uuid(), $version),
    ]);

    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'started'])->and(BankPayoutAttempt::query()->count())->toBe(1)
        ->and(bankMysqlPostings())->toBeLessThanOrEqual(1);
});

test('mysql a restriction racing the start either blocks it with a hold or lets the started transfer finish', function (): void {
    Queue::fake();
    [$admin, , $customer, , $withdrawal] = bankPayoutFixture($this, '1');

    $outcomes = bankMysqlRun([
        bankMysqlStart($admin->id, $withdrawal->id, (string) Str::uuid(), $withdrawal->version),
        bankMysqlRestrict($customer->id),
    ]);

    $withdrawal->refresh();
    $attempts = BankPayoutAttempt::query()->count();
    expect($outcomes[1])->toBe('restricted')->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted)
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBeIn(['live', 'consumed']);
    if ($attempts === 0) {
        expect($withdrawal->state)->toBe('approved')->and($withdrawal->held)->toBeTrue()->and($outcomes[0])->toBe('blocked');
    } else {
        expect($attempts)->toBe(1)->and($withdrawal->state)->toBeIn(['payout_processing', 'posted'])
            ->and(bankMysqlPostings())->toBe($withdrawal->state === 'posted' ? 1 : 0);
    }
});

test('mysql a deduction racing a started transfer never overdraws the Customer savings', function (): void {
    Queue::fake();
    [$admin, , $customer, $plan, $withdrawal] = bankPayoutFixture($this, '1');
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    LedgerAccount::query()->where('code', 'other_deduction_destination_ngn')->update(['mapping_status' => 'mapped']);
    $category = app(ManualChargeService::class)->publish($admin, ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'bank-race-deduction', 'kind' => 'deduction', 'purpose' => 'Authorized bank payout race fixture',
        'customer_description' => 'Approved deduction', 'amount_kobo' => 175000], bankMysqlRequest());

    $outcomes = bankMysqlRun([
        bankMysqlStart($admin->id, $withdrawal->id, (string) Str::uuid(), $withdrawal->version),
        bankMysqlAssess($admin->id, $customer->id, $plan->id, $category->id),
    ]);

    expect($outcomes)->toBe(['started', 'blocked'])->and(DB::table('manual_charges')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->whereIn('status', ['live', 'consumed'])->count())->toBe(1);
});

test('mysql a settlement racing a provider return leaves one consistent custody trail', function (): void {
    [, , , , $attempt] = bankMysqlSubmitted($this, '7');
    expect($attempt->fresh()->status)->toBe('succeeded');

    bankMysqlRun([
        bankMysqlCallback(bankMysqlCallbackFor($attempt, 'settled', 'evt-settle')),
        bankMysqlCallback(bankMysqlCallbackFor($attempt, 'returned', 'evt-return', ['return_reference' => 'RET-RACE'])),
    ]);

    $attempt->refresh();
    $return = BankPayoutReturn::query()->sole();
    $settlements = LedgerPostingGroup::query()->where('event_type', 'bank_payout_settlement')->count();
    expect($return->status)->toBe('posted');
    app(WithdrawalReversalOwner::class)->assertBankReturnPosting($attempt, $return);
    $settledFirst = $settlements === 1;
    expect(LedgerPostingGroup::query()->whereKey($return->return_posting_group_id)->value('metadata'))->toBeArray()
        ->and(LedgerPostingGroup::query()->whereKey($return->return_posting_group_id)->first()->metadata['settled'])->toBe($settledFirst)
        ->and($settlements)->toBeLessThanOrEqual(1);
});
