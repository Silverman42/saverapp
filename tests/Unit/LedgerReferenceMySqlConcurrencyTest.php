<?php

use App\Services\PublicIdGenerator;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function ledgerReferenceWorker(int $count): Closure
{
    return static function () use ($count): array {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe ledger reference race database.');
        }
        $references = [];
        for ($i = 0; $i < $count; $i++) {
            $references[] = app(PublicIdGenerator::class)->generate('ledger_transaction');
        }

        return $references;
    };
}

test('LED-AC-006 mysql concurrent transaction reference allocation stays globally unique', function (): void {
    Facade::clearResolvedInstances();
    app()->forgetInstance(ProcessFactory::class);

    $batches = Concurrency::driver('process')->run(array_fill(0, 4, ledgerReferenceWorker(50)));

    $all = array_merge(...$batches);
    expect($all)->toHaveCount(200)->and(array_unique($all))->toHaveCount(200);
});
