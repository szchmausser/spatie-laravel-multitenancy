<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Browser\BrowserTestCase;

uses(BrowserTestCase::class);

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
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

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
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('admin badge is not visible in user menu for non-admin user', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

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
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

/**
 * Point the `tenant` connection at the test physical database and
 * purge the cached connection. The test environment cannot create
 * per-tenant PostgreSQL databases inside a transaction, so the
 * tenant connection is repointed at the same physical DB that
 * `landlord` points to for the duration of the test.
 */
function pointTenantConnectionAtTestDatabase(): void
{
    $testDatabase = config('database.connections.landlord.database');

    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');
}

/**
 * Run the Spatie permission tables migration on the current tenant
 * connection, bypassing the `migrate` command. This is necessary
 * in tests because the `migrations` table is shared between the
 * default and tenant connections in the test environment.
 */
function runSpatiePermissionMigration(): void
{
    $migration = require base_path('database/migrations/2026_06_06_132424_create_permission_tables.php');
    $migration->up();
}

/**
 * Set the default connection to `tenant` and return the previous
 * default connection name so the caller can restore it. The Spatie
 * migration guards itself with `DB::connection()->getName()` (the
 * default connection), so we have to switch the default to `tenant`
 * before invoking the migration manually.
 */
function setDefaultConnectionToTenant(): string
{
    $previous = config('database.default');

    DB::setDefaultConnection('tenant');

    return $previous;
}

function restoreDefaultConnection(string $previous): void
{
    DB::setDefaultConnection($previous);
}

/**
 * Run the TenantPermissionsSeeder on the active tenant connection.
 * Caller MUST have set the default connection to `tenant` first
 * (use {@see setDefaultConnectionToTenant()}).
 */
function runTenantPermissionsSeeder(): void
{
    (new TenantPermissionsSeeder)->run();
}
