<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the landlord database with ten test tenants under the
 * `*.spatie-laravel-multitenancy.test` domain pattern.
 *
 * Distribution:
 *   - 4 tenants assigned to plan `basic`  (tenant1..tenant4)
 *   - 4 tenants assigned to plan `premium` (tenant5..tenant8)
 *   - 2 tenants with no explicit plan — Tenant::created() auto-assigns
 *     the `free` plan as the system-wide default
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
        $explicit = [
            'tenant1' => 'basic',
            'tenant2' => 'basic',
            'tenant3' => 'basic',
            'tenant4' => 'basic',
            'tenant5' => 'premium',
            'tenant6' => 'premium',
            'tenant7' => 'premium',
            'tenant8' => 'premium',
        ];

        foreach ($explicit as $slug => $planSlug) {
            $tenant = new Tenant([
                'name' => ucfirst($slug),
                'domain' => "{$slug}.spatie-laravel-multitenancy.test",
                'database' => "{$slug}-spatie-laravel-multitenancy",
            ]);
            $tenant->assignPlanSlug = $planSlug;
            $tenant->save();
        }

        // 2 tenants without an explicit plan: the Tenant::created listener
        // will look up the 'free' plan and create the subscription.
        foreach (['tenant9', 'tenant10'] as $slug) {
            Tenant::create([
                'name' => ucfirst($slug),
                'domain' => "{$slug}.spatie-laravel-multitenancy.test",
                'database' => "{$slug}-spatie-laravel-multitenancy",
            ]);
        }
    }
}
