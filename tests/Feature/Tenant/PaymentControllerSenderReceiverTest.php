<?php

use App\Enums\OrderStatus;
use App\Models\BankTransferDetail;
use App\Models\Order;
use App\Models\PagoMovilDetail;
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
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    $this->tenant = Tenant::factory()->createQuietly();
    $this->plan = Plan::factory()->createQuietly(['price_cents' => 10000]);
    $this->user = User::factory()->createQuietly();
    $this->tenant->makeCurrent();

    $this->actingAs($this->user);
});

// --- Pago Movil sender_id validation ---

test('pago_movil requires sender_id', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('sender_id');
});

test('pago_movil with sender_id creates payment successfully', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '1234567',
        'payment_method' => 'pago_movil',
        'sender_bank' => 'Banco Mercantil',
        'sender_phone' => '0414-1234567',
        'sender_id' => 'V-12345678',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertRedirect();

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();

    $detail = PagoMovilDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->sender_id)->toBe('V-12345678');
});

// --- Bank Transfer sender field validation ---

test('bank_transfer requires sender_bank', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

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
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('sender_bank');
});

test('bank_transfer requires sender_name', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

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
        'sender_bank' => 'Banco de Venezuela',
        'sender_id' => 'V-87654321',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('sender_name');
});

test('bank_transfer requires sender_id', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

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
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertSessionHasErrors('sender_id');
});

test('bank_transfer requires payment_date', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

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
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
    ]);

    $response->assertSessionHasErrors('payment_date');
});

test('bank_transfer with all sender fields creates detail correctly', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '1234567',
        'payment_method' => 'bank_transfer',
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'sender_account_number' => '01020000000000000001',
        'tenant_rif' => 'J-99999999-9',
        'payment_date' => now()->format('Y-m-d'),
        'concept' => 'Transferencia plan',
    ]);

    $response->assertRedirect();

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->payment_method_config_id)->toBe($config->id);

    $detail = BankTransferDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->sender_bank)->toBe('Banco de Venezuela');
    expect($detail->sender_name)->toBe('Juan Perez');
    expect($detail->sender_id)->toBe('V-87654321');
    expect($detail->sender_account_number)->toBe('01020000000000000001');
    expect($detail->tenant_rif)->toBe('J-99999999-9');
    expect($detail->concept)->toBe('Transferencia plan');
});

test('bank_transfer tenant_rif is nullable', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '1234567',
        'payment_method' => 'bank_transfer',
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertRedirect();

    $payment = Payment::where('order_id', $order->id)->first();
    $detail = BankTransferDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->tenant_rif)->toBeNull();
});

test('bank_transfer sender_account_number is nullable', function () {
    $config = PaymentMethodConfig::factory()->ofBankTransfer()->createQuietly(['is_active' => true]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => $this->plan->price_cents,
    ]);

    $response = $this->post(route('billing.orders.payments.store', $order), [
        'amount_cents' => $order->total_cents,
        'reference' => '1234568',
        'payment_method' => 'bank_transfer',
        'payment_method_config_id' => $config->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V-87654321',
        'payment_date' => now()->format('Y-m-d'),
    ]);

    $response->assertRedirect();

    $payment = Payment::where('order_id', $order->id)->first();
    $detail = BankTransferDetail::where('payment_id', $payment->id)->first();
    expect($detail)->not->toBeNull();
    expect($detail->sender_account_number)->toBeNull();
});
