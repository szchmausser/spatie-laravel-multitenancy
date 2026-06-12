<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the landlord database with ten test tenants under the
 * `*.spatie-laravel-multitenancy.test` domain pattern.
 *
 * Distribution:
 *   - 3 tenants assigned to plan `free`     (tenant1..tenant3)
 *   - 6 tenants assigned to plan `basic`    (tenant4..tenant9)
 *   - 1 tenant  assigned to plan `premium`  (tenant10)
 *
 * Every Tenant::create() call triggers the provisioning lifecycle
 * (createDatabase, configureTenantConnection) defined in the Tenant model,
 * so each iteration creates a dedicated physical database.
 *
 * NOTE: Migrations are NOT run here. Per the Spatie multitenancy standard,
 * run `php artisan tenants:artisan "migrate --database=tenant"` after this
 * seeder to migrate all tenant databases.
 */
class TenantsSeeder extends Seeder
{
    public function run(): void
    {
        $planDistribution = [
            'tenant1' => 'free',
            'tenant2' => 'free',
            'tenant3' => 'free',
            'tenant4' => 'basic',
            'tenant5' => 'basic',
            'tenant6' => 'basic',
            'tenant7' => 'basic',
            'tenant8' => 'basic',
            'tenant9' => 'basic',
            'tenant10' => 'premium',
        ];

        foreach ($planDistribution as $slug => $planSlug) {
            $tenant = new Tenant([
                'name' => ucfirst($slug),
                'domain' => "{$slug}.spatie-laravel-multitenancy.test",
                'database' => "{$slug}-spatie-laravel-multitenancy",
            ]);
            $tenant->assignPlanSlug = $planSlug;
            $tenant->save();
        }
    }
}
