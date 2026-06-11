<?php

use App\Enums\SubscriptionEventType;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;

test('record() inserts a history row with correct values', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::PlanChanged,
        'old_plan_name' => 'Free',
        'old_plan_price_cents' => 0,
        'old_plan_features' => ['email' => true],
        'old_status' => 'active',
        'new_plan_name' => 'Basic',
        'new_plan_price_cents' => 2900,
        'new_plan_features' => ['email' => true, 'reports' => true],
        'new_status' => 'active',
    ]);

    expect($history)->toBeInstanceOf(SubscriptionHistory::class);
    expect($history->subscription_id)->toBe($subscription->id);
    expect($history->tenant_id)->toBe($tenant->id);
    expect($history->event_type)->toBe(SubscriptionEventType::PlanChanged);
    expect($history->old_plan_name)->toBe('Free');
    expect($history->old_plan_price_cents)->toBe(0);
    expect($history->old_plan_features)->toBe(['email' => true]);
    expect($history->old_status)->toBe('active');
    expect($history->new_plan_name)->toBe('Basic');
    expect($history->new_plan_price_cents)->toBe(2900);
    expect($history->new_plan_features)->toBe(['email' => true, 'reports' => true]);
    expect($history->new_status)->toBe('active');
});

test('model casts event_type to SubscriptionEventType enum', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'new_plan_name' => $plan->name,
        'new_status' => 'active',
    ]);

    expect($history->event_type)->toBeInstanceOf(SubscriptionEventType::class);
    expect($history->event_type)->toBe(SubscriptionEventType::SubscriptionCreated);
});

test('model queries against landlord connection', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::SubscriptionExpired,
        'old_plan_name' => 'Basic',
        'old_status' => 'active',
        'new_status' => 'expired',
    ]);

    // Query should work against landlord connection
    $found = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($found)->not->toBeNull();
    expect($found->event_type)->toBe(SubscriptionEventType::SubscriptionExpired);
});

test('record() returns the created model instance', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::PlanChanged,
        'new_plan_name' => 'Premium',
        'new_status' => 'active',
    ]);

    expect($history->id)->not->toBeNull();
    expect($history->exists)->toBeTrue();
});

test('history entry survives plan edits (snapshot immutability)', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::PlanChanged,
        'new_plan_name' => 'Basic',
        'new_plan_price_cents' => 2900,
        'new_plan_features' => ['email' => true],
        'new_status' => 'active',
    ]);

    // Simulate plan edit
    $plan->update(['name' => 'Starter', 'price_cents' => 3900]);

    // History entry should still show original values
    $history->refresh();
    expect($history->new_plan_name)->toBe('Basic');
    expect($history->new_plan_price_cents)->toBe(2900);
    expect($history->new_plan_features)->toBe(['email' => true]);
});
