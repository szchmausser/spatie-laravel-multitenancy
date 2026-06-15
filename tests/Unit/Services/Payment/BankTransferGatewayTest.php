<?php

use App\Enums\PaymentStatus;
use App\Models\BankTransferDetail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\BankTransferGateway;

beforeEach(function () {
    $this->gateway = new BankTransferGateway;
    $this->config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
    ]);
});

test('gateway records payment with bank transfer detail atomically', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
    ]);

    $payment = $this->gateway->recordPayment($order, [
        'amount_cents' => 5000,
        'payment_method_config_id' => $this->config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'tenant_rif' => 'J-99999999-9',
        'payment_date' => '2026-06-14',
        'concept' => 'Transferencia plan',
    ]);

    expect($payment)->toBeInstanceOf(Payment::class);
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->payment_method)->toBe('bank_transfer');
    expect($payment->amount_cents)->toBe(5000);
    expect($payment->currency)->toBe('VES');
    expect($payment->payment_method_config_id)->toBe($this->config->id);

    $detail = BankTransferDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->account_number)->toBe('0134-0000-00000000');
    expect($detail->bank_name)->toBe('Banco Mercantil');
    expect($detail->account_holder)->toBe('Mi Empresa C.A.');
    expect($detail->holder_id)->toBe('J-12345678-9');
    expect($detail->sender_bank)->toBe('Banco de Venezuela');
    expect($detail->sender_name)->toBe('Juan Perez');
    expect($detail->sender_id)->toBe('V-87654321');
    expect($detail->tenant_rif)->toBe('J-99999999-9');
    expect($detail->payment_date->format('Y-m-d'))->toBe('2026-06-14');
    expect($detail->concept)->toBe('Transferencia plan');
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
        'payment_method_config_id' => $this->config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-14',
    ]);

    expect(Payment::where('id', $payment->id)->exists())->toBeTrue();
    expect(BankTransferDetail::where('payment_id', $payment->id)->exists())->toBeTrue();
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
        'payment_method_config_id' => $this->config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-14',
    ]);

    $instructions = $this->gateway->getInstructions($payment);

    expect($instructions['type'])->toBe('bank_transfer');
    expect($instructions['title'])->toBe('Transferencia Bancaria');
    expect($instructions['amount'])->toBe(2500);
    expect($instructions['fields'])->toHaveCount(4);
    expect($instructions['fields'][0]['label'])->toBe('Banco');
    expect($instructions['fields'][0]['value'])->toBe('Banco Mercantil');
    expect($instructions['fields'][1]['label'])->toBe('Cuenta');
    expect($instructions['fields'][1]['value'])->toBe('0134-0000-00000000');
    expect($instructions['fields'][2]['label'])->toBe('Titular');
    expect($instructions['fields'][2]['value'])->toBe('Mi Empresa C.A.');
    expect($instructions['fields'][3]['label'])->toBe('RIF/Cédula');
    expect($instructions['fields'][3]['value'])->toBe('J-12345678-9');
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
        'payment_method_config_id' => $this->config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-14',
    ]);

    expect($payment->order_id)->toBe($order->id);
    expect($payment->tenant_id)->toBe($tenant->id);
});
