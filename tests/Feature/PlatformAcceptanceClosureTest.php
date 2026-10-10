<?php

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\PlatformGuard;
use App\Services\PlatformIntegrity;
use App\Support\PlatformBlocked;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function opsClosureArtifact(array $overrides = []): int
{
    $customer = CustomerProfile::factory()->create();

    return DB::table('financial_artifacts')->insertGetId([
        'artifact_reference' => (string) Str::uuid(), 'operation_reference' => (string) Str::uuid(),
        'requester_user_id' => User::factory()->admin()->create()->id, 'customer_profile_id' => $customer->id,
        'kind' => 'statement', 'format' => 'pdf', 'status' => 'ready', 'payload_hash' => str_repeat('a', 64),
        'snapshot_hash' => str_repeat('b', 64), 'snapshot' => '{}', 'manifest' => '{}', 'storage_path' => 'artifacts/statement.pdf',
        'artifact_hash' => str_repeat('c', 64), 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ]);
}

function opsClosureWork(array $overrides = []): int
{
    return DB::table('platform_recovery_work')->insertGetId([
        'owner' => 'audit_projection', 'source_id' => random_int(1, 1000000), 'source_version' => 1, 'payload_hash' => str_repeat('d', 64),
        'operation_key' => (string) Str::uuid(), 'correlation_reference' => (string) Str::uuid(), 'state' => 'queued',
        'attempts' => 0, 'cycle_attempts' => 0, 'max_attempts' => 3, 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ]);
}

test('OPS-AC-019: valid issued statements and bounded recovery work pass the new invariants', function (): void {
    $first = opsClosureArtifact();
    opsClosureArtifact(['supersedes_artifact_id' => $first, 'customer_profile_id' => DB::table('financial_artifacts')->where('id', $first)->value('customer_profile_id')]);
    opsClosureArtifact(['status' => 'queued', 'storage_path' => null, 'artifact_hash' => null, 'issued_at' => null]);
    opsClosureWork(['state' => 'running', 'lease_owner' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute(), 'cycle_attempts' => 3]);
    opsClosureWork(['state' => 'dead_letter', 'cycle_attempts' => 4]);

    $run = app(PlatformIntegrity::class)->verify('ops-service');

    expect($run['results']['statements']['status'])->toBe('passed')
        ->and($run['results']['recovery_attempts']['status'])->toBe('passed')
        ->and($run['status'])->toBe('passed');
});

test('OPS-AC-019: a corrupted statement or recovery job invariant blocks financial writes until a newer pass', function (string $check, string $domain, Closure $corrupt, Closure $repair): void {
    $ids = $corrupt();
    $integrity = app(PlatformIntegrity::class);

    $run = $integrity->verify('ops-service');

    expect($run['results'][$check]['status'])->toBe('failed')
        ->and($run['failed_domains'])->toBe([$domain]);
    expect(fn () => app(PlatformGuard::class)->assertAllowed('financial'))->toThrow(PlatformBlocked::class);
    app(PlatformGuard::class)->assertAllowed('read');

    $repair($ids);
    expect($integrity->verify('ops-service')['status'])->toBe('passed')
        ->and($integrity->blockedDomains())->toBe([]);
})->with([
    'ready statement without file hash' => ['statements', 'statements', fn () => opsClosureArtifact(['artifact_hash' => null]),
        fn (int $id) => DB::table('financial_artifacts')->where('id', $id)->update(['artifact_hash' => str_repeat('c', 64)])],
    'ready statement without issue time' => ['statements', 'statements', fn () => opsClosureArtifact(['issued_at' => null]),
        fn (int $id) => DB::table('financial_artifacts')->where('id', $id)->update(['issued_at' => now()])],
    'truncated snapshot hash' => ['statements', 'statements', fn () => opsClosureArtifact(['snapshot_hash' => 'short']),
        fn (int $id) => DB::table('financial_artifacts')->where('id', $id)->update(['snapshot_hash' => str_repeat('b', 64)])],
    'correction for another Customer' => ['statements', 'statements', fn () => opsClosureArtifact(['supersedes_artifact_id' => opsClosureArtifact()]),
        fn (int $id) => DB::table('financial_artifacts')->where('id', $id)->update(['customer_profile_id' => DB::table('financial_artifacts')
            ->where('id', DB::table('financial_artifacts')->where('id', $id)->value('supersedes_artifact_id'))->value('customer_profile_id')])],
    'retry budget overrun' => ['recovery_attempts', 'work', fn () => opsClosureWork(['cycle_attempts' => 4]),
        fn (int $id) => DB::table('platform_recovery_work')->where('id', $id)->update(['state' => 'dead_letter'])],
    'running without lease expiry' => ['recovery_attempts', 'work', fn () => opsClosureWork(['state' => 'running', 'lease_owner' => (string) Str::uuid()]),
        fn (int $id) => DB::table('platform_recovery_work')->where('id', $id)->update(['lease_expires_at' => now()->addMinute()])],
    'attempt for missing work' => ['recovery_attempts', 'work', fn () => DB::table('platform_recovery_attempts')->insertGetId(['work_id' => 999999,
        'lease_token' => 1, 'attempt_number' => 1, 'phase' => 'claim', 'outcome' => 'claimed', 'created_at' => now()]),
        fn (int $id) => DB::table('platform_recovery_work')->insert(['id' => 999999, 'owner' => 'audit_projection', 'source_id' => 999999, 'source_version' => 1,
            'payload_hash' => str_repeat('d', 64), 'operation_key' => (string) Str::uuid(), 'correlation_reference' => (string) Str::uuid(),
            'state' => 'completed', 'created_at' => now(), 'updated_at' => now()])],
]);
