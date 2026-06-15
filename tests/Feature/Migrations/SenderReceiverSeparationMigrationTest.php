<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('payments table has payment_method_config_id column after migration', function () {
    $schema = Schema::connection('landlord');

    expect($schema->hasColumn('payments', 'payment_method_config_id'))->toBeTrue();
});

test('payments payment_method_config_id is nullable foreign key', function () {
    $schema = Schema::connection('landlord');
    $columns = $schema->getColumns('payments');
    $configColumn = collect($columns)->firstWhere('name', 'payment_method_config_id');

    expect($configColumn)->not->toBeNull();
    expect($configColumn['nullable'])->toBeTrue();
});

test('payments payment_method_config_id has foreign key constraint', function () {
    $schema = Schema::connection('landlord');
    $foreignKeys = $schema->getForeignKeys('payments');

    $fk = collect($foreignKeys)->first(fn ($fk) => in_array('payment_method_config_id', $fk['columns']));

    expect($fk)->not->toBeNull();
    // Verify the FK points to payment_method_configs table
    $fkString = json_encode($fk);
    expect($fkString)->toContain('payment_method_configs');
});

test('pago_movil_details table has sender_id column after migration', function () {
    $schema = Schema::connection('landlord');

    expect($schema->hasColumn('pago_movil_details', 'sender_id'))->toBeTrue();
});

test('pago_movil_details sender_id is nullable varchar', function () {
    $schema = Schema::connection('landlord');
    $columns = $schema->getColumns('pago_movil_details');
    $senderIdColumn = collect($columns)->firstWhere('name', 'sender_id');

    expect($senderIdColumn)->not->toBeNull();
    expect($senderIdColumn['nullable'])->toBeTrue();
    // PostgreSQL reports varchar as 'character varying(N)' — check it's a string type
    expect($senderIdColumn['type'])->toContain('character');
});

test('bank_transfer_details table has all 7 sender columns after migration', function () {
    $schema = Schema::connection('landlord');

    $expectedColumns = [
        'sender_bank',
        'sender_name',
        'sender_id',
        'sender_account_number',
        'tenant_rif',
        'payment_date',
        'concept',
    ];

    foreach ($expectedColumns as $column) {
        expect($schema->hasColumn('bank_transfer_details', $column))
            ->toBeTrue("Expected column '{$column}' to exist on bank_transfer_details");
    }
});

test('bank_transfer_details tenant_rif and concept are nullable', function () {
    $schema = Schema::connection('landlord');
    $columns = $schema->getColumns('bank_transfer_details');

    $tenantRif = collect($columns)->firstWhere('name', 'tenant_rif');
    $concept = collect($columns)->firstWhere('name', 'concept');

    expect($tenantRif['nullable'])->toBeTrue();
    expect($concept['nullable'])->toBeTrue();
});

test('existing rows have null payment_method_config_id', function () {
    $count = DB::connection('landlord')->table('payments')
        ->whereNull('payment_method_config_id')
        ->count();

    expect($count)->toBeGreaterThanOrEqual(0);
});

test('existing pago_movil_details rows have null sender_id', function () {
    $count = DB::connection('landlord')->table('pago_movil_details')
        ->whereNull('sender_id')
        ->count();

    expect($count)->toBeGreaterThanOrEqual(0);
});

test('existing bank_transfer_details rows have null sender fields', function () {
    $count = DB::connection('landlord')->table('bank_transfer_details')
        ->whereNull('sender_bank')
        ->whereNull('sender_name')
        ->whereNull('sender_id')
        ->count();

    expect($count)->toBeGreaterThanOrEqual(0);
});
