<?php

namespace Database\Factories;

use App\Models\BusinessConfigurationDraft;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessConfigurationDraft> */
class BusinessConfigurationDraftFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['business_profile_id' => BusinessProfile::current()->id, 'actor_user_id' => User::factory()->admin()->withTwoFactor(),
            'revision' => 1, 'base_version' => BusinessProfile::current()->version, 'patch' => ['display_name' => fake()->company()], 'status' => 'draft'];
    }
}
