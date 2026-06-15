<?php

namespace Database\Factories;

use App\Models\BankTransferDetail;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransferDetail>
 */
class BankTransferDetailFactory extends Factory
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
            'account_number' => fake()->numerify('0134-######-######'),
            'bank_name' => fake()->randomElement([
                'Banco de Venezuela',
                'Banco Mercantil',
                'Banco Provincial',
                'Banesco',
                'Banco Nacional de Crédito',
                'Bancamiga',
                'Banco Plaza',
                'Banco Caroní',
            ]),
            'account_holder' => fake()->company(),
            'holder_id' => fake()->numerify('J-########-#'),
        ];
    }
}
