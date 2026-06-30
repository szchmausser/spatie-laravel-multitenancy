<?php

use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

/**
 * Tests for ShopController is_included_in_plan serialization.
 *
 * Covers: serialized is_included_in_plan matches plan membership,
 * has_entitlement independent from is_included_in_plan.
 */
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    $this->tenant = Tenant::factory()->createQuietly();
    $this->user = User::factory()->createQuietly();
    $this->tenant->makeCurrent();
    $this->actingAs($this->user);
});

test('shop resource includes is_included_in_plan true when resource is in current plan', function () {
    $plan = Plan::factory()->createQuietly(['is_active' => true, 'price_cents' => 1000, 'slug' => 'basic']);
    $resource = Resource::factory()->createQuietly(['is_active' => true, 'is_premium' => true, 'slug' => 'premium-doc', 'price_cents' => 500]);

    // Assign tenant to plan via subscription
    $tenantPlan = Plan::factory()->createQuietly(['is_active' => true, 'price_cents' => 0, 'slug' => 'free']);
    Subscription::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $tenantPlan->id,
    ]);

    // Attach resource to the tenant's plan
    $tenantPlan->resources()->attach($resource->id);

    $this->get(route('shop.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.is_included_in_plan', true)
        );
});

test('shop resource has is_included_in_plan false when resource is not in plan', function () {
    Plan::factory()->createQuietly(['is_active' => true, 'price_cents' => 0, 'slug' => 'free']);
    $resource = Resource::factory()->createQuietly(['is_active' => true, 'is_premium' => true, 'slug' => 'premium-doc', 'price_cents' => 500]);

    $this->get(route('shop.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.is_included_in_plan', false)
        );
});

test('shop resource has both has_entitlement and is_included_in_plan independently', function () {
    $tenantPlan = Plan::factory()->createQuietly(['is_active' => true, 'price_cents' => 0, 'slug' => 'free']);
    Subscription::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $tenantPlan->id,
    ]);

    $resource = Resource::factory()->createQuietly(['is_active' => true, 'is_premium' => true, 'slug' => 'premium-doc', 'price_cents' => 500]);

    // Both: resource in plan AND has entitlement
    $tenantPlan->resources()->attach($resource->id);
    Entitlement::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'resource_id' => $resource->id,
    ]);

    $this->get(route('shop.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.has_entitlement', true)
            ->where('resources.0.is_included_in_plan', true)
        );
});
