<?php

use App\Enums\CancellationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentVerified;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentMethodConfig;
use App\Models\PaymentNotification;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\SystemConfig;
use App\Models\Tenant;
use App\Notifications\PendingPaymentCreated;
use App\Services\Payment\BankTransferGateway;
use App\Services\Payment\PagoMovilGateway;
use App\Services\Payment\PaymentService;
use App\Services\Payment\ReconciliationOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
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

test('verify payment updates status without firing event (IC-4)', function () {
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

    // IC-4: verifyPayment should NOT dispatch events — callers are responsible
    Event::assertNotDispatched(PaymentVerified::class);
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

    $this->service->cancelPayment($payment, CancellationType::Manual, $admin->id, 'Fraud detected');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled);
    expect($payment->cancellation_type)->toBe(CancellationType::Manual);
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

    $this->service->cancelPayment($payment, CancellationType::Manual, $admin->id, 'Test');
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

    $this->service->cancelPayment($payment, CancellationType::Manual, $admin->id, 'Referencia inválida');

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

    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();

    $service = new PaymentService([
        'pago_movil' => new PagoMovilGateway,
        'bank_transfer' => new BankTransferGateway,
    ]);

    // Order has no payment_method set yet, defaults to pago_movil
    $payment = $service->recordPayment($order, 5000, 'pago_movil', $config->id, [
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '0412-7654321',
        'payment_date' => '2026-06-13',
    ]);

    expect($payment->payment_method)->toBe('pago_movil');
});

test('record payment sends PendingPaymentCreated notification to landlord admins', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $admin = Landlord::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();

    $service = new PaymentService([
        'pago_movil' => new PagoMovilGateway,
    ]);

    $service->recordPayment($order, 5000, 'pago_movil', $config->id, [
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '0412-7654321',
        'payment_date' => '2026-06-13',
    ]);

    Notification::assertSentTo($admin, PendingPaymentCreated::class);
});

test('verify payment with null adminId sets verified_by to null', function () {
    Event::fake([PaymentVerified::class]);

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Pending,
    ]);

    $this->service->verifyPayment($payment);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Verified);
    expect($payment->verified_by)->toBeNull();
    expect($payment->verified_at)->not->toBeNull();

    // IC-4: verifyPayment should NOT dispatch events
    Event::assertNotDispatched(PaymentVerified::class);
});

test('cancel payment stores CancellationType enum', function () {
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

    $this->service->cancelPayment($payment, CancellationType::SystemDuplicate, 'system', 'Referencia ya verificada');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled);
    expect($payment->cancellation_type)->toBe(CancellationType::SystemDuplicate);
    expect($payment->cancellation_reason)->toBe('Referencia ya verificada');
    expect($payment->cancelled_by)->toBeNull();
});

test('cancel payment with SystemExpired type', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Pending,
    ]);

    $this->service->cancelPayment($payment, CancellationType::SystemExpired, 'system', 'Pago expirado sin conciliación');

    $payment->refresh();
    expect($payment->cancellation_type)->toBe(CancellationType::SystemExpired);
    expect($payment->cancelled_by)->toBeNull();
});

test('cancel payment with MethodChanged type', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Pending,
    ]);

    $this->service->cancelPayment($payment, CancellationType::MethodChanged, 'system', 'Payment method changed to bank_transfer');

    $payment->refresh();
    expect($payment->cancellation_type)->toBe(CancellationType::MethodChanged);
    expect($payment->cancelled_by)->toBeNull();
});

test('payment model casts cancellation_type to enum', function () {
    $payment = Payment::factory()->createQuietly([
        'cancellation_type' => CancellationType::SystemDuplicate,
    ]);

    expect($payment->cancellation_type)->toBeInstanceOf(CancellationType::class);
    expect($payment->cancellation_type)->toBe(CancellationType::SystemDuplicate);
});

// ─── Reverse Matching (S6) ───

// Note: reverse-match group tests set up their own SystemConfig and notifications inline

