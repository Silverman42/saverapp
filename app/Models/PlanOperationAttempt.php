<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $thrift_plan_id
 * @property array<string, mixed>|null $result_summary
 */
#[Fillable(['attempt_reference', 'user_id', 'business_id', 'operation_type', 'payload_fingerprint', 'status', 'thrift_plan_id', 'customer_profile_id', 'result_summary'])]
class PlanOperationAttempt extends Model
{
    protected function casts(): array
    {
        return ['result_summary' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }
}
