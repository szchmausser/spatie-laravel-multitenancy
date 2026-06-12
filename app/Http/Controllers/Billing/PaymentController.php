<?php

namespace App\Http\Controllers\Billing;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    /**
     * Create a pending order for a plan change.
     *
     * Idempotent: if a pending order already exists for this
     * tenant+plan, redirect to it instead of creating a new one.
     * The Payment + PagoMovilDetail are created only when the
     * user submits the reference — not at order creation.
     */
    public function create(Plan $plan)
    {
        $tenant = Tenant::current();

        // Idempotent: redirect to existing pending order for this plan
        $existingOrder = Order::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('status', OrderStatus::Pending)
            ->first();

        if ($existingOrder) {
            return redirect()->route('billing.orders.show', $existingOrder);
        }

        $result = $this->paymentService->createOrder(
            tenantId: $tenant->id,
            buyableType: 'plan',
            buyableId: $plan->id,
            totalCents: $plan->price_cents,
            paymentData: [
                'amount_cents' => $plan->price_cents,
            ]
        );

        return redirect()->route('billing.orders.show', $result['order']);
    }

    /**
     * Store payment reference.
     *
     * Creates Payment + PagoMovilDetail via the gateway and saves
     * the user's bank reference. Accepts either payment_id (for
     * backwards compat with billing/payment form) or order_id.
     * Redirects to the payment status page.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'payment_id' => 'sometimes|exists:payments,id',
            'order_id' => 'sometimes|exists:orders,id',
            'reference' => 'required|string|digits_between:6,10',
        ]);

        if (isset($validated['payment_id'])) {
            $payment = Payment::findOrFail($validated['payment_id']);
            $payment->update(['transaction_id' => $validated['reference']]);

            return redirect()->route('billing.payment.show', $payment);
        }

        $order = Order::findOrFail($validated['order_id']);
        $payment = $this->paymentService->recordPayment($order, $order->total_cents);
        $payment->update(['transaction_id' => $validated['reference']]);

        return redirect()->route('billing.payment.show', $payment);
    }

    /**
     * Show payment status.
     */
    public function show(Payment $payment)
    {
        $payment->load(['order', 'pagoMovilDetail']);

        $instructions = $this->gateway->getInstructions($payment);

        return Inertia::render('billing/payment', [
            'order' => $payment->order,
            'payment' => $payment,
            'instructions' => $instructions,
        ]);
    }
}
