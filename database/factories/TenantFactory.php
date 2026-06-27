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
        $number = fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => 'Tenant '.$number,
            'domain' => "tenant{$number}.spatie-laravel-multitenancy.test",
            'database' => "tenant{$number}-spatie-laravel-multitenancy",
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
