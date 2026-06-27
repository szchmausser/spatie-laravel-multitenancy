<?php

use App\Enums\CancellationType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Notifications\PaymentRejected;

it('returns database channel', function () {
    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->createQuietly(['order_id' => $order->id, 'tenant_id' => $tenant->id]);

    $notification = new PaymentRejected($payment, CancellationType::SystemExpired);

    expect($notification->via($payment))->toBe(['database']);
});

it('includes cancellation type and message for SystemExpired', function () {
    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->createQuietly(['order_id' => $order->id, 'tenant_id' => $tenant->id]);

    $notification = new PaymentRejected($payment, CancellationType::SystemExpired);
    $data = $notification->toArray($payment);

    expect($data)->toHaveKey('payment_id');
    expect($data)->toHaveKey('cancellation_type');
    expect($data['cancellation_type'])->toBe(CancellationType::SystemExpired->value);
    expect($data)->toHaveKey('message');
    expect($data['message'])->toContain('expir');
});

it('includes correct message for each CancellationType', function (CancellationType $type, string $expectedKeyword) {
    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->createQuietly(['order_id' => $order->id, 'tenant_id' => $tenant->id]);

    $notification = new PaymentRejected($payment, $type);
    $data = $notification->toArray($payment);

    expect($data['cancellation_type'])->toBe($type->value);
    expect($data['message'])->toContain($expectedKeyword);
})->with([
    [CancellationType::SystemExpired, 'expir'],
    [CancellationType::SystemDuplicate, 'referencia'],
    [CancellationType::Manual, 'cancelado'],
    [CancellationType::MethodChanged, 'camb'],
]);

it('sends only via database channel and not mail', function () {
    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->createQuietly(['order_id' => $order->id, 'tenant_id' => $tenant->id]);

    $notification = new PaymentRejected($payment, CancellationType::SystemExpired);

    $channels = $notification->via($payment);
    expect($channels)->toBe(['database']);
    expect($channels)->not->toContain('mail');
});
