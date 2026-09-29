<?php

namespace Database\Factories;

use App\Models\BusinessProfile;
use App\Models\FinancialPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialPeriod>
 */
class FinancialPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_profile_id' => BusinessProfile::current()->id,
            'timezone' => BusinessProfile::current()->timezone,
            'month' => now(BusinessProfile::current()->timezone)->startOfMonth()->toDateString(),
            'status' => 'open',
            'version' => 1,
            'changed_by_user_id' => User::factory()->admin(),
        ];
    }
}
