<?php

use App\Models\Plan;
use Illuminate\Database\Eloquent\Relations\HasMany;

test('plan model has correct fillable attributes', function () {
    $plan = Plan::factory()->make([
        'name' => 'Pro Plan',
        'slug' => 'pro',
        'description' => 'Professional plan',
        'features' => ['premium-zone' => true, 'advanced-reports' => true],
        'price_cents' => 1999,
        'is_active' => true,
    ]);

    expect($plan->name)->toBe('Pro Plan');
    expect($plan->slug)->toBe('pro');
    expect($plan->description)->toBe('Professional plan');
    expect($plan->features)->toBe(['premium-zone' => true, 'advanced-reports' => true]);
    expect($plan->price_cents)->toBe(1999);
    expect($plan->is_active)->toBeTrue();
});

test('plan hasFeature returns true when feature is enabled', function () {
    $plan = Plan::factory()->make([
        'features' => ['premium-zone' => true, 'advanced-reports' => false],
    ]);

    expect($plan->hasFeature('premium-zone'))->toBeTrue();
    expect($plan->hasFeature('advanced-reports'))->toBeFalse();
    expect($plan->hasFeature('non-existent-feature'))->toBeFalse();
});

test('plan scopeActive filters inactive plans', function () {
    $activePlan = Plan::factory()->createQuietly(['is_active' => true]);
    $inactivePlan = Plan::factory()->createQuietly(['is_active' => false]);

    $activePlans = Plan::active()->get();

    expect($activePlans->contains($activePlan))->toBeTrue();
    expect($activePlans->contains($inactivePlan))->toBeFalse();
});

test('plan has many subscriptions', function () {
    $plan = Plan::factory()->createQuietly();

    expect($plan->subscriptions())->toBeInstanceOf(HasMany::class);
});
