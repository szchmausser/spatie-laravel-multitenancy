<?php

use App\Models\BankTransferDetail;
use App\Models\Order;
use App\Models\PagoMovilDetail;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\BankTransferGateway;
use App\Services\Payment\PagoMovilGateway;

test('PagoMovilGateway persists payment_method_config_id on payment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $gateway = new PagoMovilGateway;
    $payment = $gateway->recordPayment($order, [
        'amount_cents' => 5000,
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-14',
        'concept' => 'Test payment',
    ]);

    expect($payment->payment_method_config_id)->toBe($config->id);
});

test('PagoMovilGateway persists sender_id on PagoMovilDetail', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $gateway = new PagoMovilGateway;
    $payment = $gateway->recordPayment($order, [
        'amount_cents' => 5000,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-14',
    ]);

    $detail = PagoMovilDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->sender_id)->toBe('V-12345678');
});

test('PagoMovilGateway works without payment_method_config_id', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $gateway = new PagoMovilGateway;
    $payment = $gateway->recordPayment($order, [
        'amount_cents' => 5000,
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => '2026-06-14',
    ]);

    expect($payment->payment_method_config_id)->toBeNull();
});

test('BankTransferGateway persists payment_method_config_id on payment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
    ]);
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $gateway = new BankTransferGateway;
    $payment = $gateway->recordPayment($order, [
        'amount_cents' => 10000,
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'tenant_rif' => 'J-99999999-9',
        'payment_date' => '2026-06-14',
        'concept' => 'Transferencia plan',
    ]);

    expect($payment->payment_method_config_id)->toBe($config->id);
});

test('BankTransferGateway persists all 6 sender fields on BankTransferDetail', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
    ]);
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $gateway = new BankTransferGateway;
    $payment = $gateway->recordPayment($order, [
        'amount_cents' => 10000,
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'tenant_rif' => 'J-99999999-9',
        'payment_date' => '2026-06-14',
        'concept' => 'Transferencia plan',
    ]);

    $detail = BankTransferDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->sender_bank)->toBe('Banco de Venezuela');
    expect($detail->sender_name)->toBe('Juan Perez');
    expect($detail->sender_id)->toBe('V-87654321');
    expect($detail->tenant_rif)->toBe('J-99999999-9');
    expect($detail->payment_date->format('Y-m-d'))->toBe('2026-06-14');
    expect($detail->concept)->toBe('Transferencia plan');
});

test('BankTransferGateway tenant_rif and concept are nullable', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
    ]);
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $gateway = new BankTransferGateway;
    $payment = $gateway->recordPayment($order, [
        'amount_cents' => 10000,
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => '2026-06-14',
    ]);

    $detail = BankTransferDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->tenant_rif)->toBeNull();
    expect($detail->concept)->toBeNull();
});
