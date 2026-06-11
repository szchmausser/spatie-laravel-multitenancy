<?php

use App\Enums\SubscriptionEventType;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('subscription history page renders for a tenant with history entries', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'new_plan_name' => 'Basic',
        'new_plan_price_cents' => 2900,
        'new_status' => 'active',
    ]);

    $response = $this->get(route('landlord.subscriptions.history', $tenant));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/subscriptions/history')
            ->has('tenant')
            ->has('history.data', 1)
        );
});

test('subscription history page shows empty state for tenant with no history', function () {
    $tenant = Tenant::factory()->createQuietly();

    $response = $this->get(route('landlord.subscriptions.history', $tenant));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/subscriptions/history')
            ->has('tenant')
            ->has('history.data', 0)
        );
});

test('subscription history is scoped to the specific tenant', function () {
    $tenantA = Tenant::factory()->createQuietly();
    $tenantB = Tenant::factory()->createQuietly();

    $plan = Plan::factory()->createQuietly();
    $subA = Subscription::factory()->createQuietly(['tenant_id' => $tenantA->id, 'plan_id' => $plan->id]);
    $subB = Subscription::factory()->createQuietly(['tenant_id' => $tenantB->id, 'plan_id' => $plan->id]);

    SubscriptionHistory::record([
        'subscription_id' => $subA->id,
        'tenant_id' => $tenantA->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'new_plan_name' => 'Basic',
        'new_status' => 'active',
    ]);

    SubscriptionHistory::record([
        'subscription_id' => $subB->id,
        'tenant_id' => $tenantB->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'new_plan_name' => 'Basic',
        'new_status' => 'active',
    ]);

    $response = $this->get(route('landlord.subscriptions.history', $tenantA));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/subscriptions/history')
            ->has('history.data', 1)
        );
});
