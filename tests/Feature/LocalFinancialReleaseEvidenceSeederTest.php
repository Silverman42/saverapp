<?php

use App\Models\User;
use App\Services\FinancialReleaseEvidenceService;
use Database\Seeders\LocalFinancialReleaseEvidenceSeeder;

test('local evidence makes every financial release capability ready to enable', function () {
    config(['app.financial_release_revision' => 'local-test']);
    User::factory()->create();

    $this->seed(LocalFinancialReleaseEvidenceSeeder::class);

    foreach (app(FinancialReleaseEvidenceService::class)->checks() as $check) {
        expect($check['state'])->toBe('Ready to enable');
    }
});

test('local evidence requires a financial release revision', function () {
    config(['app.financial_release_revision' => null]);
    User::factory()->create();

    $this->seed(LocalFinancialReleaseEvidenceSeeder::class);
})->throws(RuntimeException::class, 'FINANCIAL_RELEASE_REVISION');
