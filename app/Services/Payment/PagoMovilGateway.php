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
     * PaymentMethodConfig table using payment_method_config_id when
     * provided, falling back to the global config otherwise.
     *
     * @param  array{
     *     amount_cents: int,
     *     payment_method_config_id: ?int,
     *     sender_bank: string,
     *     sender_phone: string,
     *     sender_id: ?string,
     *     payment_date: string,
     *     concept: ?string,
     *  }  $data
     */
    public function recordPayment(Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($order, $data) {
            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount_cents' => $data['amount_cents'],
                'currency' => 'VES',
                'payment_method' => 'pago_movil',
                'payment_method_config_id' => $data['payment_method_config_id'] ?? null,
                'status' => PaymentStatus::Pending,
            ]);

            // Resolve the receiving account from PaymentMethodConfig or global config
            $config = $this->resolveReceivingAccount($data['payment_method_config_id'] ?? null);

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
     * If a config ID is provided, looks it up from PaymentMethodConfig.
     * Otherwise falls back to the global payment.pago_movil config.
     */
    private function resolveReceivingAccount(?int $configId): array
    {
        if ($configId !== null) {
            $config = PaymentMethodConfig::query()
                ->where('id', $configId)
                ->where('type', 'pago_movil')
                ->first();

            if ($config) {
                return [
                    'phone' => $config->account_number,
                    'bank' => $config->bank_name,
                    'rif' => $config->holder_id,
                ];
            }
        }

        return [
            'phone' => config('payment.pago_movil.phone'),
            'bank' => config('payment.pago_movil.bank'),
            'rif' => config('payment.pago_movil.rif'),
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
