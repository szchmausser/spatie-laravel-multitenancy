# Spec: Tenant Model + Factory Tests

**Change ID**: `filesystem-isolation`
**Delta**: `specs/tenant-model-tests.md`

## Purpose

Cover the `App\Models\Tenant` lifecycle and mass-assignment surface with feature tests so the `creating` callback (which performs real PostgreSQL `CREATE DATABASE` + migrations) is verified to be suppressible, the `assertTenantsTableExists()` guard fails early with an actionable message, and the factory always produces a usable, fillable Tenant model. These tests are the precondition for exercising the controller and browser suites without touching the production tenant database.

## Requirements

### R1: TenantFactory suppresses provisioning on create

`Tenant::factory()->createQuietly()` MUST persist a valid Tenant row without firing the `creating` callback (no `CREATE DATABASE`, no migrations, no connection reconfiguration). The factory MUST NOT use `withoutEvents()` on the model or `unguarded()` as a bypass mechanism — `createQuietly()` is the canonical Laravel pattern.

### R2: TenantFactory accepts attribute overrides

The factory MUST honor per-call attribute overrides (e.g. `createQuietly(['database' => 'custom_db'])`) so tests can pin the `database` field without mutating the factory definition.

### R3: Tenant model exposes required fillable fields

`name`, `domain`, and `database` MUST be in `$fillable` so `Tenant::create([...])` persists all three columns.

### R4: assertTenantsTableExists() guard throws when the table is missing

When the `landlord` connection has no `tenants` table, `Tenant::creating` MUST abort with a `RuntimeException` whose message includes the literal `php artisan migrate` instruction. The guard MUST run BEFORE any irreversible provisioning step (DB creation, migrations).

## Scenarios

### Scenario: Factory creates a fully populated tenant row

- GIVEN the PostgreSQL test database is reachable and the `tenants` migration has run
- WHEN the test calls `Tenant::factory()->createQuietly()`
- THEN a Tenant row exists in the `tenants` table
- AND its `name`, `domain`, and `database` columns are populated with non-empty values
- AND the `creating` callback did NOT execute (verified by absence of any `CREATE DATABASE` SQL against the test server)

### Scenario: Factory state override pins the database field

- GIVEN the test calls `Tenant::factory()->createQuietly(['database' => 'custom_db'])`
- WHEN the factory resolves
- THEN the persisted row has `database = 'custom_db'`
- AND no other column was unexpectedly mutated

### Scenario: Tenant mass-assignment persists all three fields

- GIVEN the test wraps creation in `Tenant::withoutEvents(fn() => Tenant::create([...]))`
- WHEN the closure runs `Tenant::create(['name' => 'X', 'domain' => 'x.test', 'database' => 'x_db'])`
- THEN all three fields are present in the persisted row
- AND no `MassAssignmentException` was raised

### Scenario: Mass-assignment rejects non-fillable fields

- GIVEN the test attempts `Tenant::withoutEvents(fn() => Tenant::create(['name' => 'X', 'is_admin' => true]))`
- WHEN the closure runs
- THEN a `MassAssignmentException` (or silent drop, depending on guarded strategy) prevents `is_admin` from being persisted
- AND only `name` ends up in the row

### Scenario: assertTenantsTableExists throws when the table is missing

- GIVEN the `landlord` connection targets a database that has no `tenants` table
- WHEN a test invokes the creating callback path (via `Tenant::creating(fn() => ...)` reflection or a dedicated helper)
- THEN a `RuntimeException` is thrown
- AND the exception message contains the literal substring `php artisan migrate`
- AND no `CREATE DATABASE` SQL was issued before the throw

### Scenario: assertTenantsTableExists passes silently when the table is present

- GIVEN the `landlord` connection has the `tenants` table (normal test setup)
- WHEN the guard runs
- THEN no exception is thrown
- AND execution continues to the rest of the provisioning pipeline
