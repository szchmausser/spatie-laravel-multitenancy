<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

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

// =====================================================================
// Requirement 5: `auth.user.roles` shared prop (1.5G.0-tenant-roles)
// =====================================================================

/**
 * These two tests pin the contract for the `roles` array on the
 * `auth.user` shared Inertia prop emitted by HandleInertiaRequests.
 *
 * The middleware exposes the current user's Spatie role names so the
 * frontend can render role-aware UI (e.g. the "Admin" badge in the
 * user menu — see Task 6 and `resources/js/components/user-menu-content.tsx`).
 *
 * Setup mirrors `tests/Feature/Auth/TenantPermissionsTest.php`:
 *   - point the `tenant` connection at the test physical DB,
 *   - run the Spatie permission migration,
 *   - run the idempotent TenantPermissionsSeeder,
 *   - wrap the body in try/finally so the default connection is
 *     always restored and the tenant connection is purged, even if
 *     an assertion fails.
 */
test('shared auth prop exposes tenant-admin user roles array', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        // The first user on a fresh tenant gets the tenant-admin role
        // automatically via TenantUsersSeeder. We replicate the effect
        // here (the seeder runs in production, not in feature tests).
        $user = User::on('tenant')->create([
            'name' => 'Admin User',
            'email' => 'admin-shared-prop@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        // Flush Spatie's permission cache so the role lookup in
        // HandleInertiaRequests::resolveRoles() reflects the assignment.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // NOTE: we intentionally do NOT call $tenant->makeCurrent() here.
        // makeCurrent() re-points the `tenant` connection at the tenant's
        // own `database` (e.g. tenant_94710), which would override the
        // test repointing we did in pointTenantConnectionAtTestDatabase().
        // For the auth-prop contract, the current tenant identity is not
        // needed — resolveRoles() only needs the Spatie tables and the
        // user record on the connection the User model is bound to.

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.roles', ['tenant-admin'])
        );
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('shared auth prop exposes empty roles for non-admin user', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $user = User::on('tenant')->create([
            'name' => 'Regular User',
            'email' => 'regular-shared-prop@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        // No role assigned. The shared prop should expose an empty array,
        // not null, so the frontend can safely call `.includes(...)`.
        // We do NOT call $tenant->makeCurrent() — see the note in the
        // admin test about why that would override the test repointing.

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.roles', [])
        );
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});
