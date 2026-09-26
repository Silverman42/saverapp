<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditProtectedPayload extends Model
{
    protected $table = 'audit_protected_payloads';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['ciphertext', 'input_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
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
