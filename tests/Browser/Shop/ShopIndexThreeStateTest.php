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
 * Browser tests for the three resource distribution states on the shop page.
 *
 * Covers the happy path of each distribution model:
 *   1. Free tier — free resources show a download button
 *   2. Plan-included — premium resources in the tenant's plan show
 *      "Incluido en tu plan" + download
 *   3. Direct purchase — premium resources NOT in any plan show
 *      "Comprar" (buyable on demand)
 *
 * Production state: PlansSeeder creates plans 'free', 'basic', 'premium'.
 * Tenant::created() auto-assigns subscription to 'free' plan via
 * ensureDefaultSubscription(). Tests replicate this production state.
 *
 * Connection setup mirrors ShopIndexTest: the tenant connection points
 * at the test database, Spatie permission tables are created, and the
 * user is created on the tenant connection.
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

test('free resource shows download button on shop for free-tier tenant', function () {
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
        'name' => 'Getting Started',
        'slug' => 'getting-started',
        'is_premium' => false,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Free Tier User',
            'email' => 'free-tier@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')
            ->assertVisible('[data-testid="shop-resource-card-getting-started"]')
            ->assertVisible('[data-testid="shop-resource-download-btn-getting-started"]')
            ->assertSee('Shop')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium resource in basic plan shows included badge and download on shop', function () {
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

    $resourceInPlan = Resource::factory()->createQuietly([
        'name' => 'Monthly Report',
        'slug' => 'monthly-report',
        'is_premium' => true,
        'price_cents' => 5000,
    ]);

    // Production state: PlansSeeder syncs premium resources to basic plan.
    $basicPlan->resources()->attach($resourceInPlan->id);

    // Tenant subscribed to basic plan (paid tier).
    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Plan Subscriber',
            'email' => 'plan-subscriber@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')
            ->assertVisible('[data-testid="shop-resource-card-monthly-report"]')
            ->assertVisible('[data-testid="shop-resource-plan-badge-monthly-report"]')
            ->assertSeeIn('[data-testid="shop-resource-plan-badge-monthly-report"]', 'Incluido')
            ->assertVisible('[data-testid="shop-resource-download-btn-monthly-report"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium resource not in plan shows buy button on shop', function () {
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
        'name' => 'Exclusive Whitepaper',
        'slug' => 'exclusive-whitepaper',
        'is_premium' => true,
        'price_cents' => 19900,
    ]);

    // Resource NOT attached to any plan — intentionally buy-only.
    // In production this happens when a premium resource exists but
    // PlansSeeder hasn't synced it to any plan yet, or when a new
    // premium resource is created after the seeder ran.

    // Tenant subscribed to basic plan (paid tier).
    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Direct Buyer',
            'email' => 'direct-buyer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')
            ->assertVisible('[data-testid="shop-resource-card-exclusive-whitepaper"]')
            ->assertVisible('[data-testid="shop-resource-buy-btn-exclusive-whitepaper"]')
            ->assertSeeIn('[data-testid="shop-resource-buy-btn-exclusive-whitepaper"]', 'Comprar')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('shop page renders all three resource states simultaneously', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $basicPlan = Plan::query()->firstOrCreate(
        ['slug' => 'basic'],
        [
            'name' => 'Basic',
            'is_active' => true,
            'price_cents' => 800000,
            'features' => [],
        ]
    );

    // Resource 1: free → download button (no is_premium, no pivot needed)
    Resource::factory()->createQuietly([
        'name' => 'Free Starter',
        'slug' => 'free-starter',
        'is_premium' => false,
    ]);

    // Resource 2: premium, in plan → plan badge + download
    $planResource = Resource::factory()->createQuietly([
        'name' => 'Plan Feature',
        'slug' => 'plan-feature',
        'is_premium' => true,
        'price_cents' => 5000,
    ]);
    $basicPlan->resources()->attach($planResource->id);

    // Resource 3: premium, NOT in plan → buy button
    Resource::factory()->createQuietly([
        'name' => 'Premium Extra',
        'slug' => 'premium-extra',
        'is_premium' => true,
        'price_cents' => 29900,
    ]);

    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Combined View',
            'email' => 'combined@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')

            // Free resource → Download button (no badge, no plan badge)
            ->assertVisible('[data-testid="shop-resource-card-free-starter"]')
            ->assertVisible('[data-testid="shop-resource-download-btn-free-starter"]')

            // Plan-included → badge verde + Download
            ->assertVisible('[data-testid="shop-resource-card-plan-feature"]')
            ->assertVisible('[data-testid="shop-resource-plan-badge-plan-feature"]')
            ->assertSeeIn('[data-testid="shop-resource-plan-badge-plan-feature"]', 'Incluido')
            ->assertVisible('[data-testid="shop-resource-download-btn-plan-feature"]')

            // Buy-only → "Comprar" button
            ->assertVisible('[data-testid="shop-resource-card-premium-extra"]')
            ->assertVisible('[data-testid="shop-resource-buy-btn-premium-extra"]')
            ->assertSeeIn('[data-testid="shop-resource-buy-btn-premium-extra"]', 'Comprar')

            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
