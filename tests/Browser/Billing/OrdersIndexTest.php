<?php

use App\Models\Order;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the orders index page.
 *
 * Covers:
 *   - Order list renders with plan and resource orders
 *   - Empty state when no orders exist
 *   - Navigation to order detail page
 *
 * Orders live on the landlord connection and are accessed through
 * the tenant scope. The `actingAs()` call authenticates the user
 * without going through the login UI.
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

test('orders index shows order list', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'name' => 'Premium']);
    $resource = Resource::factory()->createQuietly();

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Order Viewer',
            'email' => 'order-viewer@tenant.test',
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
            ->visit('/billing/orders')
            ->waitForText('Orders')
            ->assertVisible('[data-testid="orders-list"]')
            ->assertVisible("[data-testid=\"order-row-{$order->id}\"]")
            ->assertSee($plan->name)
            ->assertSee('Pendiente')
            ->assertSee('Bs. 29.00')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('orders index empty state', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Empty Orders',
            'email' => 'empty-orders@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/billing/orders')
            ->waitForText('Orders')
            ->assertSee('No orders yet')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('orders index navigate to detail', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'name' => 'Premium']);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Navigate Order',
            'email' => 'navigate-order@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $order = Order::factory()->forPlan()->pending()->createQuietly([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ]);

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/billing/orders')
            ->waitForText($plan->name)
            ->click("[data-testid=\"view-order-btn-{$order->id}\"]")
            ->waitForText("Orden #{$order->id}")
            ->assertSee($plan->name)
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
