<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Resource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Tests for Landlord\ResourceController plan_ids validation + sync (R4).
 *
 * Covers: storing with plans creates pivot rows, update syncs
 * replaces correctly, empty plan_ids is allowed, invalid plan ID rejected.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('resource creation accepts plan_ids and creates pivot rows', function () {
    $plans = Plan::factory()->count(2)->createQuietly();

    $response = $this->post(route('landlord.resources.store'), [
        'name' => 'Guide',
        'slug' => 'guide',
        'description' => null,
        'file' => UploadedFile::fake()->create('guide.pdf', 10, 'application/pdf'),
        'is_premium' => true,
        'price_cents' => 999,
        'is_active' => true,
        'plan_ids' => $plans->pluck('id')->all(),
    ]);

    $response->assertRedirect();

    $resource = Resource::query()->where('slug', 'guide')->first();
    expect($resource->plans)->toHaveCount(2)
        ->and($resource->plans->pluck('id')->all())->toEqualCanonicalizing($plans->pluck('id')->all());
});

test('resource creation rejects invalid plan_id', function () {
    $response = $this->post(route('landlord.resources.store'), [
        'name' => 'Invalid',
        'slug' => 'invalid',
        'file' => UploadedFile::fake()->create('dummy.pdf', 10, 'application/pdf'),
        'is_premium' => false,
        'price_cents' => 0,
        'is_active' => true,
        'plan_ids' => [999],
    ]);

    $response->assertSessionHasErrors(['plan_ids.0']);
});

test('resource update syncs plan_ids correctly', function () {
    $planA = Plan::factory()->createQuietly();
    $planB = Plan::factory()->createQuietly();
    $planC = Plan::factory()->createQuietly();

    $resource = Resource::factory()->create();

    // Attach planA initially
    $resource->plans()->attach($planA->id);

    // Update with planB and planC — planA should be removed
    $response = $this->put(route('landlord.resources.update', $resource), [
        'name' => $resource->name,
        'slug' => $resource->slug,
        'description' => $resource->description,
        'is_premium' => $resource->is_premium,
        'price_cents' => $resource->price_cents,
        'is_active' => $resource->is_active,
        'plan_ids' => [$planB->id, $planC->id],
    ]);

    $response->assertRedirect();

    $resource->refresh();
    expect($resource->plans)->toHaveCount(2)
        ->and($resource->plans->pluck('id')->all())->toEqualCanonicalizing([$planB->id, $planC->id])
        ->and($resource->plans->pluck('id')->all())->not->toContain($planA->id);
});

test('resource creation allows empty plan_ids', function () {
    $response = $this->post(route('landlord.resources.store'), [
        'name' => 'Standalone',
        'slug' => 'standalone',
        'file' => UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
        'is_premium' => false,
        'price_cents' => 0,
        'is_active' => true,
    ]);

    $response->assertRedirect();

    $resource = Resource::query()->where('slug', 'standalone')->first();
    expect($resource->plans)->toHaveCount(0);
});
