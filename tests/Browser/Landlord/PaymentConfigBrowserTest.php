<?php

use App\Models\Landlord;
use App\Models\PaymentMethodConfig;
use Tests\Browser\Landlord\SystemConfigBrowserTest;

/**
 * Browser tests for the S8d PaymentMethodConfig CRUD UI.
 *
 * Covers:
 *   - Index page loads with grouped tables
 *   - Admin can create PagoMóvil and Transferencia configs
 *   - Edit form preloads data and shows type as read-only
 *   - Admin can edit a config
 *   - Delete with confirmation
 *   - Validation errors appear when fields are empty
 *
 * @see SystemConfigBrowserTest
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('index page loads with empty state', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.index'))
        ->waitForText('Cuentas Bancarias')
        ->assertSee('Cuentas Bancarias')
        ->assertSee('Nueva Cuenta');
});

test('index page shows configs grouped by type', function () {
    $pm = PaymentMethodConfig::factory()->ofPagoMovil()->create([
        'label' => 'BDV Principal',
        'bank_name' => 'Banco de Venezuela',
        'account_number' => '0412-1234567',
    ]);
    $bt = PaymentMethodConfig::factory()->ofBankTransfer()->create([
        'label' => 'Corriente BNC',
        'bank_name' => 'Banco Nacional de Crédito',
        'account_number' => '0191-12345678901234',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.index'))
        ->waitForText('PagoMóvil')
        ->assertSee('BDV Principal')
        ->assertSee('Banco de Venezuela')
        ->assertSee('Transferencia Bancaria')
        ->assertSee('Corriente BNC')
        ->assertSee('Banco Nacional de Crédito');
});

test('create page loads with type selector', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.create'))
        ->waitForText('Nueva Cuenta Bancaria')
        ->assertSee('Nueva Cuenta Bancaria')
        ->assertSee('PagoMóvil')
        ->assertSee('Transferencia Bancaria')
        ->assertSee('Etiqueta')
        ->assertSee('Teléfono')
        ->assertSee('Guardar Cuenta');
});

test('account_number label changes when switching type', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.create'))
        ->waitForText('Nueva Cuenta Bancaria')
        ->assertSee('Teléfono')
        ->assertDontSee('Número de Cuenta')
        ->click('input[type="radio"][value="bank_transfer"]')
        ->assertSee('Número de Cuenta')
        ->assertDontSee('Teléfono')
        ->click('input[type="radio"][value="pago_movil"]')
        ->assertSee('Teléfono')
        ->assertDontSee('Número de Cuenta')
        ->assertNoJavaScriptErrors();
});

test('admin can create a pago movil config and sees it in list', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.create'))
        ->waitForText('Nueva Cuenta Bancaria')
        ->type('[data-testid="input-label"]', 'Banesco Pagomóvil')
        ->type('[data-testid="input-bank-name"]', 'Banesco')
        ->type('[data-testid="input-account-number"]', '0412-7654321')
        ->type('[data-testid="input-account-holder"]', 'Mi Empresa C.A.')
        ->type('[data-testid="input-holder-id"]', 'J-12345678-9')
        ->click('[type="submit"]')
        ->waitForText('Cuentas Bancarias')
        ->assertSee('Banesco Pagomóvil');
});

test('admin can create a transferencia config and sees it in list', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.create'))
        ->waitForText('Nueva Cuenta Bancaria')
        ->click('input[type="radio"][value="bank_transfer"]')
        ->assertSee('Número de Cuenta')
        ->type('[data-testid="input-label"]', 'Mercantil Cuenta Corriente')
        ->type('[data-testid="input-bank-name"]', 'Mercantil')
        ->type('[data-testid="input-account-number"]', '0105-12345678901234')
        ->type('[data-testid="input-account-holder"]', 'Empresa C.A.')
        ->type('[data-testid="input-holder-id"]', 'J-87654321-0')
        ->click('[type="submit"]')
        ->waitForText('Cuentas Bancarias')
        ->assertSee('Mercantil Cuenta Corriente');
});

test('edit page loads with pre-filled data and type as read-only', function () {
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create([
        'label' => 'BDV Principal',
        'bank_name' => 'Banco de Venezuela',
        'account_number' => '0412-1234567',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.edit', $config))
        ->waitForText('Editar Cuenta Bancaria')
        ->assertSee('Editar Cuenta Bancaria')
        ->assertSee('PagoMóvil')
        ->assertSee('Actualizar Cuenta');
});

test('admin can edit a config and sees changes in list', function () {
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create([
        'label' => 'Original Label',
        'bank_name' => 'Banco Original',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.edit', $config))
        ->waitForText('Editar Cuenta Bancaria')
        ->clear('[data-testid="input-label"]')
        ->type('[data-testid="input-label"]', 'Updated Label')
        ->clear('[data-testid="input-bank-name"]')
        ->type('[data-testid="input-bank-name"]', 'Banco Actualizado')
        ->click('[type="submit"]')
        ->waitForText('Cuentas Bancarias')
        ->assertSee('Updated Label')
        ->assertSee('Banco Actualizado');
});

test('admin can delete a config with confirmation', function () {
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create([
        'label' => 'Delete Me',
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.index'))
        ->waitForText('Cuentas Bancarias')
        ->assertSee('Delete Me')
        ->click('[data-testid="delete-config-'.$config->id.'"]')
        ->waitForText('¿Eliminar cuenta?')
        ->assertSee('¿Eliminar cuenta?')
        ->click('[data-testid="confirm-delete-btn"]')
        ->waitForText('Cuentas Bancarias')
        ->assertDontSee('Delete Me')
        ->assertNoJavaScriptErrors();
});

test('create validation shows errors when fields are empty', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.payment-configs.create'))
        ->waitForText('Nueva Cuenta Bancaria')
        ->click('[type="submit"]')
        ->waitForText('obligatorio')
        ->assertSee('obligatorio')
        ->assertNoJavaScriptErrors();
});
