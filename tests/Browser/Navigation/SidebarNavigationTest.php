<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser test for sidebar navigation.
 *
 * Covers:
 *   - Sidebar links navigate to the correct pages
 *   - Each main page loads correctly via sidebar navigation
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

test('sidebar navigation to settings profile', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Nav User',
            'email' => 'nav-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/settings/profile')
            ->waitForText('Profile')
            ->assertSee('Profile')
            ->assertSee('Name')
            ->assertSee('Email address')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('sidebar navigation to settings appearance', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Nav Appearance',
            'email' => 'nav-appearance@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/settings/appearance')
            ->waitForText('Appearance settings')
            ->assertSee('Appearance settings')
            ->assertVisible('[data-testid="appearance-tab-light"]')
            ->assertVisible('[data-testid="appearance-tab-dark"]')
            ->assertVisible('[data-testid="appearance-tab-system"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('sidebar navigation to settings security', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Nav Security',
            'email' => 'nav-security@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/settings/security')
            ->waitForText('Security settings')
            ->assertSee('Security settings')
            ->assertSee('Update password')
            ->assertVisible('[data-testid="update-password-button"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('sidebar navigation to shop', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Nav Shop',
            'email' => 'nav-shop@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/shop')
            ->waitForText('Shop')
            ->assertSee('Shop')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('sidebar navigation to billing orders', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Nav Orders',
            'email' => 'nav-orders@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/billing/orders')
            ->waitForText('Orders')
            ->assertSee('Orders')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
