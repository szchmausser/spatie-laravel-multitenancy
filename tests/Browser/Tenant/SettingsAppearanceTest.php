<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the settings appearance page.
 *
 * Covers:
 *   - Appearance page loads with theme tabs
 *   - Appearance theme toggle
 *
 * Appearance settings are shared routes (not tenant-scoped) but
 * used by tenant users. The user is created on the tenant
 * connection for consistency with other tenant browser tests.
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

test('appearance page loads with theme tabs', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Appearance User',
            'email' => 'appearance@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/settings/appearance')
            ->waitForText('Appearance settings')
            ->assertSee('Appearance settings')
            ->assertSee('Update the appearance settings for your account')
            ->assertVisible('[data-testid="appearance-tab-light"]')
            ->assertVisible('[data-testid="appearance-tab-dark"]')
            ->assertVisible('[data-testid="appearance-tab-system"]')
            ->assertSee('Light')
            ->assertSee('Dark')
            ->assertSee('System')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('appearance theme toggle', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Theme Toggle',
            'email' => 'theme-toggle@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/settings/appearance')
            ->waitForText('Appearance settings')
            // Click dark tab and verify dark class is added to <html>
            ->click('[data-testid="appearance-tab-dark"]')
            ->assertScript('document.documentElement.classList.contains("dark")', true)
            ->assertScript('localStorage.getItem("appearance")', 'dark')
            // Click light tab and verify dark class is removed
            ->click('[data-testid="appearance-tab-light"]')
            ->assertScript('document.documentElement.classList.contains("dark")', false)
            ->assertScript('localStorage.getItem("appearance")', 'light')
            // Click system tab and verify it's set
            ->click('[data-testid="appearance-tab-system"]')
            ->assertScript('localStorage.getItem("appearance")', 'system')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
