<?php

namespace Database\Factories;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\User;
use App\Support\IdentityNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'name' => fake()->name(),
            'email' => $email,
            'email_normalized' => IdentityNormalizer::normalizeEmail($email),
            'user_type' => UserType::Customer,
            'account_state' => AccountState::Active,
            'locked_until' => null,
            'lock_category' => null,
            'lock_reason' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the user is a customer.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserType::Customer,
        ]);
    }

    /**
     * Indicate that the user is an agent.
     */
    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserType::Agent,
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserType::Admin,
        ]);
    }

    /**
     * Indicate that the user is in invited state.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_state' => AccountState::Invited,
        ]);
    }

    /**
     * Indicate that the user requires MFA setup.
     */
    public function mfaSetupRequired(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_state' => AccountState::MfaSetupRequired,
        ]);
    }

    /**
     * Indicate that the user is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_state' => AccountState::Active,
        ]);
    }

    /**
     * Indicate that the user is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_state' => AccountState::Suspended,
        ]);
    }

    /**
     * Indicate that the user is deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_state' => AccountState::Deactivated,
        ]);
    }

    /**
     * Indicate that the user is temporarily locked.
     */
    public function temporarilyLocked(?string $category = 'password', ?string $reason = 'Too many failed login attempts.', int $minutes = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'locked_until' => now()->addMinutes($minutes),
            'lock_category' => $category,
            'lock_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
