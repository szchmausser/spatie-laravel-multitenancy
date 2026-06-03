<?php

use App\Models\Tenant;
use Spatie\Multitenancy\Tasks\PrefixCacheTask;
use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;
use Spatie\Multitenancy\TenantFinder\DomainTenantFinder;

test('multitenancy config loads as an array', function () {
    $config = config('multitenancy');

    expect($config)->toBeArray();
    expect($config)->not->toBeEmpty();
});

test('tenant finder uses domain resolution', function () {
    expect(config('multitenancy.tenant_finder'))
        ->toBe(DomainTenantFinder::class);
});

test('switch tenant tasks include core spatie tasks', function () {
    $tasks = config('multitenancy.switch_tenant_tasks');

    expect($tasks)->toContain(PrefixCacheTask::class);
    expect($tasks)->toContain(SwitchTenantDatabaseTask::class);
});

test('tenant model is the project Tenant class', function () {
    expect(config('multitenancy.tenant_model'))
        ->toBe(Tenant::class);
});

test('landlord connection name is landlord', function () {
    expect(config('multitenancy.landlord_database_connection_name'))
        ->toBe('landlord');
});

test('tenant connection name is tenant', function () {
    expect(config('multitenancy.tenant_database_connection_name'))
        ->toBe('tenant');
});
