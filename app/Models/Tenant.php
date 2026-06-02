<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

/**
 * Tenant model with automatic provisioning.
 *
 * Extends Spatie's Tenant model and adds lifecycle callbacks that
 * automatically provision the tenant database when a new tenant is created.
 *
 * Provisioning steps (executed in the `creating` callback):
 * 0. assertTenantsTableExists() - Validates the landlords table exists
 * 1. createDatabase() - Creates the physical PostgreSQL database
 * 2. configureTenantConnection() - Points the 'tenant' connection to the new DB
 * 3. runMigrations() - Runs Laravel migrations on the new tenant database
 *
 * This model is used by the admin dashboard, seeders, and any code that
 * needs to create or manage tenants. It uses the landlord connection
 * (UsesLandlordConnection) since tenants are stored in the landlord database.
 */
class Tenant extends SpatieTenant implements IsTenant
{
    use HasFactory;
    use ImplementsTenant;
    use UsesLandlordConnection;

    protected $fillable = [
        'name',
        'domain',
        'database',
    ];

    /**
     * Register lifecycle callbacks for automatic provisioning.
     *
     * When a tenant is created, automatically:
     * 0. Validates the tenants table exists in the landlord DB (fails early)
     * 1. Creates the physical database
     * 2. Configures the tenant connection to point to the new database
     * 3. Runs all pending migrations on the new tenant database
     *
     * Step 0 is a precondition guard: without the tenants table the Eloquent
     * INSERT itself will fail, but only after the irreversible provisioning
     * work (DB creation + migrations) has already happened. This guard fails
     * first with an actionable message.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->assertTenantsTableExists();
            $tenant->createDatabase();
            $tenant->configureTenantConnection();
            $tenant->runMigrations();
        });
    }

    /**
     * Assert that the tenants table exists in the landlord database.
     *
     * Without this table the Eloquent INSERT inside Tenant::create() will
     * fail with a "table not found" exception, but only after the creating
     * callback has already done irreversible work (creating the tenant DB
     * and running migrations). This guard fails early with a clear message
     * so the developer knows exactly what to run.
     *
     * @throws \RuntimeException when the tenants table is missing
     */
    protected function assertTenantsTableExists(): void
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            throw new \RuntimeException(
                'The tenants table does not exist in the landlord database. '
                .'Run `php artisan migrate --path=database/migrations/landlord --database=landlord` first.'
            );
        }
    }

    /**
     * Create the physical PostgreSQL database for this tenant.
     *
     * Idempotent: queries the `pg_database` system catalog on the landlord
     * connection before issuing the CREATE. This lets `Tenant::create()` be
     * called repeatedly (e.g. by seeders, by the admin UI, or by retries
     * after a partial provisioning failure) without raising
     * "42P04 database already exists" errors.
     *
     * PostgreSQL does not support `CREATE DATABASE IF NOT EXISTS`, so checking
     * the system catalog is the canonical idempotent pattern for this DDL.
     *
     * The database name comes from the 'database' attribute set during creation.
     * Always runs on the landlord connection (the catalog lives there).
     */
    protected function createDatabase(): void
    {
        $exists = DB::connection('landlord')->select(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$this->database]
        );

        if (empty($exists)) {
            DB::unprepared('CREATE DATABASE "'.$this->database.'"');
        }
    }

    /**
     * Point the 'tenant' database connection to this tenant's database.
     *
     * Changes the config at runtime so that any query using the 'tenant'
     * connection targets this specific tenant's database. DB::purge()
     * forces Laravel to create a fresh connection with the updated config.
     */
    protected function configureTenantConnection(): void
    {
        config(['database.connections.tenant.database' => $this->database]);
        DB::purge('tenant');
    }

    /**
     * Run all pending Laravel migrations on this tenant's database.
     *
     * Uses --database=tenant to target the tenant connection, and --force
     * to bypass the production safety check (required for programmatic calls).
     */
    protected function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--force' => true,
        ]);
    }
}
