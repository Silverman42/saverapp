<?php

namespace App\Models;

use App\Enums\ThriftPlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $plan_id
 * @property int $customer_profile_id
 * @property int $created_by_user_id
 * @property int|null $predecessor_plan_id
 * @property int|null $open_customer_profile_id
 * @property ThriftPlanStatus $status
 * @property int $current_terms_revision
 * @property int $version
 * @property Carbon|null $activity_started_at
 */
#[Fillable([
    'plan_id',
    'customer_profile_id',
    'created_by_user_id',
    'predecessor_plan_id',
    'open_customer_profile_id',
    'status',
    'current_terms_revision',
    'version',
    'activity_started_at',
])]
class ThriftPlan extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ThriftPlanStatus::class,
            'current_terms_revision' => 'integer',
            'version' => 'integer',
            'activity_started_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'plan_id';
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'predecessor_plan_id');
    }

    /** @return HasOne<ThriftPlan, $this> */
    public function successor(): HasOne
    {
        return $this->hasOne(ThriftPlan::class, 'predecessor_plan_id');
    }

    /** @return HasMany<PlanTermsRevision, $this> */
    public function termsRevisions(): HasMany
    {
        return $this->hasMany(PlanTermsRevision::class)->orderBy('revision');
    }

    /** @return HasMany<ContributionSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(ContributionSlot::class);
    }

    /** @return HasMany<PlanLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(PlanLifecycleEvent::class)->orderBy('effective_at')->orderBy('id');
    }

    public function currentTermsRevision(): ?PlanTermsRevision
    {
        return $this->termsRevisions()->where('revision', $this->current_terms_revision)->first();
    }
}
