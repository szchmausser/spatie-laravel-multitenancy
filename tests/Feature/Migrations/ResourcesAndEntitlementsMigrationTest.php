<?php

use Illuminate\Support\Facades\Schema;

/**
 * Migrations tests for the landlord resources and entitlements tables.
 *
 * These tests pin the schema in place so a future migration that
 * renames a column or drops a constraint will fail loudly here
 * rather than at runtime in the controller.
 */
test('resources table has the expected columns', function () {
    expect(Schema::connection('landlord')->hasTable('resources'))->toBeTrue();

    $columns = collect(Schema::connection('landlord')->getColumnListing('resources'));

    expect($columns)->toContain('id')
        ->toContain('name')
        ->toContain('slug')
        ->toContain('description')
        ->toContain('file_path')
        ->toContain('file_size_bytes')
        ->toContain('mime_type')
        ->toContain('is_premium')
        ->toContain('price_cents')
        ->toContain('is_active')
        ->toContain('created_at')
        ->toContain('updated_at');
});

test('resources.slug has a unique index', function () {
    $indexes = Schema::connection('landlord')->getIndexes('resources');

    $hasSlugIndex = collect($indexes)->contains(
        fn ($idx) => in_array('slug', $idx['columns'] ?? [], true),
    );

    expect($hasSlugIndex)->toBeTrue();
});

test('entitlements table has the expected columns', function () {
    expect(Schema::connection('landlord')->hasTable('entitlements'))->toBeTrue();

    $columns = collect(Schema::connection('landlord')->getColumnListing('entitlements'));

    expect($columns)->toContain('id')
        ->toContain('tenant_id')
        ->toContain('user_id')
        ->toContain('resource_id')
        ->toContain('granted_via')
        ->toContain('granted_at')
        ->toContain('expires_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

test('entitlements has the dedup unique constraint', function () {
    $indexes = collect(Schema::connection('landlord')->getIndexes('entitlements'));

    $hasUnique = $indexes->contains(function ($idx) {
        $cols = $idx['columns'] ?? [];
        sort($cols);
        $expected = ['resource_id', 'tenant_id', 'user_id'];
        $sorted = $expected;
        sort($sorted);

        return $cols === $sorted && ($idx['unique'] ?? false) === true;
    });

    expect($hasUnique)->toBeTrue();
});

test('entitlements cascades on tenant and resource delete', function () {
    $fks = Schema::connection('landlord')->getForeignKeys('entitlements');

    $tenantFk = collect($fks)->first(
        fn ($fk) => in_array('tenant_id', $fk['columns'] ?? [], true),
    );
    $resourceFk = collect($fks)->first(
        fn ($fk) => in_array('resource_id', $fk['columns'] ?? [], true),
    );

    expect($tenantFk)->not->toBeNull('expected FK on tenant_id');
    expect($resourceFk)->not->toBeNull('expected FK on resource_id');

    // PostgreSQL reports the cascade action in `on_delete`.
    expect(strtolower($tenantFk['on_delete'] ?? ''))->toBe('cascade');
    expect(strtolower($resourceFk['on_delete'] ?? ''))->toBe('cascade');
});
