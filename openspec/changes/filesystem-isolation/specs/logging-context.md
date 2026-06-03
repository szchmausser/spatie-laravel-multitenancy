# Spec: SwitchTenantLoggingTask (Tenant-Aware Log Context)

**Change ID**: `filesystem-isolation`
**Delta**: `specs/logging-context.md`

## Purpose

Inject the current tenant's id into the Laravel logger context whenever a tenant becomes current, and clear it when the tenant is forgotten. This makes it possible to filter logs by `tenant_id` in production debugging without changing any call site. Implementation uses `Log::shareContext()` (Laravel 11+) so the context is automatically attached to every subsequent log entry on the active logger.

## Requirements

### R1: makeCurrent sets tenant_id in the log context

When `makeCurrent(Tenant $tenant)` runs, the system MUST call `Log::shareContext(['tenant_id' => $tenant->getKey()])` so that every subsequent log entry on the active logger carries the `tenant_id` key.

### R2: forgetCurrent clears the tenant log context

When `forgetCurrent()` runs after a tenant was made current, the system MUST call `Log::shareContext([])` so that the `tenant_id` key no longer leaks into log entries written in landlord context.

### R3: Task implements the SwitchTenantTask contract

The class MUST implement `Spatie\Multitenancy\Tasks\SwitchTenantTask` so Spatie's tenant switch pipeline invokes it.

### R4: Task is registered in the multitenancy config

`config('multitenancy.switch_tenant_tasks')` MUST contain `App\Multitenancy\Tasks\SwitchTenantLoggingTask::class`.

## Scenarios

### Scenario: makeCurrent shares the tenant id in log context

- GIVEN a tenant with id `42` exists (created quietly)
- WHEN `SwitchTenantLoggingTask::makeCurrent($tenant)` runs
- THEN `Log::shareContext(['tenant_id' => 42])` is invoked
- AND any subsequent `Log::info(...)` call emits a record that includes the `tenant_id` key with value `42`

### Scenario: forgetCurrent drops the tenant id from log context

- GIVEN a tenant was made current in the previous step (log context contains `tenant_id`)
- WHEN `SwitchTenantLoggingTask::forgetCurrent()` runs
- THEN `Log::shareContext([])` is invoked
- AND any subsequent `Log::info(...)` call emits a record that does NOT include the `tenant_id` key

### Scenario: Class implements the SwitchTenantTask interface

- GIVEN the `App\Multitenancy\Tasks\SwitchTenantLoggingTask` class is loaded
- WHEN the test reflects on its interfaces
- THEN `Spatie\Multitenancy\Tasks\SwitchTenantTask` is in the implemented interface list

### Scenario: Config array includes the logging task

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.switch_tenant_tasks')`
- THEN the array contains `App\Multitenancy\Tasks\SwitchTenantLoggingTask::class`
- AND the original spatie tasks (`PrefixCacheTask`, `SwitchTenantDatabaseTask`) remain present
