<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

class SystemConfigSeeder extends Seeder
{
    /**
     * Seed system configuration values.
     */
    public function run(): void
    {
        $configs = [
            // Payment configs
            ['group' => 'payment', 'key' => 'payment.order_expiry_hours', 'value' => 48, 'type' => 'integer', 'description' => 'Horas después de las cuales un pedido pendiente de pago se considera expirado y se cancela automáticamente.'],

            // Reconciliation configs
            ['group' => 'reconciliation', 'key' => 'reconciliation.match_window_hours', 'value' => 72, 'type' => 'integer', 'description' => 'Ventana de tiempo (en horas) hacia atrás para buscar coincidencias entre notificaciones bancarias y pagos registrados.'],
            ['group' => 'reconciliation', 'key' => 'reconciliation.shadow_mode_channels', 'value' => json_encode(['bank-app', 'sms']), 'type' => 'json', 'description' => 'Canales que ejecutan en modo shadow. Las notificaciones de estos canales no auto-verifican pagos. Vacío = todos auto-verifican.'],
            ['group' => 'reconciliation', 'key' => 'reconciliation.polling_interval_seconds', 'value' => 30, 'type' => 'integer', 'description' => 'Intervalo en segundos para actualización automática del dashboard de conciliación. 0 = desactivado.'],
            ['group' => 'reconciliation', 'key' => 'reconciliation.orphan_threshold_minutes', 'value' => 30, 'type' => 'integer', 'description' => 'Minutos después de los cuales un pago pendiente sin match se considera huérfano y se muestra en alertas.'],

            // Device configs
            ['group' => 'device', 'key' => 'device.heartbeat_interval_minutes', 'value' => 1, 'type' => 'integer', 'description' => 'Intervalo en minutos entre heartbeats del dispositivo Android. Este valor se envía al teléfono en cada respuesta de heartbeat, permitiendo ajustar remotamente la frecuencia sin actualizar la app.'],
            ['group' => 'device', 'key' => 'device.heartbeat_retention_days', 'value' => 30, 'type' => 'integer', 'description' => 'Días que se conservan los heartbeats antes de ser purgados automáticamente.'],

            // Regex configs (solo BDV y BNC con formatos reales verificados)
            ['group' => 'reconciliation', 'key' => 'regex_bdv', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string', 'description' => 'Regex para extraer datos de notificaciones SMS del Banco de Venezuela (BDV). Debe incluir grupos nombrados: amount, phone, reference, date, time.'],
            ['group' => 'reconciliation', 'key' => 'regex_bnc', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string', 'description' => 'Regex para extraer datos de notificaciones SMS del Banco Nacional de Crédito (BNC). Debe incluir grupos nombrados: amount, phone, reference, date, time.'],
        ];

        foreach ($configs as $config) {
            SystemConfig::updateOrCreate(
                ['key' => $config['key']],
                $config
            );
        }
    }
}
