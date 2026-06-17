<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the settings security page.
 *
 * Covers:
 *   - Security page loads with password form
 *   - Security change password flow
 *
 * Security settings are shared routes (not tenant-scoped) but
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

test('security page loads with password form', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Security User',
            'email' => 'security@tenant.test',
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
            ->assertSee('Ensure your account is using a long, random password to stay secure')
            ->assertSee('Current password')
            ->assertSee('New password')
            ->assertSee('Confirm password')
            ->assertSee('Save')
            ->assertVisible('[data-testid="update-password-button"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('security change password flow', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Password Changer',
            'email' => 'password-changer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/settings/security')
            ->waitForText('Security settings')
            ->type('input[name="current_password"]', 'password')
            ->type('input[name="password"]', 'newpassword123')
            ->type('input[name="password_confirmation"]', 'newpassword123')
            ->click('[data-testid="update-password-button"]')
            ->wait(1)
            ->assertSee('Security settings')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
