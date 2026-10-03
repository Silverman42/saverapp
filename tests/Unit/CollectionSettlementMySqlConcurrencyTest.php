<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\CollectionBatch;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionSettlementService;
use App\Services\FinancialCashPosition;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../CollectionSettlementFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function mysqlSettlementClaim(int $actorId, int $batchId, array $payload, string $evidenceRoot, string $filePath): Closure
{
    return static function () use ($actorId, $batchId, $payload, $evidenceRoot, $filePath): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe settlement race database.');
        }
        config()->set(['filesystems.disks.collection_evidence.root' => $evidenceRoot,
            'collections.evidence_scanner_binary' => '/opt/clamav/bin/clamdscan', 'collections.evidence_scanner_version' => 'test-signatures-1']);
        Process::fake(['*clamdscan*' => Process::result()]);
        $request = Request::create('/collection-settlements', 'POST');
        $session = new Store('settlement-test', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        app()->instance('request', $request);
        try {
            app(CollectionSettlementService::class)->record(User::query()->findOrFail($actorId), CollectionBatch::query()->findOrFail($batchId),
                $payload, [new UploadedFile($filePath, 'bank-proof.png', 'image/png', test: true)]);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql competing settlements transfer each clearing batch into bank custody once', function (): void {
    [, , , $admin, $batch, $payload] = settlementFixture($this);
    $file = $payload['files'][0];
    unset($payload['files']);
    $otherReviewer = User::factory()->admin()->withTwoFactor()->create();
    $otherReviewer->givePermissionTo(AdminPermission::ReconciliationManage);
    Process::preventStrayProcesses(false);
    $root = Storage::disk('collection_evidence')->path('');
    $outcomes = Concurrency::driver('process')->run([
        mysqlSettlementClaim($admin->id, $batch->id, $payload, $root, $file->getPathname()),
        mysqlSettlementClaim($otherReviewer->id, $batch->id, [...$payload, 'settlement_reference' => (string) Str::uuid()], $root, $file->getPathname()),
    ]);
    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'posted']);
    $this->assertDatabaseCount('collection_settlements', 1);
    $this->assertDatabaseCount('collection_settlement_files', 1);
    $this->assertDatabaseCount('collection_bank_reference_claims', 1);
    expect(LedgerPostingGroup::query()->where('event_type', 'clearing_settlement')->count())->toBe(1);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::PaymentClearing))->toBe(0)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessBank))->toBe(250000);
});
