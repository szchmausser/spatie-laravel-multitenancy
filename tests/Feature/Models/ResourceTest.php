<?php

use App\Models\Resource;

/**
 * Resource model tests.
 *
 * Cover the casts, scopes, and the human-readable file size
 * formatter. The model itself is a thin wrapper around a row in
 * the `resources` table; the value lives in how it composes with
 * the controller and the entitlement system, which have their own
 * test files.
 */
test('casts boolean and integer columns', function () {
    $resource = Resource::factory()->create([
        'is_premium' => 1,
        'is_active' => 1,
        'price_cents' => '2999',
        'file_size_bytes' => '5242880',
    ]);

    expect($resource->is_premium)->toBeTrue()
        ->and($resource->is_active)->toBeTrue()
        ->and($resource->price_cents)->toBeInt()->toBe(2999)
        ->and($resource->file_size_bytes)->toBeInt()->toBe(5_242_880);
});

test('scope active returns only active resources', function () {
    Resource::factory()->create(['is_active' => true, 'name' => 'Visible']);
    Resource::factory()->inactive()->create(['name' => 'Hidden']);

    $names = Resource::query()->active()->pluck('name')->all();

    expect($names)->toContain('Visible')->not->toContain('Hidden');
});

test('scope premium returns only premium resources', function () {
    Resource::factory()->create(['name' => 'Freebie', 'is_premium' => false]);
    Resource::factory()->premium()->create(['name' => 'PaidGuide']);

    $names = Resource::query()->premium()->pluck('name')->all();

    expect($names)->toContain('PaidGuide')->not->toContain('Freebie');
});

test('formatted file size handles bytes', function () {
    $resource = Resource::factory()->create(['file_size_bytes' => 950]);

    expect($resource->formattedFileSize())->toBe('950 B');
});

test('formatted file size scales to KB', function () {
    $resource = Resource::factory()->create(['file_size_bytes' => 2_048]);

    expect($resource->formattedFileSize())->toBe('2.0 KB');
});

test('formatted file size scales to MB', function () {
    $resource = Resource::factory()->create(['file_size_bytes' => 4_500_000]);

    expect($resource->formattedFileSize())->toBe('4.3 MB');
});

test('formatted file size returns null when unknown', function () {
    $resource = Resource::factory()->create(['file_size_bytes' => null]);

    expect($resource->formattedFileSize())->toBeNull();
});
