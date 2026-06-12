<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;

test('command expires overdue pending orders', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('orders:expire')
        ->assertExitCode(0);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Expired);
});

test('command skips pending orders with future expires_at', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'expires_at' => now()->addWeek(),
    ]);

    $this->artisan('orders:expire')
        ->assertExitCode(0);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Pending);
});

test('command skips already cancelled orders', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Cancelled,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('orders:expire')
        ->assertExitCode(0);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled);
});

test('command skips already paid orders', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Paid,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('orders:expire')
        ->assertExitCode(0);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
});
