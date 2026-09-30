<?php

namespace Database\Factories;

use App\Models\CashMethodVersion;
use App\Services\AuditProjection;
use App\Services\CashMethodCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMethodVersion>
 */
class CashMethodVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'method_key' => 'cash', 'version' => fake()->unique()->numberBetween(2, 100000),
            'contract' => CashMethodCatalogue::VERSION_ONE, 'contract_hash' => AuditProjection::digest(CashMethodCatalogue::VERSION_ONE),
            'effective_at' => now()->subMinute(),
        ];
    }
}
