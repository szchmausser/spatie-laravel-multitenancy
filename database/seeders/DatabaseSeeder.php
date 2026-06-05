<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the landlord database.
 *
 * NOTE: We intentionally do NOT use the WithoutModelEvents trait here.
 * The Tenant model's `creating` and `created` lifecycle callbacks are
 * what provision the per-tenant databases and assign the default
 * subscription plan. Disabling model events would skip both side
 * effects and leave the system in an inconsistent state (tenants in
 * the landlord table but no physical database, and no subscription).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LandlordUserSeeder::class,
            PlansSeeder::class,
            TenantsSeeder::class,
            TenantUsersSeeder::class,
        ]);
    }
}
