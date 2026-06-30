<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

test('plan correctly resolves features', function () {
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true, 'advanced-reports' => false],
    ]);

    expect($plan->hasFeature('premium-zone'))->toBeTrue();
    expect($plan->hasFeature('advanced-reports'))->toBeFalse();
    expect($plan->hasFeature('nonexistent'))->toBeFalse();
});

test('subscription delegates hasFeature to plan', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true],
    ]);

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    expect($subscription->hasFeature('premium-zone'))->toBeTrue();
    expect($subscription->hasFeature('nonexistent'))->toBeFalse();
});

test('tenant without subscription has no features', function () {
    $tenant = Tenant::factory()->createQuietly();

    expect($tenant->hasFeature('premium-zone'))->toBeFalse();
});

test('tenant with active subscription has features', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true, 'advanced-reports' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    expect($tenant->hasFeature('premium-zone'))->toBeTrue();
    expect($tenant->hasFeature('advanced-reports'))->toBeTrue();
});

test('tenant with cancelled subscription has no features', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Cancelled,
    ]);

    expect($tenant->hasFeature('premium-zone'))->toBeFalse();
});

test('plan scope filters inactive plans', function () {
    Plan::factory()->createQuietly(['is_active' => true]);
    Plan::factory()->createQuietly(['is_active' => false]);

    $activePlans = Plan::active()->get();

    expect($activePlans)->toHaveCount(1);
    expect($activePlans->first()->is_active)->toBeTrue();
});

test('subscription status helpers work correctly', function () {
    $active = Subscription::factory()->createQuietly(['status' => SubscriptionStatus::Active]);
    $trialing = Subscription::factory()->createQuietly(['status' => SubscriptionStatus::Trialing]);
    $cancelled = Subscription::factory()->createQuietly(['status' => SubscriptionStatus::Cancelled]);
    $expired = Subscription::factory()->createQuietly(['status' => SubscriptionStatus::Expired]);

    expect($active->isActive())->toBeTrue();
    expect($active->isTrialing())->toBeFalse();

    expect($trialing->isTrialing())->toBeTrue();
    expect($trialing->isActive())->toBeFalse();

    expect($cancelled->isCancelled())->toBeTrue();
    expect($cancelled->isActive())->toBeFalse();

    expect($expired->isExpired())->toBeTrue();
    expect($expired->isActive())->toBeFalse();
});
