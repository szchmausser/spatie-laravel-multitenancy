<?php

namespace Database\Factories;

use App\Models\PagoMovilDetail;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagoMovilDetail>
 */
class PagoMovilDetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'phone' => fake()->numerify('04##-#######'),
            'bank' => fake()->randomElement([
                'Banco de Venezuela',
                'Banco Mercantil',
                'Banco Provincial',
                'Banesco',
                'Banco Nacional de Crédito',
                'Bancamiga',
                'Banco Plaza',
                'Banco Caroní',
            ]),
            'rif' => fake()->numerify('J-########-#'),
            'sender_bank' => fake()->randomElement([
                'Banco de Venezuela',
                'Banco Mercantil',
                'Banco Provincial',
                'Banesco',
                'Banco Nacional de Crédito',
                'Bancamiga',
                'Banco Plaza',
                'Banco Caroní',
            ]),
            'sender_phone' => fake()->numerify('04##-#######'),
            'payment_date' => fake()->dateTimeThisMonth(),
        ];
    }
}
