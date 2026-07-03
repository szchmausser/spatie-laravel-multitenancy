<?php

use App\Models\Order;
use App\Models\PaymentMethodConfig;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the order detail page.
 *
 * Covers:
 *   - Order detail shows plan/resource name, total, status
 *   - Pago movil payment flow section renders
 *   - Payment form validation on empty fields
 *
 * Connection setup mirrors ChangePlanFlowTest: the tenant connection
 * is pointed at the test database, Spatie permission tables are
 * created, and the user is created on the tenant connection.
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

test('orders show shows order details', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'name' => 'Premium']);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Show Order',
            'email' => 'show-order@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $order = Order::factory()->forPlan()->pending()->createQuietly([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'total_cents' => 2900,
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit("/billing/orders/{$order->id}")
            ->waitForText("Orden #{$order->id}")
            ->assertSee($plan->name)
            ->assertSee('29')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('orders show pago movil payment flow', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'name' => 'Premium']);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Pago Movil',
            'email' => 'pago-movil@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        // Create payment method config for pago_movil
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
            ->waitForText('Reporta tu pago')
            // Verify payment section is visible
            ->assertVisible('[data-testid="payment-section"]')
            ->assertSee('Reporta tu pago')
            // Verify payment form fields are visible
            ->assertVisible('#amount')
            ->assertVisible('#reference')
            ->assertVisible('#sender_bank')
            ->assertVisible('#sender_phone')
            ->assertVisible('#sender_id')
            ->assertVisible('#payment_date')
            // Verify submit button exists and is disabled when fields are empty
            ->assertVisible('button[type="submit"]')
            ->assertDisabled('button[type="submit"]')
            // Verify payment config info is displayed
            ->assertSee('04129338026')
            ->assertSee('Banco de Venezuela')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
