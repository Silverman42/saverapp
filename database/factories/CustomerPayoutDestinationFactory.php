<?php

namespace Database\Factories;

use App\Models\CustomerPayoutDestination;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerPayoutDestination>
 */
class CustomerPayoutDestinationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = fake()->numerify('#########').'1';

        return [
            'destination_reference' => (string) Str::uuid(), 'registration_reference' => (string) Str::uuid(),
            'customer_profile_id' => CustomerProfile::factory(), 'version' => 1, 'active_customer_profile_id' => null,
            'status' => 'pending_verification', 'provider_key' => 'fake', 'bank_code' => '058', 'bank_name' => 'Test Bank',
            'account_token' => 'tok_'.$account, 'account_fingerprint' => hash('sha256', '058|'.$account),
            'account_mask' => '******'.substr($account, -4), 'verified_payee_name' => fake()->name(), 'name_match' => 'exact',
            'registration_attestation' => 'The Customer gave this account in person.',
            'registered_by_user_id' => User::factory()->agent(), 'payload_hash' => hash('sha256', $account),
        ];
    }

    public function verified(?User $verifier = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'verified', 'verified_by_user_id' => $verifier === null ? User::factory()->admin() : $verifier->id, 'verified_at' => now(),
            'active_customer_profile_id' => $attributes['customer_profile_id'] instanceof CustomerProfile
                ? $attributes['customer_profile_id']->id : $attributes['customer_profile_id'],
        ]);
    }
}
