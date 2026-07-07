<?php

use App\Models\SystemConfig;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $descriptions = [
        'payment.default_gateway' => 'Método de pago por defecto para nuevos pedidos. Valores: pago_movil (PagoMóvil), bank_transfer (Transferencia Bancaria).',
        'payment.order_expiry_hours' => 'Horas después de las cuales un pedido pendiente de pago se considera expirado y se cancela automáticamente.',
        'reconciliation.match_window_hours' => 'Ventana de tiempo (en horas) hacia atrás para buscar coincidencias entre notificaciones bancarias y pagos registrados.',
        'reconciliation.shadow_mode_enabled' => 'Cuando está activo, las conciliaciones se ejecutan en modo simulación: se registran los resultados pero no se aplican cambios a los pagos. Útil para validar reglas sin afectar datos reales.',
        'device.heartbeat_interval_minutes' => 'Intervalo en minutos entre heartbeats del dispositivo Android. Este valor se envía al teléfono en cada respuesta de heartbeat, permitiendo ajustar remotamente la frecuencia sin actualizar la app.',
        'regex_bdv_sms' => 'Regex para notificaciones SMS del Banco de Venezuela (BDV). Grupos: amount, phone, reference, date, time.',
        'regex_bdv_bank-app' => 'Regex para notificaciones Bank App del Banco de Venezuela (BDV). Grupos: amount, phone, reference, date, time.',
        'regex_bnc_sms' => 'Regex para notificaciones SMS del Banco Nacional de Crédito (BNC). Grupos: amount, phone, reference, date, time.',
        'regex_bnc_bank-app' => 'Regex para notificaciones Bank App del Banco Nacional de Crédito (BNC). Grupos: amount, phone, reference, date, time.',
    ];

    public function up(): void
    {
        foreach ($this->descriptions as $key => $description) {
            SystemConfig::where('key', $key)
                ->whereNull('description')
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        foreach ($this->descriptions as $key => $description) {
            SystemConfig::where('key', $key)
                ->update(['description' => null]);
        }
    }
};
