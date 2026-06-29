<?php

use App\Models\Landlord;
use App\Models\PaymentNotification;

/**
 * Browser tests for the S8e PaymentNotification viewer + reprocess.
 *
 * Covers:
 *   - Index page loads with empty state
 *   - Index page shows notifications in list
 *   - Expandable detail shows raw text, parsed_data, match info
 *   - Filter by parse_status works
 *   - Filter by bank_code (Select) works
 *   - Filter by reference works
 *   - Filter by date range works
 *   - Clear filters resets to full list
 *   - Reprocess failed notification shows success flash
 *
 * @see PaymentConfigBrowserTest
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('index page loads with empty state', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('Notificaciones Bancarias')
        ->assertSee('Notificaciones Bancarias')
        ->assertSee('No hay notificaciones bancarias');
});

test('index page shows notifications in list', function () {
    PaymentNotification::factory()->count(3)->create([
        'bank_code' => 'BDV',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('Notificaciones Bancarias')
        ->assertSee('BDV')
        ->assertSee('3 registradas');
});

test('toggle expand shows raw text and parsed data', function () {
    $notification = PaymentNotification::factory()->create([
        'bank_code' => 'BNC',
        'raw_text' => 'Pago recibido Bs 100,00',
        'parse_status' => 'parsed',
        'parsed_data' => [
            'amount_cents' => 10000,
            'reference' => 'REF-001',
            'sender_phone_last4' => '1234',
        ],
        'parsed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('BNC')
        ->click("[data-testid=\"expand-btn-{$notification->id}\"]")
        ->waitForText('Texto original')
        ->assertSee('Pago recibido Bs 100,00')
        ->assertSee('REF-001')
        ->assertSee('Sin match');
});

test('filters by parse_status', function () {
    PaymentNotification::factory()->pending()->create([
        'bank_code' => 'PEN',
    ]);
    PaymentNotification::factory()->create([
        'bank_code' => 'SUC',
        'parse_status' => 'parsed',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('PEN')
        ->assertSee('PEN')
        ->assertSee('SUC')
        ->click('[data-testid="filter-parse-status"]')
        ->click('[role="option"]:has-text("Pendiente")')
        ->click('[data-testid="filter-btn"]')
        ->waitForText('PEN')
        ->assertDontSee('SUC');
});

test('filters by date range', function () {
    PaymentNotification::factory()->create([
        'bank_code' => 'OLD',
        'created_at' => now()->subDays(10),
    ]);
    PaymentNotification::factory()->create([
        'bank_code' => 'NEW',
        'created_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('OLD')
        ->assertSee('OLD')
        ->assertSee('NEW')
        ->type('[data-testid="filter-from"]', now()->subDays(5)->format('Y-m-d'))
        ->click('[data-testid="filter-btn"]')
        ->waitForText('NEW')
        ->assertDontSee('OLD');
});

test('clear filters resets to full list', function () {
    PaymentNotification::factory()->pending()->create([
        'bank_code' => 'PEN',
    ]);
    PaymentNotification::factory()->create([
        'bank_code' => 'SUC',
        'parse_status' => 'parsed',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('PEN')
        ->click('[data-testid="filter-parse-status"]')
        ->click('[role="option"]:has-text("Pendiente")')
        ->click('[data-testid="filter-btn"]')
        ->waitForText('PEN')
        ->assertDontSee('SUC')
        ->click('[data-testid="clear-filters-btn"]')
        ->waitForText('SUC')
        ->assertSee('PEN')
        ->assertSee('SUC');
});

test('reprocess failed notification shows success flash', function () {
    $notification = PaymentNotification::factory()->failed()->create([
        'bank_code' => 'BDV',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('Reprocesar')
        ->click("[data-testid=\"reprocess-btn-{$notification->id}\"]")
        ->waitForText('Notificación encolada para reprocesar')
        ->assertSee('Notificación encolada para reprocesar');
});

test('cannot reprocess a non-failed notification', function () {
    $notification = PaymentNotification::factory()->create([
        'bank_code' => 'BDV',
        'parse_status' => 'parsed',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('BDV')
        ->assertDontSee('Reprocesar')
        ->assertNoJavaScriptErrors();
});

test('filter by bank_code', function () {
    PaymentNotification::factory()->create([
        'bank_code' => 'BDV',
    ]);
    PaymentNotification::factory()->create([
        'bank_code' => 'BNC',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('BDV')
        ->assertSee('BDV')
        ->assertSee('BNC')
        ->click('[data-testid="filter-bank-code"]')
        ->click('[role="option"]:has-text("BDV")')
        ->click('[data-testid="filter-btn"]')
        ->waitForText('BDV')
        ->assertDontSee('BNC');
});

test('filter by reference', function () {
    PaymentNotification::factory()->create([
        'bank_code' => 'BNC',
        'raw_text' => 'Ref: ABC-12345',
        'parse_status' => 'pending',
        'parsed_data' => null,
        'parsed_at' => null,
    ]);
    PaymentNotification::factory()->create([
        'bank_code' => 'BDV',
        'raw_text' => 'Pago sin referencia buscada',
        'parse_status' => 'parsed',
        'parsed_data' => [
            'amount_cents' => 5000,
            'reference' => 'OTHER-999',
            'sender_phone_last4' => '5678',
        ],
        'parsed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-notifications.index'))
        ->waitForText('BNC')
        ->assertSee('BNC')
        ->assertSee('BDV')
        ->type('[data-testid="filter-reference"]', 'ABC-12345')
        ->click('[data-testid="filter-btn"]')
        ->waitForText('BNC')
        ->assertDontSee('BDV');
});
