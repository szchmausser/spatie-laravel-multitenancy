<?php

namespace Database\Seeders;

use App\Models\PaymentMethodConfig;
use Illuminate\Database\Seeder;

/**
 * Seeds default payment method configurations.
 *
 * Creates sample PagoMóvil and bank transfer accounts so the
 * payment method selector appears in the order flow. Bank names
 * match the two banks whose notification format the system
 * supports: BDV (Banco de Venezuela) and BNC (Banco Nacional
 * de Crédito).
 *
 * @see NotificationSampleSeeder for bank_code values
 * @see SystemConfigSeeder for regex_bdv / regex_bnc
 */
class PaymentMethodConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Migrate old placeholder records to real bank names before upserting,
        // so existing FK references from payments remain valid.
        PaymentMethodConfig::where('bank_name', 'Banco Mercantil')
            ->where('type', 'pago_movil')
            ->update(['bank_name' => 'Banco Nacional de Crédito (BNC)']);
        PaymentMethodConfig::where('bank_name', 'Banco Mercantil')
            ->where('type', 'bank_transfer')
            ->update(['bank_name' => 'Banco Nacional de Crédito (BNC)']);
        PaymentMethodConfig::where('bank_name', 'Banco de Venezuela')
            ->where('type', 'pago_movil')
            ->where('label', 'PDVSA Principal')
            ->update(['bank_name' => 'Banco de Venezuela (BDV)', 'label' => 'BDV Principal']);
        PaymentMethodConfig::where('bank_name', 'Banco de Venezuela')
            ->where('type', 'bank_transfer')
            ->where('label', 'Corriente BDV')
            ->update(['bank_name' => 'Banco de Venezuela (BDV)']);

        // PagoMóvil accounts
        PaymentMethodConfig::updateOrCreate(
            ['type' => 'pago_movil', 'bank_name' => 'Banco de Venezuela (BDV)'],
            [
                'label' => 'BDV Principal',
                'account_number' => '0412-1234567',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        PaymentMethodConfig::updateOrCreate(
            ['type' => 'pago_movil', 'bank_name' => 'Banco Nacional de Crédito (BNC)'],
            [
                'label' => 'BNC Secundaria',
                'account_number' => '0416-7654321',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        // Bank transfer accounts
        PaymentMethodConfig::updateOrCreate(
            ['type' => 'bank_transfer', 'bank_name' => 'Banco de Venezuela (BDV)'],
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
            ['type' => 'bank_transfer', 'bank_name' => 'Banco Nacional de Crédito (BNC)'],
            [
                'label' => 'Corriente BNC',
                'account_number' => '0191-12345678901234',
                'account_holder' => 'Mi Empresa C.A.',
                'holder_id' => 'J-12345678-9',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );
    }
}