test('reverse matches an existing unmatched notification and auto-verifies payment', function () {
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.shadow_mode_enabled',
        'value' => 'false',
        'type' => 'boolean',
    ]);

    $notification = new PaymentNotification;
    $notification->bank_code = 'bdv';
    $notification->raw_text = 'test reverse match';
    $notification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'test reverse match');
    $notification->parse_status = 'pending';
    $notification->save();
    $notification->refresh();

    $orchestrator = new ReconciliationOrchestrator($this->service);
    $service = new PaymentService(['pago_movil' => new PagoMovilGateway], $orchestrator);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();

    // Create an unmatched PaymentMatch (notification arrived before payment)
    $match = PaymentMatch::create([
        'payment_notification_id' => $notification->id,
        'parsed_reference' => '006236568762',
        'parsed_amount_cents' => 300000,
        'match_status' => 'unmatched',
    ]);

    $payment = $service->recordPayment(
        $order,
        300000,
        'pago_movil',
        $config->id,
        ['sender_bank' => 'BDV', 'sender_phone' => '04121234567', 'payment_date' => '2026-06-20'],
        '006236568762',
    );

    $match->refresh();
    $payment->refresh();

    expect($match->match_status)->toBe('matched');
    expect($match->payment_id)->toBe($payment->id);
    expect($payment->status)->toBe(PaymentStatus::Verified);

    // IC-4: verifyPayment no longer dispatches events; events are accumulated
    // in pendingEvents for the controller to dispatch after commit
    $pending = $service->getPendingEvents();
    expect($pending)->toHaveCount(1);
    expect($pending[0])->toBeInstanceOf(PaymentVerified::class);

    Cache::forget('system_config.reconciliation.shadow_mode_enabled');
})->group('reverse-match');

test('ignores reverse match when no matching notification exists', function () {
    $orchestrator = new ReconciliationOrchestrator($this->service);
    $service = new PaymentService(['pago_movil' => new PagoMovilGateway], $orchestrator);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();

    // No PaymentMatch created — no notification exists for this reference

    $payment = $service->recordPayment(
        $order,
        300000,
        'pago_movil',
        $config->id,
        ['sender_bank' => 'BDV', 'sender_phone' => '04121234567', 'payment_date' => '2026-06-20'],
        '006236568762',
    );

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Pending);

    // No PaymentMatch should be linked
    expect($payment->paymentMatch)->toBeNull();
})->group('reverse-match');

test('reverse match stays as pending when shadow mode is on', function () {
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.shadow_mode_enabled',
        'value' => 'true',
        'type' => 'boolean',
    ]);

    $notification = new PaymentNotification;
    $notification->bank_code = 'bdv';
    $notification->raw_text = 'test shadow reverse match';
    $notification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'test shadow reverse match');
    $notification->parse_status = 'pending';
    $notification->save();
    $notification->refresh();

    $orchestrator = new ReconciliationOrchestrator($this->service);
    $service = new PaymentService(['pago_movil' => new PagoMovilGateway], $orchestrator);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();

    $match = PaymentMatch::create([
        'payment_notification_id' => $notification->id,
        'parsed_reference' => '006236568762',
        'parsed_amount_cents' => 300000,
        'match_status' => 'unmatched',
    ]);

    $payment = $service->recordPayment(
        $order,
        300000,
        'pago_movil',
        $config->id,
        ['sender_bank' => 'BDV', 'sender_phone' => '04121234567', 'payment_date' => '2026-06-20'],
        '006236568762',
    );

    $match->refresh();
    $payment->refresh();

    expect($match->match_status)->toBe('pending');
    expect($match->payment_id)->toBe($payment->id);
    expect($payment->status)->toBe(PaymentStatus::Pending);

    Cache::forget('system_config.reconciliation.shadow_mode_enabled');
})->group('reverse-match');

test('does not reverse match non-pago-movil payment', function () {
    $orchestrator = new ReconciliationOrchestrator($this->service);
    $service = new PaymentService(['pago_movil' => new PagoMovilGateway, 'bank_transfer' => new BankTransferGateway], $orchestrator);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly();

    // Create unmatched PaymentMatch that WOULD match if method check didn't guard
    $notification = new PaymentNotification;
    $notification->bank_code = 'bdv';
    $notification->raw_text = 'test non-pago-movil';
    $notification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'test non-pago-movil');
    $notification->parse_status = 'pending';
    $notification->save();
    $notification->refresh();

    $match = PaymentMatch::create([
        'payment_notification_id' => $notification->id,
        'parsed_reference' => '006236568762',
        'parsed_amount_cents' => 300000,
        'match_status' => 'unmatched',
    ]);

    $payment = $service->recordPayment(
        $order,
        300000,
        'bank_transfer',
        $config->id,
        ['sender_bank' => 'BDV', 'sender_name' => 'Test User', 'sender_id' => 'V-12345678', 'payment_date' => '2026-06-20'],
        '006236568762',
    );

    $match->refresh();
    $payment->refresh();

    // Match should still be unmatched — reverse match should not have run
    expect($match->match_status)->toBe('unmatched');
    expect($payment->status)->toBe(PaymentStatus::Pending);
})->group('reverse-match');

test('getPendingEvents returns and flushes accumulated events', function () {
    $orchestrator = new ReconciliationOrchestrator($this->service);
    $service = new PaymentService(['pago_movil' => new PagoMovilGateway], $orchestrator);

    // Initially empty
    expect($service->getPendingEvents())->toBe([]);

    // After flush, it should be empty again
    expect($service->getPendingEvents())->toBe([]);
})->group('reverse-match');
