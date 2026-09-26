<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CanonicalAuditEvent extends Model
{
    protected $table = 'canonical_audit_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['ciphertext', 'input_hash'];

    protected function casts(): array
    {
        return ['content' => 'array', 'legacy_evidence' => 'boolean', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Audit evidence is append-only.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Audit evidence is append-only.');
        });
    }
}
