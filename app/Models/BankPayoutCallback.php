<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property int $id
 * @property string $provider_key
 * @property string $event_id
 * @property string $payload_hash
 * @property string $disposition
 * @property CarbonImmutable $received_at
 */
#[Fillable(['provider_key', 'event_id', 'event_type', 'idempotency_key', 'payload_hash', 'signature_timestamp', 'bank_payout_attempt_id', 'disposition', 'sanitized_payload', 'received_at'])]
class BankPayoutCallback extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $hidden = ['sanitized_payload'];

    protected function casts(): array
    {
        return ['sanitized_payload' => 'encrypted:array', 'signature_timestamp' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Provider callbacks are append-only.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Provider callbacks cannot be deleted.');
        });
    }
}
