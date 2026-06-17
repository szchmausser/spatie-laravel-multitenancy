<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the tenant user management CRUD pages.
 *
 * Covers:
 *   - Users index shows user list
 *   - Users index search filters
 *   - Users create flow
 *   - Users create validation
 *   - Users show page details
 *   - Users edit flow
 *   - Users delete flow
 *
 * All settings/users/* routes are TENANT routes requiring
 * the tenant middleware. Connection setup mirrors ChangePlanFlowTest.
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

test('users index shows user list', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Admin User',
            'email' => 'admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $otherUser = User::on('tenant')->create([
            'name' => 'Other User',
            'email' => 'other@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit('/settings/users')
            ->waitForText('Users')
            ->assertSee('Users')
            ->assertSee('Admin User')
            ->assertSee('admin@tenant.test')
            ->assertSee('Other User')
            ->assertSee('other@tenant.test')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('users index search filters results', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Searchable Admin',
            'email' => 'searchable-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        User::on('tenant')->create([
            'name' => 'Another Person',
            'email' => 'another@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit('/settings/users')
            ->waitForText('Searchable Admin')
            ->type('[data-testid="search-input"]', 'Searchable')
            ->waitForText('Searchable Admin')
            ->assertSee('Searchable Admin')
            ->assertDontSee('Another Person')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('users create flow', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Create Admin',
            'email' => 'create-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit('/settings/users')
            ->waitForText('Users')
            ->click('[data-testid="create-user-btn"]')
            ->waitForText('Create Admin')
            ->type('[data-testid="input-name"]', 'New Browser User')
            ->type('[data-testid="input-email"]', 'new-browser@tenant.test')
            ->type('[data-testid="input-password"]', 'password123')
            ->type('[data-testid="input-password-confirmation"]', 'password123')
            ->click('[data-testid="submit-user-btn"]')
            ->waitForText('New Browser User')
            ->assertSee('new-browser@tenant.test')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('users create validation on empty fields', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Validation Admin',
            'email' => 'validation-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit('/settings/users/create')
            ->waitForText('Create')
            ->click('[data-testid="submit-user-btn"]')
            ->waitForText('required')
            ->assertSee('required')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('users show shows user details', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Detail Admin',
            'email' => 'detail-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $detailUser = User::on('tenant')->create([
            'name' => 'Detail Target',
            'email' => 'detail-target@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit("/settings/users/{$detailUser->id}")
            ->waitForText('Detail Target')
            ->assertSee('Detail Target')
            ->assertSee('detail-target@tenant.test')
            ->assertSee('User details')
            ->assertSee('Roles')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('users edit flow updates user', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Edit Admin',
            'email' => 'edit-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $editUser = User::on('tenant')->create([
            'name' => 'Original Name',
            'email' => 'original@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit("/settings/users/{$editUser->id}/edit")
            ->waitForText('Edit')
            ->assertValue('[data-testid="edit-input-name"]', 'Original Name')
            ->type('[data-testid="edit-input-name"]', 'Updated Name')
            ->click('[data-testid="edit-user-submit-btn"]')
            ->waitForText('Updated Name')
            ->assertSee('Updated Name')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('users delete flow removes user', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Delete Admin',
            'email' => 'delete-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $deleteUser = User::on('tenant')->create([
            'name' => 'To Be Deleted',
            'email' => 'to-delete@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit('/settings/users')
            ->waitForText('To Be Deleted')
            ->assertSee('To Be Deleted')
            ->click("[data-testid=\"delete-user-btn-{$deleteUser->id}\"]")
            ->waitForText('Are you sure')
            ->click("[data-testid=\"confirm-delete-user-btn-{$deleteUser->id}\"]")
            ->waitForText('Users')
            ->assertDontSee('To Be Deleted')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
