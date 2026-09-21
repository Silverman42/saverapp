<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\PublicIdGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    protected $model = AgentProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->agent(),
            'agent_id' => app(PublicIdGenerator::class)->generateForAgent(),
            'phone' => '+23480'.fake()->unique()->numerify('########'),
            'address' => fake()->address(),
            'profile_photo_path' => null,
            'employment_date' => null,
            'notes' => null,
            'operational_status' => AgentStatus::Inactive,
            'version' => 1,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'operational_status' => AgentStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'operational_status' => AgentStatus::Inactive,
        ]);
    }

    public function withEmploymentDate(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_date' => $date ?? now()->toDateString(),
        ]);
    }
}
