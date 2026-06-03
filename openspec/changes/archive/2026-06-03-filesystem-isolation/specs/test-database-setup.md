# Spec: PostgreSQL Test Database Setup

**Change ID**: `filesystem-isolation`
**Delta**: `specs/test-database-setup.md`

## Purpose

Establish a PostgreSQL-backed test environment (matching the production engine) and a factory that can persist tenant rows in tests without triggering the real PostgreSQL provisioning pipeline (`CREATE DATABASE` + migrations) that the `Tenant::creating` lifecycle callback runs. This unblocks the test suites for the Tenant model, controller, and multitenancy config that currently have zero coverage.

## Requirements

### R1: phpunit.xml points tests at PostgreSQL

The test runner MUST use the `pgsql` connection and the dedicated `spatie-laravel-multitenancy-testing` database so SQL semantics match production. SQLite MUST NOT be used.

### R2: .env.testing carries the PostgreSQL connection settings

The repository MUST contain a `.env.testing` file with `DB_CONNECTION=pgsql`, `DB_DATABASE=spatie-laravel-multitenancy-testing`, plus host/port/username/password entries that resolve to the test database server.

### R3: Test database creation is documented

Both `.env.testing` (as a comment) and `tasks.md` MUST document the one-time `CREATE DATABASE "spatie-laravel-multitenancy-testing";` SQL a developer must run before the first test execution.

### R4: RefreshDatabase uses truncation

`RefreshDatabase` MUST truncate tables between tests and MUST NOT drop or recreate the test database, so the test database persists across runs and no elevated `CREATE DATABASE` permission is required at test time.

### R5: TenantFactory uses createQuietly()

`database/factories/TenantFactory.php` MUST produce a valid Tenant row via `createQuietly()` (Laravel factory method that suppresses all model events) so the `creating` callback never runs in tests. The factory MUST NEVER use `withoutEvents()` on the model or `unguarded()` to bypass provisioning — `createQuietly()` is the canonical Laravel pattern.

### R6: TenantFactory supports database state override

The factory MUST accept attribute overrides (e.g. `Tenant::factory()->createQuietly(['database' => 'custom_db'])`) so individual tests can pin the `database` field to a deterministic value.

## Scenarios

### Scenario: phpunit.xml declares PostgreSQL as the test driver

- GIVEN the repository's `phpunit.xml`
- WHEN the test runner boots
- THEN the `DB_CONNECTION` env is `pgsql`
- AND the `DB_DATABASE` env is `spatie-laravel-multitenancy-testing`
- AND no `DB_CONNECTION=sqlite` entry remains

### Scenario: .env.testing is present and complete

- GIVEN `.env.testing` exists in the repository root
- WHEN Laravel reads it during a test run
- THEN `DB_CONNECTION=pgsql`
- AND `DB_DATABASE=spatie-laravel-multitenancy-testing`
- AND `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` are all defined

### Scenario: Developer finds the CREATE DATABASE instruction

- GIVEN a developer reading `.env.testing` for the first time
- WHEN they scan the file for setup instructions
- THEN they find the literal command `CREATE DATABASE "spatie-laravel-multitenancy-testing";`
- AND the same command is also present in `tasks.md`

### Scenario: RefreshDatabase preserves the test database across runs

- GIVEN the test suite has completed a full run
- WHEN a second test run starts
- THEN the `spatie-laravel-multitenancy-testing` database still exists on the PostgreSQL server
- AND only table data was truncated — no `DROP DATABASE` was issued

### Scenario: TenantFactory suppresses the creating callback

- GIVEN a test invokes `Tenant::factory()->createQuietly()`
- WHEN the factory call resolves
- THEN a Tenant row is persisted to the `tenants` table
- AND the `creating` callback did NOT execute (no `CREATE DATABASE` issued, no migrations ran)
- AND the resulting model has `name`, `domain`, and `database` populated

### Scenario: Factory state override pins the database field

- GIVEN a test calls `Tenant::factory()->createQuietly(['database' => 'custom_db'])`
- WHEN the factory resolves
- THEN the persisted row has `database = 'custom_db'`
- AND no provisioning side effects occurred
