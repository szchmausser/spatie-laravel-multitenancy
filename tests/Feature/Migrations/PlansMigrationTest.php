<?php

use Illuminate\Support\Facades\Schema;

test('plans table exists with correct columns', function () {
    expect(Schema::connection('landlord')->hasTable('plans'))->toBeTrue();

    $columns = Schema::connection('landlord')->getColumns('plans');
    $columnNames = array_column($columns, 'name');

    expect($columnNames)->toContain('id');
    expect($columnNames)->toContain('name');
    expect($columnNames)->toContain('slug');
    expect($columnNames)->toContain('description');
    expect($columnNames)->toContain('features');
    expect($columnNames)->toContain('price_cents');
    expect($columnNames)->toContain('is_active');
    expect($columnNames)->toContain('created_at');
    expect($columnNames)->toContain('updated_at');
});

test('plans table slug column is unique', function () {
    $indexes = Schema::connection('landlord')->getIndexes('plans');
    $indexNames = array_column($indexes, 'name');

    $hasUniqueSlug = false;
    foreach ($indexes as $index) {
        if (in_array('slug', $index['columns']) && $index['unique']) {
            $hasUniqueSlug = true;
            break;
        }
    }

    expect($hasUniqueSlug)->toBeTrue();
});

test('plans table has correct column types', function () {
    $columns = Schema::connection('landlord')->getColumns('plans');
    $columnMap = collect($columns)->keyBy('name');

    // PostgreSQL returns raw database types, not Laravel types
    expect($columnMap['name']['type'])->toContain('character varying');
    expect($columnMap['slug']['type'])->toContain('character varying');
    expect($columnMap['description']['type'])->toContain('text');
    expect($columnMap['features']['type'])->toContain('json');
    expect($columnMap['price_cents']['type'])->toContain('bigint');
    expect($columnMap['is_active']['type'])->toContain('boolean');
});
