<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'domain' => fake()->unique()->domainName(),
            'database' => 'tenant_'.fake()->unique()->randomNumber(5),
        ];
    }

    /**
     * Override the database field to a specific value.
     *
     * Use this state to pin the database name without mutating the factory definition.
     */
    public function forDatabase(string $dbName): static
    {
        return $this->state(['database' => $dbName]);
    }
}
