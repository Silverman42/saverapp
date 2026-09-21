<?php

namespace Database\Factories;

use App\Enums\CustomerAssignmentStatus;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CustomerAssignment>
 */
class CustomerAssignmentFactory extends Factory
{
    protected $model = CustomerAssignment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'agent_profile_id' => AgentProfile::factory(),
            'assigned_by_user_id' => User::factory()->agent(),
            'reason' => 'Initial customer registration assignment',
            'status' => CustomerAssignmentStatus::Current,
            'is_current' => 1,
            'effective_at' => Carbon::now(),
            'ended_at' => null,
            'version' => 1,
        ];
    }

    /**
     * Mark assignment as ended.
     */
    public function ended(?Carbon $endedAt = null, ?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerAssignmentStatus::Ended,
            'is_current' => null,
            'ended_at' => $endedAt ?? Carbon::now(),
            'reason' => $reason ?? ($attributes['reason'] ?? 'Customer reassigned'),
        ]);
    }
}
