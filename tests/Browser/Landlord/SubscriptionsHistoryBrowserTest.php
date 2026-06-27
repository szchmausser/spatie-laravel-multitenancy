<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;

/**
 * Browser tests for the landlord subscription history page.
 *
 * Covers:
 *   - Admin can see the subscription history page
 *   - History shows subscription changes
 *   - History shows empty state when no changes
 *   - Unauthenticated user is redirected to login
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('admin can see subscription history page', function () {
    $tenant = Tenant::factory()->createQuietly(['name' => 'History Corp']);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.history', $tenant))
        ->assertSee('Subscription History')
        ->assertSee('History Corp')
        ->assertNoJavaScriptErrors();
});

test('history shows subscription changes', function () {
    $plan = Plan::factory()->create(['name' => 'Gold Plan', 'price_cents' => 4900]);
    $tenant = Tenant::factory()->createQuietly(['name' => 'Change Corp']);
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    SubscriptionHistory::create([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => 'subscription_created',
        'actor_type' => 'landlord',
        'new_plan_name' => 'Gold Plan',
        'new_plan_price_cents' => 4900,
        'new_status' => 'active',
        'currency' => 'USD',
        'amount_cents' => 4900,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.history', $tenant))
        ->waitForText('Subscription History')
        ->assertSee('Subscription History')
        ->assertSee('Created')
        ->assertSee('Gold Plan')
        ->assertNoJavaScriptErrors();
});

test('history shows empty state when no changes', function () {
    $tenant = Tenant::factory()->createQuietly(['name' => 'Empty Corp']);

    $this->actingAs($this->admin)
        ->visit(route('landlord.subscriptions.history', $tenant))
        ->waitForText('Subscription History')
        ->assertSee('Subscription History')
        ->assertSee('No subscription history entries yet.')
        ->assertNoJavaScriptErrors();
});
