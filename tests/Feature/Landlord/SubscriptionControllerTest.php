<?php

use App\Enums\SubscriptionStatus;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    // Disable CSRF for HTTP POST/PUT/DELETE in feature tests
    $this->withoutMiddleware(VerifyCsrfToken::class);

    // Shared precondition: an authenticated admin (every test needs it)
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('admin can access subscriptions index', function () {
    $response = $this->get(route('landlord.subscriptions.index'));
    $response->assertOk();
});

test('admin can view a subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $response = $this->get(route('landlord.subscriptions.show', $subscription));
    $response->assertOk();
});

test('admin can assign a plan to a tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
    ]);

    // Verify the concrete result: subscription persisted with the assigned plan
    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->plan_id)->toBe($plan->id);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});

test('admin assigning new plan updates existing subscription, not duplicates', function () {
    $tenant = Tenant::factory()->createQuietly();
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $newPlan->id,
    ]);

    // Verify: only one subscription, and it now points to the new plan
    $count = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->count();

    expect($count)->toBe(1);

    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($subscription->plan_id)->toBe($newPlan->id);
});
