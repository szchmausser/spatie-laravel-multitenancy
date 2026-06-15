<?php

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PagoMovilDetail;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\PagoMovilGateway;

beforeEach(function () {
    $this->gateway = new PagoMovilGateway;
});

test('gateway records payment with pago movil detail atomically', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
    ]);

    $payment = $this->gateway->recordPayment($order, [
        'amount_cents' => 5000,
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '0412-7654321',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-13',
        'concept' => 'Pago plan premium',
    ]);

    expect($payment)->toBeInstanceOf(Payment::class);
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->payment_method)->toBe('pago_movil');
    expect($payment->amount_cents)->toBe(5000);
    expect($payment->currency)->toBe('VES');

    $detail = PagoMovilDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->phone)->toBe(config('payment.pago_movil.phone'));
    expect($detail->bank)->toBe(config('payment.pago_movil.bank'));
    expect($detail->rif)->toBe(config('payment.pago_movil.rif'));
    expect($detail->sender_bank)->toBe('Banco de Venezuela');
    expect($detail->sender_phone)->toBe('0412-7654321');
    expect($detail->sender_id)->toBe('V-12345678');
    expect($detail->payment_date->format('Y-m-d'))->toBe('2026-06-13');
    expect($detail->concept)->toBe('Pago plan premium');
});

test('gateway records payment with config_id on payment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
    ]);

    $payment = $this->gateway->recordPayment($order, [
        'amount_cents' => 5000,
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '0412-7654321',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-13',
    ]);

    expect($payment->payment_method_config_id)->toBe($config->id);
});

test('gateway creates both payment and detail in transaction', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = $this->gateway->recordPayment($order, [
        'amount_cents' => 1000,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-13',
    ]);

    // Both should exist
    expect(Payment::where('id', $payment->id)->exists())->toBeTrue();
    expect(PagoMovilDetail::where('payment_id', $payment->id)->exists())->toBeTrue();
});

test('gateway returns correct instructions', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = $this->gateway->recordPayment($order, [
        'amount_cents' => 2500,
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '0412-7654321',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-13',
    ]);

    $instructions = $this->gateway->getInstructions($payment);

    expect($instructions['type'])->toBe('pago_movil');
    expect($instructions['title'])->toBe('Pago Móvil');
    expect($instructions['amount'])->toBe(2500);
    expect($instructions['fields'])->toHaveCount(3);
    expect($instructions['fields'][0]['label'])->toBe('Teléfono');
    expect($instructions['fields'][0]['value'])->toBe(config('payment.pago_movil.phone'));
});

test('gateway payment belongs to correct order and tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $payment = $this->gateway->recordPayment($order, [
        'amount_cents' => 1000,
        'sender_bank' => 'Banco Provincial',
        'sender_phone' => '0424-9876543',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-12',
    ]);

    expect($payment->order_id)->toBe($order->id);
    expect($payment->tenant_id)->toBe($tenant->id);
});
