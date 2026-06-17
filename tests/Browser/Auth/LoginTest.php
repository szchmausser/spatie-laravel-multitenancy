<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the login flow.
 *
 * Tests the actual login UI without actingAs() to verify that:
 *   - Valid credentials grant access to the dashboard
 *   - Invalid credentials show error messages
 *   - The complete authentication flow works end-to-end
 *
 * These tests are CRITICAL — they verify the entry point to the app.
 * All other tests use actingAs() which bypasses the login UI.
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

test('tenant user can login with valid credentials', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Login Test User',
            'email' => 'login@tenant.test',
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        // makeCurrent() is needed so post-login redirect to /dashboard works
        $tenant->makeCurrent();

        // ⚠️ NO usar actingAs() — este test DEBE usar la UI real
        $this->visit('/login')
            ->waitForText('Email address')
            ->type('input[name="email"]', 'login@tenant.test')
            ->type('input[name="password"]', 'password123')
            ->click('[data-test="login-button"]')
            ->waitForText('Dashboard')
            ->assertPathIs('/dashboard')
            ->assertSee('Login Test User')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('login shows error with invalid credentials', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        User::on('tenant')->create([
            'name' => 'Valid User',
            'email' => 'valid@tenant.test',
            'password' => bcrypt('correctpassword'),
            'email_verified_at' => now(),
        ]);

        // Login is a public route — no makeCurrent() needed
        $this->visit('/login')
            ->waitForText('Email address')
            ->type('input[name="email"]', 'valid@tenant.test')
            ->type('input[name="password"]', 'wrongpassword')
            ->click('[data-test="login-button"]')
            ->waitForText('These credentials do not match our records')
            ->assertSee('These credentials do not match our records')
            ->assertPathIs('/login')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
