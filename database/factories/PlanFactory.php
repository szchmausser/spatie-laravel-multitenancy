<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'features' => ['premium-zone' => false],
            'price_cents' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Mark the plan as inactive.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Set specific features for the plan.
     */
    public function withFeatures(array $features): static
    {
        return $this->state(['features' => $features]);
    }
}
