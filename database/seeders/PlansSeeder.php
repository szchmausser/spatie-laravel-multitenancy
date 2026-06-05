<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the landlord database with the three pricing tiers.
 *
 * Run before TenantsSeeder — the auto-assign-free plan behavior in
 * Tenant::created() looks up the 'free' plan by slug, so it must
 * exist before any tenant is created.
 *
 * The slugs are part of the public contract: assigning a plan in
 * TenantsSeeder, in the admin UI, or in tests is done by slug, not
 * by id.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Plan gratuito con acceso básico al producto.',
                'features' => [
                    'premium-zone' => false,
                    'advanced-reports' => false,
                    'api-access' => false,
                    'priority-support' => false,
                    'custom-branding' => false,
                ],
                'price_cents' => 0,
                'is_active' => true,
            ],
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'basic'],
            [
                'name' => 'Basic',
                'description' => 'Reportes avanzados, soporte por email y acceso al catálogo premium.',
                'features' => [
                    'premium-zone' => false,
                    'premium-content' => true,
                    'advanced-reports' => true,
                    'api-access' => false,
                    'priority-support' => false,
                    'custom-branding' => false,
                ],
                'price_cents' => 4900,
                'is_active' => true,
            ],
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'description' => 'Todas las features: zona premium, API, soporte prioritario y branding.',
                'features' => [
                    'premium-zone' => true,
                    'premium-content' => true,
                    'advanced-reports' => true,
                    'api-access' => true,
                    'priority-support' => true,
                    'custom-branding' => true,
                ],
                'price_cents' => 14900,
                'is_active' => true,
            ],
        );
    }
}
