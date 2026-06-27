<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

beforeEach(function () {
    // Point tenant connection to the same DB as landlord for testing
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    // Disable CSRF and tenant middlewares for HTTP tests
    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    // Create tenant and user
    $this->tenant = Tenant::factory()->createQuietly();
    $this->plan = Plan::factory()->createQuietly(['price_cents' => 10000]);
    $this->user = User::factory()->createQuietly();
    $this->tenant->makeCurrent();

    $this->actingAs($this->user);

    // Create PaymentMethodConfig for pago_movil
    $this->pagoMovilConfig = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();

    // Default sender fields for pago_movil tests
    $this->senderData = [
        'amount_cents' => $this->plan->price_cents,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => now()->format('Y-m-d'),
        'concept' => 'Test payment',
    ];
});

test('tenant can report payment reference for a pending order', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $response->assertRedirect();

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->transaction_id)->toBe('1234567');
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->payment_method)->toBe('pago_movil');
});

test('tenant cannot report duplicate reference for same tenant', function () {
    $order1 = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $order2 = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    // First payment with reference 1234567
    $this->post(route('billing.orders.payments.store', $order1), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    // Second payment with same reference should fail
    $response = $this->post(route('billing.orders.payments.store', $order2), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $response->assertSessionHasErrors('reference');
});

test('tenant cannot report duplicate reference from another tenant', function () {
    $otherTenant = Tenant::factory()->createQuietly();
    $otherPlan = Plan::factory()->createQuietly();

    // Other tenant creates order and pays with reference 1234567
    $otherOrder = Order::factory()->createQuietly([
        'tenant_id' => $otherTenant->id,
        'plan_id' => $otherPlan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $otherPlan->price_cents,
    ]);

    Payment::factory()->createQuietly([
        'tenant_id' => $otherTenant->id,
        'order_id' => $otherOrder->id,
        'transaction_id' => '1234567',
        'status' => PaymentStatus::Pending,
    ]);

    // Current tenant tries to use same reference
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $response->assertSessionHasErrors('reference');
});

test('tenant cannot report payment for non-pending order', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Paid,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $response->assertSessionHasErrors('order_id');
});

test('tenant cannot report payment for expired order', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Expired,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $response->assertSessionHasErrors('order_id');
});

test('reference must be numeric', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => 'abc123',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $response->assertSessionHasErrors('reference');
});

test('reference must be between 6 and 10 digits', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    // Too short
    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '12345',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));
    $response->assertSessionHasErrors('reference');

    // Too long
    $response = $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '12345678901',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));
    $response->assertSessionHasErrors('reference');
});

test('payment_method is required', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
    ]);

    $response->assertSessionHasErrors('payment_method');
});

test('payment_method must be a valid gateway', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
        'payment_method' => 'invalid_method',
    ]);

    $response->assertSessionHasErrors('payment_method');
});

test('payment is created with the correct payment method', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment->payment_method)->toBe('pago_movil');
});

test('bank_transfer payment uses config from payment_method_config_id', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'is_active' => true,
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '1234567',
        'payment_method' => 'bank_transfer',
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->payment_method)->toBe('bank_transfer');

    $detail = $payment->bankTransferDetail;
    expect($detail)->not->toBeNull();
    expect($detail->account_number)->toBe($config->account_number);
    expect($detail->bank_name)->toBe($config->bank_name);
    expect($detail->account_holder)->toBe($config->account_holder);
    expect($detail->holder_id)->toBe($config->holder_id);
});

test('pago_movil payment uses config from payment_method_config_id when provided', function () {
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly([
        'is_active' => true,
        'account_number' => '04149876543',
        'bank_name' => 'Banco Mercantil',
        'holder_id' => 'J-99999999-9',
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $config->id,
    ], $this->senderData));

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->payment_method)->toBe('pago_movil');

    $detail = $payment->pagoMovilDetail;
    expect($detail)->not->toBeNull();
    expect($detail->phone)->toBe($config->account_number);
    expect($detail->bank)->toBe($config->bank_name);
    expect($detail->rif)->toBe($config->holder_id);
    expect($detail->sender_bank)->toBe($this->senderData['sender_bank']);
    expect($detail->sender_phone)->toBe($this->senderData['sender_phone']);
    expect($detail->payment_date->format('Y-m-d'))->toBe($this->senderData['payment_date']);
    expect($detail->concept)->toBe($this->senderData['concept']);
});

