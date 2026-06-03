<?php

use App\Models\Tenant;
use App\Multitenancy\Tasks\SwitchTenantLoggingTask;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

test('make current sets tenant id in log context', function () {
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchTenantLoggingTask;

    $task->makeCurrent($tenant);

    expect(Log::sharedContext())->toBe(['tenant_id' => $tenant->getKey()]);
});

test('make current updates context when switching between different tenants', function () {
    $tenant1 = Tenant::factory()->createQuietly();
    $tenant2 = Tenant::factory()->createQuietly();
    $task = new SwitchTenantLoggingTask;

    $task->makeCurrent($tenant1);
    expect(Log::sharedContext())->toBe(['tenant_id' => $tenant1->getKey()]);

    $task->makeCurrent($tenant2);
    expect(Log::sharedContext())->toBe(['tenant_id' => $tenant2->getKey()]);
});

test('forget current clears tenant log context', function () {
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchTenantLoggingTask;

    $task->makeCurrent($tenant);
    $task->forgetCurrent();

    expect(Log::sharedContext())->toBe([]);
});

test('task implements switch tenant task interface', function () {
    expect(new SwitchTenantLoggingTask)
        ->toBeInstanceOf(SwitchTenantTask::class);
});

test('switch tenant tasks config includes the logging task', function () {
    $tasks = config('multitenancy.switch_tenant_tasks');

    expect($tasks)->toContain(SwitchTenantLoggingTask::class);
});
