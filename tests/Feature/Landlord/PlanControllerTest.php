<?php

use App\Models\Landlord;
use App\Models\Plan;

test('admin can access plans index', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $response = $this->get(route('landlord.plans.index'));
    $response->assertOk();
});

test('admin can create a plan', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $planData = [
        'name' => 'Pro Plan',
        'slug' => 'pro',
        'description' => 'Professional plan',
        'features' => ['premium-zone' => true, 'advanced-reports' => true],
        'price_cents' => 1999,
        'is_active' => true,
    ];

    $response = $this->post(route('landlord.plans.store'), $planData);

    $response->assertRedirect();
    $this->assertDatabaseHas('plans', [
        'name' => 'Pro Plan',
        'slug' => 'pro',
        'price_cents' => 1999,
    ], 'landlord');
});

test('admin can update a plan', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $plan = Plan::factory()->createQuietly();

    $updateData = [
        'name' => 'Updated Plan',
        'slug' => $plan->slug,
        'description' => 'Updated description',
        'features' => ['premium-zone' => true],
        'price_cents' => 2999,
        'is_active' => true,
    ];

    $response = $this->put(route('landlord.plans.update', $plan), $updateData);

    $response->assertRedirect();
    $this->assertDatabaseHas('plans', [
        'id' => $plan->id,
        'name' => 'Updated Plan',
        'price_cents' => 2999,
    ], 'landlord');
});

test('admin can deactivate a plan', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $plan = Plan::factory()->createQuietly(['is_active' => true]);

    $response = $this->delete(route('landlord.plans.destroy', $plan));

    $response->assertRedirect();
    $this->assertDatabaseHas('plans', [
        'id' => $plan->id,
        'is_active' => false,
    ], 'landlord');
});

test('guest cannot access plans index', function () {
    $response = $this->get(route('landlord.plans.index'));
    $response->assertRedirect(route('login'));
});
