<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

test('subscription factory creates valid subscription', function () {
    $subscription = Subscription::factory()->createQuietly();

    expect($subscription->tenant_id)->toBeNumeric();
    expect($subscription->plan_id)->toBeNumeric();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->trial_ends_at)->toBeNull();
    expect($subscription->ends_at)->toBeNull();
});

test('subscription factory trialing state sets status to trialing', function () {
    $subscription = Subscription::factory()->trialing()->createQuietly();

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing);
});

test('subscription factory cancelled state sets status to cancelled', function () {
    $subscription = Subscription::factory()->cancelled()->createQuietly();

    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled);
});

test('subscription factory expired state sets status to expired', function () {
    $subscription = Subscription::factory()->expired()->createQuietly();

    expect($subscription->status)->toBe(SubscriptionStatus::Expired);
});

test('subscription factory creates associated tenant and plan', function () {
    $subscription = Subscription::factory()->createQuietly();

    expect($subscription->tenant)->toBeInstanceOf(Tenant::class);
    expect($subscription->plan)->toBeInstanceOf(Plan::class);
});
