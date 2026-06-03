# Spec: Multitenancy Config Smoke Tests

**Change ID**: `filesystem-isolation`
**Delta**: `specs/multitenancy-config-tests.md`

## Purpose

Verify that the `config/multitenancy.php` file is wired correctly: the tenant finder, switch tasks array, tenant model, and landlord/tenant connection names all point at the right classes/values. These are pure configuration smoke tests — they catch typos and accidental deletions in the config without booting the full multitenancy stack.

## Requirements

### R1: Multitenancy config is readable as an array

`config('multitenancy')` MUST return an array (not `null` or a scalar), proving the config file loaded.

### R2: Tenant finder is DomainTenantFinder

`config('multitenancy.tenant_finder')` MUST equal `Spatie\Multitenancy\TenantFinder\DomainTenantFinder::class`.

### R3: Switch tenant tasks are registered

`config('multitenancy.switch_tenant_tasks')` MUST contain `Spatie\Multitenancy\Tasks\PrefixCacheTask::class` and `Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask::class`. (Additional tasks may be appended by T2 and T3.)

### R4: Tenant model is the project Tenant

`config('multitenancy.tenant_model')` MUST equal `App\Models\Tenant::class`.

### R5: Landlord connection name is `landlord`

`config('multitenancy.landlord_database_connection_name')` MUST equal the string `'landlord'`.

### R6: Tenant connection name is `tenant`

`config('multitenancy.tenant_database_connection_name')` MUST equal the string `'tenant'`.

## Scenarios

### Scenario: Config file is loaded and is an array

- GIVEN a fresh test environment
- WHEN the test reads `config('multitenancy')`
- THEN the value is an array
- AND the array is non-empty

### Scenario: Tenant finder uses domain resolution

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.tenant_finder')`
- THEN the value is `Spatie\Multitenancy\TenantFinder\DomainTenantFinder::class`

### Scenario: Switch tasks array includes the two core spatie tasks

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.switch_tenant_tasks')`
- THEN the array contains `Spatie\Multitenancy\Tasks\PrefixCacheTask::class`
- AND the array contains `Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask::class`

### Scenario: Tenant model points to the project class

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.tenant_model')`
- THEN the value is `App\Models\Tenant::class`

### Scenario: Landlord connection name resolves

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.landlord_database_connection_name')`
- THEN the value is the string `'landlord'`

### Scenario: Tenant connection name resolves

- GIVEN the multitenancy config is loaded
- WHEN the test reads `config('multitenancy.tenant_database_connection_name')`
- THEN the value is the string `'tenant'`
