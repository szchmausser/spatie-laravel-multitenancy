<?php

use App\Models\Tenant;
use App\Multitenancy\Tasks\SwitchFilesystemTask;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

test('make current sets tenant prefix', function () {
    $tenant = Tenant::factory()->createQuietly(['id' => 7]);
    $task = new SwitchFilesystemTask;

    $task->makeCurrent($tenant);

    expect(config('filesystems.disks.tenant.prefix'))->toBe('tenant_7');
});

test('forget current restores original prefix', function () {
    Config::set('filesystems.disks.tenant.prefix', 'tenant');
    $tenant = Tenant::factory()->createQuietly(['id' => 7]);
    $task = new SwitchFilesystemTask;

    $task->makeCurrent($tenant);
    $task->forgetCurrent();

    expect(config('filesystems.disks.tenant.prefix'))->toBe('tenant');
});

test('tenant prefixes are different per tenant', function () {
    $tenant1 = Tenant::factory()->createQuietly(['id' => 1]);
    $tenant2 = Tenant::factory()->createQuietly(['id' => 2]);
    $task = new SwitchFilesystemTask;

    $task->makeCurrent($tenant1);
    $prefix1 = config('filesystems.disks.tenant.prefix');

    $task->makeCurrent($tenant2);
    $prefix2 = config('filesystems.disks.tenant.prefix');

    expect($prefix1)->toBe('tenant_1');
    expect($prefix2)->toBe('tenant_2');
    expect($prefix1)->not->toBe($prefix2);
});

test('make current sets media library disk', function () {
    Config::set('media-library.disk_name', 'public');
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchFilesystemTask;

    $task->makeCurrent($tenant);

    expect(config('media-library.disk_name'))->toBe('tenant');
});

test('forget current restores media library disk', function () {
    Config::set('media-library.disk_name', 'public');
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchFilesystemTask;

    $task->makeCurrent($tenant);
    $task->forgetCurrent();

    expect(config('media-library.disk_name'))->toBe('public');
});

test('filesystem manager cache flushed on make current', function () {
    Config::set('filesystems.disks.tenant.prefix', 'tenant');
    $tenant = Tenant::factory()->createQuietly();

    // Prime the storage cache by resolving the disk once through the facade
    $pathBefore = Storage::disk('tenant')->path('test.txt');
    expect($pathBefore)->toContain('tenant'.DIRECTORY_SEPARATOR);

    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);

    // After makeCurrent, the disk should resolve with the new prefix
    $pathAfter = Storage::disk('tenant')->path('test.txt');
    expect($pathAfter)->toContain("tenant_{$tenant->getKey()}".DIRECTORY_SEPARATOR);
    expect($pathAfter)->not->toBe($pathBefore);
});

test('filesystem manager cache flushed on forget current', function () {
    Config::set('filesystems.disks.tenant.prefix', 'tenant');
    $tenant = Tenant::factory()->createQuietly();

    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);

    // Resolve with tenant prefix active
    $pathDuring = Storage::disk('tenant')->path('test.txt');
    expect($pathDuring)->toContain("tenant_{$tenant->getKey()}".DIRECTORY_SEPARATOR);

    $task->forgetCurrent();

    // After forgetCurrent, the disk should resolve with the original prefix
    $pathAfter = Storage::disk('tenant')->path('test.txt');
    expect($pathAfter)->toContain('tenant'.DIRECTORY_SEPARATOR);
    expect($pathAfter)->not->toBe($pathDuring);
});

test('task implements switch tenant task interface', function () {
    expect(new SwitchFilesystemTask)->toBeInstanceOf(SwitchTenantTask::class);
});

test('switch tenant tasks config includes filesystem task', function () {
    $tasks = config('multitenancy.switch_tenant_tasks');

    expect($tasks)->toContain(SwitchFilesystemTask::class);
});

test('tenant disk uses scoped driver', function () {
    $disk = config('filesystems.disks.tenant');

    expect($disk['driver'])->toBe('scoped');
    expect($disk['disk'])->toBe('public');
    expect($disk['prefix'])->toBe('tenant');
});
