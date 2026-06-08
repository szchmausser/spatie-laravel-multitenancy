<?php

namespace Database\Seeders;

use App\Models\Auth\Permission;
use App\Models\Auth\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the per-tenant authorization catalog inside every tenant
 * database.
 *
 * Per-tenant replication contract:
 *   - `change-plan` permission (verb, kebab-case; refactor to
 *     `billing.*` at 3+ billing perms)
 *   - `tenant-admin` role granted `change-plan` via `givePermissionTo`
 *
 * Idempotency: this seeder is safe to run multiple times. The
 * `findOrCreate` calls return the existing row when one already
 * exists, and `syncPermissions` (used internally by `givePermissionTo`
 * with a single permission) only inserts new mappings. Calling the
 * seeder twice produces zero duplicates and leaves the catalog in
 * the same state.
 *
 * Iteration: this seeder iterates the same way `TenantUsersSeeder`
 * does — it cannot assume a single tenant context because it is
 * called from `DatabaseSeeder`, which runs in the landlord context.
 * The `creating` callback of the Tenant model is what points the
 * `tenant` connection at a specific DB during a single `Tenant::create`
 * call, but by the time the seeders chain runs that pointer has
 * been left on the last-created tenant. So we point the connection
 * ourselves for each iteration.
 *
 * Default-connection flip: the Spatie `Role` and `Permission` models
 * use the global default connection. After pointing the `tenant`
 * connection at the current tenant's DB, we ALSO call
 * `DB::setDefaultConnection('tenant')` so the Spatie queries hit the
 * right database. Without this, `Role::findOrCreate` queries the
 * landlord `pgsql` DB and blows up with "no existe la relación
 * «roles»" because the Spatie permission migration is gated to the
 * `tenant` connection only (see
 * `database/migrations/2026_06_06_132424_create_permission_tables.php`,
 * which `return`s early when not on the `tenant` connection).
 *
 * Cache flush: `forgetCachedPermissions()` runs before AND after each
 * tenant's seeding pass so a stale cache from a previous iteration
 * or a previous run cannot mask missing rows.
 *
 * Wiring: this seeder is called from `DatabaseSeeder` BEFORE
 * `TenantUsersSeeder`, so the first user per tenant can be
 * assigned `tenant-admin` without "role does not exist" errors.
 *
 * Test note: when invoked from a feature test that uses
 * `createQuietly()` + manual tenant connection pointing, the seeder
 * operates on whatever the `tenant` connection is currently
 * pointed at (no special handling is required — both production
 * `Tenant::creating` and the test path use the same connection
 * name).
 */
class TenantPermissionsSeeder extends Seeder
{
    /**
     * The permission names this seeder guarantees to exist on every
     * tenant DB.
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = [
        'change-plan',
    ];

    /**
     * The role names this seeder guarantees to exist on every
     * tenant DB, mapped to the permissions each role is granted.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLES_WITH_PERMISSIONS = [
        'tenant-admin' => [
            'change-plan',
        ],
    ];

    public function run(): void
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $this->forTenant($tenant);
        }
    }

    /**
     * Run the seeding logic on the currently configured tenant connection.
     * Useful for tests where the connection is already pointed at the target DB,
     * avoiding iteration over all landlord tenants (which may include non-existent test DBs).
     */
    public function runForCurrentConnection(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = $this->ensurePermissionsExist();
        $this->ensureRolesWithPermissionsExist($permissions);
    }

    public function forTenant(Tenant $tenant): void
    {
        $this->pointTenantConnectionAt($tenant->database);

        try {
            // Flush the Spatie permission cache so a previous run
            // (or test) can't mask missing rows.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $permissions = $this->ensurePermissionsExist();
            $this->ensureRolesWithPermissionsExist($permissions);
        } finally {
            $this->forgetTenantConnection();
        }
    }

    /**
     * Rewrite the `tenant` connection's `database` value in the
     * in-memory config and drop the cached PDO so the next query
     * opens a fresh connection against the new DB.
     *
     * The Spatie Role and Permission models are bound to the
     * `tenant` connection via {@see Role} and
     * {@see Permission}, so flipping the global
     * default is not required: the model queries land on the
     * `tenant` connection automatically.
     */
    private function pointTenantConnectionAt(string $database): void
    {
        config(['database.connections.tenant.database' => $database]);
        DB::purge('tenant');
    }

    private function forgetTenantConnection(): void
    {
        DB::purge('tenant');
    }

    /**
     * Ensure every permission in {@see self::PERMISSIONS} exists
     * on the tenant connection. Returns the resolved permission
     * instances keyed by name, so {@see self::ensureRolesWithPermissionsExist()}
     * can attach them to the roles.
     *
     * @return array<string, Permission>
     */
    protected function ensurePermissionsExist(): array
    {
        $resolved = [];

        foreach (self::PERMISSIONS as $name) {
            $resolved[$name] = Permission::findOrCreate($name, 'web');
        }

        return $resolved;
    }

    /**
     * Ensure every role in {@see self::ROLES_WITH_PERMISSIONS} exists
     * on the tenant connection, and that each role is granted its
     * declared permissions.
     *
     * `givePermissionTo` is idempotent for a single permission: it
     * uses `syncPermissions` internally, so calling it twice with
     * the same permission produces no duplicates.
     *
     * @param  array<string, Permission>  $permissions
     */
    protected function ensureRolesWithPermissionsExist(array $permissions): void
    {
        foreach (self::ROLES_WITH_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions(array_map(
                fn (string $name): Permission => $permissions[$name],
                $permissionNames,
            ));
        }
    }
}
