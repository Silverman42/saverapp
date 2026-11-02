<?php

namespace App\Models;

use App\Enums\FinancialNoteType;
use Carbon\CarbonImmutable;
use Database\Factories\FinancialNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Admin memo about business revenue or expenses. It is never posted to the ledger.
 *
 * @property int $id
 * @property string $reference
 * @property FinancialNoteType $type
 * @property string $title
 * @property int $amount_kobo
 * @property CarbonImmutable $note_date
 * @property string|null $category
 * @property string $body
 * @property string $status
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 */
#[Fillable(['business_profile_id', 'reference', 'type', 'title', 'amount_kobo', 'note_date', 'category', 'body', 'status',
    'archived_at', 'created_by_user_id', 'updated_by_user_id', 'archived_by_user_id', 'version'])]
class FinancialNote extends Model
{
    /** @use HasFactory<FinancialNoteFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => FinancialNoteType::class,
            'amount_kobo' => 'integer',
            'note_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
