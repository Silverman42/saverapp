<?php

namespace Database\Factories;

use App\Models\BusinessConfigurationVersion;
use App\Models\BusinessProfile;
use App\Services\BusinessSettingsCatalogue;
use App\Services\BusinessSettingsReadiness;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessConfigurationVersion> */
class BusinessConfigurationVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $profile = BusinessProfile::current();
        $values = app(BusinessSettingsCatalogue::class)->initialValues($profile);

        return ['business_profile_id' => $profile->id, 'version' => fake()->unique()->numberBetween(1000, 999999),
            'base_version' => $profile->version, 'values' => $values, 'values_hash' => app(BusinessSettingsCatalogue::class)->hash($values),
            'dependency_hash' => app(BusinessSettingsReadiness::class)->hash(), 'changed_codes' => ['display_name'],
            'actor_user_id' => null, 'reason' => 'Reviewed test configuration', 'source' => 'publication', 'requested_effective_at' => now()];
    }
}
