<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the public welcome page (/).
 *
 * Covers:
 *   - Guest sees "Log in" and "Register" links (not authenticated links)
 *   - Authenticated tenant user sees "Log out" and "Dashboard" links (not guest links)
 *   - Guest can click "Log in" to navigate to the login page
 *
 * The welcome page is a static landing page — tests focus on the
 * auth-conditional nav, not static content.
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

test('guest sees login and register links on welcome page', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $this->fakeTenantFinderForTest($tenant);
    $tenant->makeCurrent();

    $this->visit('/')
        ->waitForText('Log in')
        ->assertSee('Log in')
        ->assertSee('Register')
        ->assertDontSee('Log out')
        ->assertDontSee('Dashboard')
        ->assertNoJavaScriptErrors();
});

test('authenticated tenant user sees logout and dashboard links', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Welcome User',
            'email' => 'welcome-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/')
            ->waitForText('Log out')
            ->assertSee('Log out')
            ->assertSee('Dashboard')
            ->assertDontSee('Log in')
            ->assertDontSee('Register')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('guest can click login link to navigate to login page', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $this->fakeTenantFinderForTest($tenant);
    $tenant->makeCurrent();

    $this->visit('/')
        ->waitForText('Log in')
        ->click('Log in')
        ->waitForText('Log in')
        ->assertSee('Email')
        ->assertSee('Password')
        ->assertNoJavaScriptErrors();
});
