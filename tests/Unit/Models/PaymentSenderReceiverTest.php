<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

test('payment accepts payment_method_config_id via mass assignment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'currency' => 'VES',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $config->id,
        'status' => 'pending',
    ]);

    expect($payment->payment_method_config_id)->toBe($config->id);
});

test('payment with null payment_method_config_id creates successfully', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'currency' => 'VES',
        'payment_method' => 'pago_movil',
        'status' => 'pending',
    ]);

    expect($payment->payment_method_config_id)->toBeNull();
});

test('payment paymentMethodConfig relationship returns config when set', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'currency' => 'VES',
        'payment_method' => 'pago_movil',
        'payment_method_config_id' => $config->id,
        'status' => 'pending',
    ]);

    $loaded = Payment::with('paymentMethodConfig')->find($payment->id);
    expect($loaded->paymentMethodConfig)->not->toBeNull();
    expect($loaded->paymentMethodConfig->id)->toBe($config->id);
});

test('payment paymentMethodConfig relationship returns null when not set', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'currency' => 'VES',
        'payment_method' => 'pago_movil',
        'status' => 'pending',
    ]);

    $loaded = Payment::with('paymentMethodConfig')->find($payment->id);
    expect($loaded->paymentMethodConfig)->toBeNull();
});

test('pagoMovilDetail accepts sender_id via mass assignment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'currency' => 'VES',
        'payment_method' => 'pago_movil',
        'status' => 'pending',
    ]);

    $detail = $payment->pagoMovilDetail()->create([
        'phone' => '0412-1234567',
        'bank' => 'Banco de Venezuela',
        'rif' => 'J-12345678-9',
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-7654321',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-14',
        'concept' => 'Test payment',
    ]);

    expect($detail->sender_id)->toBe('V-12345678');
});

test('bankTransferDetail accepts all 6 sender fields via mass assignment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 10000,
        'currency' => 'VES',
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
    ]);

    $detail = $payment->bankTransferDetail()->create([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'tenant_rif' => 'J-99999999-9',
        'payment_date' => '2026-06-14',
        'concept' => 'Transferencia plan',
    ]);

    expect($detail->sender_bank)->toBe('Banco de Venezuela');
    expect($detail->sender_name)->toBe('Juan Perez');
    expect($detail->sender_id)->toBe('V-87654321');
    expect($detail->tenant_rif)->toBe('J-99999999-9');
    expect($detail->payment_date->format('Y-m-d'))->toBe('2026-06-14');
    expect($detail->concept)->toBe('Transferencia plan');
});

test('bankTransferDetail payment_date is cast to Carbon', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 10000,
        'currency' => 'VES',
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
    ]);

    $detail = $payment->bankTransferDetail()->create([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-14',
    ]);

    expect($detail->payment_date)->toBeInstanceOf(CarbonImmutable::class);
});

test('bankTransferDetail accepts null tenant_rif and concept', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 10000,
        'currency' => 'VES',
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
    ]);

    $detail = $payment->bankTransferDetail()->create([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-14',
    ]);

    expect($detail->tenant_rif)->toBeNull();
    expect($detail->concept)->toBeNull();
});
