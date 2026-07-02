<?php

use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Browser tests for the reconciliation dashboard (index + tab pages).
 *
 * The index page now shows KPI cards and tab navigation.
 * The shadow mode toggle moved to system-configs page.
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

test('index page loads with empty state KPIs and no tab content', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.index'))
        ->waitForText('Dashboard de Conciliación')
        ->assertSee('Dashboard de Conciliación')
        ->assertSeeIn('[data-testid="kpi-match-rate"]', 'N/A')
        ->assertSeeIn('[data-testid="kpi-autoverified"]', '0')
        ->assertSeeIn('[data-testid="kpi-alerts"]', '0')
        ->assertSeeIn('[data-testid="kpi-failed"]', '0');
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

test('kpi cards show autoverified and alert counts', function () {
    createPaymentForBrowserTest([
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

test('tab navigation loads pending page', function () {
    // Create a pending payment without a match
    createPaymentForBrowserTest();

    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.pending'))
        ->waitForText('Pagos Pendientes')
        ->assertSee('Pagos Pendientes');
});

test('tab navigation loads matched page', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.matched'))
        ->waitForText('Pagos Matcheados')
        ->assertSee('No hay pagos conciliados');
});

test('tab navigation loads stats page', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.stats'))
        ->waitForText('Estadísticas')
        ->assertSee('No hay datos estadísticos');
});

test('pending tab shows empty state when no payments exist', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.pending'))
        ->waitForText('Pagos Pendientes')
        ->assertSee('No hay pagos reportados pendientes');
});

test('pending tab shows payment rows and expand detail', function () {
    $payment = createPaymentForBrowserTest();

    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.pending'))
        ->waitForText('Pagos Pendientes')
        ->assertSee('#'.$payment->id)
        ->click('[data-testid="expand-'.$payment->id.'"]')
        ->waitForText('Monto')
        ->assertSee('Monto');
});

test('matched tab shows empty state', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.reconciliation.matched'))
        ->waitForText('Pagos Matcheados')
        ->assertSee('No hay pagos conciliados');
});
