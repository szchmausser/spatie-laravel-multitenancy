<?php

use App\Enums\EntitlementGrantVia;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
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
 * Tests for the Resource\ResourceController (the tenant-side
 * resources catalog). Tests for the landlord-side CRUD on
 * resources live in `tests/Feature/Landlord/ResourceControllerTest.php`.
 *
 * The controller is the integration point of Phase 1.5: it ties
 * together the resource catalog, the entitlement system, the
 * tenant's plan, and the request-side user. These tests exercise
 * every public action (index, show, request, download) plus the
 * cross-cutting authorization rules.
 *
 * The `tenant + auth + verified` middlewares are skipped because
 * they require a real subdomain + an authenticated User living in
 * the tenant's database. The tests create a tenant, make it
 * current via `switchToTenant()`, and act as a real User created
 * with `User::factory()->createQuietly()` (the `tenant` connection
 * is repurposed to point at the landlord DB in this project's
 * testing setup — see `RefreshLandlordDatabase` for the PDO
 * sharing trick that makes this work in transactions). Premium
 * gating is now enforced inside the controller (per-resource
 * `canSeePremium()`), so this file does not need a route-level
 * free-tier middleware.
 *
 * Two non-obvious traps we have to work around here:
 *
 *  1. `SwitchFilesystemTask::makeCurrent()` (a project-specific
 *     task in `config/multitenancy.php`) calls
 *     `app()->forgetInstance('filesystem')` and
 *     `Storage::clearResolvedInstance('filesystem')`. That kills
 *     the FilesystemManager instance installed by
 *     `Storage::fake('local')` in beforeEach, so the next
 *     `Storage::disk('local')` resolves back to the real disk
 *     and any file written before `makeCurrent()` is gone. The
 *     helper `switchToTenant()` re-fakes the `local` disk after
 *     calling `makeCurrent()` to restore the test isolation.
 *     Every test that writes to `Storage::disk('local')` MUST
 *     go through `switchToTenant()`.
 *
 *  2. The `User` model carries `$appends = ['avatar']` and a
 *     `getAvatarAttribute` accessor that calls
 *     `getFirstMedia('avatar')` on the `tenant` connection.
 *     Inertia serializes the user via `$request->user()` to
 *     populate the `auth.user` shared prop, so any Inertia
 *     response would try to query the `media` table on the
 *     tenant connection. We don't use Inertia in the model
 *     tests (`ResourceTest`/`EntitlementTest`) but the
 *     controller tests do render Inertia pages; for those we
 *     accept the 500 from the avatar query — see the
 *     `TODO(1.5B)` notes in the request body assertions.
 */
beforeEach(function () {
    $this->withoutMiddleware([
        NeedsTenant::class,
        EnsureValidTenantSession::class,
        Authenticate::class,
        EnsureEmailIsVerified::class,
    ]);

    Storage::fake('local');
});

// ---------- index ----------

test('index renders the catalog with active resources', function () {
    Resource::factory()->create(['name' => 'Free Guide', 'is_premium' => false]);
    Resource::factory()->premium()->create(['name' => 'Premium Guide']);
    Resource::factory()->inactive()->create(['name' => 'Retired Guide']);

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);

    switchToTenant($tenant);
    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resources/index')
            ->has('resources', 2)
            ->where('resources.0.name', 'Free Guide')
            ->where('resources.1.name', 'Premium Guide')
        );
});

test('index marks each resource with can_download for the current user', function () {
    $free = Resource::factory()->create(['is_premium' => false]);
    $premium = Resource::factory()->premium()->create();

    $tenant = makePaidTenant('basic'); // basic has premium-content
    $user = makeUserFor($tenant);

    switchToTenant($tenant);
    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('resources.0.can_download', true)   // free resource
            ->where('resources.1.can_download', true)   // premium, plan grants access
        );
});

// ---------- show ----------

test('show returns 404 for unknown slug', function () {
    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.show', 'nope'))
        ->assertNotFound();
});

test('show returns 404 for inactive resources', function () {
    $resource = Resource::factory()->inactive()->create();

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.show', $resource->slug))
        ->assertNotFound();
});

test('show renders the resource page when the resource is active', function () {
    $resource = Resource::factory()->create();

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.show', $resource->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resources/show')
            ->where('resource.slug', $resource->slug)
        );
});

// ---------- request (auto-approve) ----------

test('request creates an order for the resource', function () {
    $resource = Resource::factory()->premium()->create();

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    expect(Order::query()->count())->toBe(0);

    $this->actingAs($user)
        ->post(route('resources.request', $resource->slug))
        ->assertRedirect(route('billing.orders.show', Order::query()->first()));

    expect(Order::query()->count())->toBe(1);
    $order = Order::query()->first();
    expect($order->tenant_id)->toBe($tenant->id)
        ->and($order->resource_id)->toBe($resource->id)
        ->and($order->plan_id)->toBeNull()
        ->and($order->total_cents)->toBe($resource->price_cents);
});

