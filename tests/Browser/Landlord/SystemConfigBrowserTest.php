<?php

use App\Models\Landlord;
use App\Models\SystemConfig;

/**
 * Browser tests for the S8b SystemConfig UI.
 *
 * Covers:
 *   - Index page loads with grouped configs
 *   - Admin can edit a string config
 *   - Admin can toggle a boolean config
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('index page loads with grouped configs', function () {
    SystemConfig::updateOrCreate(
        ['key' => 'payment.default_gateway'],
        ['group' => 'payment', 'value' => 'pago_movil', 'type' => 'string'],
    );
    SystemConfig::updateOrCreate(
        ['key' => 'reconciliation.shadow_mode_channels'],
        ['group' => 'reconciliation', 'value' => '[]', 'type' => 'json'],
    );

    $this->actingAs($this->admin)
        ->visit(route('landlord.admin.system-configs'))
        ->waitForText('Configuración del Sistema')
        ->assertSee('payment')
        ->assertSee('reconciliation')
        ->assertSee('payment.default_gateway')
        ->assertSee('shadow_mode_channels');
});

test('admin can edit a string config', function () {
    $config = SystemConfig::updateOrCreate(
        ['key' => 'payment.default_gateway'],
        ['group' => 'payment', 'value' => 'pago_movil', 'type' => 'string'],
    );

    $this->actingAs($this->admin)
        ->visit(route('landlord.admin.system-configs'))
        ->waitForText('payment.default_gateway')
        ->click("[data-testid=\"edit-config-{$config->id}\"]")
        ->waitForText('Editar configuración')
        ->clear('[data-testid="input-value"]')
        ->type('[data-testid="input-value"]', 'bank_transfer')
        ->click('[data-testid="save-config-btn"]')
        ->waitForText('bank_transfer');
});

test('admin can toggle shadow channels via checkboxes', function () {
    $config = SystemConfig::updateOrCreate(
        ['key' => 'reconciliation.shadow_mode_channels'],
        ['group' => 'reconciliation', 'value' => '[]', 'type' => 'json'],
    );

    $this->actingAs($this->admin)
        ->visit(route('landlord.admin.system-configs'))
        ->waitForText('shadow_mode_channels')
        ->click("[data-testid=\"edit-config-{$config->id}\"]")
        ->waitForText('Editar configuración')
        ->waitForText('Bank App')
        ->check('[data-testid="shadow-channel-bank-app"]')
        ->click('[data-testid="save-config-btn"]')
        ->waitForText('["bank-app"]');
});

test('reconciliation polling interval appears in system configs', function () {
    $config = SystemConfig::updateOrCreate(
        ['key' => 'reconciliation.polling_interval_seconds'],
        ['group' => 'reconciliation', 'value' => '30', 'type' => 'integer'],
    );

    $this->actingAs($this->admin)
        ->visit(route('landlord.admin.system-configs'))
        ->waitForText('polling_interval_seconds')
        ->assertSee('30')
        ->click("[data-testid=\"edit-config-{$config->id}\"]")
        ->waitForText('Editar configuración')
        ->clear('[data-testid="input-value"]')
        ->type('[data-testid="input-value"]', '15')
        ->click('[data-testid="save-config-btn"]')
        ->waitForText('15');
});
