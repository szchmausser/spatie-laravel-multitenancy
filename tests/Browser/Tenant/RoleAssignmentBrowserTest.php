<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the tenant role assignment UI on the user show page.
 *
 * Covers:
 *   - Owner can assign a role to a member user
 *   - Tenant-admin can assign a role to a member user
 *   - Self-protection: user cannot change their own role (no selector)
 *   - Owner immutable: owner badge visible, no selector for changing owner
 *   - Tenant-admin constraint: no selector for changing another tenant-admin
 *   - Role badge updates after assignment
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

test('owner can assign role to member user via UI', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $owner = User::on('tenant')->create([
            'name' => 'Role Owner',
            'email' => 'role-owner@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $owner->assignRole('owner');

        $target = User::on('tenant')->create([
            'name' => 'Role Target',
            'email' => 'role-target@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();
        $this->actingAs($owner)
            ->visit("/settings/users/{$target->id}")
            ->waitForText('Role Target')
            ->assertSee('No role assigned')
            ->assertSee('Change role:')
            ->click('[data-testid="assign-role-select"]')
            ->waitForText('member')
            ->click('[data-testid="role-option-member"]')
            ->waitForText('member')
            ->assertDontSee('No role assigned')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('tenant-admin can assign role to member user via UI', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Admin Assigner',
            'email' => 'admin-assigner@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $target = User::on('tenant')->create([
            'name' => 'Target Member',
            'email' => 'target-member@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit("/settings/users/{$target->id}")
            ->waitForText('Target Member')
            ->assertSee('Change role:')
            ->click('[data-testid="assign-role-select"]')
            ->waitForText('tenant-admin')
            ->click('[data-testid="role-option-tenant-admin"]')
            ->waitForText('tenant-admin')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('self-protection: user cannot see role selector for own role', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'Self Admin',
            'email' => 'self-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit("/settings/users/{$admin->id}")
            ->waitForText('Self Admin')
            ->assertSee('You cannot change your own role.')
            ->assertDontSee('Change role:')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('owner immutable: owner role badge shown with no role selector', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $owner = User::on('tenant')->create([
            'name' => 'Real Owner',
            'email' => 'real-owner@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $owner->assignRole('owner');

        $target = User::on('tenant')->create([
            'name' => 'Also Owner',
            'email' => 'also-owner@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $target->assignRole('owner');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();
        $this->actingAs($owner)
            ->visit("/settings/users/{$target->id}")
            ->waitForText('Also Owner')
            ->assertSee('owner')
            ->assertSee('Owner role is immutable.')
            ->assertDontSee('Change role:')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('tenant-admin constraint: no selector for changing another tenant-admin', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $admin = User::on('tenant')->create([
            'name' => 'First Admin',
            'email' => 'first-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('tenant-admin');

        $otherAdmin = User::on('tenant')->create([
            'name' => 'Second Admin',
            'email' => 'second-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $otherAdmin->assignRole('tenant-admin');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();
        $this->actingAs($admin)
            ->visit("/settings/users/{$otherAdmin->id}")
            ->waitForText('Second Admin')
            ->assertSee('You cannot change this role.')
            ->assertDontSee('Change role:')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
