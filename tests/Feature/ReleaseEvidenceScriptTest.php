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
