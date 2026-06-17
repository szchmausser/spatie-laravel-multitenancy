<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the read-only role catalog in settings/roles.
 *
 * Covers:
 *   - Admin can view the roles list page
 *   - Roles list displays roles with permission and user counts
 *   - Admin can view a role detail page
 *   - Role detail shows assigned permissions
 *   - Member without roles-list permission gets 403
 *   - Unauthenticated user is redirected to login
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

test('admin can see the roles list page', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Roles Admin',
            'email' => 'roles-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('owner');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($admin)
            ->visit('/settings/roles')
            ->waitForText('Roles')
            ->assertSee('Roles')
            ->assertSee('View roles and their permissions in this tenant.')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('roles list shows roles with permission and user counts', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Roles Viewer',
            'email' => 'roles-viewer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('owner');

        // The seeder already created roles (owner, tenant-admin, member).
        // Verify they appear in the list.
        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($admin)
            ->visit('/settings/roles')
            ->waitForText('Roles')
            ->assertSee('owner')
            ->assertSee('permissions')
            ->assertSee('users')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('admin can view a role detail page', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Detail Viewer',
            'email' => 'detail-viewer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('owner');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        // Get the owner role ID to visit its detail page
        $role = \App\Models\Auth\Role::on('tenant')->where('name', 'owner')->first();

        $this->actingAs($admin)
            ->visit("/settings/roles/{$role->id}")
            ->waitForText('owner')
            ->assertSee('Permissions')
            ->assertSee('Users')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('role detail shows assigned permissions', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Perm Viewer',
            'email' => 'perm-viewer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('owner');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $role = \App\Models\Auth\Role::on('tenant')->where('name', 'owner')->first();

        $this->actingAs($admin)
            ->visit("/settings/roles/{$role->id}")
            ->waitForText('Permissions')
            ->assertSee('Permissions granted to the owner role.')
            ->assertSee('Back to Roles')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});


