<?php

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser test for error pages.
 *
 * Covers:
 *   - 404 page when visiting a non-existent route
 *   - Unauthenticated access redirects to login
 *
 * This app uses Laravel's default Inertia error handling.
 * Custom error pages are not defined, so we verify the
 * framework's default behavior.
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

test('visiting a non-existent route shows 404 error', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Error User',
            'email' => 'error-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($user)
            ->visit('/this-page-does-not-exist-12345')
            // Laravel returns a 404 page — check for common 404 indicators
            ->assertSee('404')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('unauthenticated access to tenant route redirects to login', function () {
    $this->visit('/dashboard')
        ->assertPathIs('/login')
        ->assertSee('Log in');
});

test('unauthenticated access to landlord route redirects to login', function () {
    $this->visit(route('landlord.tenants.index'))
        ->assertPathIs('/login')
        ->assertSee('Log in');
});
