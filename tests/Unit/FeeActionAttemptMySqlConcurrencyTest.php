<?php

use App\Enums\AdminPermission;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FeeActionAttemptService;
use App\Services\FeeObligationService;
use App\Services\FeeSavingsApplicationService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function durableFeeMysqlRequest(): Request
{
    $request = Request::create('/admin/fees/attempts', 'POST');
    $session = new Store('durable-fee-worker', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);

    return $request;
}

/** @return array<string, array<int, object>> */
function durableFeeMysqlRows(): array
{
    $rows = [];
    foreach (['fee_obligations', 'fee_obligation_entries', 'fee_obligation_events', 'fee_savings_applications',
        'ledger_posting_groups', 'ledger_entries', 'fee_obligation_notification_intents', 'fee_application_notification_intents',
        'collection_receipts', 'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

/** @param array<string, mixed> $body */
function durableFeeMysqlWorker(int $actorId, int $feeId, array $body, string $side, string $barrierPath): Closure
{
    return static function () use ($actorId, $feeId, $body, $side, $barrierPath): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe durable fee attempt worker database.');
        }
        config()->set('fees.savings_applications_enabled', true);
        $request = Request::create('/admin/fees/attempts', 'POST');
        $session = new Store('durable-fee-worker-'.$side, new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        $actor = User::findOrFail($actorId);
        file_put_contents($barrierPath.'/'.$side, 'ready');
        $other = $side === 'commit' ? 'cancel' : 'commit';
        $deadline = microtime(true) + 10;
        while (! is_file($barrierPath.'/'.$other)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Durable fee workers did not meet at the barrier.');
            }
            usleep(10000);
        }
        if ($side === 'cancel') {
            return app(FeeActionAttemptService::class)->cancel($actor, $feeId, $body, $request)['status'];
        }
        $payload = $body['payload'];
        try {
            if ($body['operation'] === 'apply_savings') {
                app(FeeSavingsApplicationService::class)->apply($actor, $feeId, $payload, $request);
            } elseif ($body['operation'] === 'waive') {
                app(FeeObligationService::class)->waive($actor, $feeId, 10000, $payload['reason'],
                    $payload['customer_description'], $payload['attempt_reference'], $request);
            } else {
                app(FeeObligationService::class)->correctUnsettledAssessment($actor, $feeId, 10000,
                    FeeAssessmentCorrectionDirection::Reduce, $payload['reason'], $payload['customer_description'],
                    $payload['attempt_reference'], $request);
            }

            return 'recorded';
        } catch (ConflictHttpException) {
            return 'denied';
        }
    };
}

test('mysql simultaneous commit and cancellation retain one authoritative fee outcome', function (string $operation): void {
    config()->set('fees.savings_applications_enabled', true);
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'reason' => 'PRIVATE parallel original review.',
        'customer_description' => 'Your registration agreement was reviewed.', 'confirmed' => true];
    if ($operation === 'apply_savings') {
        $payload['plan_id'] = $plan->plan_id;
        $quote = app(FeeSavingsApplicationService::class)->preview($admin, $fee->id, $payload);
        $payload['preview_fingerprint'] = $quote['preview_fingerprint'];
        $payload['quote_expires_at'] = $quote['quote_expires_at'];
    } else {
        $payload['amount_ngn'] = '100.00';
        if ($operation === 'correct') {
            $payload['direction'] = 'reduce';
        }
    }
    $body = ['operation' => $operation, 'attempt_reference' => $payload['attempt_reference'], 'payload' => $payload];
    $owner = app(FeeActionAttemptService::class);
    expect($owner->prepare($admin, $fee->id, $body, durableFeeMysqlRequest())['status'])->toBe('prepared');
    $before = durableFeeMysqlRows();
    $barrierPath = sys_get_temp_dir().'/saverapp-fee-attempt-race-'.Str::uuid();
    mkdir($barrierPath, 0700);
    try {
        $tasks = [
            durableFeeMysqlWorker($admin->id, $fee->id, $body, 'commit', $barrierPath),
            durableFeeMysqlWorker($admin->id, $fee->id, $body, 'cancel', $barrierPath),
        ];
        $results = Concurrency::driver('process')->run($tasks);
        $afterFirstRace = durableFeeMysqlRows();
        unlink($barrierPath.'/commit');
        unlink($barrierPath.'/cancel');
        expect(Concurrency::driver('process')->run($tasks))->toBe($results);
        expect(durableFeeMysqlRows())->toEqual($afterFirstRace);
    } finally {
        foreach (['commit', 'cancel'] as $side) {
            if (is_file($barrierPath.'/'.$side)) {
                unlink($barrierPath.'/'.$side);
            }
        }
        rmdir($barrierPath);
    }

    $outcome = $owner->status($admin, $fee->id, $payload['attempt_reference']);
    $this->assertDatabaseCount('fee_action_attempts', 1);
    if ($outcome['status'] === 'cancelled') {
        expect($results)->toBe(['denied', 'cancelled']);
        expect(durableFeeMysqlRows())->toEqual($before);
        expect($fee->fresh()->outstandingAmountKobo())->toBe(20000);
        expect(DB::table('audit_events')->where('event_type', 'fee.action.cancelled')->count())->toBe(1);
    } else {
        expect($outcome['status'])->toBe('recorded');
        expect($results)->toBe(['recorded', 'recorded']);
        expect($fee->fresh()->outstandingAmountKobo())->toBe($operation === 'apply_savings' ? 0 : 10000);
        expect(DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->count())->toBe(1);
        expect(DB::table('audit_events')->where('event_type', 'fee.action.cancelled')->count())->toBe(0);
        if ($operation === 'apply_savings') {
            $group = DB::table('ledger_posting_groups')->where('source_id', $payload['attempt_reference'])->sole();
            expect($outcome['posting_reference'])->toBe($group->posting_reference);
            $lines = DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->get();
            expect($lines->pluck('side')->sort()->values()->all())->toBe(['credit', 'debit']);
            expect($lines->pluck('amount_kobo')->all())->toBe([20000, 20000]);
            $this->assertDatabaseCount('fee_savings_applications', 1);
            $this->assertDatabaseCount('fee_application_notification_intents', 3);
        } else {
            $entry = DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->sole();
            expect($outcome['entry_id'])->toBe($entry->id);
            $this->assertDatabaseCount('fee_obligation_events', 1);
            $this->assertDatabaseCount('fee_obligation_notification_intents', 3);
        }
    }
    $retained = durableFeeMysqlRows();
    foreach (['ledger_posting_groups', 'ledger_entries', 'fee_obligation_entries', 'collection_receipts',
        'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations', 'thrift_plans',
        'plan_terms_revisions', 'contribution_slots'] as $table) {
        expect(DB::table($table)->whereIn('id', array_column($before[$table], 'id'))->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    expect($owner->cancel($admin, $fee->id, $body, durableFeeMysqlRequest()))->toBe($outcome);
    expect($owner->prepare($admin, $fee->id, $body, durableFeeMysqlRequest()))->toBe($outcome);
    expect(durableFeeMysqlRows())->toEqual($retained);
})->with(['waive', 'correct', 'apply_savings']);
