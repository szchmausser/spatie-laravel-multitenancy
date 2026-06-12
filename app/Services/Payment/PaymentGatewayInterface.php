<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Record a payment for an order.
     *
     * Creates the payment record (and any payment-method-specific detail)
     * in a single transaction.
     *
     * @param  array<string, mixed>  $data  Payment-method-specific data
     */
    public function recordPayment(Order $order, array $data): Payment;

    /**
     * Get display instructions for this payment method.
     *
     * Returns an array with human-readable fields the frontend
     * can render (e.g. phone number, bank, RIF for Pago Móvil).
     */
    public function getInstructions(Payment $payment): array;
}
