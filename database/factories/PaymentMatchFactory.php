<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMatch>
 */
class PaymentMatchFactory extends Factory
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
            'payment_notification_id' => PaymentNotification::factory(),
            'parsed_reference' => 'REF-'.fake()->randomNumber(6),
            'parsed_amount_cents' => 1000,
            'parsed_sender_phone_last4' => fake()->numerify('####'),
            'match_status' => 'matched',
            'matched_at' => now(),
        ];
    }
}
