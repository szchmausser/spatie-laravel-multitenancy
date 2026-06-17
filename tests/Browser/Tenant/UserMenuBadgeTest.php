<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the "Admin" badge in the user menu.
 *
 * Pinned contract (openSpec change 1.5G.0, Task 6):
 *   - The badge renders inside the user menu (after `<UserInfo>`)
 *     when `auth.user.roles.includes('tenant-admin')` is true.
 *   - The badge text is "Admin".
 *   - The badge uses `data-testid="user-role-badge"` as the stable
 *     selector (browser-testing §3.5 top priority).
 *   - The badge is absent for users with no roles.
 *
 * Browser-testing principles followed:
 *   1. No direct HTTP calls — every interaction goes through the UI.
 *   2. Self-sufficient — data is set up in `beforeEach`, no order
 *      dependencies, `actAs()` used for auth precondition.
 *   3. `actingAs()` for auth (login is not the behavior under test).
 *   4. Factories only before the browser starts.
 *   5. Real UI assertions — `assertSeeIn` on the badge text, not
 *      just `assertPathIs`.
 *   6. No `assertDatabaseHas` in browser tests.
 *   7. `data-testid` selectors — top of the priority order.
 *
 * Connection setup mirrors `tests/Feature/Auth/TenantPermissionsTest.php`:
 *   - point the `tenant` connection at the test physical DB,
 *   - run the Spatie permission migration,
 *   - run the idempotent TenantPermissionsSeeder,
 *   - create the user on the tenant connection,
 *   - assign the role,
 *   - wrap the role-creation step in try/finally so the default
 *     connection is always restored and the tenant connection is
 *     purged between tests.
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

test('admin badge is visible in user menu for tenant-admin', function () {
    // Domain MUST match the test server host (127.0.0.1) so the
    // DomainTenantFinder can resolve the tenant from the HTTP request.
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Badge Admin',
            'email' => 'badge-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $user->assignRole('tenant-admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)
            ->visit('/dashboard')
            ->waitForText($user->name)
            ->click('@user-menu-trigger')
            ->waitFor('[role="menu"]')
            ->assertVisible('@user-role-badge')
            ->assertSeeIn('@user-role-badge', 'Admin');
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('admin badge is not visible in user menu for non-admin user', function () {
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Badge Regular',
            'email' => 'badge-regular@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        // No role assigned — the badge must be absent.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)
            ->visit('/dashboard')
            ->waitForText('Badge Regular')
            ->click('@user-menu-trigger')
            ->waitFor('[role="menu"]')
            ->assertMissing('@user-role-badge');
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
