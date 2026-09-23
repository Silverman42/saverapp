<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array{title: string, message: string, fields?: array<int, string>, url?: string} $payload
 */
#[Fillable(['notification_id', 'profile_change_history_id', 'recipient_user_id', 'audience_type', 'channel', 'purpose', 'subject_type', 'subject_id', 'payload', 'status', 'delivered_at', 'suppressed_at'])]
class ProfileNotificationIntent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'suppressed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProfileChangeHistory, $this> */
    public function history(): BelongsTo
    {
        return $this->belongsTo(ProfileChangeHistory::class, 'profile_change_history_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
