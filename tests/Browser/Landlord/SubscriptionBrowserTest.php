<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Browser tests for the subscription index and show pages.
 *
 * Verifies the landlord can:
 *  - browse the subscriptions list with data
 *  - search/filter subscriptions
 *  - view subscription details on the show page
 *  - navigate from list to detail
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('subscriptions list renders and shows subscriptions', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan', 'slug' => 'gold']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp']);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.index'))
        ->assertSee('Subscriptions')
        ->assertSee('Acme Corp')
        ->assertSee('Gold Plan')
        ->assertSee('active')
        ->assertNoJavaScriptErrors();
});

test('subscriptions list search filters results', function () {
    $plan = Plan::factory()->create(['name' => 'Pro Plan']);
    $tenantA = Tenant::factory()->createQuietly(['name' => 'Alpha Corp']);
    $tenantB = Tenant::factory()->createQuietly(['name' => 'Beta Inc']);
    Subscription::factory()->create(['tenant_id' => $tenantA->id, 'plan_id' => $plan->id]);
    Subscription::factory()->create(['tenant_id' => $tenantB->id, 'plan_id' => $plan->id]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.index'))
        ->assertSee('Alpha Corp')
        ->assertSee('Beta Inc')
        ->type('@search-subscriptions-input', 'Alpha')
        ->assertSee('Alpha Corp')
        ->assertDontSee('Beta Inc')
        ->assertNoJavaScriptErrors();
});

test('admin can view subscription detail page', function () {
    $plan = Plan::factory()->create([
        'name' => 'Premium Plan',
        'slug' => 'premium',
        'price_cents' => 4900,
        'features' => ['premium-zone' => true, 'api-access' => true],
    ]);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Detail Corp']);
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.show', $subscription))
        ->assertSee('Subscription #'.$subscription->id)
        ->assertSee('active')
        ->assertSee('Detail Corp')
        ->assertSee('Premium Plan')
        ->assertSee('premium-zone')
        ->assertSee('api-access')
        ->assertNoJavaScriptErrors();
});

test('admin can navigate from list to detail page', function () {
    $plan = Plan::factory()->create(['name' => 'Basic Plan']);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Nav Corp']);
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.index'))
        ->click("@view-subscription-btn-{$subscription->id}")
        ->assertSee('Subscription #'.$subscription->id)
        ->assertSee('Nav Corp')
        ->assertNoJavaScriptErrors();
});
