<?php

use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for resource access states on the tenant resource catalog.
 *
 * Covers the three distribution models visible on /resources:
 *   1. Free resource → download button (free-tier tenant)
 *   2. Plan-included → "Incluido en tu plan" badge + download
 *   3. Buyable → "Buy" button (premium, no plan access, no entitlement)
 *
 * Production state: PlansSeeder creates plans 'free', 'basic', 'premium'.
 * Tenant::created() auto-assigns subscription to 'free' plan via
 * ensureDefaultSubscription(). Tests replicate this production state.
 *
 * Connection setup mirrors ResourceCatalogFlowTest: tenant connection
 * points at the test database, permission tables are created, and
 * the user is created on the tenant connection. Uses fakeTenantFinder
 * so the HTTP server resolves the correct tenant.
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

test('free resource shows download button on catalog for free-tier tenant', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    // Production state: PlansSeeder creates 'free' plan, Tenant::created()
    // auto-assigns subscription to it via ensureDefaultSubscription().
    $freePlan = Plan::query()->firstOrCreate(
        ['slug' => 'free'],
        [
            'name' => 'Free',
            'is_active' => true,
            'price_cents' => 0,
            'features' => [],
        ]
    );
    $tenant->subscription()->create([
        'plan_id' => $freePlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Resource::factory()->createQuietly([
        'name' => 'Quick Start',
        'slug' => 'quick-start',
        'is_premium' => false,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Catalog Free User',
            'email' => 'catalog-free@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Resources')
            ->assertVisible('[data-testid="resource-card-quick-start"]')
            ->assertVisible('[data-testid="resource-download-btn-quick-start"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium resource included in basic plan shows plan badge and download on catalog', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    // Production state: PlansSeeder creates 'basic' plan.
    $basicPlan = Plan::query()->firstOrCreate(
        ['slug' => 'basic'],
        [
            'name' => 'Basic',
            'is_active' => true,
            'price_cents' => 800000,
            'features' => [],
        ]
    );

    $resource = Resource::factory()->createQuietly([
        'name' => 'Analytics Dashboard',
        'slug' => 'analytics-dashboard',
        'is_premium' => true,
        'price_cents' => 8000,
    ]);

    // Production state: PlansSeeder syncs premium resources to basic plan.
    $basicPlan->resources()->attach($resource->id);

    // Tenant subscribed to basic plan (paid tier).
    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Catalog Plan User',
            'email' => 'catalog-plan@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Resources')
            ->assertVisible('[data-testid="resource-card-analytics-dashboard"]')
            ->assertVisible('[data-testid="resource-plan-badge-analytics-dashboard"]')
            ->assertSeeIn('[data-testid="resource-plan-badge-analytics-dashboard"]', 'Incluido en tu plan')
            ->assertVisible('[data-testid="resource-download-btn-analytics-dashboard"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium resource not in plan and not entitled shows buy button on catalog', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    // Production state: PlansSeeder creates 'basic' plan.
    $basicPlan = Plan::query()->firstOrCreate(
        ['slug' => 'basic'],
        [
            'name' => 'Basic',
            'is_active' => true,
            'price_cents' => 800000,
            'features' => [],
        ]
    );

    Resource::factory()->createQuietly([
        'name' => 'Premium Data Pack',
        'slug' => 'premium-data-pack',
        'is_premium' => true,
        'price_cents' => 14900,
    ]);

    // Resource NOT attached to any plan — intentionally buy-only.

    // Tenant subscribed to basic plan (paid tier).
    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Catalog Buy User',
            'email' => 'catalog-buy@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Resources')
            ->assertVisible('[data-testid="resource-card-premium-data-pack"]')
            ->assertVisible('[data-testid="resource-buy-btn-premium-data-pack"]')
            ->assertSeeIn('[data-testid="resource-buy-btn-premium-data-pack"]', 'Buy')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
