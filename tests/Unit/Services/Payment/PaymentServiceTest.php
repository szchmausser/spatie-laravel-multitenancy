<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentVerified;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use App\Services\Payment\BankTransferGateway;
use App\Services\Payment\PagoMovilGateway;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->service = new PaymentService(['pago_movil' => new PagoMovilGateway]);
});

test('create order with plan cancels previous pending plan orders', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan1 = Plan::factory()->createQuietly();
    $plan2 = Plan::factory()->createQuietly();

    // Previous pending plan order
    $previousOrder = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan1->id,
        'status' => OrderStatus::Pending,
    ]);

    $result = $this->service->createOrder(
        tenantId: $tenant->id,
        buyableType: 'plan',
        buyableId: $plan2->id,
        totalCents: $plan2->price_cents,
        paymentData: [
            'amount_cents' => $plan2->price_cents,
            'phone' => '0412-1234567',
            'bank' => 'Banco de Venezuela',
            'rif' => 'J-12345678-9',
        ],
    );

    $previousOrder->refresh();
    expect($previousOrder->status)->toBe(OrderStatus::Cancelled);
    expect($result['order'])->toBeInstanceOf(Order::class);
    expect($result['order']->plan_id)->toBe($plan2->id);
    expect($result['order']->status)->toBe(OrderStatus::Pending);
});

test('create order with resource allows multiple pending orders', function () {
    $tenant = Tenant::factory()->createQuietly();
    $resource1 = Resource::factory()->createQuietly();
    $resource2 = Resource::factory()->createQuietly();

    // First pending resource order
    Order::factory()->forResource()->createQuietly([
        'tenant_id' => $tenant->id,
        'resource_id' => $resource1->id,
        'status' => OrderStatus::Pending,
    ]);

    $result = $this->service->createOrder(
        tenantId: $tenant->id,
        buyableType: 'resource',
        buyableId: $resource2->id,
        totalCents: $resource2->price_cents,
        paymentData: [
            'amount_cents' => $resource2->price_cents,
            'phone' => '0412-1234567',
            'bank' => 'Banco de Venezuela',
            'rif' => 'J-12345678-9',
        ],
    );

    // Both orders should still be pending
    $pendingOrders = Order::where('tenant_id', $tenant->id)
        ->where('status', OrderStatus::Pending)
        ->count();

    expect($pendingOrders)->toBe(2);
    expect($result['order']->resource_id)->toBe($resource2->id);
});

test('create order does not create payment (payment created on reference submit)', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $result = $this->service->createOrder(
        tenantId: $tenant->id,
        buyableType: 'plan',
        buyableId: $plan->id,
        totalCents: 5000,
        paymentData: [
            'amount_cents' => 5000,
        ],
    );

    expect($result['order'])->toBeInstanceOf(Order::class);
    expect($result['order']->total_cents)->toBe(5000);
    // No payment should exist yet — it's created when user submits reference
    expect($result['order']->payments()->count())->toBe(0);
});

test('verify payment updates status and fires event', function () {
    Event::fake([PaymentVerified::class]);

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);
    $admin = Landlord::factory()->createQuietly();

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Pending,
    ]);

    $this->service->verifyPayment($payment, $admin->id);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Verified);
    expect($payment->verified_by)->toBe($admin->id);
    expect($payment->verified_at)->not->toBeNull();

    Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $e) => $e->payment->id === $payment->id);
});

test('verify payment rejects non-pending payment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);
    $admin = Landlord::factory()->createQuietly();

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Verified,
    ]);

    $this->expectException(HttpException::class);

    $this->service->verifyPayment($payment, $admin->id);
});

test('cancel payment updates status and recalculates order', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
        'status' => OrderStatus::Paid,
    ]);
    $admin = Landlord::factory()->createQuietly();

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Verified,
    ]);

    $this->service->cancelPayment($payment, 'Fraud detected', $admin->id);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled);
    expect($payment->cancellation_reason)->toBe('Fraud detected');
    expect($payment->cancelled_by)->toBe($admin->id);

    // Order should revert to pending since it's no longer fully paid
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Pending);
});

test('cancel payment rejects already-cancelled payment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);
    $admin = Landlord::factory()->createQuietly();

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Cancelled,
    ]);

    $this->expectException(HttpException::class);

    $this->service->cancelPayment($payment, 'Test', $admin->id);
});

test('cancel pending payment rejects and keeps order pending', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
        'status' => OrderStatus::Pending,
    ]);
    $admin = Landlord::factory()->createQuietly();

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Pending,
    ]);

    $this->service->cancelPayment($payment, 'Referencia inválida', $admin->id);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled);
    expect($payment->cancellation_reason)->toBe('Referencia inválida');
    expect($payment->cancelled_by)->toBe($admin->id);

    // Order should stay pending (it was never paid)
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Pending);
});

test('resolve gateway returns correct gateway for pago_movil', function () {
    $service = new PaymentService([
        'pago_movil' => new PagoMovilGateway,
        'bank_transfer' => new BankTransferGateway,
    ]);

    $gateway = $service->resolveGateway('pago_movil');

    expect($gateway)->toBeInstanceOf(PagoMovilGateway::class);
});

test('resolve gateway returns correct gateway for bank_transfer', function () {
    $service = new PaymentService([
        'pago_movil' => new PagoMovilGateway,
        'bank_transfer' => new BankTransferGateway,
    ]);

    $gateway = $service->resolveGateway('bank_transfer');

    expect($gateway)->toBeInstanceOf(BankTransferGateway::class);
});

test('resolve gateway throws exception for unknown method', function () {
    $service = new PaymentService([
        'pago_movil' => new PagoMovilGateway,
    ]);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown payment method: paypal');

    $service->resolveGateway('paypal');
});

test('record payment uses correct gateway based on order payment method', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $service = new PaymentService([
        'pago_movil' => new PagoMovilGateway,
        'bank_transfer' => new BankTransferGateway,
    ]);

    // Order has no payment_method set yet, defaults to pago_movil
    $payment = $service->recordPayment($order, 5000, 'pago_movil', null, [
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '0412-7654321',
        'payment_date' => '2026-06-13',
    ]);

    expect($payment->payment_method)->toBe('pago_movil');
});
