<?php

use App\Models\Order;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser test for the complete payment flow for an order.
 *
 * Covers:
 *   - Payment form fields are visible and functional
 *   - Submit button is disabled when required fields are empty
 *   - Filling all fields enables the submit button
 *   - Submitting the payment reports it successfully
 *
 * Connection setup mirrors other tenant browser tests: the tenant
 * connection is pointed at the test database, Spatie permission
 * tables are created, and the user is created on the tenant connection.
 */
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');

    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $tableNames = config('permission.table_names');
    Schema::connection('tenant')->dropIfExists($tableNames['role_has_permissions']);
    Schema::connection('tenant')->dropIfExists($tableNames['model_has_roles']);
    Schema::connection('tenant')->dropIfExists($tableNames['model_has_permissions']);
    Schema::connection('tenant')->dropIfExists($tableNames['roles']);
    Schema::connection('tenant')->dropIfExists($tableNames['permissions']);

    DB::purge('tenant');
});

test('payment form fields are visible and submit is disabled when empty', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'name' => 'Premium']);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Payment Flow',
            'email' => 'payment-flow@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        // Create payment method config
        PaymentMethodConfig::create([
            'type' => 'pago_movil',
            'label' => 'Pago Móvil Test',
            'bank_name' => 'Banco de Venezuela',
            'account_number' => '04129338026',
            'account_holder' => 'Test Holder',
            'holder_id' => 'V-12345678',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $order = Order::factory()->forPlan()->pending()->createQuietly([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'total_cents' => 2900,
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit("/billing/orders/{$order->id}")
            ->waitForText('Realizar Pago')
            // Verify all pago movil form fields are visible
            ->assertVisible('#amount')
            ->assertVisible('#reference')
            ->assertVisible('#sender_bank')
            ->assertVisible('#sender_phone')
            ->assertVisible('#sender_id')
            ->assertVisible('#payment_date')
            // Submit button should be disabled when fields are empty
            ->assertVisible('button[type="submit"]')
            ->assertDisabled('button[type="submit"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('filling payment fields enables submit button', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'name' => 'Premium']);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Payment Fill',
            'email' => 'payment-fill@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        PaymentMethodConfig::create([
            'type' => 'pago_movil',
            'label' => 'Pago Móvil Test',
            'bank_name' => 'Banco de Venezuela',
            'account_number' => '04129338026',
            'account_holder' => 'Test Holder',
            'holder_id' => 'V-12345678',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $order = Order::factory()->forPlan()->pending()->createQuietly([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'total_cents' => 2900,
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit("/billing/orders/{$order->id}")
            ->waitForText('Realizar Pago')
            // Fill all required fields
            ->type('#amount', '29.00')
            ->type('#reference', '1234567890')
            ->select('#sender_bank', 'Banco de Venezuela')
            ->type('#sender_phone', '04129338026')
            ->type('#sender_id', 'V-12345678')
            ->type('#payment_date', '2025-06-15')
            // Submit button should now be enabled
            ->assertEnabled('button[type="submit"]')
            // Click submit
            ->click('button[type="submit"]')
            ->waitForText('Reportar Pago')
            // Verify the page updates (payment was submitted)
            ->assertSee('Reportar Pago')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
