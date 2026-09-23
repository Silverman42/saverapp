<?php

namespace Database\Factories;

use App\Models\AgentOffboardingCase;
use App\Models\AgentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentOffboardingCase>
 */
class AgentOffboardingCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_profile_id' => AgentProfile::factory(),
            'status' => 'in_progress',
            'is_open' => 1,
        ];
    }
}
