<?php

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PagoMovilDetail;
use App\Models\Payment;
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
    ]);

    $instructions = $this->gateway->getInstructions($payment);

    expect($instructions['type'])->toBe('pago_movil');
    expect($instructions['title'])->toBe('Pago Móvil');
    expect($instructions['amount'])->toBe(25);
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
    ]);

    expect($payment->order_id)->toBe($order->id);
    expect($payment->tenant_id)->toBe($tenant->id);
});
