<?php

namespace App\Http\Controllers\Billing;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\PaymentService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
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
}