test('request creates an order: second click creates a second order for resources', function () {
    $resource = Resource::factory()->premium()->create();

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->post(route('resources.request', $resource->slug))
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('resources.request', $resource->slug))
        ->assertRedirect();

    // Resource orders allow multiple pending orders (unlike plan orders)
    expect(Order::query()->count())->toBe(2);
});

// ---------- download ----------

test('download streams a free resource for any authenticated user', function () {
    $resource = Resource::factory()->withFile('resources/free.pdf', 'application/pdf', 0)->create([
        'is_premium' => false,
    ]);

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    // `put` MUST happen after switchToTenant (see test docblock).
    $body = 'free content';
    Storage::disk('local')->put('resources/free.pdf', $body);
    $resource->update(['file_size_bytes' => strlen($body)]);

    $response = $this->actingAs($user)->get(route('resources.download', $resource->slug));

    $response->assertOk();
    expect($response->streamedContent())->toBe($body);
});

test('download streams a premium resource when the plan has premium-content', function () {
    $resource = Resource::factory()->withFile('resources/paid.pdf', 'application/pdf', 0)->premium()->create();

    $tenant = makePaidTenant('premium'); // premium has premium-content
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $body = 'premium content';
    Storage::disk('local')->put('resources/paid.pdf', $body);
    $resource->update(['file_size_bytes' => strlen($body)]);

    $response = $this->actingAs($user)->get(route('resources.download', $resource->slug));

    $response->assertOk();
    expect($response->streamedContent())->toBe($body);
});

test('download streams a premium resource when an explicit entitlement exists', function () {
    $resource = Resource::factory()->withFile('resources/purchased.pdf', 'application/pdf', 0)->premium()->create();

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);

    Entitlement::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->getKey(),
        'resource_id' => $resource->id,
    ]);

    switchToTenant($tenant);

    $body = 'purchased content';
    Storage::disk('local')->put('resources/purchased.pdf', $body);
    $resource->update(['file_size_bytes' => strlen($body)]);

    $response = $this->actingAs($user)->get(route('resources.download', $resource->slug));

    $response->assertOk();
    expect($response->streamedContent())->toBe($body);
});

test('download returns 403 for a premium resource when the plan does not grant premium-content and no entitlement exists', function () {
    $resource = Resource::factory()->premium()->create();

    // Phase 1.5F: the slug is no longer secret. The 1.5D "treat as
    // non-existent" 404 was reverted because it made rule 3 of
    // userCanAccess() (explicit entitlement) unreachable from the
    // frontend — the request() endpoint required the plan to ALREADY
    // have premium-content. With the filters gone, the slug is open
    // and the userCanAccess() check at the top of download() is what
    // blocks the file with 403.
    $starterPlan = Plan::factory()->createQuietly(['slug' => 'starter', 'name' => 'Starter']);
    Subscription::factory()->createQuietly([
        'tenant_id' => ($tenant = Tenant::factory()->createQuietly())->id,
        'plan_id' => $starterPlan->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.download', $resource->slug))
        ->assertForbidden();
});

test('download returns 404 when the file is missing from storage', function () {
    $resource = Resource::factory()->withFile('resources/missing.pdf', 'application/pdf', 100)->create();
    // Note: nothing was put on the fake disk.

    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.download', $resource->slug))
        ->assertNotFound();
});

test('download is granted by the plan even when an explicit entitlement is expired', function () {
    $resource = Resource::factory()->withFile('resources/expired.pdf', 'application/pdf', 0)->premium()->create();

    // The "basic" plan already includes premium-content, so the
    // plan-level rule (rule 2 of userCanAccess) grants the download
    // without ever consulting the per-resource entitlement row.
    // An expired entitlement is therefore irrelevant when the plan
    // grants the feature: the user keeps access.
    $tenant = makePaidTenant('basic');
    $user = makeUserFor($tenant);

    Entitlement::factory()->expired()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->getKey(),
        'resource_id' => $resource->id,
    ]);

    switchToTenant($tenant);

    $body = 'still good';
    Storage::disk('local')->put('resources/expired.pdf', $body);
    $resource->update(['file_size_bytes' => strlen($body)]);

    $response = $this->actingAs($user)
        ->get(route('resources.download', $resource->slug));

    $response->assertOk();
    expect($response->streamedContent())->toBe($body);
});

// ---------- helpers ----------

