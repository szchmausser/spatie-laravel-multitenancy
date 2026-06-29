<?php

use App\Enums\EntitlementGrantVia;
use App\Models\Entitlement;
use App\Models\Resource;
use App\Models\Tenant;

/**
 * Entitlement model tests.
 *
 * Focus on the cast for `granted_via` and the validity window
 * helper. The wiring between entitlement, tenant, and resource is
 * exercised by the controller tests; this file pins the model
 * itself.
 */
test('granted_via is cast to the enum', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $entitlement = Entitlement::factory()->create([
        'granted_via' => 'purchase',
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($entitlement->granted_via)->toBeInstanceOf(EntitlementGrantVia::class)
        ->and($entitlement->granted_via)->toBe(EntitlementGrantVia::Purchase);
});

test('grant_via factories map to the right enum cases', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $plan = Entitlement::factory()->viaPlan()->create([
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);
    // Different resource so the UNIQUE(tenant_id, resource_id)
    // constraint does not collide with the previous insert.
    $otherResource = Resource::factory()->create();
    $admin = Entitlement::factory()->viaAdmin()->create([
        'resource_id' => $otherResource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($plan->granted_via)->toBe(EntitlementGrantVia::Plan);
    expect($admin->granted_via)->toBe(EntitlementGrantVia::Admin);
});

test('isValid returns true when expires_at is null', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $entitlement = Entitlement::factory()->create([
        'expires_at' => null,
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($entitlement->isValid())->toBeTrue();
});

test('isValid returns true when expires_at is in the future', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $entitlement = Entitlement::factory()->create([
        'expires_at' => now()->addDay(),
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($entitlement->isValid())->toBeTrue();
});

test('isValid returns false when expires_at is in the past', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $entitlement = Entitlement::factory()->expired()->create([
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($entitlement->isValid())->toBeFalse();
});

test('isValid accepts an explicit reference moment', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $entitlement = Entitlement::factory()->create([
        'expires_at' => '2026-06-01 00:00:00',
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($entitlement->isValid(new DateTimeImmutable('2026-05-31 23:59:59')))->toBeTrue();
    expect($entitlement->isValid(new DateTimeImmutable('2026-06-01 00:00:01')))->toBeFalse();
});

test('entitlement belongs to a resource and a tenant', function () {
    $resource = Resource::factory()->create();
    $tenant = Tenant::factory()->createQuietly();

    $entitlement = Entitlement::factory()->create([
        'resource_id' => $resource->id,
        'tenant_id' => $tenant->id,
    ]);

    expect($entitlement->resource->is($resource))->toBeTrue();
    expect($entitlement->tenant->is($tenant))->toBeTrue();
});
