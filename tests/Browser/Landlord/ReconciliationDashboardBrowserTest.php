<?php

use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Browser tests for the S8f reconciliation dashboard.
 *
 * Covers:
 *   - Empty state with N/A match rate and empty sections
 *   - Shadow mode toggle via PATCH
 *   - KPI cards display match rate percentage and breakdown
 *   - Timeline shows notification events
 *   - Orphan notifications table renders data
 *   - KPI counts for active alerts and failed notifications
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

/**
 * Create a Payment with its dependency chain without firing Tenant events.
 */
function createPaymentForBrowserTest(array $attributes = []): Payment
{
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    return Payment::factory()->createQuietly(array_merge([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
    ], $attributes));
}

test('index page loads with empty state', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->assertSee('Dashboard de Conciliación')
        ->assertSeeIn('[data-testid="kpi-match-rate"]', 'N/A')
        ->assertSee('No hay payments huérfanos')
        ->assertSee('No hay notificaciones huérfanas')
        ->assertSee('No hay actividad reciente');
});

test('shadow mode toggle sends patch and shows flash message', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->click('[data-testid="shadow-toggle-btn"]')
        ->waitForText('Shadow mode')
        ->assertSee('Shadow mode');
});

test('kpi cards show match rate percentage and breakdown', function () {
    PaymentMatch::factory()->createQuietly(['match_status' => 'matched']);
    PaymentMatch::factory()->createQuietly(['match_status' => 'matched']);
    PaymentMatch::factory()->createQuietly(['match_status' => 'unmatched']);

    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->assertSeeIn('[data-testid="kpi-match-rate"]', '66.7%')
        ->assertSeeIn('[data-testid="kpi-match-rate"]', '2 de 3');
});

test('timeline shows payment notification events and clicking them filters notifications', function () {
    PaymentNotification::factory()->createQuietly([
        'parsed_data' => [
            'amount_cents' => 3000,
            'reference' => 'NOTIF-BROWSER',
            'sender_phone_last4' => '1234',
        ],
        'created_at' => now()->subMinutes(15),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->assertSee('NOTIF-BROWSER')
        ->click('[data-testid="timeline-item-0"] a')
        ->waitForText('Notificaciones Bancarias')
        ->assertPathIs('/admin/payment-notifications');
});

test('orphan notifications table shows unmatched records', function () {
    PaymentMatch::factory()->createQuietly([
        'match_status' => 'unmatched',
        'parsed_amount_cents' => 5000,
        'created_at' => now()->subHours(2),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->assertSeeIn('[data-testid="orphaned-notifications-table"]', 'Bs. 50.00');
});

test('kpi cards show autoverified and alert counts', function () {
    // Create verified payments (autoverified today)
    $payment = createPaymentForBrowserTest([
        'verified_at' => now(),
        'verified_by' => null,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->assertSeeIn('[data-testid="kpi-autoverified"]', '1')
        ->assertSeeIn('[data-testid="kpi-failed"]', '0')
        ->assertSeeIn('[data-testid="kpi-alerts"]', '0');
});
