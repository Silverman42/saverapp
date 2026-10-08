<?php

use App\Enums\AdminPermission;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FinancialReleaseEvidenceService;
use Illuminate\Support\Facades\DB;

function readinessAdmin(): User
{
    $user = User::factory()->admin()->withTwoFactor()->create();
    $user->givePermissionTo(AdminPermission::BusinessSettingsManage);

    return $user;
}

/** @param array<string, mixed> $notes */
function readinessNotesFile(array $notes): string
{
    $path = tempnam(sys_get_temp_dir(), 'notes');
    file_put_contents($path, json_encode($notes, JSON_THROW_ON_ERROR));

    return $path;
}

function completeRoleNotes(): array
{
    return ['defaults' => array_combine(FinancialReleaseEvidenceService::ROLES, array_map(
        fn (string $role): string => "Signed off by the {$role} owner.", FinancialReleaseEvidenceService::ROLES,
    ))];
}

beforeEach(function () {
    config(['app.financial_release_revision' => 'readiness-test']);
});

test('status mode reports readiness without changing anything', function () {
    readinessAdmin();
    $mapped = LedgerAccount::query()->where('mapping_status', 'mapped')->count();

    $this->artisan('financial:readiness')
        ->expectsOutputToContain('Release revision: readiness-test')
        ->assertSuccessful();

    expect(DB::table('financial_release_evidence')->count())->toBe(0)
        ->and(LedgerAccount::query()->where('mapping_status', 'mapped')->count())->toBe($mapped);
});

test('local mode makes every capability ready and is idempotent', function () {
    config(['collections.noncash_enabled' => true, 'collections.evidence_scanner_fake' => true]);
    readinessAdmin();

    $this->artisan('financial:readiness', ['--local' => true])->assertSuccessful();

    foreach (app(FinancialReleaseEvidenceService::class)->checks() as $check) {
        expect($check['state'])->toBe('Ready to enable');
    }
    $recorded = DB::table('financial_release_evidence')->count();

    $this->artisan('financial:readiness', ['--local' => true])
        ->expectsOutputToContain('Recorded 0 owner evidence record(s)')
        ->assertSuccessful();
    expect(DB::table('financial_release_evidence')->count())->toBe($recorded);
});

test('local mode is refused outside local and testing environments', function () {
    readinessAdmin();
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        $this->artisan('financial:readiness', ['--local' => true])->assertFailed();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }

    expect(DB::table('financial_release_evidence')->count())->toBe(0);
});

test('owner notes map the ledger and record current evidence for the selected capability', function () {
    $actor = readinessAdmin();
    $notes = 'base64:'.base64_encode(json_encode(completeRoleNotes(), JSON_THROW_ON_ERROR));

    $this->artisan('financial:readiness', ['--notes' => $notes, '--map-ledger' => true, '--actor' => $actor->id, '--capability' => ['plan_creation'], '--force' => true])
        ->assertSuccessful();

    expect(app(FinancialReleaseEvidenceService::class)->check('plan_creation')['state'])->toBe('Ready to enable')
        ->and(DB::table('financial_release_evidence')->where('capability', '!=', 'plan_creation')->count())->toBe(0)
        ->and(LedgerAccount::query()->whereIn('code', FinancialReleaseEvidenceService::NONCASH_LEDGER_ACCOUNTS)->where('mapping_status', 'mapped')->count())->toBe(0);
});

test('incomplete notes, unmapped ledgers, unauthorized actors and declined confirmation change nothing', function (array $options, ?string $answer) {
    $actor = readinessAdmin();
    $options = [...$options, '--capability' => ['plan_creation']];
    $options['--actor'] ??= $actor->id;
    $mapped = LedgerAccount::query()->where('mapping_status', 'mapped')->count();

    $command = $this->artisan('financial:readiness', $options);
    if ($answer !== null) {
        $command->expectsConfirmation('These owner attestations are permanent. Continue?', $answer);
    }
    $command->assertFailed();

    expect(DB::table('financial_release_evidence')->count())->toBe(0)
        ->and(LedgerAccount::query()->where('mapping_status', 'mapped')->count())->toBe($mapped);
})->with([
    'missing role notes' => fn () => [['--notes' => readinessNotesFile(['defaults' => ['acceptance' => 'Only one role.']]), '--map-ledger' => true, '--force' => true], null],
    'notes before mapping' => fn () => [['--notes' => readinessNotesFile(completeRoleNotes()), '--force' => true], null],
    'actor without permission' => fn () => [['--notes' => readinessNotesFile(completeRoleNotes()), '--map-ledger' => true, '--actor' => User::factory()->admin()->create()->id, '--force' => true], null],
    'declined confirmation' => fn () => [['--notes' => readinessNotesFile(completeRoleNotes()), '--map-ledger' => true], 'no'],
]);
