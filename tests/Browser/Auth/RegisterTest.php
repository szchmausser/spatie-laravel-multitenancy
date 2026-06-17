<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for tenant registration flow.
 *
 * Registration is TENANT-SCOPED — the user registers within an existing
 * tenant context. The /register route requires Tenant::current() to be set.
 *
 * Tests cover:
 *   - New user can register and access dashboard
 *   - Validation for required fields
 *   - Password confirmation mismatch
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

test('new user can register and access dashboard', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $uniqueId = uniqid('test-', true);
    $email = "owner-{$uniqueId}@test.com";
    $name = "Test Owner {$uniqueId}";

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $tenant->makeCurrent();

        $this->visit('/register')
            ->waitForText('Create an account')
            ->type('input[name="name"]', $name)
            ->type('input[name="email"]', $email)
            ->type('input[name="password"]', 'password123')
            ->type('input[name="password_confirmation"]', 'password123')
            ->click('[data-test="register-button"]')
            ->waitForText('Dashboard', 15)
            ->assertPathIs('/dashboard')
            ->assertSee($name)
            ->assertNoJavaScriptErrors();

        // Verify user was created in tenant database
        $userExists = DB::connection('tenant')
            ->table('users')
            ->where('email', $email)
            ->exists();
        expect($userExists)->toBeTrue();
    } finally {
        // Cleanup: delete the registered user
        DB::connection('tenant')->table('users')->where('email', $email)->delete();
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('registration prevents submit with empty fields', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $tenant->makeCurrent();

        // HTML5 required attributes prevent form submission when fields are empty.
        // Playwright cannot interact with native browser validation tooltips,
        // so we verify the user stays on /register (form did not submit).
        $this->visit('/register')
            ->waitForText('Create an account')
            ->click('[data-test="register-button"]')
            ->assertPathIs('/register')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('registration validates password confirmation match', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $uniqueId = uniqid('test-', true);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $tenant->makeCurrent();

        $this->visit('/register')
            ->waitForText('Create an account')
            ->type('input[name="name"]', "Test User {$uniqueId}")
            ->type('input[name="email"]', "user-{$uniqueId}@test.com")
            ->type('input[name="password"]', 'password123')
            ->type('input[name="password_confirmation"]', 'different456')
            ->click('[data-test="register-button"]')
            ->waitForText('password')
            ->assertSee('confirmation')
            ->assertPathIs('/register')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
