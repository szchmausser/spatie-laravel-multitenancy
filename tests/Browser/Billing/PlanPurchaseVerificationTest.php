<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Browser test for the plan purchase + landlord manual verification flow.
 *
 * Covers the full happy path:
 *   - Landlord visits order show page with a pending payment
 *   - Clicks "Aprobar Pago" → opens confirmation dialog
 *   - Confirms → payment is verified, subscription is activated
 *   - Page reloads showing the verified state
 *   - Subscription and entitlement are created in the database
 *
 * Landlord routes (admin/*) do NOT go through the 'tenant' middleware group
 * (NeedsTenant / EnsureValidTenantSession), so no tenant resolution setup
 * needed — follows the same pattern as SubscriptionBrowserTest.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly([
        'name' => 'Admin Verificador',
        'email' => 'admin-verificador@test.com',
    ]);
});

test('landlord verifies payment for plan order and subscription is activated', function () {
    $tenant = Tenant::factory()->createQuietly(['name' => 'Plan Tenant']);
    $plan = Plan::factory()->createQuietly([
        'name' => 'Premium Plan',
        'slug' => 'premium',
        'is_active' => true,
        'price_cents' => 2900,
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 2900,
        'status' => OrderStatus::Pending,
    ]);

    $payment = Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 2900,
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

    // Subscription should be created
    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();
    expect($subscription)->not->toBeNull();
    expect($subscription->plan_id)->toBe($plan->id);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
});
