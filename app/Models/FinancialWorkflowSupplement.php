<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property array<string, mixed> $facts
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['operation_reference', 'payload_hash', 'kind', 'customer_profile_id', 'thrift_plan_id', 'collection_batch_id', 'reversal_request_id', 'actor_user_id', 'facts', 'evidence', 'created_at'])]
class FinancialWorkflowSupplement extends Model
{
    public $timestamps = false;

    protected $hidden = ['evidence', 'payload_hash'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'evidence' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Financial supplements are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Financial supplements cannot be deleted.');
        });
    }
}
