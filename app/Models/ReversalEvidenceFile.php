<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable, scanned evidence file attached to a reversal request. Supplements add files; nothing replaces or removes one.
 *
 * @property int $id
 * @property int $reversal_request_id
 * @property string $storage_path
 * @property string $checksum
 * @property string $mime_type
 * @property int $byte_size
 * @property string $scanner_version
 * @property CarbonImmutable $scanned_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['reversal_request_id', 'storage_path', 'checksum', 'mime_type', 'byte_size', 'scanner_version', 'scanned_at', 'uploaded_by_user_id', 'created_at'])]
class ReversalEvidenceFile extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $hidden = ['storage_path', 'checksum'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'scanned_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Reversal evidence files are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Reversal evidence files must be retained with the request.');
        });
    }

    /** @return BelongsTo<ReversalRequest, $this> */
    public function reversalRequest(): BelongsTo
    {
        return $this->belongsTo(ReversalRequest::class);
    }
}
