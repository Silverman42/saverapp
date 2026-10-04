<?php

use App\Models\CustomerProfile;
use App\Services\PlatformIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function mysqlPromotionTask(int $version): Closure
{
    return static function () use ($version): int {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe promotion worker database');
        }

        return Artisan::call('platform:promote-projection', ['projection' => 'audit', '--expected-version' => $version,
            '--operator' => 'ops-service', '--reason' => 'Index rebuild', '--incident' => 'INC-300']);
    };
}

test('manual statements cannot rewrite reservations fee history or integrity evidence', function () {
    $customer = CustomerProfile::factory()->create();
    $reservation = DB::table('withdrawal_reservations')->insertGetId(['customer_profile_id' => $customer->id, 'owner_reference' => 'mysql-boundary',
        'gross_amount_kobo' => 5000, 'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    app(PlatformIntegrity::class)->verify('mysql.acceptance');

    expect(fn () => DB::table('withdrawal_reservations')->where('id', $reservation)->update(['gross_amount_kobo' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('withdrawal_reservations')->where('id', $reservation)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('platform_integrity_runs')->update(['status' => 'failed']))->toThrow(QueryException::class);
    DB::table('withdrawal_reservations')->where('id', $reservation)->update(['status' => 'released', 'version' => 2]);
    expect(DB::table('withdrawal_reservations')->where('id', $reservation)->value('gross_amount_kobo'))->toBe(5000);
});

test('competing projection promotions from one expected version promote exactly once', function () {
    $version = (int) DB::table('audit_projection_state')->value('active_version');
    $task = mysqlPromotionTask($version);

    $results = Concurrency::driver('process')->run([$task, $task]);

    sort($results);
    expect($results)->toBe([0, 1])
        ->and((int) DB::table('audit_projection_state')->value('active_version'))->toBe($version + 1);
});
