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
 * Browser tests for resource download flow.
 *
 * Covers:
 *   - User with access can see download button on resource detail page
 *   - Free resources are downloadable by any authenticated tenant
 *   - Premium resources show download button when user has access
 *   - Premium resources without access show "Buy" button
 *
 * This is CRITICAL — if broken, users pay for content they cannot download.
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

test('user can download free resource', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $freeResource = Resource::factory()->create([
        'name' => 'Free Guide',
        'slug' => 'free-guide',
        'is_premium' => false,
        'price_cents' => 0,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Free User',
            'email' => 'free@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit("/resources/{$freeResource->slug}")
            ->waitForText('Free Guide')
            ->assertSee('Free Guide')
            ->assertVisible('[data-testid="resource-show-free-badge-free-guide"]')
            ->assertVisible('[data-testid="resource-show-download-btn-free-guide"]')
            ->assertSeeIn('[data-testid="resource-show-download-btn-free-guide"]', 'Download')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('user with premium plan can download premium resource', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $premiumPlan = Plan::factory()->create([
        'name' => 'Premium Plan',
        'slug' => 'premium',
        'is_active' => true,
        'features' => ['premium-zone' => true],
    ]);

    $tenant->subscription()->create([
        'plan_id' => $premiumPlan->id,
        'status' => 'active',
    ]);

    $premiumResource = Resource::factory()->create([
        'name' => 'Premium eBook',
        'slug' => 'premium-ebook',
        'is_premium' => true,
        'price_cents' => 2500,
    ]);

    // Attach the resource to the plan via pivot
    $premiumPlan->resources()->attach($premiumResource->id);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Premium User',
            'email' => 'premium@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit("/resources/{$premiumResource->slug}")
            ->waitForText('Premium eBook')
            ->assertSee('Premium eBook')
            ->assertVisible('[data-testid="resource-show-premium-badge-premium-ebook"]')
            ->assertVisible('[data-testid="resource-show-download-btn-premium-ebook"]')
            ->assertSeeIn('[data-testid="resource-show-download-btn-premium-ebook"]', 'Download')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('user without premium access sees buy button for premium resource', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $basicPlan = Plan::factory()->create([
        'name' => 'Basic Plan',
        'slug' => 'basic',
        'is_active' => true,
        'features' => [],
    ]);

    $tenant->subscription()->create([
        'plan_id' => $basicPlan->id,
        'status' => 'active',
    ]);

    $premiumResource = Resource::factory()->create([
        'name' => 'Premium Video',
        'slug' => 'premium-video',
        'is_premium' => true,
        'price_cents' => 3500,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Basic User',
            'email' => 'basic@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit("/resources/{$premiumResource->slug}")
            ->waitForText('Premium Video')
            ->assertSee('Premium Video')
            ->assertVisible('[data-testid="resource-show-premium-badge-premium-video"]')
            ->assertVisible('[data-testid="resource-show-buy-btn-premium-video"]')
            ->assertSeeIn('[data-testid="resource-show-buy-btn-premium-video"]', 'Buy')
            ->assertDontSee('Download')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
