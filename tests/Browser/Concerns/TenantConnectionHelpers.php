<?php

namespace Tests\Browser\Concerns;

use App\Models\Tenant;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\TenantFinder\TenantFinder;
use Tests\Browser\FakeTenantFinder;

/**
 * Shared helpers for browser tests that need tenant database access.
 *
 * Extracts duplicated code from UserMenuBadgeTest and ChangePlanFlowTest
 * into a reusable trait. These helpers manage the tenant connection setup
 * required for tests that create users or interact with tenant-specific
 * tables (like Spatie permission tables).
 */
trait TenantConnectionHelpers
{
    /**
     * Point the `tenant` connection at the test physical database and
     * purge the cached connection. The test environment cannot create
     * per-tenant PostgreSQL databases inside a transaction, so the
     * tenant connection is repointed at the same physical DB that
     * `landlord` points to for the duration of the test.
     */
    protected function pointTenantConnectionAtTestDatabase(): void
    {
        $testDatabase = config('database.connections.landlord.database');

        config(['database.connections.tenant.database' => $testDatabase]);
        DB::purge('tenant');
    }

    /**
     * Set the default connection to `tenant` and return the previous
     * default connection name so the caller can restore it. The Spatie
     * migration guards itself with `DB::connection()->getName()` (the
     * default connection), so we have to switch the default to `tenant`
     * before invoking the migration manually.
     */
    protected function setDefaultConnectionToTenant(): string
    {
        $previous = config('database.default');

        DB::setDefaultConnection('tenant');

        return $previous;
    }

    /**
     * Restore the default connection to the previous value.
     */
    protected function restoreDefaultConnection(string $previous): void
    {
        DB::setDefaultConnection($previous);
    }

    /**
     * Run the Spatie permission tables migration on the current tenant
     * connection, bypassing the `migrate` command. This is necessary
     * in tests because the `migrations` table is shared between the
     * default and tenant connections in the test environment.
     */
    protected function runSpatiePermissionMigration(): void
    {
        $migration = require base_path('database/migrations/2026_06_06_132424_create_permission_tables.php');
        $migration->up();
    }

    /**
     * Run the TenantPermissionsSeeder on the active tenant connection.
     * Caller MUST have set the default connection to `tenant` first
     * (use {@see setDefaultConnectionToTenant()}).
     */
    protected function runTenantPermissionsSeeder(): void
    {
        $tenant = Tenant::latest('id')->first();

        if ($tenant) {
            $originalDb = $tenant->database;
            $tenant->database = config('database.connections.landlord.database');
            (new TenantPermissionsSeeder)->forTenant($tenant);
            $tenant->database = $originalDb;
        } else {
            (new TenantPermissionsSeeder)->run();
        }
    }

    /**
     * Set up the tenant connection for a browser test.
     *
     * This is a convenience method that combines the common setup steps:
     * 1. Point tenant connection at test database
     * 2. Set default connection to tenant
     * 3. Drop and recreate permission tables
     * 4. Run Spatie permission migration
     * 5. Run tenant permissions seeder
     *
     * @return string The previous default connection name (for restore)
     */
    protected function setupTenantConnectionForTest(): string
    {
        $this->pointTenantConnectionAtTestDatabase();
        $previousDefault = $this->setDefaultConnectionToTenant();

        // Drop permission tables if they exist (for clean state)
        $tableNames = config('permission.table_names');
        Schema::connection('tenant')->dropIfExists($tableNames['role_has_permissions']);
        Schema::connection('tenant')->dropIfExists($tableNames['model_has_roles']);
        Schema::connection('tenant')->dropIfExists($tableNames['model_has_permissions']);
        Schema::connection('tenant')->dropIfExists($tableNames['roles']);
        Schema::connection('tenant')->dropIfExists($tableNames['permissions']);

        DB::purge('tenant');

        $this->runSpatiePermissionMigration();
        $this->runTenantPermissionsSeeder();

        return $previousDefault;
    }

    /**
     * Clean up tenant connection after a browser test.
     */
    protected function cleanupTenantConnection(string $previousDefault): void
    {
        $this->restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }

    /**
     * Bind a fake TenantFinder that always returns the given tenant.
     *
     * Because pest-plugin-browser runs the HTTP server in the same PHP
     * process, this binding affects the server too. Every request will
     * resolve to this tenant regardless of the HTTP Host header.
     */
    protected function fakeTenantFinderForTest(Tenant $tenant): void
    {
        app()->bind(TenantFinder::class, fn () => new FakeTenantFinder($tenant));
    }
}
