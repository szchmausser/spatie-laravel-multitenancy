<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Tests for the auto-assign-default-subscription behaviour.
 *
 * Business rule: every tenant in the system must have a subscription
 * row. Tenants created by the seeder or the admin UI go through
 * Tenant::created, which delegates to ensureDefaultSubscription().
 * The seeder passes an explicit `assignPlanSlug`; everyone else
 * falls back to the 'free' plan.
 *
 * These tests use createQuietly() to skip the provisioning callback
 * (which issues CREATE DATABASE and cannot run inside a transaction).
 * The auto-assign method is exercised directly so we test the rule
 * in isolation from the provisioning side-effects.
 */
test('new tenant without explicit plan gets the free plan', function () {
    Plan::factory()->createQuietly(['slug' => 'free', 'name' => 'Free']);

    $tenant = Tenant::factory()->createQuietly();

    $tenant->ensureDefaultSubscription();
    $tenant->refresh();

    expect($tenant->subscription)->not->toBeNull();
    expect($tenant->subscription->plan->slug)->toBe('free');
    expect($tenant->subscription->status)->toBe(SubscriptionStatus::Active);
});

test('new tenant with explicit assignPlanSlug gets that plan', function () {
    Plan::factory()->createQuietly(['slug' => 'free', 'name' => 'Free']);
    Plan::factory()->createQuietly(['slug' => 'premium', 'name' => 'Premium']);

    $tenant = Tenant::factory()->createQuietly();
    $tenant->assignPlanSlug = 'premium';

    $tenant->ensureDefaultSubscription();
    $tenant->refresh();

    expect($tenant->subscription->plan->slug)->toBe('premium');
});

test('ensureDefaultSubscription does not duplicate an existing subscription', function () {
    Plan::factory()->createQuietly(['slug' => 'free', 'name' => 'Free']);
    Plan::factory()->createQuietly(['slug' => 'premium', 'name' => 'Premium']);

    $tenant = Tenant::factory()->createQuietly();
    $existingPlan = Plan::factory()->createQuietly(['slug' => 'basic', 'name' => 'Basic']);

    Subscription::on('landlord')->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $existingPlan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->assignPlanSlug = 'premium';
    $tenant->ensureDefaultSubscription();
    $tenant->refresh();

    // The existing 'basic' subscription must not be overwritten by
    // the default-fallback logic. The UNIQUE(tenant_id) constraint
    // would also prevent a second row from being inserted.
    expect(Subscription::on('landlord')->where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($tenant->subscription->plan->slug)->toBe('basic');
});

test('ensureDefaultSubscription throws when the requested plan does not exist', function () {
    $tenant = Tenant::factory()->createQuietly();
    $tenant->assignPlanSlug = 'non-existent-plan';

    $tenant->ensureDefaultSubscription();
})->throws(RuntimeException::class, "plan with slug 'non-existent-plan' not found");
