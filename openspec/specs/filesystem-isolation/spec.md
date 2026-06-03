# Spec: SwitchFilesystemTask (Tenant-Scoped Filesystem + MediaLibrary)

**Change ID**: `filesystem-isolation`
**Delta**: `specs/filesystem-isolation.md`

## Purpose

Isolate file storage per tenant via a `tenant` filesystem disk built on Laravel's native `scoped` driver (which wraps the `local` disk with a dynamic path prefix) and a `SwitchFilesystemTask` that updates the prefix and `media-library.disk_name` on every tenant switch. Pattern from spatie/laravel-multitenancy GitHub Discussion #480 — a dynamic **prefix** is portable to S3 later without touching the task class, while rewriting `root` would couple the task to the `local` driver. The `scoped` driver requires `league/flysystem-path-prefixing` ^3.0 (a Laravel transitive dep that must be installed explicitly). After every switch the cached `FilesystemManager` MUST be flushed via `app()->forgetInstance('filesystem')` so the new prefix takes effect.

## Requirements

### R1: Tenant disk uses the scoped driver

`config/filesystems.php` MUST register a `tenant` disk with `driver => 'scoped'`, `disk => 'local'`, and a string `prefix` (default `'tenant'`). The driver MUST be `scoped`, not `local` — this is what makes the disk portable to S3 later.

### R2: makeCurrent rewrites the prefix to include the tenant id

`makeCurrent(Tenant $tenant)` MUST set `config('filesystems.disks.tenant.prefix')` to `tenant_{$tenant->getKey()}` (e.g. `tenant_7`). The base `local` disk stays untouched.

### R3: forgetCurrent restores the original prefix

`forgetCurrent()` MUST restore `config('filesystems.disks.tenant.prefix')` to the value captured at task construction (e.g. `'tenant'`).

### R4: FilesystemManager cache is flushed on every switch

Both `makeCurrent()` and `forgetCurrent()` MUST call `app()->forgetInstance('filesystem')` so the next `Storage::disk('tenant')` resolution rebuilds the Flysystem adapter with the current prefix.

### R5: Task implements the SwitchTenantTask contract

The class MUST implement `Spatie\Multitenancy\Tasks\SwitchTenantTask`.

### R6: Task is registered in the multitenancy config

`config('multitenancy.switch_tenant_tasks')` MUST contain `App\Multitenancy\Tasks\SwitchFilesystemTask::class`.

### R7: Per-tenant prefixes are distinct

When tenants with ids `1` and `2` are each made current in turn, the resulting prefixes MUST be `tenant_1` and `tenant_2` — and MUST be different, so two tenants never share a path.

### R8: MediaLibrary points at the tenant disk on switch

`makeCurrent()` MUST set `config('media-library.disk_name')` to `'tenant'`; `forgetCurrent()` MUST restore the original value captured at construction. If `config('media-library.disk_name')` is `null` at construction (MediaLibrary not installed), that part MUST be a safe no-op.

## Scenarios

### Scenario: Tenant disk is registered with the scoped driver

- GIVEN `config/filesystems.php` has been updated
- WHEN the test reads `config('filesystems.disks.tenant')`
- THEN `driver` equals `'scoped'`, `disk` equals `'local'`, and `prefix` is a non-empty string

### Scenario: makeCurrent rewrites the prefix to include the tenant id

- GIVEN a tenant with id `7` (created quietly)
- WHEN `SwitchFilesystemTask::makeCurrent($tenant)` runs
- THEN `config('filesystems.disks.tenant.prefix')` equals `'tenant_7'`
- AND the underlying `local` disk config is unchanged

### Scenario: forgetCurrent restores the original prefix

- GIVEN the previous step ran (prefix is `'tenant_7'`)
- WHEN `SwitchFilesystemTask::forgetCurrent()` runs
- THEN `config('filesystems.disks.tenant.prefix')` equals the original value (e.g. `'tenant'`)

### Scenario: FilesystemManager cache is flushed on makeCurrent

- GIVEN `Storage::disk('tenant')` was resolved once (manager cached the instance)
- AND a tenant with id `9` exists
- WHEN `makeCurrent($tenant)` runs
- THEN `app()->forgetInstance('filesystem')` was invoked
- AND the next `Storage::disk('tenant')->path('foo.txt')` reflects the new prefix (resolves under `tenant_9`)

### Scenario: FilesystemManager cache is flushed on forgetCurrent

- GIVEN a tenant was made current (prefix changed, cache flushed)
- WHEN `forgetCurrent()` runs
- THEN `app()->forgetInstance('filesystem')` was invoked
- AND the next `Storage::disk('tenant')->path('foo.txt')` resolves without any `tenant_{id}` segment

### Scenario: Class implements the SwitchTenantTask interface

- GIVEN `App\Multitenancy\Tasks\SwitchFilesystemTask` is loaded
- WHEN the test reflects on its interfaces
- THEN `Spatie\Multitenancy\Tasks\SwitchTenantTask` is in the implemented interface list

### Scenario: Config array includes the filesystem task

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.switch_tenant_tasks')`
- THEN the array contains `App\Multitenancy\Tasks\SwitchFilesystemTask::class`
- AND the original spatie tasks remain present

### Scenario: Two tenants get distinct prefixes

- GIVEN tenants with ids `1` and `2` exist (created quietly)
- WHEN tenant `1` then tenant `2` is made current
- THEN the prefix is `'tenant_1'` after the first switch
- AND the prefix is `'tenant_2'` after the second switch
- AND the two prefixes are not equal

### Scenario: makeCurrent points MediaLibrary at the tenant disk

- GIVEN `config('media-library.disk_name')` is `'public'` at construction
- AND a tenant with id `5` exists
- WHEN `makeCurrent($tenant)` runs
- THEN `config('media-library.disk_name')` equals `'tenant'`

### Scenario: forgetCurrent restores the original MediaLibrary disk

- GIVEN the previous step ran (MediaLibrary disk is `'tenant'`)
- WHEN `forgetCurrent()` runs
- THEN `config('media-library.disk_name')` equals the original value (e.g. `'public'`)

### Scenario: league/flysystem-path-prefixing is installed

- GIVEN `composer require league/flysystem-path-prefixing` has been run
- WHEN the framework boots and `Storage::disk('tenant')` is resolved
- THEN the disk resolves without error
- AND no `DriverNotSupportedException` is thrown
