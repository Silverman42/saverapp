<?php

namespace App\Models;

use Database\Factories\AgentOffboardingCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_profile_id', 'status', 'is_open'])]
class AgentOffboardingCase extends Model
{
    /** @use HasFactory<AgentOffboardingCaseFactory> */
    use HasFactory;

    /** @return BelongsTo<AgentProfile, $this> */
    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }
}
