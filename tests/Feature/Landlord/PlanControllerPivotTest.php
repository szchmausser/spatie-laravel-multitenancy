<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Resource;

/**
 * Tests for PlanController resource_ids validation + sync (R3).
 *
 * Covers: storing with resources creates pivot rows, update syncs,
 * empty resource_ids is allowed, invalid resource ID is rejected.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('plan creation accepts resource_ids and creates pivot rows', function () {
    $resources = Resource::factory()->count(2)->create();

    $response = $this->post(route('landlord.plans.store'), [
        'name' => 'Pro Plan',
        'slug' => 'pro',
        'description' => 'Professional plan',
        'features' => ['premium-zone' => true],
        'price_cents' => 1999,
        'is_active' => true,
        'resource_ids' => $resources->pluck('id')->all(),
    ]);

    $response->assertRedirect();

    $plan = Plan::query()->where('slug', 'pro')->first();
    expect($plan->resources)->toHaveCount(2)
        ->and($plan->resources->pluck('id')->all())->toEqualCanonicalizing($resources->pluck('id')->all());
});

test('plan creation rejects invalid resource_id', function () {
    $response = $this->post(route('landlord.plans.store'), [
        'name' => 'Invalid Plan',
        'slug' => 'invalid',
        'description' => null,
        'features' => ['premium-zone' => false],
        'price_cents' => 0,
        'is_active' => true,
        'resource_ids' => [999],
    ]);

    $response->assertSessionHasErrors(['resource_ids.0']);
});

test('plan creation allows empty resource_ids', function () {
    $response = $this->post(route('landlord.plans.store'), [
        'name' => 'Empty Plan',
        'slug' => 'empty',
        'description' => null,
        'features' => ['premium-zone' => false],
        'price_cents' => 0,
        'is_active' => true,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('landlord.plans.index'));

    $plan = Plan::query()->where('slug', 'empty')->first();
    expect($plan)->not->toBeNull();
    expect($plan->resources)->toHaveCount(0);
});

test('plan update syncs resource_ids correctly', function () {
    $plan = Plan::factory()->createQuietly();
    $resourceA = Resource::factory()->create();
    $resourceB = Resource::factory()->create();
    $resourceC = Resource::factory()->create();

    // Attach resourceA initially
    $plan->resources()->attach($resourceA->id);

    // Update with resourceB and resourceC — resourceA should be removed
    $response = $this->put(route('landlord.plans.update', $plan), [
        'name' => $plan->name,
        'slug' => $plan->slug,
        'description' => $plan->description,
        'features' => $plan->features,
        'price_cents' => $plan->price_cents,
        'is_active' => $plan->is_active,
        'resource_ids' => [$resourceB->id, $resourceC->id],
    ]);

    $response->assertRedirect();

    $plan->refresh();
    expect($plan->resources)->toHaveCount(2)
        ->and($plan->resources->pluck('id')->all())->toEqualCanonicalizing([$resourceB->id, $resourceC->id])
        ->and($plan->resources->pluck('id')->all())->not->toContain($resourceA->id);
});
