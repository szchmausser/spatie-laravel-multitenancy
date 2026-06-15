<?php

namespace Database\Factories;

use App\Models\PaymentMethodConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethodConfig>
 */
class PaymentMethodConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['pago_movil', 'bank_transfer']),
            'label' => fake()->randomElement(['Bancaracas', 'Mercantil', 'Banesco', 'Provincial']),
            'bank_name' => fake()->randomElement([
                'Banco de Venezuela',
                'Banco Mercantil',
                'Banco Provincial',
                'Banesco',
            ]),
            'account_number' => fake()->numerify('0134-######-######'),
            'account_holder' => fake()->company(),
            'holder_id' => fake()->numerify('J-########-#'),
            'is_active' => true,
            'sort_order' => 0,
            'metadata' => null,
        ];
    }

    /**
     * Mark the config as active.
     */
    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    /**
     * Mark the config as inactive.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Set the config type.
     */
    public function ofBankTransfer(): static
    {
        return $this->state(['type' => 'bank_transfer']);
    }

    /**
     * Set the config type to pago_movil.
     */
    public function ofPagoMovil(): static
    {
        return $this->state(['type' => 'pago_movil']);
    }
}
