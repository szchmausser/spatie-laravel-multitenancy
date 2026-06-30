<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\CancellationType;
use App\Events\PaymentCancelled;
use App\Events\PaymentVerified;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * List all payments with tenant, order, and payment details.
     *
     * Supports optional status and date-range filtering.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,verified,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Payment::with([
            'tenant',
            'order.plan',
            'order.resource',
            'pagoMovilDetail',
            'bankTransferDetail',
            'verifier',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $payments = $query->orderByDesc('created_at')->get();

        return inertia('admin/payments/index', [
            'payments' => $payments,
        ]);
    }

    /**
     * Verify a pending payment.
     *
     * Dispatches PaymentVerified AFTER the DB transaction has committed
     * (IC-4 compliance — PaymentService::verifyPayment does NOT dispatch events).
     */
    public function verify(Request $request, Payment $payment)
    {
        $this->paymentService->verifyPayment($payment, $request->user()->id);

        event(new PaymentVerified($payment->fresh()));

        return redirect()->route('landlord.orders.show', $payment->order);
    }

    /**
     * Cancel a verified payment with reason.
     *
     * Dispatches PaymentCancelled AFTER the DB transaction has committed
     * (IC-4 compliance — PaymentService::cancelPayment does NOT dispatch events).
     */
    public function cancel(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->paymentService->cancelPayment(
            $payment,
            CancellationType::Manual,
            $request->user()->id,
            $validated['reason'],
        );

        event(new PaymentCancelled(
            $payment->fresh(),
            CancellationType::Manual,
            $validated['reason'],
        ));

        return redirect()->route('landlord.orders.show', $payment->order);
    }
}