test('pago_movil payment uses PaymentMethodConfig', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $payment = Payment::where('order_id', $order->id)->first();
    $detail = $payment->pagoMovilDetail;
    expect($detail)->not->toBeNull();
    expect($detail->phone)->toBe($this->pagoMovilConfig->account_number);
    expect($detail->bank)->toBe($this->pagoMovilConfig->bank_name);
    expect($detail->rif)->toBe($this->pagoMovilConfig->holder_id);
    expect($detail->sender_bank)->toBe($this->senderData['sender_bank']);
    expect($detail->sender_phone)->toBe($this->senderData['sender_phone']);
});

test('idempotency returns existing payment when same method used', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $firstPayment = Payment::where('order_id', $order->id)->first();

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '7654321',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $secondPayment = Payment::where('order_id', $order->id)->first();
    expect($firstPayment->id)->toBe($secondPayment->id);
});

test('idempotency cancels old payment when method changes', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'is_active' => true,
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $this->senderData));

    $oldPayment = Payment::where('order_id', $order->id)->first();
    expect($oldPayment->payment_method)->toBe('pago_movil');

    $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '7654321',
        'payment_method' => 'bank_transfer',
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    // Old payment should be cancelled
    $oldPayment->refresh();
    expect($oldPayment->status)->toBe(PaymentStatus::Cancelled);

    // New payment should exist with bank_transfer
    $newPayment = Payment::where('order_id', $order->id)
        ->where('status', PaymentStatus::Pending)
        ->first();
    expect($newPayment)->not->toBeNull();
    expect($newPayment->payment_method)->toBe('bank_transfer');
    expect($newPayment->id)->not->toBe($oldPayment->id);
});

test('sender_bank is required for pago_movil', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
        'sender_phone' => '0414-1234567',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('sender_bank');
});

test('sender_phone is required for pago_movil', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
        'sender_bank' => 'Banco Mercantil',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('sender_phone');
});

test('payment_date is required for pago_movil', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
    ]);

    $response->assertSessionHasErrors('payment_date');
});

test('payment_date cannot be in the future for pago_movil', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), array_merge(
        $this->senderData,
        [
            'reference' => '1234567',
            'payment_method' => 'pago_movil',
            'payment_method_config_id' => $this->pagoMovilConfig->id,
            'payment_date' => now()->addDay()->format('Y-m-d'),
        ],
    ));

    $response->assertSessionHasErrors('payment_date');
});

test('pago_movil payment is created with correct sender fields', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $senderData = [
        'amount_cents' => $order->total_cents,
        'sender_bank' => 'Banesco',
        'sender_phone' => '0424-9876543',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-10',
        'concept' => 'Pago plan mensual',
    ];

    $this->post(route('billing.orders.payments.store', $order), array_merge([
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
    ], $senderData));

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();

    $detail = $payment->pagoMovilDetail;
    expect($detail)->not->toBeNull();
    expect($detail->sender_bank)->toBe('Banesco');
    expect($detail->sender_phone)->toBe('0424-9876543');
    expect($detail->payment_date->format('Y-m-d'))->toBe('2026-06-10');
    expect($detail->concept)->toBe('Pago plan mensual');
});

test('concept is optional for pago_movil', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $this->pagoMovilConfig->id,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();

    $detail = $payment->pagoMovilDetail;
    expect($detail)->not->toBeNull();
    expect($detail->concept)->toBeNull();
});

test('bank_transfer requires sender fields', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'is_active' => true,
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
        'payment_method' => 'bank_transfer',
        'payment_method_config_id' => $config->id,
    ]);

    $response->assertSessionHasErrors(['sender_bank', 'sender_name', 'sender_id', 'payment_date']);
});
