<?php

use App\Models\Plan;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

/**
 * Tests for the Plan-Resource many-to-many pivot.
 *
 * Covers R1 (pivot table creation and constraints), R2 (BelongsToMany
 * relationships on both models), and the unique + cascade behaviour.
 * These are model-level structural tests so the test method is the
 * same for every scenario: arrange data, act through the ORM, assert
 * expected state in the database.
 */
beforeEach(function () {
    $this->plan = Plan::factory()->createQuietly();
    $this->resource = Resource::factory()->create();
    $this->resourceB = Resource::factory()->create();
});

// ---------- R1: Pivot table structure ----------

test('plan_resource table exists with expected columns', function () {
    expect(Schema::connection('landlord')->hasTable('plan_resource'))->toBeTrue();

    $columns = Schema::connection('landlord')->getColumnListing('plan_resource');
    expect($columns)->toContain('plan_id', 'resource_id');
});

test('plan_resource unique constraint rejects duplicate pairs', function () {
    $this->plan->resources()->attach($this->resource->id);

    expect(fn () => $this->plan->resources()->attach($this->resource->id))
        ->toThrow(Exception::class);
});

test('plan_resource cascade deletes when plan is removed', function () {
    $this->plan->resources()->attach([$this->resource->id, $this->resourceB->id]);

    expect(Schema::connection('landlord')
        ->hasTable('plan_resource'))->toBeTrue();

    $planId = $this->plan->id;
    $this->plan->delete();

    $remaining = DB::connection('landlord')
        ->table('plan_resource')
        ->where('plan_id', $planId)
        ->count();

    expect($remaining)->toBe(0);
});

test('plan_resource cascade deletes when resource is removed', function () {
    $this->plan->resources()->attach([$this->resource->id, $this->resourceB->id]);

    $resourceId = $this->resource->id;
    $this->resource->delete();

    $remaining = DB::connection('landlord')
        ->table('plan_resource')
        ->where('resource_id', $resourceId)
        ->count();

    expect($remaining)->toBe(0);
});

// ---------- R2: BelongsToMany relationships ----------

test('plan has many resources via BelongsToMany', function () {
    $this->plan->resources()->attach([$this->resource->id, $this->resourceB->id]);

    $planResources = $this->plan->resources;

    expect($this->plan->resources())->toBeInstanceOf(BelongsToMany::class)
        ->and($planResources)->toHaveCount(2)
        ->and($planResources->pluck('id')->all())->toContain($this->resource->id, $this->resourceB->id);
});

test('resource has many plans via BelongsToMany', function () {
    $planB = Plan::factory()->createQuietly();

    $this->resource->plans()->attach([$this->plan->id, $planB->id]);

    $resourcePlans = $this->resource->plans;

    expect($this->resource->plans())->toBeInstanceOf(BelongsToMany::class)
        ->and($resourcePlans)->toHaveCount(2)
        ->and($resourcePlans->pluck('id')->all())->toContain($this->plan->id, $planB->id);
});
