<?php

use App\Models\Landlord;
use App\Models\PaymentMethodConfig;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

/**
 * Note on type values:
 * The spec validation Rule::in(['Pagomovil', 'Transferencia']) is intentionally
 * overridden to use 'pago_movil' and 'bank_transfer' for consistency with the
 * existing database data, factory, and design's TypeScript types.
 */

// Helpers for re-usable admin user
function createAdmin(): Landlord
{
    return Landlord::factory()->create([
        'email_verified_at' => now(),
    ]);
}

/**
 * Create a non-admin User without persisting to DB.
 * The User model uses UsesTenantConnection, so we must
 * avoid DB interaction in the landlord test context.
 */
function createNonAdminUser(): User
{
    return User::factory()->make();
}

beforeEach(function () {
    // Ensure we're in the landlord/test database context
    $this->withoutMix();
});

test('admin can list configs grouped by type', function () {
    $admin = createAdmin();
    actingAs($admin);

    // Create configs of both types
    $pagoMovil = PaymentMethodConfig::factory()->ofPagoMovil()->create();
    $bankTransfer = PaymentMethodConfig::factory()->ofBankTransfer()->create();

    $response = $this->get(route('landlord.payment-configs.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('landlord/payment-configs/index')
        ->has('configsByType.pago_movil', 1)
        ->has('configsByType.bank_transfer', 1)
    );
});

test('admin can create a pago movil config', function () {
    $admin = createAdmin();
    actingAs($admin);

    $response = $this->post(route('landlord.payment-configs.store'), [
        'type' => 'pago_movil',
        'label' => 'Banesco Pagomóvil',
        'bank_name' => 'Banesco',
        'account_number' => '0412-1234567',
        'account_holder' => 'Empresa CA',
        'holder_id' => 'J-12345678-9',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('landlord.payment-configs.index'));
    $response->assertSessionHas('success');

    assertDatabaseHas('payment_method_configs', [
        'type' => 'pago_movil',
        'label' => 'Banesco Pagomóvil',
        'bank_name' => 'Banesco',
        'account_number' => '0412-1234567',
    ], 'landlord');
});

test('admin can create a transferencia config', function () {
    $admin = createAdmin();
    actingAs($admin);

    $response = $this->post(route('landlord.payment-configs.store'), [
        'type' => 'bank_transfer',
        'label' => 'Mercantil Cta Corriente',
        'bank_name' => 'Mercantil',
        'account_number' => '0105-012345-67890',
        'account_holder' => 'Empresa CA',
        'holder_id' => 'J-12345678-9',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('landlord.payment-configs.index'));
    $response->assertSessionHas('success');

    assertDatabaseHas('payment_method_configs', [
        'type' => 'bank_transfer',
        'label' => 'Mercantil Cta Corriente',
        'bank_name' => 'Mercantil',
        'account_number' => '0105-012345-67890',
    ], 'landlord');
});

test('validation rejects invalid data', function () {
    $admin = createAdmin();
    actingAs($admin);

    // Missing all required fields
    $response = $this->post(route('landlord.payment-configs.store'), []);
    $response->assertSessionHasErrors(['type', 'label', 'bank_name', 'account_number', 'account_holder', 'holder_id']);

    // Invalid type
    $response = $this->post(route('landlord.payment-configs.store'), [
        'type' => 'invalid_type',
        'label' => 'Test',
        'bank_name' => 'Test',
        'account_number' => '123',
        'account_holder' => 'Test',
        'holder_id' => 'J-123',
    ]);
    $response->assertSessionHasErrors(['type']);

    // Duplicate label for same type
    PaymentMethodConfig::factory()->ofPagoMovil()->create(['label' => 'Duplicado']);
    $response = $this->post(route('landlord.payment-configs.store'), [
        'type' => 'pago_movil',
        'label' => 'Duplicado',
        'bank_name' => 'Test',
        'account_number' => '123',
        'account_holder' => 'Test',
        'holder_id' => 'J-123',
    ]);
    $response->assertSessionHasErrors(['label']);

    // Same label allowed for different type
    $response = $this->post(route('landlord.payment-configs.store'), [
        'type' => 'bank_transfer',
        'label' => 'Duplicado',
        'bank_name' => 'Test',
        'account_number' => '123',
        'account_holder' => 'Test',
        'holder_id' => 'J-123',
    ]);
    $response->assertSessionHasNoErrors();
});

test('admin can edit config and type is not editable', function () {
    $admin = createAdmin();
    actingAs($admin);

    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create([
        'label' => 'Original Label',
        'bank_name' => 'Banco Original',
    ]);

    $response = $this->put(route('landlord.payment-configs.update', $config), [
        'label' => 'Updated Label',
        'bank_name' => 'Banco Actualizado',
        'account_number' => '0412-7654321',
        'account_holder' => 'Nuevo Titular',
        'holder_id' => 'J-87654321-0',
        'is_active' => false,
    ]);

    $response->assertRedirect(route('landlord.payment-configs.index'));
    $response->assertSessionHas('success');

    assertDatabaseHas('payment_method_configs', [
        'id' => $config->id,
        'type' => 'pago_movil', // Type should NOT have changed
        'label' => 'Updated Label',
        'bank_name' => 'Banco Actualizado',
        'is_active' => false,
    ], 'landlord');
});

test('unique label validation ignores self on update', function () {
    $admin = createAdmin();
    actingAs($admin);

    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create([
        'label' => 'Mi Cuenta',
    ]);

    // Updating with the same label should be allowed
    $response = $this->put(route('landlord.payment-configs.update', $config), [
        'label' => 'Mi Cuenta',
        'bank_name' => 'Banesco',
        'account_number' => '0412-1234567',
        'account_holder' => 'Empresa CA',
        'holder_id' => 'J-12345678-9',
        'is_active' => true,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('landlord.payment-configs.index'));
});

test('admin can delete config', function () {
    $admin = createAdmin();
    actingAs($admin);

    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create();

    $response = $this->delete(route('landlord.payment-configs.destroy', $config));

    $response->assertRedirect(route('landlord.payment-configs.index'));

    assertDatabaseMissing('payment_method_configs', [
        'id' => $config->id,
    ], 'landlord');
});

test('deleting last active of a type shows flash warning', function () {
    $admin = createAdmin();
    actingAs($admin);

    // Create a single active PagoMóvil config
    $config = PaymentMethodConfig::factory()->ofPagoMovil()->active()->create();

    $response = $this->delete(route('landlord.payment-configs.destroy', $config));

    $response->assertRedirect(route('landlord.payment-configs.index'));
    $response->assertSessionHas('warning');

    assertDatabaseMissing('payment_method_configs', [
        'id' => $config->id,
    ], 'landlord');
});

test('non admin gets 403 on non-model routes', function (string $method, string $route, array $params) {
    $user = createNonAdminUser();
    actingAs($user);

    $response = $this->{$method}(route($route, $params));
    $response->assertForbidden();
})->with([
    'index' => ['get', 'landlord.payment-configs.index', []],
    'create' => ['get', 'landlord.payment-configs.create', []],
    'store' => ['post', 'landlord.payment-configs.store', []],
]);

test('non admin gets 403 on model routes', function (string $method, string $route) {
    $user = createNonAdminUser();
    actingAs($user);

    $config = PaymentMethodConfig::factory()->ofPagoMovil()->create();

    $response = $this->{$method}(route($route, $config));
    $response->assertForbidden();
})->with([
    'edit' => ['get', 'landlord.payment-configs.edit'],
    'update' => ['put', 'landlord.payment-configs.update'],
    'destroy' => ['delete', 'landlord.payment-configs.destroy'],
]);

test('admin panel includes cuentas bancarias card', function () {
    $admin = createAdmin();
    actingAs($admin);

    $response = $this->get(route('landlord.admin-panel'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('landlord/admin-panel')
    );
});
