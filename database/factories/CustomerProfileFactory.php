<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\Gender;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\PublicIdGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    protected $model = CustomerProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->customer(),
            'customer_id' => app(PublicIdGenerator::class)->generateForCustomer(),
            'phone' => '+23480'.fake()->unique()->numerify('########'),
            'address' => fake()->address(),
            'gender' => fake()->randomElement(Gender::cases()),
            'occupation' => fake()->jobTitle(),
            'notes' => null,
            'internal_reference' => null,
            'next_of_kin' => null,
            'operational_status' => CustomerStatus::Active,
            'version' => 1,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'operational_status' => CustomerStatus::Inactive,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes) => [
            'operational_status' => CustomerStatus::Restricted,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'operational_status' => CustomerStatus::Archived,
        ]);
    }

    public function withNextOfKin(array $overrides = []): static
    {
        return $this->state(fn (array $attributes) => [
            'next_of_kin' => array_merge([
                'full_name' => fake()->name(),
                'relationship' => 'Spouse',
                'phone' => '+23480'.fake()->numerify('########'),
                'address' => fake()->address(),
            ], $overrides),
        ]);
    }

    public function withInternalReference(?string $ref = null): static
    {
        return $this->state(fn (array $attributes) => [
            'internal_reference' => $ref ?? 'REF-'.fake()->unique()->numerify('#####'),
        ]);
    }
}
