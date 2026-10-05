<?php

namespace Database\Seeders;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditProjection;
use App\Services\FinancialReleaseEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records accepted owner evidence for every financial release capability so the
 * gated features become "Ready to enable" on a local machine. Never production evidence.
 */
class LocalFinancialReleaseEvidenceSeeder extends Seeder
{
    public function run(FinancialReleaseEvidenceService $evidence): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local financial release evidence may only be seeded in local or testing environments.');
        }
        if (blank(config('app.financial_release_revision'))) {
            throw new RuntimeException('Set FINANCIAL_RELEASE_REVISION in .env before seeding release evidence.');
        }
        $recorder = User::query()->orderBy('id')->first()
            ?? throw new RuntimeException('Create an admin user before seeding release evidence.');

        LedgerAccount::query()->where('mapping_status', '!=', 'mapped')->get()
            ->each(fn (LedgerAccount $account) => $account->update(['mapping_status' => 'mapped']));

        $dependencyHash = $evidence->dependencyHash();
        $validUntil = CarbonImmutable::now()->addYear()->utc()->toDateTimeString();

        foreach (FinancialReleaseEvidenceService::CAPABILITIES as $capability) {
            foreach (FinancialReleaseEvidenceService::ROLES as $role) {
                $version = (int) DB::table('financial_release_evidence')
                    ->where('capability', $capability)->where('owner_role', $role)->max('version') + 1;
                $data = ['capability' => $capability, 'owner_role' => $role, 'version' => $version, 'state' => 'accepted',
                    'dependency_hash' => $dependencyHash, 'valid_until' => $validUntil,
                    'evidence' => 'Local development evidence only; not a production release certification.'];

                DB::table('financial_release_evidence')->insert([
                    ...array_diff_key($data, ['evidence' => true]), 'evidence_hash' => AuditProjection::digest($data),
                    'evidence' => Crypt::encryptString($data['evidence']), 'recorded_by_user_id' => $recorder->id, 'created_at' => now(),
                ]);
            }
        }
    }
}
