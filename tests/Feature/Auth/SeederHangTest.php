<?php

use App\Models\Tenant;
use Database\Seeders\TenantPermissionsSeeder;
use Database\Seeders\TenantUsersSeeder;
use Illuminate\Database\QueryException;

test('TenantPermissionsSeeder run fails on non-existent database', function () {
    Tenant::factory()->createQuietly(['database' => 'non_existent_tenant_db_12345']);

    expect(fn () => (new TenantPermissionsSeeder)->run())
        ->toThrow(QueryException::class);
});

test('TenantUsersSeeder run fails on non-existent database', function () {
    Tenant::factory()->createQuietly(['database' => 'non_existent_tenant_db_12345']);

    expect(fn () => (new TenantUsersSeeder)->run())
        ->toThrow(QueryException::class);
});
