<?php

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Billing\PlanChangeController;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Services\Billing\ChangePlanService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

/**
 * Feature tests for {@see PlanChangeController}.
 *
 * The tenant-side write surface for self-service plan change.
 * Guarded by `$user->can('change-plan')` (permission-based, not
 * role-based) and shares the same mutation as the landlord backdoor
 * via {@see ChangePlanService}.
 *
 * The `NeedsTenant` / `EnsureValidTenantSession` middlewares are
 * skipped because they require a real subdomain; tests bypass them
 * and act as a tenant user directly. `Authenticate` and
 * `EnsureEmailIsVerified` are only skipped in tests that need to
 * exercise the redirect/403 path before the auth gates.
 */
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');

    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->withoutMiddleware([
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);
});

test('redirects unauthenticated user to login on GET /billing/change-plan', function () {
    // `Authenticate` is intentionally left enabled here so the test
    // exercises the real auth redirect. The tenant middleware was
    // disabled in beforeEach because no real subdomain exists in
    // the test environment.
    $this->get(route('billing.change-plan.show'))
        ->assertRedirect(route('login'));
});

test('returns 403 for a user without the change-plan permission on GET /billing/change-plan', function () {
    // Build a tenant user that has NO roles and therefore lacks the
    // `change-plan` permission. The controller must return 403 from
    // the gate, not 500 and not 302 to login.
    $user = new class implements Authenticatable
    {
        public int $id = 1;

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 1;
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
            return 1;
        }

        public function can(string $ability): bool
        {
            return false; // no `change-plan`
        }
    };

    $this->actingAs($user)
        ->get(route('billing.change-plan.show'))
        ->assertForbidden();
});

test('renders the change-plan page for a user with the change-plan permission', function () {
    // Make a tenant + an active subscription on a `basic` plan so
    // the Inertia page can resolve `currentPlan` from the model.
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    Plan::factory()->createQuietly(['name' => 'Premium', 'slug' => 'premium']);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $tenant->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    $this->actingAs($user)
        ->get(route('billing.change-plan.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/change-plan')
            ->has('plans', 2)
            ->has('currentPlan')
            ->where('currentPlan.name', 'Basic')
        );
});

test('available plans in the change-plan page exclude the current plan', function () {
    $basic = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $free = Plan::factory()->createQuietly(['name' => 'Free', 'slug' => 'free']);
    $premium = Plan::factory()->createQuietly(['name' => 'Premium', 'slug' => 'premium']);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $basic->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $tenant->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    $this->actingAs($user)
        ->get(route('billing.change-plan.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/change-plan')
            ->has('plans', 3)
            ->where('currentPlan.slug', 'basic')
            // The page receives ALL active plans. The Inertia page
            // component is responsible for filtering out the current
            // one (avoids server-side coupling to the data shape).
            ->where('plans.0.slug', fn ($slug) => in_array($slug, ['basic', 'free', 'premium'], true))
        );
});

test('POST /billing/change-plan updates the subscription to the requested plan', function () {
    $basic = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $premium = Plan::factory()->createQuietly(['name' => 'Premium', 'slug' => 'premium']);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $basic->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);
    $tenant->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    Carbon::setTestNow('2026-06-15 12:00:00');
    try {
        $this->actingAs($user)
            ->post(route('billing.change-plan.update'), ['plan_id' => $premium->id])
            ->assertRedirect(route('billing.change-plan.show'))
            ->assertSessionHas('success');

        $subscription = $tenant->subscription()->first();
        expect($subscription->plan_id)->toBe($premium->id)
            ->and($subscription->ends_at?->toDateTimeString())->toBe('2026-07-15 12:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('POST /billing/change-plan with the current plan returns 422', function () {
    $basic = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $basic->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $tenant->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    $this->actingAs($user)
        ->post(route('billing.change-plan.update'), ['plan_id' => $basic->id])
        ->assertStatus(422);
});

test('POST /billing/change-plan only affects the current tenant', function () {
    $basic = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $premium = Plan::factory()->createQuietly(['name' => 'Premium', 'slug' => 'premium']);

    $tenant1 = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant1->id,
        'plan_id' => $basic->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant2 = Tenant::factory()->createQuietly();
    $tenant2OriginalPlan = Plan::factory()->createQuietly(['name' => 'Tenant2 Plan', 'slug' => 'tenant2-plan']);
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant2->id,
        'plan_id' => $tenant2OriginalPlan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    // Make tenant1 current and act as a tenant1 user.
    $tenant1->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    $this->actingAs($user)
        ->post(route('billing.change-plan.update'), ['plan_id' => $premium->id])
        ->assertRedirect(route('billing.change-plan.show'));

    // tenant1's subscription is now premium.
    expect($tenant1->subscription()->first()->plan_id)->toBe($premium->id);

    // tenant2's subscription is untouched.
    expect($tenant2->subscription()->first()->plan_id)->toBe($tenant2OriginalPlan->id);
});

test('POST /billing/change-plan creates a subscription history entry with plan_changed event', function () {
    $basic = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic', 'price_cents' => 2900]);
    $premium = Plan::factory()->createQuietly(['name' => 'Premium', 'slug' => 'premium', 'price_cents' => 9900]);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $basic->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);
    $tenant->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    $this->actingAs($user)
        ->post(route('billing.change-plan.update'), ['plan_id' => $premium->id])
        ->assertRedirect(route('billing.change-plan.show'));

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history)->not->toBeNull();
    expect($history->event_type)->toBe(SubscriptionEventType::PlanChanged);
    expect($history->old_plan_name)->toBe('Basic');
    expect($history->old_plan_price_cents)->toBe(2900);
    expect($history->new_plan_name)->toBe('Premium');
    expect($history->new_plan_price_cents)->toBe(9900);
});

test('POST /billing/change-plan history entry captures old and new plan snapshots', function () {
    $basic = Plan::factory()->createQuietly([
        'name' => 'Basic',
        'slug' => 'basic',
        'price_cents' => 2900,
        'features' => ['email' => true],
    ]);
    $premium = Plan::factory()->createQuietly([
        'name' => 'Premium',
        'slug' => 'premium',
        'price_cents' => 9900,
        'features' => ['email' => true, 'reports' => true],
    ]);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $basic->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);
    $tenant->makeCurrent();

    $user = makeChangePlanUser(canChangePlan: true);

    $this->actingAs($user)
        ->post(route('billing.change-plan.update'), ['plan_id' => $premium->id])
        ->assertRedirect(route('billing.change-plan.show'));

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history->old_plan_features)->toBe(['email' => true]);
    expect($history->old_status)->toBe('active');
    expect($history->new_plan_features)->toBe(['email' => true, 'reports' => true]);
    expect($history->new_status)->toBe('active');
});

/**
 * Build a minimal Authenticatable that authorises `change-plan`
 * (or not). Reused by the show / update tests.
 *
 * Creates a real Landlord user so the `actor_id` FK in
 * `subscription_history` is satisfied. The anonymous class
 * overrides `can()` to mock the permission check.
 */
function makeChangePlanUser(bool $canChangePlan = true): Authenticatable
{
    $admin = Landlord::factory()->create();

    return new class($canChangePlan, $admin->id) implements Authenticatable
    {
        public int $id;

        public function __construct(private readonly bool $canChangePlan, int $id)
        {
            $this->id = $id;
        }

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

        public function can(string $ability): bool
        {
            return $this->canChangePlan;
        }
    };
}
