<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

test('tenant without subscription has no features', function () {
    $tenant = Tenant::factory()->createQuietly();

    expect($tenant->hasFeature('premium-zone'))->toBeFalse();
});

test('tenant with subscription has feature when plan has it', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true, 'premium-content' => false],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    expect($tenant->hasFeature('premium-zone'))->toBeTrue();
    expect($tenant->hasFeature('premium-content'))->toBeFalse();
});

test('tenant without subscription returns null for activeSubscription', function () {
    $tenant = Tenant::factory()->createQuietly();

    expect($tenant->activeSubscription())->toBeNull();
});

test('tenant with active subscription returns subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    expect($tenant->activeSubscription())->toBeInstanceOf(Subscription::class);
    expect($tenant->activeSubscription()->id)->toBe($subscription->id);
});

test('tenant with cancelled subscription returns null for activeSubscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Cancelled,
    ]);

    expect($tenant->activeSubscription())->toBeNull();
});

test('tenant subscription relation returns subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    expect($tenant->subscription)->toBeInstanceOf(Subscription::class);
    expect($tenant->subscription->id)->toBe($subscription->id);
});

test('tenant with past ends_at returns null for activeSubscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    expect($tenant->activeSubscription())->toBeNull();
});

test('tenant with past ends_at returns false for hasFeature', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    expect($tenant->hasFeature('premium-zone'))->toBeFalse();
});

test('tenant with null ends_at retains access via activeSubscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    expect($tenant->activeSubscription())->toBeInstanceOf(Subscription::class);
    expect($tenant->activeSubscription()->id)->toBe($subscription->id);
});
