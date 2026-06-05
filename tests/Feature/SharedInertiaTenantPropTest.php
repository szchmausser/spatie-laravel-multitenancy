<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Tests for the `tenant` shared Inertia prop emitted by
 * HandleInertiaRequests.
 *
 * The sidebar (app-sidebar.tsx) decides whether to show the
 * "Resources" link based on `tenant.is_free_tier` and
 * `tenant.has_free_resources`. These tests
 * pin the data contract: when a tenant is current and the tenant is on
 * the free plan the prop says so, and when the tenant is on any other
 * plan the prop says otherwise.
 *
 * The actual UI render is exercised manually in the browser; the
 * conditional in the sidebar is a one-liner and the only thing that
 * could really go wrong is the data flowing from the model up to the
 * shared prop, which is what this test pins down.
 */
test('shared tenant prop is null on landlord routes (no current tenant)', function () {
    // No makeCurrent() — we're on the landlord domain.
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant', null)
    );
});

test('shared tenant prop marks free-tier tenants as is_free_tier=true', function () {
    $tenant = Tenant::factory()->createQuietly([
        'name' => 'Free Tier Co',
        'domain' => 'free.example.test',
        'database' => 'free_tier_db',
    ]);

    $plan = Plan::factory()->createQuietly([
        'slug' => 'free',
        'name' => 'Free',
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', 'Free Tier Co')
        ->where('tenant.domain', 'free.example.test')
        ->where('tenant.is_free_tier', true)
        ->where('tenant.has_free_resources', false)
    );
});

test('shared tenant prop marks paid-tier tenants as is_free_tier=false', function () {
    $tenant = Tenant::factory()->createQuietly([
        'name' => 'Basic Co',
        'domain' => 'basic.example.test',
        'database' => 'basic_co_db',
    ]);

    $plan = Plan::factory()->createQuietly([
        'slug' => 'basic',
        'name' => 'Basic',
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', 'Basic Co')
        ->where('tenant.is_free_tier', false)
        ->where('tenant.has_free_resources', false)
    );
});

test('shared tenant prop reports has_free_resources=true when at least one free resource exists', function () {
    App\Models\Resource::factory()->create(['name' => 'Free Guide', 'is_premium' => false]);

    $tenant = Tenant::factory()->createQuietly();
    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.has_free_resources', true)
    );
});

test('shared tenant prop reports has_free_resources=false when only premium resources exist', function () {
    App\Models\Resource::factory()->premium()->create(['name' => 'Pro Playbook']);
    App\Models\Resource::factory()->inactive()->create(['name' => 'Retired', 'is_premium' => false]);

    $tenant = Tenant::factory()->createQuietly();
    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.has_free_resources', false)
    );
});

test('shared tenant prop treats tenant without subscription as free tier', function () {
    $tenant = Tenant::factory()->createQuietly();

    // Deliberately no subscription row.
    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.is_free_tier', true)
    );
});

test('shared tenant prop reports has_premium_zone=true when plan includes premium-zone feature', function () {
    $tenant = Tenant::factory()->createQuietly([
        'name' => 'Premium Co',
        'domain' => 'premium.example.test',
    ]);

    $plan = Plan::factory()->createQuietly([
        'slug' => 'premium',
        'name' => 'Premium',
        'features' => ['premium-zone' => true, 'premium-content' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.has_premium_zone', true)
    );
});

test('shared tenant prop reports has_premium_zone=false when plan lacks premium-zone feature', function () {
    $tenant = Tenant::factory()->createQuietly([
        'name' => 'Basic Co',
        'domain' => 'basic.example.test',
    ]);

    $plan = Plan::factory()->createQuietly([
        'slug' => 'basic',
        'name' => 'Basic',
        'features' => ['premium-zone' => false, 'premium-content' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.has_premium_zone', false)
    );
});

test('shared tenant prop reports has_premium_zone=false on free plan', function () {
    $tenant = Tenant::factory()->createQuietly();

    $plan = Plan::factory()->createQuietly([
        'slug' => 'free',
        'name' => 'Free',
        'features' => ['premium-zone' => false],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('tenant.has_premium_zone', false)
    );
});
