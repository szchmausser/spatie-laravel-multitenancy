<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
     * - Creates the physical database
     * - Configures the tenant connection to point to the new database
     * - Runs all pending migrations on the new tenant database
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->createDatabase();
            $tenant->configureTenantConnection();
            $tenant->runMigrations();
        });
    }

    /**
     * Create the physical PostgreSQL database for this tenant.
     *
     * The database name comes from the 'database' attribute set during creation.
     * This runs on the landlord connection (default pgsql).
     */
    protected function createDatabase(): void
    {
        DB::unprepared('CREATE DATABASE "'.$this->database.'"');
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
