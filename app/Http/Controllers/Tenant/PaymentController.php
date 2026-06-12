<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
     * List the tenant's orders with payments.
     */
    public function index(Request $request)
    {
        $tenant = Tenant::current();
        $orders = Order::where('tenant_id', $tenant->id)
            ->with(['payments' => function ($query) {
                $query->with('pagoMovilDetail');
            }, 'plan', 'resource'])
            ->latest()
            ->get();

        return Inertia::render('billing/orders/index', [
            'orders' => $orders,
        ]);
    }

    /**
     * Show order detail with payment info.
     *
     * Passes the business receiving account (from config) so the
     * frontend can show payment instructions without requiring the
     * user to type data we already know.
     */
    public function show(Order $order)
    {
        $order->load(['payments.pagoMovilDetail', 'plan', 'resource']);

        return Inertia::render('billing/orders/show', [
            'order' => $order,
            'paymentConfig' => config('payment.pago_movil'),
        ]);
    }

    /**
     * Report a Pago Móvil payment against an existing order.
     *
     * Creates a new Payment + PagoMovilDetail atomically using the
     * gateway (business account from config). Only requires the
     * bank reference — no phone/bank/RIF typing needed.
     */
    public function store(Request $request, Order $order)
    {
        $validated = $request->validate([
            'reference' => 'required|string|digits_between:6,10',
        ]);

        // Record payment against the EXISTING order (not a new one)
        $payment = $this->paymentService->recordPayment(
            $order,
            $order->total_cents,
        );

        // Store the reference
        $payment->update(['transaction_id' => $validated['reference']]);

        return redirect()->route('billing.payment.show', $payment);
    }
}
