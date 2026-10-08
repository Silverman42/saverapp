<?php

namespace App\Services;

use App\Models\FinancialArtifact;
use App\Support\RecoveryOwner;
use App\Support\RecoverySource;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class FinancialArtifactRecoveryOwner implements RecoveryOwner
{
    public function operation(): string
    {
        return 'derived';
    }

    public function snapshot(int $sourceId): RecoverySource
    {
        $artifact = FinancialArtifact::query()->findOrFail($sourceId);
        $state = match ($artifact->status) {
            'ready' => $this->verified($artifact) ? 'succeeded' : 'dead_letter',
            'cancelled', 'expired' => 'cancelled',
            'queued' => 'queued',
            default => 'dead_letter',
        };

        return new RecoverySource(1, AuditProjection::digest(['reference' => $artifact->artifact_reference, 'snapshot_hash' => $artifact->snapshot_hash, 'requester_user_id' => $artifact->requester_user_id]),
            'artifact:'.$artifact->artifact_reference, $state, 0, result: in_array($state, ['succeeded', 'cancelled'], true) ? 'artifact:'.$artifact->artifact_reference : null);
    }

    private function verified(FinancialArtifact $artifact): bool
    {
        if ($artifact->storage_path === null || ! Storage::disk()->exists($artifact->storage_path)) {
            return false;
        }

        return hash_equals($artifact->artifact_hash, hash('sha256', Crypt::decryptString(Storage::disk()->get($artifact->storage_path))));
    }

    public function execute(int $sourceId): void
    {
        app(FinancialArtifactService::class)->render($sourceId);
    }

    public function failed(int $sourceId, string $state, string $code, ?string $availableAt, bool $countAttempt = true): void
    {
        if ($state === 'dead_letter') {
            app(FinancialArtifactService::class)->recordFailure($sourceId);
        }
    }
}