/**
 * Make the given tenant the current one and restore the `local`
 * Storage fake afterwards.
 *
 * Required by every test that writes to or reads from
 * `Storage::disk('local')` after making a tenant current. See the
 * "non-obvious trap #1" note in the file docblock.
 */
function switchToTenant(Tenant $tenant): void
{
    $tenant->makeCurrent();
    Storage::fake('local');
}

/**
 * Build a tenant with the given plan slug. The plan must already be
 * seeded (default PlansSeeder covers free/basic/premium); for
 * synthetic plans like 'starter' or 'starter-no-content' the caller
 * should have created the plan first.
 */
function makePaidTenant(string $planSlug): Tenant
{
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['slug' => $planSlug, 'name' => ucfirst($planSlug)]);

    $features = match ($planSlug) {
        'premium' => ['premium-content' => true],
        'basic' => ['premium-content' => true, 'advanced-reports' => true],
        'starter-no-content' => ['advanced-reports' => true], // explicit: no premium-content
        default => [],
    };
    if (! $plan->features || $plan->features === ['premium-zone' => false]) {
        $plan->features = $features;
        $plan->save();
    }

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    return $tenant;
}

/**
 * Create a fake authenticated user for the given tenant.
 *
 * We deliberately do NOT use `User::factory()->createQuietly()`
 * here: the `User` model carries `$appends = ['avatar']` and a
 * `getAvatarAttribute` accessor that calls `getFirstMedia('avatar')`
 * on the `tenant` connection. The Inertia middleware serializes
 * the user to populate the `auth.user` shared prop, so any Inertia
 * response would try to query the `media` table on a tenant
 * database that does not exist in this test setup (we use
 * `createQuietly()` to skip the `Tenant::creating` callback that
 * would `CREATE DATABASE`).
 *
 * The fake is a plain `Authenticatable`. Inertia only calls
 * `Model::toArray()` (which triggers Eloquent accessors) for
 * Eloquent models; for a non-Model it falls back to public
 * property access, so no avatar query happens.
 *
 * The id is `tenant->id * 1000 + a small offset` so different
 * tenants produce different ids, which keeps the
 * `entitlements(tenant_id, user_id, resource_id)` UNIQUE constraint
 * happy when one test exercises multiple tenants in sequence.
 */
function makeUserFor(Tenant $tenant): Authenticatable
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

        public function setRememberToken($value): void
        {
            // no-op
        }

        public function getRememberTokenName(): string
        {
            return '';
        }

        /**
         * Compatibility shim for the controller, which calls
         * `$user->getKey()` (a `Model` method) rather than the
         * `Authenticatable` contract's `getAuthIdentifier()`.
         */
        public function getKey(): int
        {
            return $this->id;
        }
    };
}

// =====================================================================
// Free-tier behavior (added in 1.5C-fix, REWORKED in 1.5F)
// =====================================================================
//
// Phase 1.5F: the 1.5D decision to hide premium slugs from free
// tenants made rule 3 of userCanAccess() (explicit entitlement)
// unreachable from the frontend — the request() endpoint required
// the plan to ALREADY have premium-content. So the catalog now
// shows every active resource to every authenticated tenant; the
// `can_download` and `has_explicit_entitlement` flags drive the
// button state per resource. The download endpoint still 403s for
// a free tenant with no entitlement — but the show page renders
// for free tenants so they can hit "Buy".

/**
 * Build a free-tier tenant (plan slug = "free", no premium-content
 * feature). Mirror of makePaidTenant but for the free path.
 */
function makeFreeTenant(): Tenant
{
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['slug' => 'free', 'name' => 'Free']);

    // Force-clear any default features so the plan truly does NOT
    // grant premium-content. The Plan factory's default state
    // produces ['premium-zone' => false] which already excludes it,
    // but we re-assert it for clarity.
    $plan->features = ['premium-zone' => false, 'premium-content' => false];
    $plan->save();

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    return $tenant;
}

// ---------- index: free tier sees ALL active resources, premium are not downloadable ----------

test('free-tier tenant sees every active resource on the index, free + premium', function () {
    Resource::factory()->create(['name' => 'Free Guide', 'is_premium' => false]);
    Resource::factory()->premium()->create(['name' => 'Premium Guide']);
    Resource::factory()->inactive()->premium()->create(['name' => 'Retired Premium']);

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);

    switchToTenant($tenant);
    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resources/index')
            // 2 active: Free Guide + Premium Guide. Retired is filtered by active().
            ->has('resources', 2)
            ->where('resources.0.name', 'Free Guide')
            ->where('resources.0.can_download', true)
            ->where('resources.1.name', 'Premium Guide')
            ->where('resources.1.can_download', false)        // R1: free cannot download premium
            ->where('resources.1.has_explicit_entitlement', false)
        );
});

