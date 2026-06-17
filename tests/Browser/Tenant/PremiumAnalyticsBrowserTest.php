<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the premium analytics page (/premium/analytics).
 *
 * Covers:
 *   - User with premium-zone feature can access the page
 *   - Page displays stat cards (users, sessions, revenue)
 *   - Premium badge is visible
 *   - User without premium-zone feature is denied (403)
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

test('user with premium-zone feature can access premium analytics', function () {
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

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Premium User',
            'email' => 'premium-analytics@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/premium/analytics')
            ->waitForText('Premium Analytics')
            ->assertSee('Premium Analytics')
            ->assertVisible('[data-testid="premium-badge"]')
            ->assertVisible('[data-testid="stat-card-users"]')
            ->assertVisible('[data-testid="stat-card-sessions"]')
            ->assertVisible('[data-testid="stat-card-revenue"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium analytics displays stat values', function () {
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

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Stat User',
            'email' => 'stat-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/premium/analytics')
            ->waitForText('Premium Analytics')
            ->assertVisible('[data-testid="stat-users"]')
            ->assertVisible('[data-testid="stat-sessions"]')
            ->assertVisible('[data-testid="stat-revenue"]')
            ->assertSee('Total users')
            ->assertSee('Active sessions')
            ->assertSee('Revenue (MTD)')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('user without premium-zone feature gets 403 on premium analytics', function () {
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

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Basic User',
            'email' => 'basic-analytics@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/premium/analytics')
            ->waitForText('403')
            ->assertSee('403')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium analytics shows feature description', function () {
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

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Feature User',
            'email' => 'feature-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/premium/analytics')
            ->waitForText('Premium Analytics')
            ->assertSee('premium-zone')
            ->assertSee('Welcome to your premium dashboard')
            ->assertSee('Protected by the')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
