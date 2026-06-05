<?php

use Illuminate\Support\Facades\Schema;

test('subscriptions table exists with correct columns', function () {
    expect(Schema::connection('landlord')->hasTable('subscriptions'))->toBeTrue();

    $columns = Schema::connection('landlord')->getColumns('subscriptions');
    $columnNames = array_column($columns, 'name');

    expect($columnNames)->toContain('id');
    expect($columnNames)->toContain('tenant_id');
    expect($columnNames)->toContain('plan_id');
    expect($columnNames)->toContain('status');
    expect($columnNames)->toContain('trial_ends_at');
    expect($columnNames)->toContain('ends_at');
    expect($columnNames)->toContain('created_at');
    expect($columnNames)->toContain('updated_at');
});

test('subscriptions table has unique constraint on tenant_id', function () {
    $indexes = Schema::connection('landlord')->getIndexes('subscriptions');

    $hasUniqueTenant = false;
    foreach ($indexes as $index) {
        if (in_array('tenant_id', $index['columns']) && $index['unique']) {
            $hasUniqueTenant = true;
            break;
        }
    }

    expect($hasUniqueTenant)->toBeTrue();
});

test('subscriptions table has foreign keys', function () {
    $foreignKeys = Schema::connection('landlord')->getForeignKeys('subscriptions');

    $hasTenantFk = false;
    $hasPlanFk = false;

    foreach ($foreignKeys as $fk) {
        // Check the structure of the foreign key
        if (isset($fk['columns']) && in_array('tenant_id', $fk['columns'])) {
            $hasTenantFk = true;
        }
        if (isset($fk['columns']) && in_array('plan_id', $fk['columns'])) {
            $hasPlanFk = true;
        }
    }

    expect($hasTenantFk)->toBeTrue();
    expect($hasPlanFk)->toBeTrue();
});
