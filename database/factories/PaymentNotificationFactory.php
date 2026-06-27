<?php

namespace Database\Factories;

use App\Models\PaymentNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentNotification>
 */
class PaymentNotificationFactory extends Factory
{
    /**
     * Bank codes supported by the parser.
     */
    private const BANKS = ['BDV', 'BNC'];

    /**
     * Define the model's default state.
     *
     * Generates realistic bank notification text matching real SMS formats
     * from Venezuelan banks, with parsed_data set for the 'parsed' status.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bankCode = fake()->randomElement(self::BANKS);
        $amount = fake()->randomFloat(2, 100, 5000);
        $phone = '04'.fake()->numerify('##-#######');
        $reference = fake()->numerify('##########');
        $now = now();

        $rawText = match ($bankCode) {
            'BDV' => "Recibiste un PagomovilBDV por Bs. {$amount} del {$phone} Ref: {$reference} en fecha: ".$now->format('d-m-y').' hora: '.$now->format('H:i'),
            'BNC' => "BNC Pago Movil Recibido Bs.{$amount} Telf.{$phone} Dia:".$now->format('d/m/y').'-'.$now->format('H:i').' Ref:'.$reference.' Llamar al 0500-2625000 si no realizo esta Operacion',
        };

        return [
            'bank_code' => $bankCode,
            'raw_text' => $rawText,
            'dedup_hash' => PaymentNotification::computeDedupHash($bankCode, $rawText),
            'parse_status' => 'parsed',
            'parsed_data' => [
                'amount_cents' => (int) ($amount * 100),
                'reference' => $reference,
                'sender_phone_last4' => substr($phone, -4),
            ],
            'parsed_at' => $now,
        ];
    }

    /**
     * Indicate that the notification is pending parsing.
     *
     * Keeps realistic raw_text but omits parsed data.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'parse_status' => 'pending',
            'parsed_data' => null,
            'parsed_at' => null,
        ]);
    }

    /**
     * Indicate that the notification is failed to parse.
     *
     * Keeps realistic raw_text, adds a realistic parse error based on
     * actual failure modes from PaymentNotificationParser.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'parse_status' => 'failed',
            'parsed_data' => null,
            'parse_error' => fake()->randomElement([
                'Regex did not match',
                'No se encontró patrón de parseo para este banco',
                'Monto no encontrado en el mensaje',
                'Referencia no encontrada en el mensaje',
                'Formato de fecha inválido en el mensaje',
                'Error inesperado durante el parseo: Call to a member function format() on null',
            ]),
            'parsed_at' => now(),
        ]);
    }
}
