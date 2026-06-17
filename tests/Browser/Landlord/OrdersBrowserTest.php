<?php

use App\Enums\OrderStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Payment;
use App\Models\Tenant;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the landlord orders index and show pages.
 *
 * Covers:
 *   - Admin can see the orders list page
 *   - Orders list shows order with tenant and status
 *   - Orders list search filters results
 *   - Admin can view order detail page
 *   - Order detail shows payments
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('admin can see orders list page', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.index'))
        ->assertSee('Órdenes')
        ->assertNoJavaScriptErrors();
});

test('orders list shows order with tenant and status', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan', 'slug' => 'gold']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 5000,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.index'))
        ->assertSee('Acme Corp')
        ->assertSee('Gold Plan')
        ->assertSee('#'.$order->id)
        ->assertNoJavaScriptErrors();
});

test('orders list search filters results', function () {
    $plan = Plan::factory()->create(['name' => 'Pro Plan']);
    $tenantA = Tenant::factory()->createQuietly(['name' => 'Alpha Corp']);
    $tenantB = Tenant::factory()->createQuietly(['name' => 'Beta Inc']);
    Order::factory()->create(['tenant_id' => $tenantA->id, 'plan_id' => $plan->id]);
    Order::factory()->create(['tenant_id' => $tenantB->id, 'plan_id' => $plan->id]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.index'))
        ->assertSee('Alpha Corp')
        ->assertSee('Beta Inc')
        ->fill('@orders-search', 'Alpha')
        ->assertSee('Alpha Corp')
        ->assertDontSee('Beta Inc')
        ->assertNoJavaScriptErrors();
});

test('admin can view order detail page', function () {
    $plan = Plan::factory()->create(['name' => 'Premium Plan', 'slug' => 'premium']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Detail Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 9900,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Orden #'.$order->id)
        ->assertSee('Detail Corp')
        ->assertSee('Premium Plan')
        ->assertNoJavaScriptErrors();
});

test('order detail shows payments', function () {
    $plan = Plan::factory()->create(['name' => 'Basic Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Payment Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 3000,
    ]);

    Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 3000,
        'status' => \App\Enums\PaymentStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Orden #'.$order->id)
        ->assertSee('Acciones')
        ->assertNoJavaScriptErrors();
});


