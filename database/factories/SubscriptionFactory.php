<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * Set the subscription status to trialing.
     */
    public function trialing(): static
    {
        return $this->state(['status' => SubscriptionStatus::Trialing]);
    }

    /**
     * Set the subscription status to cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(['status' => SubscriptionStatus::Cancelled]);
    }

    /**
     * Set the subscription status to expired.
     */
    public function expired(): static
    {
        return $this->state(['status' => SubscriptionStatus::Expired]);
    }
}
