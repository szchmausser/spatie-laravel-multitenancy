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
            // ─── Admin ──────────────────────────────────────────────────
            ['group' => 'admin', 'key' => 'admin.polling_interval_seconds', 'value' => 30, 'type' => 'integer', 'description' => 'Segundos entre cada auto-refresh del badge de notificaciones bancarias en el admin. Afecta el dashboard y la página de notificaciones. 0 = desactivado, refresca manualmente.'],

            // ─── Payment ────────────────────────────────────────────────
            ['group' => 'payment', 'key' => 'payment.order_expiry_hours', 'value' => 48, 'type' => 'integer', 'description' => 'Horas antes de que una orden pendiente de pago expire automáticamente. Se calcula desde que se crea la orden. Cuando expira, el sistema la marca como expirada y notifica al admin del tenant. Sube si tus usuarios tardan más en pagar. Bájalo si quieres que las órdenes pendientes se liberen más rápido.'],

            // ─── Reconciliation ─────────────────────────────────────────
            ['group' => 'reconciliation', 'key' => 'reconciliation.match_window_hours', 'value' => 72, 'type' => 'integer', 'description' => 'Horas hacia atrás que busca el sistema al conciliar una notificación bancaria con un pago registrado. Si el pago fue creado hace más de este tiempo, no se matchea. El comando payments:expire-pending usa este valor +24h como cutoff para cancelar pagos huérfanos. Sube si las notificaciones bancarias llegan con mucho retardo. Bájalo para ser más estricto.'],
            ['group' => 'reconciliation', 'key' => 'reconciliation.shadow_mode_channels', 'value' => json_encode([]), 'type' => 'json', 'description' => 'Canales que operan en modo sombra: el sistema parsea la notificación y busca coincidencia, pero NO verifica el pago automáticamente — lo deja como pendiente para revisión manual. Quita un canal de esta lista para que verifique pagos automáticamente. Lista vacía = todos verifican sin intervención humana.'],
            ['group' => 'reconciliation', 'key' => 'reconciliation.polling_interval_seconds', 'value' => 30, 'type' => 'integer', 'description' => 'Segundos entre auto-refresh del dashboard de conciliación (KPIs, pagos pendientes, matcheados). 0 = desactivado, refresca manualmente. El valor se envía al frontend como prop.'],

            // ─── Device ─────────────────────────────────────────────────
            ['group' => 'device', 'key' => 'device.heartbeat_interval_minutes', 'value' => 1, 'type' => 'integer', 'description' => 'Minutos entre heartbeats del teléfono Android. El valor se devuelve al dispositivo en cada respuesta del API /heartbeat, así que el teléfono ajusta su frecuencia automáticamente sin necesidad de actualizar la app. El comando devices:check-heartbeats usa 3x este valor como timeout — si el teléfono no responde en ese tiempo, se desactiva y se alerta al admin.'],
            ['group' => 'device', 'key' => 'device.heartbeat_retention_days', 'value' => 30, 'type' => 'integer', 'description' => 'Días que se conservan los registros de heartbeats antes de ser eliminados por device-heartbeats:purge. Los heartbeats registran batería, IP y mensajes pendientes del dispositivo. Útil para diagnosticar cuándo se cayó un dispositivo. Baja este valor para ahorrar espacio en la base de datos.'],

            // ─── Regex (expresiones regulares) ──────────────────────────
            ['group' => 'regex', 'key' => 'regex_bdv_sms', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string', 'description' => 'Patrón regex que extrae monto, teléfono, referencia y fecha del SMS de Pago Móvil del Banco de Venezuela. Se usa cuando sourceType = sms. Solo edita si BDV cambia el formato de sus SMS. Los grupos amount y reference son obligatorios.'],
            ['group' => 'regex', 'key' => 'regex_bdv_bank-app', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string', 'description' => 'Patrón regex para notificaciones de Pago Móvil BDV que llegan por la app bancaria (sourceType = bank-app). Normalmente es el mismo que el SMS, pero se actualiza independientemente si la app cambia su formato.'],
            ['group' => 'regex', 'key' => 'regex_bnc_sms', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string', 'description' => 'Patrón regex para SMS de Pago Móvil del Banco Nacional de Crédito (sourceType = sms). Solo edita si BNC cambia el formato de sus SMS.'],
            ['group' => 'regex', 'key' => 'regex_bnc_bank-app', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string', 'description' => 'Patrón regex para notificaciones de Pago Móvil BNC por app bancaria (sourceType = bank-app). Normalmente es el mismo que el SMS, pero se actualiza independientemente si la app cambia su formato.'],
        ];

        foreach ($configs as $config) {
            SystemConfig::updateOrCreate(
                ['key' => $config['key']],
                $config
            );
        }
    }
}
