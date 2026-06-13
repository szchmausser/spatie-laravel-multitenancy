<?php

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentVerified;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\PendingPaymentCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    /**
     * Create an order for a buyable (Plan or Resource).
     *
     * Business rules:
     * - Plans: only 1 pending order at a time (cancels previous pending plan orders)
     * - Resources: allow multiple pending orders
     *
     * @param  array<string, mixed>  $paymentData  Gateway-specific payment data
     */
    public function createOrder(
        int $tenantId,
        string $buyableType,
        int $buyableId,
        int $totalCents,
        array $paymentData,
    ): array {
        return DB::transaction(function () use ($tenantId, $buyableType, $buyableId, $totalCents) {
            // Cancel previous pending plan orders if this is a plan purchase
            if ($buyableType === 'plan') {
                Order::where('tenant_id', $tenantId)
                    ->where('status', OrderStatus::Pending)
                    ->whereNotNull('plan_id')
                    ->update(['status' => OrderStatus::Cancelled]);
            }

            // Create the order
            $order = Order::create([
                'tenant_id' => $tenantId,
                'plan_id' => $buyableType === 'plan' ? $buyableId : null,
                'resource_id' => $buyableType === 'resource' ? $buyableId : null,
                'total_cents' => $totalCents,
                'status' => OrderStatus::Pending,
                'expires_at' => now()->addHours(48),
            ]);

            return [
                'order' => $order,
            ];
        });
    }

    /**
     * Record a payment against an existing order (no new order created).
     *
     * Uses the gateway to create Payment + gateway-specific detail
     * (e.g. PagoMovilDetail) atomically. The business receiving account
     * is read from config by the gateway.
     *
     * Idempotent: if a pending payment already exists for this order,
     * returns it instead of creating a duplicate. Prevents double
     * submission when the user accidentally submits the form twice.
     */
    public function recordPayment(Order $order, int $amountCents): Payment
    {
        // Check for existing pending payment — idempotency guard
        $existingPayment = $order->payments()
            ->where('status', PaymentStatus::Pending)
            ->first();

        if ($existingPayment) {
            return $existingPayment;
        }

        $payment = $this->gateway->recordPayment($order, [
            'amount_cents' => $amountCents,
        ]);

        // Notify landlord admins that a new payment needs verification
        $this->notifyLandlordAdmins($payment);

        return $payment;
    }

    /**
     * Verify a pending payment.
     *
     * Sets verified_by, verified_at, and fires PaymentVerified event.
     * The event listener (ActivateSubscription) handles order status
     * transition and subscription creation.
     */
    public function verifyPayment(Payment $payment, int $adminId): void
    {
        DB::transaction(function () use ($payment, $adminId) {
            if ($payment->status !== PaymentStatus::Pending) {
                abort(422, 'Only pending payments can be verified.');
            }

            $payment->update([
                'status' => PaymentStatus::Verified,
                'verified_by' => $adminId,
                'verified_at' => now(),
            ]);

            event(new PaymentVerified($payment));
        });
    }

    /**
     * Reject or cancel a payment.
     *
     * Two scenarios:
     *   - Pending payment → reject it (the admin determined it's invalid).
     *   - Verified payment → cancel it (refund-like, the money was already approved).
     *
     * In both cases the payment is marked as cancelled with the given reason.
     * For verified payments the order is recalculated (if no longer fully paid,
     * it reverts to pending). For pending payments the order was never paid,
     * so recalculation is a no-op.
     */
    public function cancelPayment(Payment $payment, string $reason, int $adminId): void
    {
        DB::transaction(function () use ($payment, $reason, $adminId) {
            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Verified])) {
                abort(422, 'Only pending or verified payments can be cancelled.');
            }

            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by' => $adminId,
                'cancelled_at' => now(),
            ]);

            // Recalculate order — if no longer fully paid, revert to pending
            $this->recalculateOrder($payment->order);
        });
    }

    /**
     * Recalculate the order status after a payment cancellation.
     *
     * If the order was previously paid but is no longer fully paid
     * after a cancellation, revert it to pending.
     */
    private function recalculateOrder(Order $order): void
    {
        $order->refresh();

        if (! $order->isFullyPaid() && $order->status === OrderStatus::Paid) {
            $order->update(['status' => OrderStatus::Pending]);
        }
    }

    /**
     * Notify landlord admin users that a new payment requires verification.
     *
     * Queries the landlord connection for all admin users (Landlord model)
     * and sends the PendingPaymentCreated notification.
     */
    private function notifyLandlordAdmins(Payment $payment): void
    {
        try {
            $admins = Landlord::all();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new PendingPaymentCreated($payment));
        } catch (\Throwable) {
            // Notification failure should not break payment recording
        }
    }
}
