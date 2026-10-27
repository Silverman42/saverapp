<?php

use App\Services\FinancialReleaseEvidenceService;
use Illuminate\Support\Facades\Process;

test('release evidence script template covers every financial release capability and role', function () {
    $result = Process::path(base_path())->run(['php', 'release-evidence.php', '--template']);

    expect($result->successful())->toBeTrue();

    $template = json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR);

    expect(array_keys($template['capabilities']))->toBe(FinancialReleaseEvidenceService::CAPABILITIES)
        ->and(array_keys($template['defaults']))->toBe(FinancialReleaseEvidenceService::ROLES);
});

test('cloud record script reads the final result line of streamed cloud cli output', function (int $exitCode, bool $successful, string $message) {
    $directory = sys_get_temp_dir().'/release-evidence-'.uniqid();
    mkdir($directory);
    $notes = json_encode(['defaults' => array_fill_keys(FinancialReleaseEvidenceService::ROLES, 'Owner attestation.')]);
    file_put_contents("{$directory}/notes.json", $notes);
    $result = json_encode(['output' => "{$message}\n", 'exitCode' => $exitCode]);
    file_put_contents("{$directory}/cloud", "#!/usr/bin/env bash\necho '{\"status\":\"command.running\"}'\necho '{\"status\":\"command.success\"}'\necho '{$result}'\n");
    chmod("{$directory}/cloud", 0700);

    Process::path(base_path())->run(['php', 'release-evidence.php', "--notes={$directory}/notes.json", '--hash='.str_repeat('a', 64), "--out={$directory}/out", '--cloud', '--environment=env-test', '--actor=1'])->throw();
    $record = Process::path(base_path())->env(['CLOUD' => "{$directory}/cloud"])->run(["{$directory}/out/cloud-record.sh"]);

    expect($record->successful())->toBe($successful)
        ->and($record->output())->toContain($message);

    Process::run(['rm', '-rf', $directory]);
})->with([
    'recorded' => [0, true, 'Owner evidence recorded: 1.'],
    'rejected' => [1, false, 'Evidence must identify a current release'],
]);
