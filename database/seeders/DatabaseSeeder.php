<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Seeds the database, adapting to the current context.
 *
 * This seeder is designed to work in two modes following the Spatie
 * multitenancy standard:
 *
 * 1. Landlord context (php artisan db:seed):
 *    - Creates admin user, plans, and tenant rows
 *    - Tenant::creating callback creates physical databases
 *
 * 2. Tenant context (php artisan tenants:artisan "db:seed"):
 *    - Seeds permissions and users for each tenant database
 *
 * Usage (Spatie standard flow):
 *   php artisan db:seed                                          # landlord only
 *   php artisan tenants:artisan "migrate --database=tenant --seed" # migrate + seed each tenant
 *
 * NOTE: We intentionally do NOT use the WithoutModelEvents trait here.
 * The Tenant model's `creating` lifecycle callback creates the physical
 * database for each tenant. Disabling model events would skip that side
 * effect and leave the system in an inconsistent state (tenants in
 * the landlord table but no physical database).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::checkCurrent()
            ? $this->runTenantSeeders()
            : $this->runLandlordSeeders();
    }

    /**
     * Seed landlord-specific data.
     *
     * Called when running `php artisan db:seed` (no tenant context).
     */
    protected function runLandlordSeeders(): void
    {
        $this->call([
            LandlordUserSeeder::class,
            PlansSeeder::class,
            TenantsSeeder::class,
            PaymentMethodConfigSeeder::class,
            SystemConfigSeeder::class,
        ]);
    }

    /**
     * Seed tenant-specific data.
     *
     * Called when running `php artisan tenants:artisan "db:seed"`
     * (within a tenant context). The tenant connection is already
     * pointed at the correct database by Spatie.
     */
    protected function runTenantSeeders(): void
    {
        $this->call([
            TenantPermissionsSeeder::class,
            TenantUsersSeeder::class,
        ]);
    }
}
