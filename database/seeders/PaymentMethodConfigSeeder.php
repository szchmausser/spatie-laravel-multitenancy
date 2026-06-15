<?php

namespace Database\Seeders;

use App\Models\PaymentMethodConfig;
use Illuminate\Database\Seeder;

/**
 * Seeds default payment method configurations.
 *
 * Creates sample PagoMóvil and bank transfer accounts so the
 * payment method selector appears in the order flow. These are
 * placeholder values for testing — real accounts will be managed
 * via the admin CRUD (pending).
 */
class PaymentMethodConfigSeeder extends Seeder
{
    public function run(): void
    {
        // PagoMóvil accounts
        PaymentMethodConfig::updateOrCreate(
            ['type' => 'pago_movil', 'bank_name' => 'Banco de Venezuela'],
            [
                'label' => 'PDVSA Principal',
                'account_number' => '0412-1234567',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        PaymentMethodConfig::updateOrCreate(
            ['type' => 'pago_movil', 'bank_name' => 'Banco Mercantil'],
            [
                'label' => 'Mercantil Secundaria',
                'account_number' => '0414-7654321',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        // Bank transfer accounts
        PaymentMethodConfig::updateOrCreate(
            ['type' => 'bank_transfer', 'bank_name' => 'Banco de Venezuela'],
            [
                'label' => 'Corriente BDV',
                'account_number' => '0102-12345678901234',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        PaymentMethodConfig::updateOrCreate(
            ['type' => 'bank_transfer', 'bank_name' => 'Banco Mercantil'],
            [
                'label' => 'Ahorro Mercantil',
                'account_number' => '0105-12345678901234',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );
    }
}
