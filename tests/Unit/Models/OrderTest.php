<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('order model uses landlord connection', function () {
    $order = Order::factory()->createQuietly();

    expect($order->getConnectionName())->toBe('landlord');
});

test('order has correct status cast', function () {
    $order = Order::factory()->createQuietly(['status' => OrderStatus::Pending]);

    expect($order->status)->toBeInstanceOf(OrderStatus::class);
    expect($order->status)->toBe(OrderStatus::Pending);
});

test('order paid_cents accessor returns sum of verified payments', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => Tenant::factory()->createQuietly()->id,
        'total_cents' => 5000,
    ]);

    // Create a pending payment (should NOT count)
    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
        'amount_cents' => 2000,
        'status' => PaymentStatus::Pending,
    ]);

    // Create a verified payment (should count)
    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
        'amount_cents' => 3000,
        'status' => PaymentStatus::Verified,
    ]);

    expect($order->paid_cents)->toBe(3000);
});

test('order remaining_cents accessor returns correct difference', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => Tenant::factory()->createQuietly()->id,
        'total_cents' => 5000,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
        'amount_cents' => 2000,
        'status' => PaymentStatus::Verified,
    ]);

    expect($order->remaining_cents)->toBe(3000);
});

test('order remaining_cents never goes below zero', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => Tenant::factory()->createQuietly()->id,
        'total_cents' => 1000,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
        'amount_cents' => 2000,
        'status' => PaymentStatus::Verified,
    ]);

    expect($order->remaining_cents)->toBe(0);
});

test('order isFullyPaid returns true when payments cover total', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => Tenant::factory()->createQuietly()->id,
        'total_cents' => 1000,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
        'amount_cents' => 1000,
        'status' => PaymentStatus::Verified,
    ]);

    expect($order->isFullyPaid())->toBeTrue();
});

test('order isFullyPaid returns false when payments do not cover total', function () {
    $order = Order::factory()->createQuietly([
        'tenant_id' => Tenant::factory()->createQuietly()->id,
        'total_cents' => 5000,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
        'amount_cents' => 1000,
        'status' => PaymentStatus::Verified,
    ]);

    expect($order->isFullyPaid())->toBeFalse();
});

test('order buyable accessor returns plan when plan_id is set', function () {
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'plan_id' => $plan->id,
        'resource_id' => null,
    ]);

    expect($order->buyable)->toBeInstanceOf(Plan::class);
    expect($order->buyable_type)->toBe('plan');
});

test('order buyable accessor returns resource when resource_id is set', function () {
    $resource = Resource::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'plan_id' => null,
        'resource_id' => $resource->id,
    ]);

    expect($order->buyable)->toBeInstanceOf(Resource::class);
    expect($order->buyable_type)->toBe('resource');
});

test('order exclusive arcs constraint prevents both plan_id and resource_id', function () {
    $plan = Plan::factory()->createQuietly();
    $resource = Resource::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();

    $this->expectException(QueryException::class);

    DB::connection('landlord')->table('orders')->insert([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'resource_id' => $resource->id,
        'total_cents' => 1000,
        'status' => 'pending',
        'expires_at' => now()->addHours(48),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('order has relationship with payments', function () {
    $order = Order::factory()->createQuietly();
    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $order->tenant_id,
    ]);

    expect($order->payments)->toHaveCount(1);
    expect($order->payments->first()->id)->toBe($payment->id);
});

test('order has relationship with tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);

    expect($order->tenant)->not->toBeNull();
    expect($order->tenant->id)->toBe($tenant->id);
});
