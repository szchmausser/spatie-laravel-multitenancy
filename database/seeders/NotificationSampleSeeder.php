<?php

namespace Database\Seeders;

use App\Models\PaymentNotification;
use Illuminate\Database\Seeder;

class NotificationSampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $samples = $this->generateSamples();

        foreach ($samples as $sample) {
            $dedupHash = PaymentNotification::computeDedupHash($sample['bank_code'], $sample['raw_text']);

            // Skip if already seeded (idempotent)
            if (PaymentNotification::where('dedup_hash', $dedupHash)->exists()) {
                continue;
            }

            PaymentNotification::forceCreate([
                'bank_code' => $sample['bank_code'],
                'raw_text' => $sample['raw_text'],
                'dedup_hash' => $dedupHash,
                'parse_status' => 'pending',
            ]);
        }

        $this->command?->info('Seeded '.count($samples).' sample notifications.');
    }

    /**
     * Generate representative notification samples for BDV and BNC.
     *
     * @return array<int, array{bank_code: string, raw_text: string}>
     */
    private function generateSamples(): array
    {
        $samples = [];

        // BDV: Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40
        $samples[] = $this->makeBdv('006236568762', '3.000,00', '0424-3153557', '02-06-26', '09:40');
        $samples[] = $this->makeBdv('123456789012', '1.500,00', '0412-9876543', '15-06-26', '14:30');
        $samples[] = $this->makeBdv('001234567890', '500', '0424-1111222', '01-01-26', '00:00');
        $samples[] = $this->makeBdv('9876543210', '2.500,50', '0416-5555666', '20-06-26', '18:15');

        // BNC: BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603
        $samples[] = $this->makeBnc('006236568762', '3.000,00', '0424***3557', '02/06/26', '09:40');
        $samples[] = $this->makeBnc('603185603', '10455,00', '0416***9503', '31/05/26', '20:25');
        $samples[] = $this->makeBnc('12345678', '1.500,00', '0412***6789', '15/06/26', '14:30');
        $samples[] = $this->makeBnc('998877665', '250,75', '0424***1234', '01/01/26', '00:00');

        return $samples;
    }

    private function makeBdv(string $ref, string $amount, string $phone, string $date, string $time): array
    {
        return [
            'bank_code' => 'bdv',
            'raw_text' => "Recibiste un PagomovilBDV por Bs. {$amount} del {$phone} Ref: {$ref} en fecha: {$date} hora: {$time}",
        ];
    }

    private function makeBnc(string $ref, string $amount, string $phone, string $date, string $time): array
    {
        return [
            'bank_code' => 'bnc',
            'raw_text' => "BNC Pago Movil Recibido Bs.{$amount} Telf.{$phone} Dia:{$date}-{$time} Ref:{$ref} Llamar al 0500-2625000 si no realizo esta Operacion",
        ];
    }
}
