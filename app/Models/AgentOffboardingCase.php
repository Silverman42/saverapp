<?php

namespace App\Models;

use Database\Factories\AgentOffboardingCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 */
#[Fillable(['agent_profile_id', 'status', 'is_open'])]
class AgentOffboardingCase extends Model
{
    /** @use HasFactory<AgentOffboardingCaseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'reason' => 'encrypted', 'started_at' => 'datetime',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<AgentProfile, $this> */
    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }
}
