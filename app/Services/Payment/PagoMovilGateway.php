<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
use Illuminate\Support\Facades\DB;

class PagoMovilGateway implements PaymentGatewayInterface
{
    /**
     * Record a Pago Móvil payment for an order.
     *
     * Creates both the Payment (supertipo) and PagoMovilDetail (subtipo)
     * in a single database transaction to guarantee atomicity.
     *
     * The receiving account (phone, bank, rif) is resolved from the
     * PaymentMethodConfig table using payment_method_config_id.
     *
     * @param  array{
     *     amount_cents: int,
     *     payment_method_config_id: int,
     *     sender_bank: string,
     *     sender_phone: string,
     *     sender_id: ?string,
     *     payment_date: string,
     *     concept: ?string,
     *  }  $data
     */
    public function recordPayment(Order $order, array $data): Payment
    {
        $config = $this->resolveReceivingAccount($data['payment_method_config_id'] ?? null);

        return DB::transaction(function () use ($order, $data, $config) {
            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount_cents' => $data['amount_cents'],
                'currency' => 'VES',
                'payment_method' => 'pago_movil',
                'payment_method_config_id' => $data['payment_method_config_id'] ?? null,
                'status' => PaymentStatus::Pending,
            ]);

            $payment->pagoMovilDetail()->create([
                'phone' => $config['phone'],
                'bank' => $config['bank'],
                'rif' => $config['rif'],
                'sender_bank' => $data['sender_bank'],
                'sender_phone' => $data['sender_phone'],
                'sender_id' => $data['sender_id'] ?? null,
                'payment_date' => $data['payment_date'],
                'concept' => $data['concept'] ?? null,
            ]);

            return $payment;
        });
    }

    /**
     * Resolve receiving account details for Pago Móvil.
     *
     * Always requires a valid PaymentMethodConfig record.
     * Aborts with 422 if config is missing or inactive.
     *
     * @return array{phone: string, bank: string, rif: string}
     */
    private function resolveReceivingAccount(?int $configId): array
    {
        abort_if($configId === null, 422, 'Se requiere una configuración de método de pago para Pago Móvil.');

        $config = PaymentMethodConfig::query()
            ->where('id', $configId)
            ->where('type', 'pago_movil')
            ->first();

        abort_if(! $config, 422, 'No hay cuenta PagoMóvil activa configurada.');

        return [
            'phone' => $config->account_number,
            'bank' => $config->bank_name,
            'rif' => $config->holder_id,
        ];
    }

    /**
     * Get display instructions for Pago Móvil.
     *
     * Returns the destination phone, bank, RIF, and reference
     * so the frontend can show the tenant where to send the payment.
     */
    public function getInstructions(Payment $payment): array
    {
        $detail = $payment->pagoMovilDetail;

        return [
            'type' => 'pago_movil',
            'title' => 'Pago Móvil',
            'fields' => [
                ['label' => 'Teléfono', 'value' => $detail->phone],
                ['label' => 'Banco', 'value' => $detail->bank],
                ['label' => 'RIF', 'value' => $detail->rif],
            ],
            'amount' => $payment->amount_cents,
        ];
    }
}
