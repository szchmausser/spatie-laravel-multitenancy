<?php

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Billing\SubscriptionHistoryController;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

/**
 * Feature tests for {@see SubscriptionHistoryController}.
 *
 * Tenant-side read surface for subscription history. Guarded by
 * `$user->can('change-plan')` — same permission as PlanChangeController.
 * Shows paginated history scoped to the current tenant.
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

test('redirects unauthenticated user to login on GET /billing/history', function () {
    $this->get(route('billing.history'))
        ->assertRedirect(route('login'));
});

test('returns 403 for a user without the change-plan permission on GET /billing/history', function () {
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
            return false;
        }
    };

    $this->actingAs($user)
        ->get(route('billing.history'))
        ->assertForbidden();
});

test('renders the history page for an authorized user', function () {
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $tenant->makeCurrent();

    $user = makeHistoryUser(canChangePlan: true);

    $this->actingAs($user)
        ->get(route('billing.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/history')
            ->has('history')
        );
});

test('history is scoped to the current tenant only', function () {
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $tenant1 = Tenant::factory()->createQuietly();
    $tenant2 = Tenant::factory()->createQuietly();

    $subscription1 = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant1->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $subscription2 = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant2->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    // Create history entries for both tenants
    SubscriptionHistory::record([
        'subscription_id' => $subscription1->id,
        'tenant_id' => $tenant1->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'new_plan_name' => 'Basic',
        'new_plan_price_cents' => 2900,
        'new_status' => 'active',
        'currency' => 'USD',
    ]);
    SubscriptionHistory::record([
        'subscription_id' => $subscription2->id,
        'tenant_id' => $tenant2->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'new_plan_name' => 'Premium',
        'new_plan_price_cents' => 9900,
        'new_status' => 'active',
        'currency' => 'USD',
    ]);

    $tenant1->makeCurrent();

    $user = makeHistoryUser(canChangePlan: true);

    $this->actingAs($user)
        ->get(route('billing.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/history')
            ->has('history.data', 1)
            ->where('history.data.0.new_plan_name', 'Basic')
        );
});

test('history is paginated', function () {
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    // Create 25 history entries for pagination testing
    for ($i = 0; $i < 25; $i++) {
        SubscriptionHistory::record([
            'subscription_id' => $subscription->id,
            'tenant_id' => $tenant->id,
            'event_type' => SubscriptionEventType::PlanChanged,
            'old_plan_name' => 'Basic',
            'new_plan_name' => 'Premium',
            'old_plan_price_cents' => 2900,
            'new_plan_price_cents' => 9900,
            'old_status' => 'active',
            'new_status' => 'active',
            'currency' => 'USD',
        ]);
    }

    $tenant->makeCurrent();

    $user = makeHistoryUser(canChangePlan: true);

    // First page — should have 20 entries (default pagination)
    $this->actingAs($user)
        ->get(route('billing.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/history')
            ->has('history.data', 20)
            ->where('history.current_page', 1)
            ->where('history.last_page', 2)
            ->where('history.total', 25)
        );

    // Second page — should have 5 entries
    $this->actingAs($user)
        ->get(route('billing.history', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/history')
            ->has('history.data', 5)
            ->where('history.current_page', 2)
        );
});

test('empty history shows empty state', function () {
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'slug' => 'basic']);
    $tenant = Tenant::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $tenant->makeCurrent();

    $user = makeHistoryUser(canChangePlan: true);

    $this->actingAs($user)
        ->get(route('billing.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/history')
            ->where('history.data', [])
            ->where('history.total', 0)
        );
});

/**
 * Build a minimal Authenticatable that authorises `change-plan`
 * (or not). Reused by the history tests.
 *
 * Creates a real Landlord user so the `actor_id` FK in
 * `subscription_history` is satisfied.
 */
function makeHistoryUser(bool $canChangePlan = true): Authenticatable
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
