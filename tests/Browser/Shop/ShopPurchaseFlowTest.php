<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the shop purchase flow.
 *
 * Covers the complete user journey:
 *   1. Tenant visits /shop
 *   2. Sees available plans
 *   3. Clicks "Suscribirse" or "Cambiar" button
 *   4. Lands on /billing/change-plan page
 *
 * This is a CRITICAL flow — if broken, tenants cannot purchase plans
 * and the platform generates no revenue.
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

test('tenant can initiate plan purchase from shop', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $basicPlan = Plan::factory()->create([
        'name' => 'Basic Plan',
        'slug' => 'basic',
        'is_active' => true,
        'price_cents' => 0,
    ]);

    $premiumPlan = Plan::factory()->create([
        'name' => 'Premium Plan',
        'slug' => 'premium',
        'is_active' => true,
        'price_cents' => 2900,
        'features' => ['premium-zone' => true],
    ]);

    // Tenant starts on basic plan
    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Shop Buyer',
            'email' => 'buyer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')
            ->assertSee('Premium Plan')
            ->assertVisible('[data-testid="shop-plan-card-premium"]')
            ->assertVisible('[data-testid="shop-plan-action-btn-premium"]')
            ->click('[data-testid="shop-plan-action-btn-premium"]')
            ->waitForText('Change plan')
            ->assertPathIs('/billing/change-plan')
            ->assertSee('Premium Plan')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('tenant without subscription can view shop and initiate purchase', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $plan = Plan::factory()->create([
        'name' => 'Starter Plan',
        'slug' => 'starter',
        'is_active' => true,
        'price_cents' => 1500,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'New Buyer',
            'email' => 'new-buyer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')
            ->assertSee('Starter Plan')
            ->assertVisible('[data-testid="shop-plan-card-starter"]')
            ->assertSeeIn('[data-testid="shop-plan-action-btn-starter"]', 'Suscribirse')
            ->click('[data-testid="shop-plan-action-btn-starter"]')
            ->waitForText('Change plan')
            ->assertPathIs('/billing/change-plan')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
