<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Services\ManualChargeService;
use App\Services\WithdrawalReversalOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../BankPayoutMySqlFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    config()->set('withdrawals.bank.fake_store', 'file');
});

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
