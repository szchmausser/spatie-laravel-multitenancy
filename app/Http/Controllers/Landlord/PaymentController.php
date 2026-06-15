<?php

namespace App\Http\Controllers\Landlord;

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
     * Verify a pending payment.
     */
    public function verify(Request $request, Payment $payment)
    {
        $this->paymentService->verifyPayment($payment, $request->user()->id);

        return redirect()->route('landlord.orders.show', $payment->order);
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

        return redirect()->route('landlord.orders.show', $payment->order);
    }
}
