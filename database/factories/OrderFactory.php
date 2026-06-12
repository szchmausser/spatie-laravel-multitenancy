<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
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
            'resource_id' => null,
            'total_cents' => 1000,
            'status' => OrderStatus::Pending,
            'expires_at' => now()->addHours(48),
            'metadata' => null,
        ];
    }

    /**
     * Mark the order as for a plan (the default).
     */
    public function forPlan(): static
    {
        return $this->state([
            'plan_id' => Plan::factory(),
            'resource_id' => null,
        ]);
    }

    /**
     * Mark the order as for a resource.
     */
    public function forResource(): static
    {
        return $this->state([
            'plan_id' => null,
            'resource_id' => Resource::factory(),
        ]);
    }

    /**
     * Set the order status to pending.
     */
    public function pending(): static
    {
        return $this->state(['status' => OrderStatus::Pending]);
    }

    /**
     * Set the order status to paid.
     */
    public function paid(): static
    {
        return $this->state(['status' => OrderStatus::Paid]);
    }

    /**
     * Set the order status to cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(['status' => OrderStatus::Cancelled]);
    }

    /**
     * Set the order status to expired.
     */
    public function expired(): static
    {
        return $this->state(['status' => OrderStatus::Expired]);
    }
}
