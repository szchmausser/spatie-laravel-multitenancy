<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PagoMovilGateway implements PaymentGatewayInterface
{
    /**
     * Record a Pago Móvil payment for an order.
     *
     * Creates both the Payment (supertipo) and PagoMovilDetail (subtipo)
     * in a single database transaction to guarantee atomicity.
     *
     * @param  array{
     *     amount_cents: int,
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
                'status' => PaymentStatus::Pending,
            ]);

            // PagoMovilDetail stores the BUSINESS receiving account
            $payment->pagoMovilDetail()->create([
                'phone' => config('payment.pago_movil.phone'),
                'bank' => config('payment.pago_movil.bank'),
                'rif' => config('payment.pago_movil.rif'),
            ]);

            return $payment;
        });
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
            'amount' => $payment->amount_cents / 100,
        ];
    }
}