test('free-tier tenant sees premium resources even when no free resources exist', function () {
    Resource::factory()->premium()->create(['name' => 'Premium Only']);

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);

    switchToTenant($tenant);
    $this->actingAs($user)
        ->get(route('resources.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.name', 'Premium Only')
            ->where('resources.0.can_download', false)
        );
});

// ---------- show: free tier CAN view premium resource pages ----------

test('free-tier tenant can view a premium resource show page (200, can_download false)', function () {
    $premium = Resource::factory()->premium()->create(['slug' => 'pro-playbook']);

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    // R2: the show page renders for free tenants so they can hit "Buy".
    $this->actingAs($user)
        ->get(route('resources.show', $premium->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resources/show')
            ->where('resource.slug', 'pro-playbook')
            ->where('resource.is_premium', true)
            ->where('resource.can_download', false)
            ->where('resource.has_explicit_entitlement', false)
        );
});

test('free-tier tenant can view a free resource show page', function () {
    $free = Resource::factory()->create(['slug' => 'welcome-guide', 'is_premium' => false]);

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    $this->actingAs($user)
        ->get(route('resources.show', $free->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resources/show')
            ->where('resource.slug', 'welcome-guide')
            ->where('resource.can_download', true)
        );
});

// ---------- request: free tier CAN buy premium (simulated purchase) ----------

test('free-tier tenant can "buy" a premium resource and an order is created', function () {
    $premium = Resource::factory()->premium()->create();

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    expect(Order::query()->count())->toBe(0);

    // R3: free tenants are no longer blocked from the request endpoint.
    // The "Buy" dialog posts here and the controller creates an Order
    // that redirects to the billing orders page for payment.
    $this->actingAs($user)
        ->post(route('resources.request', $premium->slug))
        ->assertRedirect();

    expect(Order::query()->count())->toBe(1);
    $order = Order::query()->first();
    expect($order->tenant_id)->toBe($tenant->id)
        ->and($order->resource_id)->toBe($premium->id)
        ->and($order->plan_id)->toBeNull()
        ->and($order->total_cents)->toBe($premium->price_cents);
});

// ---------- download: free tier can download free, cannot download premium without entitlement ----------

test('free-tier tenant can download a free resource', function () {
    $resource = Resource::factory()->withFile('resources/free.pdf', 'application/pdf', 0)->create([
        'is_premium' => false,
    ]);

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    // `put` MUST happen after switchToTenant (see test docblock).
    $body = 'free content';
    Storage::disk('local')->put('resources/free.pdf', $body);
    $resource->update(['file_size_bytes' => strlen($body)]);

    $response = $this->actingAs($user)
        ->get(route('resources.download', $resource->slug));

    $response->assertOk();
    expect($response->streamedContent())->toBe($body);
});

test('free-tier tenant gets 403 trying to download a premium resource they have not purchased', function () {
    $resource = Resource::factory()->withFile('resources/paywalled.pdf', 'application/pdf', 0)->premium()->create();

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    Storage::disk('local')->put('resources/paywalled.pdf', 'premium content');
    $resource->update(['file_size_bytes' => strlen('premium content')]);

    // R4: 403, not 404. The slug is no longer secret — once the
    // catalog shows the premium entry, the show page is reachable,
    // and the download endpoint must answer with a proper 403.
    $this->actingAs($user)
        ->get(route('resources.download', $resource->slug))
        ->assertForbidden();
});

test('free-tier tenant CAN download a premium resource after full payment flow', function () {
    $resource = Resource::factory()->withFile('resources/purchased-by-free.pdf', 'application/pdf', 0)->premium()->create();

    $tenant = makeFreeTenant();
    $user = makeUserFor($tenant);
    switchToTenant($tenant);

    // Step 1: Create order via the request endpoint
    $this->actingAs($user)
        ->post(route('resources.request', $resource->slug))
        ->assertRedirect();

    $order = Order::query()->where('resource_id', $resource->id)->first();
    expect($order)->not->toBeNull();

    // Step 2: Grant entitlement directly (simulates what the listener does
    // after payment verification — the listener uses User::on('tenant')
    // which requires a real tenant DB, not available in this test env)
    Entitlement::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->getKey(),
        'resource_id' => $resource->id,
        'granted_via' => EntitlementGrantVia::Purchase,
        'granted_at' => now(),
        'expires_at' => null,
    ]);

    // Step 3: Download should now succeed
    $body = 'premium content unlocked by purchase';
    Storage::disk('local')->put('resources/purchased-by-free.pdf', $body);
    $resource->update(['file_size_bytes' => strlen($body)]);

    $response = $this->actingAs($user)
        ->get(route('resources.download', $resource->slug));

    $response->assertOk();
    expect($response->streamedContent())->toBe($body);
});
