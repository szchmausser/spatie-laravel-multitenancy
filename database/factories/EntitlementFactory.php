<?php

namespace Database\Factories;

use App\Enums\EntitlementGrantVia;
use App\Models\Entitlement;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default produces a Purchase entitlement for a freshly
     * created tenant and resource. The `viaPlan()`, `viaAdmin()`,
     * and `expired()` states are common variations used by the
     * controller and download tests.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'resource_id' => Resource::factory(),
            'granted_via' => EntitlementGrantVia::Purchase,
            'granted_at' => now(),
            'expires_at' => null,
        ];
    }

    /**
     * Mark the entitlement as granted through a plan feature.
     */
    public function viaPlan(): static
    {
        return $this->state(['granted_via' => EntitlementGrantVia::Plan]);
    }

    /**
     * Mark the entitlement as granted by an admin (manual grant).
     */
    public function viaAdmin(): static
    {
        return $this->state(['granted_via' => EntitlementGrantVia::Admin]);
    }

    /**
     * Make the entitlement already expired.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
