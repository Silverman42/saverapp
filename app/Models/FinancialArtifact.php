<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $manifest
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $issued_at
 */
#[Fillable(['supersedes_artifact_id', 'artifact_reference', 'operation_reference', 'requester_user_id', 'customer_profile_id', 'kind', 'format', 'status', 'render_generation', 'payload_hash', 'snapshot_hash', 'snapshot', 'manifest', 'storage_path', 'artifact_hash', 'failure_code', 'expires_at', 'held', 'issued_at'])]
class FinancialArtifact extends Model
{
    protected $hidden = ['snapshot', 'manifest', 'storage_path', 'payload_hash'];

    protected function casts(): array
    {
        return ['render_generation' => 'integer', 'snapshot' => 'encrypted:array', 'manifest' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'held' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $artifact): void {
            if ($artifact->isDirty(['supersedes_artifact_id', 'artifact_reference', 'operation_reference', 'requester_user_id', 'customer_profile_id', 'kind', 'format', 'payload_hash', 'snapshot_hash', 'snapshot', 'manifest', 'expires_at'])
                || ($artifact->getOriginal('issued_at') !== null && $artifact->isDirty(['artifact_hash', 'issued_at']))) {
                throw new RuntimeException('Financial document identity, snapshot and issuance evidence are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Financial document manifests cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'artifact_reference';
    }
}
