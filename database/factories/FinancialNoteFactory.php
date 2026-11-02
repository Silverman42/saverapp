<?php

namespace Database\Factories;

use App\Enums\FinancialNoteType;
use App\Models\BusinessProfile;
use App\Models\FinancialNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinancialNote>
 */
class FinancialNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $author = User::factory()->admin();

        return [
            'business_profile_id' => BusinessProfile::current()->id,
            'reference' => (string) Str::uuid(),
            'type' => fake()->randomElement(FinancialNoteType::cases()),
            'title' => fake()->sentence(4),
            'amount_kobo' => fake()->numberBetween(1_000, 5_000_000),
            'note_date' => now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'category' => fake()->optional()->randomElement(['Rent', 'Utilities', 'Salaries', 'Sponsorship', 'Transport']),
            'body' => fake()->paragraph(),
            'status' => FinancialNote::STATUS_ACTIVE,
            'created_by_user_id' => $author,
            'updated_by_user_id' => fn (array $attributes) => $attributes['created_by_user_id'],
            'version' => 1,
        ];
    }

    public function credit(): static
    {
        return $this->state(fn (): array => ['type' => FinancialNoteType::Credit]);
    }

    public function debit(): static
    {
        return $this->state(fn (): array => ['type' => FinancialNoteType::Debit]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FinancialNote::STATUS_ARCHIVED,
            'archived_at' => now(),
            'archived_by_user_id' => $attributes['created_by_user_id'],
        ]);
    }
}
