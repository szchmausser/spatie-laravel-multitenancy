<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * List all payments with optional filters.
     */
    public function index(Request $request)
    {
        $query = Payment::with(['order.plan', 'order.resource', 'tenant', 'pagoMovilDetail']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        $payments = $query->latest()->get();

        return Inertia::render('admin/payments/index', [
            'payments' => $payments,
        ]);
    }

    /**
     * Show payment detail with pago movil info.
     */
    public function show(Payment $payment)
    {
        $payment->load(['order.plan', 'order.resource', 'tenant', 'pagoMovilDetail', 'verifier']);

        return Inertia::render('admin/payments/show', [
            'payment' => $payment,
        ]);
    }

    /**
     * Verify a pending payment.
     */
    public function verify(Request $request, Payment $payment)
    {
        $this->paymentService->verifyPayment($payment, $request->user()->id);

        return redirect()->route('landlord.payments.show', $payment);
    }

    /**
     * Cancel a verified payment with reason.
     */
    public function cancel(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->paymentService->cancelPayment($payment, $validated['reason'], $request->user()->id);

        return redirect()->route('landlord.payments.show', $payment);
    }
}
