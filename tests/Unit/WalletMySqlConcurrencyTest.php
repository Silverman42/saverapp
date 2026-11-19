<?php

use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\WalletBalanceService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../BankPayoutMySqlFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WalletFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function walletMysqlWorker(Closure $work): Closure
{
    return bankMysqlWorker(static function () use ($work): string {
        config()->set(['wallet.enabled' => true, 'wallet.certified' => true, 'wallet.collection_enabled' => true,
            'withdrawals.offline_enabled' => true, 'collections.enabled' => true]);
        try {
            $work();

            return 'committed';
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        }
    });
}

/** @param array<string, mixed> $payload */
function walletMysqlPayoutWorker(int $agentId, int $customerId, array $payload): Closure
{
    return walletMysqlWorker(static fn () => app(WithdrawalService::class)->submit(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $payload));
}

/** @param array<string, mixed> $payload */
function walletMysqlContributionWorker(int $agentId, int $customerId, array $payload): Closure
{
    return walletMysqlWorker(static fn () => app(CollectionService::class)->record(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $payload));
}

/**
 * A two-day ₦2,000 plan paid for day one in cash, with ₦1,500 moved to the wallet.
 *
 * @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: ThriftPlan, 4: string}
 */
function walletMysqlFixture(): array
{
    Queue::fake();
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2);
    enableWallet(collections: true);
    $cash = [...collectionPayload($customer, $assignment, $plan, $today, '2000.00'), 'method' => 'cash'];
    $cash['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $cash)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $cash);
    transferToWallet($agent, $customer->fresh(), $plan->fresh(), 'partial', '1500.00');

    return [$agent, $customer->fresh(), $assignment->fresh(), $plan->fresh(), $today];
}

/** @return array<string, mixed> */
function walletMysqlContribution(User $agent, CustomerProfile $customer, $assignment, $plan, string $today, string $amount): array
{
    $payload = [...collectionPayload($customer, $assignment, $plan, $today, $amount), 'method' => 'wallet'];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];

    return $payload;
}

/** @return array<string, mixed> */
function walletMysqlPayout(User $agent, CustomerProfile $customer, string $amount): array
{
    $instruction = ['source' => 'wallet', 'type' => 'wallet_payout', 'gross_ngn' => $amount, 'method' => 'offline',
        'destination_reference' => 'customer:'.$customer->id, 'reason' => 'Race payout', 'internal_notes' => ''];
    $quote = app(WithdrawalService::class)->preview($agent, $customer, $instruction);

    return [...$instruction, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'quote_expires_at' => $quote['quote_expires_at'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true];
}

test('WAL-AC-014 mysql a wallet payout and a wallet contribution cannot both overdraw the wallet', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletMysqlFixture();
    $payout = walletMysqlPayout($agent, $customer, '1000.00');
    $contribution = walletMysqlContribution($agent, $customer, $assignment, $plan, $today, '1000.00');
    $agentId = $agent->id;
    $customerId = $customer->id;

    $outcomes = bankMysqlRun([
        walletMysqlPayoutWorker($agentId, $customerId, $payout),
        walletMysqlContributionWorker($agentId, $customerId, $contribution),
    ]);

    sort($outcomes);
    $position = app(WalletBalanceService::class)->position($customer->fresh());
    expect($outcomes)->toBe(['blocked', 'committed'])
        ->and($position['available_kobo'])->toBe(50000)
        ->and($position['available_kobo'])->toBeGreaterThanOrEqual(0);
});

test('WAL-AC-014 mysql two wallet contributions cannot both spend the same wallet money', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletMysqlFixture();
    $first = walletMysqlContribution($agent, $customer, $assignment, $plan, $today, '1000.00');
    $second = [...$first, 'attempt_reference' => (string) Str::uuid()];
    $second['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $second)['preview_fingerprint'];
    $agentId = $agent->id;
    $customerId = $customer->id;

    $outcomes = bankMysqlRun([
        walletMysqlContributionWorker($agentId, $customerId, $first),
        walletMysqlContributionWorker($agentId, $customerId, $second),
    ]);

    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'committed'])
        ->and(app(WalletBalanceService::class)->position($customer->fresh())['liability_kobo'])->toBe(50000)
        ->and(DB::table('collection_receipts')->where('method', 'wallet')->count())->toBe(1);
});
