<?php

use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

/**
 * Tests for the tenant ResourceController access logic (R6/R1 of spec 2).
 *
 * Covers all 4 branches of userCanAccess: free → true, plan-included → true,
 * entitlement → true, denied → false. Also covers is_included_in_plan
 * serialization (R7/R2) and entitlement persistence across plan changes (R10/R5).
 *
 * These tests exercise the download endpoint which is the PUBLIC face of
 * the access gate. Testing through the HTTP layer validates the full stack:
 * routing → controller → access gate → response.
 */
beforeEach(function () {
    $this->withoutMiddleware([
        NeedsTenant::class,
        EnsureValidTenantSession::class,
        Authenticate::class,
        EnsureEmailIsVerified::class,
    ]);

    Storage::fake('local');

    // Create resources with real files for download tests
    $this->freeResource = Resource::factory()->create([
        'file_path' => 'resources/free-test.pdf',
        'mime_type' => 'application/pdf',
        'is_premium' => false,
    ]);
    Storage::disk('local')->put('resources/free-test.pdf', 'free content');
    $this->freeResource->update(['file_size_bytes' => strlen('free content')]);

    $this->premiumResource = Resource::factory()->premium(999)->create([
        'file_path' => 'resources/premium-test.pdf',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put('resources/premium-test.pdf', 'premium content');
    $this->premiumResource->update(['file_size_bytes' => strlen('premium content')]);
});

function makeTestTenant(string $planSlug = 'basic'): Tenant
{
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['slug' => $planSlug, 'name' => ucfirst($planSlug)]);

    $plan->features = match ($planSlug) {
        'premium' => ['premium-zone' => true],
        'basic' => ['advanced-reports' => true],
        default => ['premium-zone' => false],
    };
    $plan->save();

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    return $tenant;
}

function makeTestUserFor(Tenant $tenant): Authenticatable
{
    static $counter = 0;
    $counter++;

    $id = $tenant->id * 1000 + $counter;

    return new class($id) implements Authenticatable
    {
        public function __construct(public int $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return 'secret';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function getKey(): int
        {
            return $this->id;
        }
    };
}

function switchToTestTenant(Tenant $tenant): void
{
    $tenant->makeCurrent();
    Storage::fake('local');
}

// ---------- R1: userCanAccess() — all 4 branches ----------

test('non-premium resource is always downloadable', function () {
    $tenant = makeTestTenant('free');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    Storage::disk('local')->put('resources/free-test.pdf', 'free content');

    $response = $this->actingAs($user)
        ->get(route('resources.download', $this->freeResource->slug));

    $response->assertOk();
});

test('premium resource included in plan is downloadable', function () {
    $tenant = makeTestTenant('basic');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    // Attach resource to the tenant's plan via pivot
    $tenant->subscription->plan->resources()->attach($this->premiumResource->id);

    Storage::disk('local')->put('resources/premium-test.pdf', 'premium content');

    $response = $this->actingAs($user)
        ->get(route('resources.download', $this->premiumResource->slug));

    $response->assertOk();
});

test('premium resource with explicit entitlement is downloadable', function () {
    $tenant = makeTestTenant('free');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    // Grant explicit entitlement (NOT via plan pivot)
    Entitlement::query()->create([
        'tenant_id' => $tenant->id,
        'resource_id' => $this->premiumResource->id,
        'granted_via' => 'purchase',
        'granted_at' => now(),
        'expires_at' => null,
    ]);

    Storage::disk('local')->put('resources/premium-test.pdf', 'premium content');

    $response = $this->actingAs($user)
        ->get(route('resources.download', $this->premiumResource->slug));

    $response->assertOk();
});

test('premium resource without plan or entitlement is denied', function () {
    $tenant = makeTestTenant('free');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    $response = $this->actingAs($user)
        ->get(route('resources.download', $this->premiumResource->slug));

    $response->assertForbidden();
});

// ---------- R2: is_included_in_plan serialization ----------

test('serialized resource includes is_included_in_plan true when resource is in current plan', function () {
    $tenant = makeTestTenant('basic');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    $tenant->subscription->plan->resources()->attach($this->premiumResource->id);

    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 2)
            ->where('resources.1.is_included_in_plan', true)
        );
});

test('serialized resource has is_included_in_plan false when no subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 2)
            ->where('resources.1.is_included_in_plan', false)
        );
});

test('serialized resource has is_included_in_plan false for free plan without resource', function () {
    $tenant = makeTestTenant('free');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 2)
            ->where('resources.1.is_included_in_plan', false)
        );
});

test('has_explicit_entitlement and is_included_in_plan are independent', function () {
    $tenant = makeTestTenant('basic');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    // Both: resource in plan AND has entitlement
    $tenant->subscription->plan->resources()->attach($this->premiumResource->id);
    Entitlement::query()->create([
        'tenant_id' => $tenant->id,
        'resource_id' => $this->premiumResource->id,
        'granted_via' => 'purchase',
        'granted_at' => now(),
        'expires_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 2)
            ->where('resources.1.is_included_in_plan', true)
            ->where('resources.1.has_explicit_entitlement', true)
        );
});

// ---------- R5: entitlement persists across plan changes ----------

test('entitlement access persists when plan changes and no longer includes resource', function () {
    $tenant = makeTestTenant('basic');
    $user = makeTestUserFor($tenant);
    switchToTestTenant($tenant);

    // Attach resource to plan
    $tenant->subscription->plan->resources()->attach($this->premiumResource->id);

    // Create explicit entitlement
    Entitlement::query()->create([
        'tenant_id' => $tenant->id,
        'resource_id' => $this->premiumResource->id,
        'granted_via' => 'purchase',
        'granted_at' => now(),
        'expires_at' => null,
    ]);

    // Change to a different plan that does NOT include the resource
    $newPlan = Plan::factory()->createQuietly(['slug' => 'different']);
    $tenant->subscription->update(['plan_id' => $newPlan->id]);

    Storage::disk('local')->put('resources/premium-test.pdf', 'premium content');

    $response = $this->actingAs($user)
        ->get(route('resources.download', $this->premiumResource->slug));

    $response->assertOk();
});
