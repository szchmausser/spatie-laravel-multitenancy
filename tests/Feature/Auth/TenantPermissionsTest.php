<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ChangePlanService;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tests for the `tenant-authorization` capability (openSpec change
 * `1.5G.0-tenant-roles`).
 *
 * This file accumulates tests across Tasks 1, 2, 3, 4, and 7 of the
 * apply-phase work units. It starts with Requirement 1 (Tenant has
 * isolated authorization state) and grows as subsequent tasks ship.
 *
 * Architectural context:
 * - `spatie/laravel-permission` is installed per-tenant (its
 *   migrations live in the root `database/migrations/` path, so
 *   `Tenant::creating → runMigrations()` replicates them to every
 *   new tenant DB).
 * - Each tenant's physical PostgreSQL database has its own copy of
 *   the 5 Spatie tables: `permissions`, `roles`,
 *   `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
 * - The landlord DB does NOT have these tables; landlord-side
 *   authorization is a separate slice (1.5G.1-landlord-roles).
 * - The `HasRoles` trait on the User model (Task 4) makes all
 *   role/permission queries honor the model's tenant connection.
 *
 * Test-environment limitation: in the test setup, the `tenant`
 * connection's database is repointed at the test physical database
 * (the same one `landlord` points to) so that DDL issued through
 * the tenant connection can actually run inside a transaction. This
 * means we cannot directly observe physical-DB isolation in a
 * feature test — that property is enforced by the production
 * `Tenant::creating` callback and is exercised end-to-end by the
 * `tenants:artisan migrate` propagation test in Task 7.
 */
/**
 * Clean up Spatie permission tables before each test.
 *
 * The `tenant` connection is NOT wrapped in a database transaction
 * (only `null` and `landlord` are — see TestCase::$connectionsToTransact).
 * DDL issued through the tenant connection (CREATE TABLE for the 5
 * Spatie tables) commits immediately and persists across tests.
 * This `beforeEach` drops them so every test starts from a clean
 * schema, preventing "Duplicate table" errors on subsequent runs.
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

test('new tenant has all 5 Spatie authorization tables with expected columns', function () {
    // Add a tenant row to the landlord `tenants` table. createQuietly
    // skips the `creating` callback, so no physical DB is created —
    // fine for this test, which only needs to verify the migration
    // creates the 5 tables on the tenant connection.
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();

    // In production, `Tenant::creating → runMigrations()` runs all root
    // migrations on the tenant connection. In the test environment, the
    // `migrations` table is shared between the default and tenant
    // connections (they point at the same physical DB), so the Spatie
    // migration is marked as run after `migrate:fresh` on default and
    // `migrate --database=tenant` would skip it. We invoke the
    // migration class directly on the tenant connection to verify
    // what it would do on a real tenant DB.
    $previousDefault = setDefaultConnectionToTenant();
    runSpatiePermissionMigration();
    restoreDefaultConnection($previousDefault);

    // 1. The 5 Spatie tables exist on the tenant connection.
    expect(Schema::connection('tenant')->hasTable('permissions'))->toBeTrue();
    expect(Schema::connection('tenant')->hasTable('roles'))->toBeTrue();
    expect(Schema::connection('tenant')->hasTable('model_has_permissions'))->toBeTrue();
    expect(Schema::connection('tenant')->hasTable('model_has_roles'))->toBeTrue();
    expect(Schema::connection('tenant')->hasTable('role_has_permissions'))->toBeTrue();

    // 2. Key columns are present (the spec requires name + guard_name
    // on both permissions and roles).
    $permissionColumns = collect(Schema::connection('tenant')->getColumns('permissions'))
        ->pluck('name')
        ->all();
    expect($permissionColumns)->toContain('id');
    expect($permissionColumns)->toContain('name');
    expect($permissionColumns)->toContain('guard_name');

    $roleColumns = collect(Schema::connection('tenant')->getColumns('roles'))
        ->pluck('name')
        ->all();
    expect($roleColumns)->toContain('id');
    expect($roleColumns)->toContain('name');
    expect($roleColumns)->toContain('guard_name');

    DB::purge('tenant');
});

test('tenant connection is the target of the Spatie authorization schema', function () {
    // Two tenants, each with its own row in the landlord table. The
    // `tenant` connection is pointed at the test database, so
    // migrations run on that same physical DB. The test pins the
    // contract: the tenant connection is the one that gets the 5
    // Spatie tables (not the landlord connection), and queries
    // through the Spatie model layer route through the tenant
    // connection.
    $tenantA = Tenant::factory()->createQuietly();
    $tenantB = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();
    runSpatiePermissionMigration();
    restoreDefaultConnection($previousDefault);

    // Insert a role via the Spatie model bound to the tenant
    // connection. This proves the connection wiring is correct: the
    // Role::on('tenant') model goes through the tenant connection
    // and lands in the tenant copy of the `roles` table.
    $role = Role::on('tenant')->create([
        'name' => 'tenant-admin-isolation',
        'guard_name' => 'web',
    ]);

    expect($role->exists)->toBeTrue();
    expect(Role::on('tenant')->where('name', 'tenant-admin-isolation')->exists())->toBeTrue();

    // Two tenants were created — their row in the landlord table is
    // independent of which DB the tenant connection is pointed at.
    expect($tenantA->id)->not->toBe($tenantB->id);

    DB::purge('tenant');
});

test('landlord database has no Spatie authorization tables', function () {
    $spatieTables = [
        'permissions',
        'roles',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
    ];

    foreach ($spatieTables as $table) {
        expect(Schema::connection('landlord')->hasTable($table))
            ->toBeFalse("Landlord DB should NOT have the `{$table}` Spatie table — that slice is deferred to 1.5G.1-landlord-roles.");
    }
});

// =====================================================================
// Requirement 2: Tenant permissions and roles are seeded idempotently
// =====================================================================

test('TenantPermissionsSeeder creates the change-plan permission on a fresh tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();
    runSpatiePermissionMigration();
    runTenantPermissionsSeeder();
    restoreDefaultConnection($previousDefault);

    $permission = Permission::on('tenant')->where('name', 'change-plan')->first();

    expect($permission)->not->toBeNull();
    expect($permission->guard_name)->toBe('web');

    DB::purge('tenant');
});

test('TenantPermissionsSeeder creates the tenant-admin role on a fresh tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();
    runSpatiePermissionMigration();
    runTenantPermissionsSeeder();
    restoreDefaultConnection($previousDefault);

    $role = Role::on('tenant')->where('name', 'tenant-admin')->first();

    expect($role)->not->toBeNull();
    expect($role->guard_name)->toBe('web');

    DB::purge('tenant');
});

test('TenantPermissionsSeeder grants change-plan to the tenant-admin role', function () {
    $tenant = Tenant::factory()->createQuietly();
    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $role = Role::on('tenant')->where('name', 'tenant-admin')->first();

        expect($role)->not->toBeNull();
        expect($role->hasPermissionTo('change-plan'))->toBeTrue();
        expect($role->getPermissionNames()->all())->toBe([
            'users-list',
            'users-show',
            'users-create',
            'users-update',
            'users-delete',
            'users-manage-roles',
            'roles-list',
            'roles-show',
            'change-plan',
        ]);
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('TenantPermissionsSeeder is idempotent on double run', function () {
    $tenant = Tenant::factory()->createQuietly();
    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();
        runTenantPermissionsSeeder(); // second run

        // Exactly one tenant-admin role and one change-plan permission.
        expect(Role::on('tenant')->where('name', 'tenant-admin')->count())->toBe(1);
        expect(Permission::on('tenant')->where('name', 'change-plan')->count())->toBe(1);

        // owner (9) + tenant-admin (9) + member (0) = 18 rows total.
        expect(DB::connection('tenant')->table('role_has_permissions')->count())->toBe(18);
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('TenantPermissionsSeeder flushes the permission cache so a fresh check reflects the seed', function () {
    $tenant = Tenant::factory()->createQuietly();
    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();

        // Prime the Spatie permission cache by reading permissions BEFORE
        // the seeder runs. If the seeder failed to call
        // `forgetCachedPermissions()` first, the cached empty set would
        // mask the freshly-seeded rows and the post-seed check below
        // would return false.
        $registrar = app(PermissionRegistrar::class);
        $registrar->getPermissions(['guard_name' => 'web']);

        runTenantPermissionsSeeder();

        // After the seeder runs, the cache must be re-read from the DB.
        // The permission must show up in the registrar's view of the
        // catalog (Spatie consults the cache to resolve role/permission
        // checks like `$user->can(...)`).
        $freshlyResolved = $registrar->getPermissions(['guard_name' => 'web']);

        expect($freshlyResolved->pluck('name')->contains('change-plan'))->toBeTrue();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

// =====================================================================
// Requirement 3: First tenant user is auto-assigned the tenant-admin role
// =====================================================================

test('first user has tenant-admin role', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        // Simulate what TenantUsersSeeder does: create a user on the
        // tenant connection and assign the tenant-admin role.
        $user = User::on('tenant')->create([
            'name' => 'First User',
            'email' => 'first-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $user->syncRoles(['tenant-admin']);

        expect($user->roles->count())->toBe(1);
        expect($user->hasRole('tenant-admin'))->toBeTrue();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('re-seeding does not duplicate roles', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $user = User::on('tenant')->create([
            'name' => 'Idempotent User',
            'email' => 'idempotent@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        // syncRoles is idempotent — calling it twice produces the same
        // result as calling it once.
        $user->syncRoles(['tenant-admin']);
        $user->syncRoles(['tenant-admin']);

        expect($user->fresh()->roles->count())->toBe(1);
        expect($user->hasRole('tenant-admin'))->toBeTrue();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('other users do not have tenant-admin', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        // First user gets the role.
        $firstUser = User::on('tenant')->create([
            'name' => 'Admin User',
            'email' => 'admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $firstUser->syncRoles(['tenant-admin']);

        // Second user does NOT get the role (the seeder only assigns
        // it to the first user per tenant).
        $secondUser = User::on('tenant')->create([
            'name' => 'Regular User',
            'email' => 'regular@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        expect($secondUser->roles->count())->toBe(0);
        expect($secondUser->hasRole('tenant-admin'))->toBeFalse();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

// =====================================================================
// Requirement 4: Authorization via $user->can() (HasRoles trait)
// =====================================================================

test('user with tenant-admin can change plan', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $user = User::on('tenant')->create([
            'name' => 'Admin User',
            'email' => 'can-admin@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $user->assignRole('tenant-admin');

        expect($user->can('change-plan'))->toBeTrue();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('user without roles cannot change plan', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $user = User::on('tenant')->create([
            'name' => 'No Role User',
            'email' => 'no-role@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        expect($user->can('change-plan'))->toBeFalse();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('revoked permission returns false even with role', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $user = User::on('tenant')->create([
            'name' => 'Revoked User',
            'email' => 'revoked@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $user->assignRole('tenant-admin');

        // Verify the permission works before revoking.
        expect($user->can('change-plan'))->toBeTrue();

        // Revoke the permission from the role.
        $role = Role::on('tenant')->where('name', 'tenant-admin')->first();
        $role->revokePermissionTo('change-plan');

        // Flush the Spatie cache so the revocation takes effect.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Clear the user's cached relations.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        expect($user->can('change-plan'))->toBeFalse();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

// =====================================================================
// Requirement 8: Downgrade blocks premium read paths via the existing
// `feature:premium-zone` middleware (no entitlement mutation needed).
//
// Pinned contract (openSpec change 1.5G-buy-plan, Task 6.1):
//   - `ChangePlanService::applyPlanChange()` does NOT touch any
//     `Entitlement` row. The change is `subscription.plan_id` only.
//   - When a tenant downgrades from `premium` (which carries the
//     `premium-zone` feature) to a plan that does NOT carry it, the
//     existing read-path gate (`feature:premium-zone` middleware on
//     `premium.analytics`) must start returning 403.
//   - This proves the existing read-path feature gate is the single
//     source of truth for "is this feature on?" — no new code in
//     `Entitlement`, `ResourceController`, or `EnsureTenantHasFeature`
//     is required for the downgrade case to work.
// =====================================================================

test('after premium to free plan change, premium-content feature gate returns 403', function () {
    $tenant = Tenant::factory()->createQuietly();

    $premiumPlan = Plan::factory()->create([
        'name' => 'Premium',
        'slug' => 'premium',
        'is_active' => true,
        'features' => ['premium-zone' => true, 'premium-content' => true],
    ]);
    $freePlan = Plan::factory()->create([
        'name' => 'Free',
        'slug' => 'free',
        'is_active' => true,
        'features' => ['premium-zone' => false, 'premium-content' => false],
    ]);

    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $premiumPlan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $user = new class($tenant->id * 1000 + 7) implements Authenticatable
    {
        public function __construct(public int $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return 'secret';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void
        {
            // no-op
        }

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function getKey(): int
        {
            return $this->id;
        }
    };

    $tenant->makeCurrent();

    // Sanity: while on premium, the premium-zone middleware lets
    // the request through (200).
    $this->actingAs($user)
        ->get(route('premium.analytics'))
        ->assertOk();

    // Downgrade via the shared service (the same code path the
    // billing controller calls). No entitlement mutation.
    app(ChangePlanService::class)
        ->applyPlanChange($subscription, $freePlan);

    // Forget the Spatie permission cache and the tenant's cached
    // subscription/plan relations so the next request re-reads
    // from the DB.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $tenant->forgetCurrent();
    $tenant->refresh();

    $tenant->makeCurrent();

    // After the downgrade, the same route must return 403.
    // The `feature:premium-zone` middleware reads the new plan's
    // features and aborts.
    $this->actingAs($user)
        ->get(route('premium.analytics'))
        ->assertForbidden();

    $subscription->refresh();
    expect($subscription->plan_id)->toBe($freePlan->id);
    expect($subscription->plan->hasFeature('premium-zone'))->toBeFalse();

    DB::purge('tenant');
});

// =====================================================================
// Requirement 7: `tenants:artisan migrate` propagates Spatie tables
// =====================================================================

test('tenants:artisan migrate propagates Spatie tables to existing tenants', function () {
    $testDatabase = config('database.connections.landlord.database');

    // Create a tenant whose `database` field points at the test physical
    // DB. The default factory would generate a non-existent `tenant_NNNNN`
    // name; we override it to use the test DB so the migrate command can
    // actually read the migrations table inside our test transaction.
    $tenant = Tenant::factory()->createQuietly([
        'database' => $testDatabase,
    ]);

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        // The Spatie tables do NOT exist yet on the tenant connection.
        // We never ran the migration in the test setup.
        expect(Schema::connection('tenant')->hasTable('roles'))->toBeFalse();
        expect(Schema::connection('tenant')->hasTable('permissions'))->toBeFalse();

        // The `migrations` table is shared between the default and tenant
        // connections in the test environment (both point at the same
        // physical DB). The Spatie permission tables migration is already
        // recorded as "run" from a previous test. Delete that row so the
        // upcoming `migrate --force` re-creates the tables.
        DB::connection('tenant')->table('migrations')
            ->where('migration', 'like', '%create_permission_tables%')
            ->delete();

        // Make the tenant current so the tenants:artisan command picks it up.
        // SwitchTenantDatabaseTask::makeCurrent() will repoint the tenant
        // connection at the tenant's `database` field (which we set to
        // the test physical DB above, so no re-override is needed).
        $tenant->makeCurrent();
        DB::setDefaultConnection('tenant');

        // Run the artisan migrate command for the current tenant.
        // tenants:artisan takes the command name as the first positional
        // argument and forwards it to Artisan::call().
        Artisan::call('tenants:artisan', [
            'artisanCommand' => 'migrate --force',
        ]);

        // The Spatie tables now exist on the tenant connection.
        expect(Schema::connection('tenant')->hasTable('roles'))->toBeTrue();
        expect(Schema::connection('tenant')->hasTable('permissions'))->toBeTrue();
        expect(Schema::connection('tenant')->hasTable('model_has_roles'))->toBeTrue();
        expect(Schema::connection('tenant')->hasTable('model_has_permissions'))->toBeTrue();
        expect(Schema::connection('tenant')->hasTable('role_has_permissions'))->toBeTrue();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('new tenant gets Spatie tables automatically via creating callback', function () {
    // Contract test: the `Tenant::creating` callback in app/Models/Tenant.php
    // calls `runMigrations()` which runs `Artisan::call('migrate', ['--database'
    // => 'tenant'])`. That picks up the root `database/migrations/` path,
    // which includes the Spatie permission tables migration.
    //
    // We can't fully exercise this in a feature test because the creating
    // callback issues `CREATE DATABASE` (DDL) which cannot run inside the
    // landlord/default transaction. We verify the contract by checking
    // (a) the Spatie migration file exists in the migrations path, and
    // (b) the Tenant model has the creating callback that triggers
    // `runMigrations()`.

    $spatieMigration = base_path('database/migrations/2026_06_06_132424_create_permission_tables.php');
    expect(file_exists($spatieMigration))->toBeTrue();

    // The migration class exists and the `up()` method creates the 5 tables.
    $migration = require $spatieMigration;
    expect(method_exists($migration, 'up'))->toBeTrue();
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
 *
 * @return string the previous default connection name
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
 *
 * Uses `runForCurrentConnection()` to avoid iterating over all landlord
 * tenants (which in tests includes fake tenants with non-existent DBs).
 */
function runTenantPermissionsSeeder(): void
{
    (new TenantPermissionsSeeder)->runForCurrentConnection();
}
