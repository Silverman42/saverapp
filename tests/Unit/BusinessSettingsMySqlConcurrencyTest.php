<?php

use App\Enums\AdminPermission;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\BusinessSettingsReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function configurationMysqlActor(): User
{
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::BusinessSettingsManage);

    return $actor;
}
function configurationMysqlRequest(): Request
{
    $request = Request::create('/admin/business-settings', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);

    return $request;
}
function configurationMysqlDraft(User $actor, string $name, ?string $at): array
{
    $settings = app(BusinessSettings::class);
    $settings->import();
    $draft = $settings->saveDraft($actor, ['display_name' => $name], BusinessProfile::current()->version, (string) Str::uuid());
    $preview = $settings->preview($actor, $draft['draft_id'], 1, $at);

    return [...$draft, 'preview_reference' => $preview['reference']];
}
function configurationMysqlTask(string $action, int $actorId, array $draft, string $operation, int $configurationId = 0): Closure
{
    return static function () use ($action, $actorId, $draft, $operation, $configurationId): int|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe configuration worker database.');
        }
        $settings = app(BusinessSettings::class);
        if ($action === 'activate') {
            return $settings->activate($configurationId) ? 'effective' : 'no_change';
        }
        $request = Request::create('/admin/business-settings', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        try {
            $actor = User::query()->findOrFail($actorId);
            if ($action === 'cancel') {
                $settings->cancel($actor, $configurationId, 'Cancel racing change', $operation, $request);

                return 'cancelled';
            }
            $result = $settings->publish($actor, $draft['draft_id'], 1, $draft['preview_reference'], 'Reviewed change', $operation, $request);

            return (int) $result['configuration_id'];
        } catch (ConflictHttpException) {
            return 'conflict';
        }
    };
}

test('mysql competing base publications retain one pending bundle without lost values', function () {
    $first = configurationMysqlActor();
    $second = configurationMysqlActor();
    $at = now()->addHour()->toIso8601String();
    $a = configurationMysqlDraft($first, 'First', $at);
    $b = configurationMysqlDraft($second, 'Second', $at);
    $results = Concurrency::driver('process')->run([
        configurationMysqlTask('publish', $first->id, $a, (string) Str::uuid()),
        configurationMysqlTask('publish', $second->id, $b, (string) Str::uuid()),
    ]);
    expect(collect($results)->filter(fn ($result) => is_int($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn ($result) => $result === 'conflict'))->toHaveCount(1);
    $this->assertDatabaseCount('business_configuration_versions', 2);
    expect(DB::table('business_configuration_work')->where('status', 'scheduled')->count())->toBe(1);
    expect(BusinessProfile::current()->display_name)->toBe('SaverApp');
});
test('mysql identical publication attempts resolve to one immutable result', function () {
    $actor = configurationMysqlActor();
    $draft = configurationMysqlDraft($actor, 'One result', now()->addHour()->toIso8601String());
    $operation = (string) Str::uuid();
    $results = Concurrency::driver('process')->run([
        configurationMysqlTask('publish', $actor->id, $draft, $operation),
        configurationMysqlTask('publish', $actor->id, $draft, $operation),
    ]);
    expect($results[0])->toBeInt()->toBe($results[1]);
    $this->assertDatabaseCount('business_configuration_versions', 2);
    expect(DB::table('business_configuration_events')->where('event_type', 'published')->count())->toBe(1);
});
test('mysql activation and cancellation serialize without an effective cancelled bundle', function () {
    $actor = configurationMysqlActor();
    $draft = configurationMysqlDraft($actor, 'Racing effect', null);
    $this->partialMock(BusinessSettingsReadiness::class, fn ($mock) => $mock->shouldReceive('acknowledge')->andReturn(false));
    $result = app(BusinessSettings::class)->publish($actor, $draft['draft_id'], 1, $draft['preview_reference'], 'Reviewed change', (string) Str::uuid(), configurationMysqlRequest());
    $this->app->forgetInstance(BusinessSettingsReadiness::class);
    $results = Concurrency::driver('process')->run([
        configurationMysqlTask('activate', 0, [], '', $result['configuration_id']),
        configurationMysqlTask('cancel', $actor->id, [], (string) Str::uuid(), $result['configuration_id']),
    ]);
    $state = DB::table('business_configuration_work')->where('configuration_id', $result['configuration_id'])->value('status');
    expect($state)->toBeIn(['effective', 'cancelled']);
    expect(DB::table('business_configuration_events')->where('event_type', 'effective')->count()
        + DB::table('business_configuration_events')->where('event_type', 'cancelled')->count())->toBe(1);
    expect(BusinessProfile::current()->version)->toBe($state === 'effective' ? 2 : 1);
});
