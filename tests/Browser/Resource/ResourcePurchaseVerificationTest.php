<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Entitlement;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Browser test for the resource purchase + landlord manual verification flow.
 *
 * Covers the full happy path:
 *   - Landlord visits order show page with a pending payment
 *   - Clicks "Aprobar Pago" → opens confirmation dialog
 *   - Confirms → payment is verified, entitlement is granted
 *   - Page reloads showing the verified state
 *   - Tenant-level entitlement is created in the database
 *
 * Landlord routes (admin/*) do NOT go through the 'tenant' middleware group
 * (NeedsTenant / EnsureValidTenantSession), so no tenant resolution setup
 * needed — follows the same pattern as SubscriptionBrowserTest.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly([
        'name' => 'Admin Recursos',
        'email' => 'admin-recursos@test.com',
    ]);
});

test('landlord verifies payment for resource order and entitlement is granted', function () {
    $tenant = Tenant::factory()->createQuietly(['name' => 'Resource Tenant']);
    $resource = Resource::factory()->createQuietly([
        'name' => 'Premium Ebook',
        'slug' => 'premium-ebook',
        'is_premium' => true,
        'price_cents' => 3500,
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'resource_id' => $resource->id,
        'plan_id' => null,
        'total_cents' => 3500,
        'status' => OrderStatus::Pending,
    ]);

    $payment = Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 3500,
        'status' => PaymentStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.orders.show', $order))
        ->waitForText('Aprobar Pago')
        ->assertSee('Aprobar Pago')
        ->click('button:has-text("Aprobar Pago")')
        ->waitForText('Confirmar Verificación')
        ->click('[role="dialog"] button:has-text("Aprobar")')
        ->waitForText('Verificado por')
        ->assertSee('Verificado por')
        ->assertSee($this->admin->name)
        ->assertDontSee('Aprobar Pago');

    // Verify database state after verification
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Verified);
    expect($payment->verified_by)->toBe($this->admin->id);
    expect($payment->verified_at)->not->toBeNull();

    // Entitlement should be created (tenant-level, one row)
    $entitlements = Entitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('resource_id', $resource->id)
        ->get();
    expect($entitlements)->toHaveCount(1);

    $entitlement = $entitlements->first();
    expect($entitlement->granted_via->value)->toBe('purchase');
    expect($entitlement->expires_at)->toBeNull();

    // Should NOT create a subscription
    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();
    expect($subscription)->toBeNull();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
});
