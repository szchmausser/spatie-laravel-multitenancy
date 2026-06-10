<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

test('subscription model has correct fillable attributes', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'trial_ends_at' => now()->addDays(14),
        'ends_at' => null,
    ]);

    expect($subscription->tenant_id)->toBe($tenant->id);
    expect($subscription->plan_id)->toBe($plan->id);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->trial_ends_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($subscription->ends_at)->toBeNull();
});

test('subscription isActive returns true only for active status', function () {
    $activeSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
    ]);

    expect($activeSubscription->isActive())->toBeTrue();

    $cancelledSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Cancelled,
    ]);

    expect($cancelledSubscription->isActive())->toBeFalse();
});

test('subscription isTrialing returns true only for trialing status', function () {
    $trialingSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Trialing,
    ]);

    expect($trialingSubscription->isTrialing())->toBeTrue();

    $activeSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
    ]);

    expect($activeSubscription->isTrialing())->toBeFalse();
});

test('subscription isExpired returns true only for expired status', function () {
    $expiredSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Expired,
    ]);

    expect($expiredSubscription->isExpired())->toBeTrue();

    $activeSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
    ]);

    expect($activeSubscription->isExpired())->toBeFalse();
});

test('subscription isCancelled returns true only for cancelled status', function () {
    $cancelledSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Cancelled,
    ]);

    expect($cancelledSubscription->isCancelled())->toBeTrue();

    $activeSubscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
    ]);

    expect($activeSubscription->isCancelled())->toBeFalse();
});

test('subscription belongs to tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    expect($subscription->tenant)->toBeInstanceOf(Tenant::class);
    expect($subscription->tenant->id)->toBe($tenant->id);
});

test('subscription belongs to plan', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    expect($subscription->plan)->toBeInstanceOf(Plan::class);
    expect($subscription->plan->id)->toBe($plan->id);
});

test('subscription features delegates to plan', function () {
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true, 'premium-content' => false],
    ]);

    $subscription = Subscription::factory()->createQuietly([
        'plan_id' => $plan->id,
    ]);

    expect($subscription->features())->toBe(['premium-zone' => true, 'premium-content' => false]);
});

test('subscription hasFeature delegates to plan', function () {
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true, 'premium-content' => false],
    ]);

    $subscription = Subscription::factory()->createQuietly([
        'plan_id' => $plan->id,
    ]);

    expect($subscription->hasFeature('premium-zone'))->toBeTrue();
    expect($subscription->hasFeature('premium-content'))->toBeFalse();
    expect($subscription->hasFeature('non-existent-feature'))->toBeFalse();
});

test('subscription isCurrentlyValid returns true for active with future ends_at', function () {
    $subscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addMonth(),
    ]);

    expect($subscription->isCurrentlyValid())->toBeTrue();
});

test('subscription isCurrentlyValid returns false for active with past ends_at', function () {
    $subscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    expect($subscription->isCurrentlyValid())->toBeFalse();
});

test('subscription isCurrentlyValid returns true for active with null ends_at', function () {
    $subscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    expect($subscription->isCurrentlyValid())->toBeTrue();
});

test('subscription isCurrentlyValid returns false for expired status regardless of ends_at', function () {
    $subscription = Subscription::factory()->createQuietly([
        'status' => SubscriptionStatus::Expired,
        'ends_at' => now()->addMonth(),
    ]);

    expect($subscription->isCurrentlyValid())->toBeFalse();
});
