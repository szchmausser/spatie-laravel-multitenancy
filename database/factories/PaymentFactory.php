<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'order_id' => Order::factory(),
            'amount_cents' => 1000,
            'currency' => 'VES',
            'payment_method' => 'pago_movil',
            'transaction_id' => null,
            'status' => PaymentStatus::Pending,
            'verified_by' => null,
            'verified_at' => null,
            'cancellation_reason' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'metadata' => null,
        ];
    }

    /**
     * Mark the payment as verified.
     */
    public function verified(): static
    {
        return $this->state([
            'status' => PaymentStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    /**
     * Mark the payment as cancelled.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status' => PaymentStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
