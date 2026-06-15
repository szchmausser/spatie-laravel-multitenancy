<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
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
                $query->orderByDesc('created_at')
                    ->with('pagoMovilDetail', 'bankTransferDetail');
            }, 'plan', 'resource'])
            ->oldest()
            ->get();

        return Inertia::render('billing/orders/index', [
            'orders' => $orders,
        ]);
    }

    /**
     * Show order detail with payment info.
     *
     * Passes payment method configs so the frontend can show
     * available payment methods for the tenant to choose.
     */
    public function show(Order $order)
    {
        $order->load(['payments' => function ($q) {
            $q->orderByDesc('created_at');
        }, 'payments.pagoMovilDetail', 'payments.bankTransferDetail', 'plan', 'resource']);

        $paymentMethodConfigs = PaymentMethodConfig::active()
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('billing/orders/show', [
            'order' => $order,
            'paymentConfig' => config('payment.pago_movil'),
            'paymentMethodConfigs' => $paymentMethodConfigs,
        ]);
    }

    /**
     * Report a payment reference against an existing order.
     *
     * Validates:
     * - Order must be in pending status
     * - Reference must be unique (same tenant and cross-tenant)
     * - Reference format: 6-10 digits
     */
    public function store(Request $request, Order $order)
    {
        // Validate order is in a state that accepts payments
        if ($order->status !== OrderStatus::Pending) {
            return back()->withErrors([
                'order_id' => 'Solo se pueden reportar pagos para órdenes pendientes.',
            ]);
        }

        $validated = $request->validate([
            'amount_cents' => [
                'required',
                'integer',
                'min:1',
                'max:'.$order->remaining_cents,
            ],
            'reference' => [
                'required',
                'string',
                'digits_between:6,10',
                function ($attribute, $value, $fail) {
                    $exists = Payment::where('transaction_id', $value)->exists();
                    if ($exists) {
                        $fail('Esta referencia ya ha sido registrada por otro pago.');
                    }
                },
            ],
            'payment_method' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        $this->paymentService->resolveGateway($value);
                    } catch (\InvalidArgumentException) {
                        $fail('Método de pago no válido.');
                    }
                },
            ],
            'payment_method_config_id' => [
                'nullable',
                'integer',
                'exists:payment_method_configs,id',
            ],
            'sender_bank' => [
                ...in_array($request->input('payment_method'), ['pago_movil', 'bank_transfer'])
                    ? ['required', 'string', 'max:100']
                    : ['nullable', 'string', 'max:100'],
            ],
            'sender_phone' => [
                ...($request->input('payment_method') === 'pago_movil'
                    ? ['required', 'string', 'max:20']
                    : ['nullable', 'string', 'max:20']),
            ],
            'sender_id' => [
                ...in_array($request->input('payment_method'), ['pago_movil', 'bank_transfer'])
                    ? ['required', 'string', 'max:20']
                    : ['nullable', 'string', 'max:20'],
            ],
            'payment_date' => [
                ...in_array($request->input('payment_method'), ['pago_movil', 'bank_transfer'])
                    ? ['required', 'date', 'before_or_equal:today']
                    : ['nullable', 'date', 'before_or_equal:today'],
            ],
            'concept' => [
                'nullable',
                'string',
                'max:255',
            ],
            'sender_name' => [
                ...($request->input('payment_method') === 'bank_transfer'
                    ? ['required', 'string', 'max:100']
                    : ['nullable', 'string', 'max:100']),
            ],
            'sender_account_number' => [
                ...($request->input('payment_method') === 'bank_transfer'
                    ? ['nullable', 'string', 'max:20']
                    : ['nullable', 'string', 'max:20']),
            ],
            'tenant_rif' => [
                'nullable',
                'string',
                'max:20',
            ],
        ]);

        // Build gateway-specific data (sender fields for each method)
        $gatewayData = [];
        if ($validated['payment_method'] === 'pago_movil') {
            $gatewayData = [
                'sender_bank' => $validated['sender_bank'],
                'sender_phone' => $validated['sender_phone'],
                'sender_id' => $validated['sender_id'],
                'payment_date' => $validated['payment_date'],
                'concept' => $validated['concept'] ?? null,
            ];
        } elseif ($validated['payment_method'] === 'bank_transfer') {
            $gatewayData = [
                'sender_bank' => $validated['sender_bank'],
                'sender_name' => $validated['sender_name'],
                'sender_id' => $validated['sender_id'],
                'sender_account_number' => $validated['sender_account_number'] ?? null,
                'tenant_rif' => $validated['tenant_rif'] ?? null,
                'payment_date' => $validated['payment_date'],
                'concept' => $validated['concept'] ?? null,
            ];
        }

        // Record payment against the EXISTING order (not a new one)
        $payment = $this->paymentService->recordPayment(
            $order,
            $validated['amount_cents'],
            $validated['payment_method'],
            $validated['payment_method_config_id'] ?? null,
            $gatewayData,
        );

        // Store the reference
        $payment->update(['transaction_id' => $validated['reference']]);

        return redirect()->route('billing.orders.show', $order);
    }
}
