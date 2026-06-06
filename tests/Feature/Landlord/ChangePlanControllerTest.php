<?php

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Landlord\ChangePlanController;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;

/**
 * Feature tests for {@see ChangePlanController}.
 *
 * The landlord-side backdoor for plan change. Bypasses the
 * tenant-side `change-plan` permission (the landlord DB has no
 * Spatie tables) and is protected by the `EnsureUserIsAdmin`
 * middleware at the route group level.
 *
 * The `null` and `landlord` connections are transacted by the
 * base TestCase, so landlord-side tests get transactional
 * isolation out of the box and do not need the
 * `pointTenantConnectionAtTestDatabase()` helper.
 */
beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    // Every landlord test needs an authenticated admin — the
    // `EnsureUserIsAdmin` middleware short-circuits the request
    // otherwise. The `landlord` connection is transacted by the
    // base TestCase, so the admin is wiped at the end of the test.
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('landlord can change a tenant plan via the admin route', function () {
    $tenant = Tenant::factory()->createQuietly();
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    Carbon::setTestNow('2026-06-15 12:00:00');
    try {
        $this->post(
            route('landlord.subscriptions.change', ['tenant' => $tenant->id]),
            ['plan_id' => $newPlan->id],
        )
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('success');

        $subscription = Subscription::on('landlord')
            ->where('tenant_id', $tenant->id)
            ->first();
        expect($subscription->plan_id)->toBe($newPlan->id)
            ->and($subscription->ends_at?->toDateTimeString())->toBe('2026-07-15 12:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('landlord changing to the same plan returns 422', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->post(
        route('landlord.subscriptions.change', ['tenant' => $tenant->id]),
        ['plan_id' => $plan->id],
    )->assertStatus(422);
});

test('tenant user hitting the landlord change-plan route is rejected with 403', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    // Drop the admin and replace with a non-Landlord authenticatable.
    // `EnsureUserIsAdmin` checks `$request->user() instanceof Landlord`
    // — passing a plain `Authenticatable` (not a Landlord instance)
    // exercises the rejection path.
    $tenantUser = new class implements Authenticatable
    {
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
    };

    $this->actingAs($tenantUser)
        ->post(
            route('landlord.subscriptions.change', ['tenant' => $tenant->id]),
            ['plan_id' => $plan->id],
        )
        ->assertForbidden();
});
