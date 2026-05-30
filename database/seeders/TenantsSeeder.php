<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the landlord database with initial tenants.
 *
 * Each Tenant::create() call automatically triggers the provisioning
 * lifecycle callback (createDatabase, configureTenantConnection,
 * runMigrations) defined in the Tenant model.
 *
 * This seeder creates two test tenants with dedicated databases.
 */
class TenantsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Tenant::create([
            'name' => 'Tenant One',
            'domain' => 'tenant1.spatie-laravel-multitenancy.test',
            'database' => 'tenant1-spatie-laravel-multitenancy',
        ]);

        Tenant::create([
            'name' => 'Tenant Two',
            'domain' => 'tenant2.spatie-laravel-multitenancy.test',
            'database' => 'tenant2-spatie-laravel-multitenancy',
        ]);
    }
}
