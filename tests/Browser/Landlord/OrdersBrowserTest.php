<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\Plan;
use App\Models\Tenant;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the landlord orders index and show pages.
 *
 * Covers:
 *   - Orders list renders and shows order data
 *   - Orders list search filters results
 *   - Admin can view order detail page
 *   - Order detail shows payments
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('orders list renders and shows orders', function () {
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
        ->assertSee('Órdenes')
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
        'status' => PaymentStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Orden #'.$order->id)
        ->assertSee('Acciones')
        ->assertNoJavaScriptErrors();
});

test('verified payment shows verifier info', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Verifier Corp']);
    $verifier = Landlord::factory()->createQuietly(['name' => 'Juan Verifier']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 5000,
    ]);

    Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Verified,
        'verified_by' => $verifier->id,
        'verified_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Verificado por')
        ->assertSee($verifier->name)
        ->assertNoJavaScriptErrors();
});

test('auto-verified payment shows Automático', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Auto Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 5000,
    ]);

    Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Verified,
        'verified_by' => null,
        'verified_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Automático')
        ->assertNoJavaScriptErrors();
});

test('cancelled payment shows coloured badge per type', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Cancel Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 10000,
    ]);

    // Create one payment per cancellation type
    foreach (['manual' => 'Cancelado manualmente', 'system_duplicate' => 'Cancelado: duplicado', 'system_expired' => 'Cancelado: expirado', 'method_changed' => 'Cambio de método'] as $type => $label) {
        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'amount_cents' => 2500,
            'status' => PaymentStatus::Cancelled,
            'cancellation_type' => $type,
            'cancellation_reason' => 'Test reason',
            'cancelled_at' => now(),
        ]);
    }

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Cancelado manualmente')
        ->assertSee('Cancelado: duplicado')
        ->assertSee('Cancelado: expirado')
        ->assertSee('Cambio de método')
        ->assertNoJavaScriptErrors();
});

test('matched payment shows match data', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Match Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 5000,
    ]);

    $payment = Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Verified,
        'verified_at' => now(),
    ]);

    $notification = PaymentNotification::factory()->create();
    PaymentMatch::factory()->create([
        'payment_id' => $payment->id,
        'payment_notification_id' => $notification->id,
        'match_status' => 'matched',
        'parsed_reference' => 'REF-123456',
        'parsed_amount_cents' => 5000,
        'matched_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertSee('Estado de conciliación')
        ->assertSee('REF-123456')
        ->assertNoJavaScriptErrors();
});

test('unmatched payment hides match section', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'NoMatch Corp']);
    $order = Order::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'total_cents' => 5000,
    ]);

    Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Pending,
        'payment_method' => 'pago_movil',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->assertDontSee('Estado de conciliación')
        ->assertNoJavaScriptErrors();
});
