# Tasks: Filesystem Isolation + Logging Context

**Change ID**: `filesystem-isolation`
**Execution order**: TDD — tests first, then features.

---

## Prerequisites (before T1)

### P1: Install pestphp/pest-browser
- **Command**: `composer require pestphp/pest-browser --dev`
- **What**: Enables browser testing with Playwright
- **Also**: `npx playwright install` to install browser binaries

### P2: Create PostgreSQL test database
- **Command**: `CREATE DATABASE "spatie-laravel-multitenancy-testing";`
- **What**: The test DB must exist before running tests
- **Document**: In `.env.testing` comments and tasks.md

### P3: Update phpunit.xml
- **File**: `phpunit.xml`
- **What**: Change `DB_CONNECTION` from `sqlite` to `pgsql`, `DB_DATABASE` from `:memory:` to `spatie-laravel-multitenancy-testing`
- **Lines**: +4 (modify existing)

### P4: Create .env.testing
- **File**: `.env.testing`
- **What**: PostgreSQL connection config for test environment
- **Lines**: ~12

---

## Task 1: Tests Foundation (PostgreSQL + Feature + Browser)

**Goal**: Establish comprehensive test coverage for existing code before adding features. All tests run against PostgreSQL.

**Estimated lines**: ~320

### Subtasks

#### T1.1: Create TenantFactory
- **File**: `database/factories/TenantFactory.php`
- **What**: Factory that creates Tenant model WITHOUT triggering the `creating` callback (which needs PostgreSQL provisioning). Uses `createQuietly()` to suppress all model events.
- **States**: `forDatabase(string $dbName)` — override database field
- **Lines**: ~30
- **Skill**: pest-testing — factory is not a test, but follows Laravel conventions

#### T1.2: TenantTest (Feature)
- **File**: `tests/Feature/Tenant/TenantTest.php`
- **Tests**:
  - `test_factory_creates_valid_tenant` — factory produces model with name, domain, database (uses `assertDatabaseHas`)
  - `test_tenants_table_guard_throws_when_table_missing` — secondary connection without tenants table triggers RuntimeException
  - `test_tenant_has_required_fillable` — name, domain, database are fillable
- **Lines**: ~45
- **Skill**: pest-testing — `test()` syntax, `assertSuccessful()`, `assertDatabaseHas` (feature test)

#### T1.3: TenantControllerTest (Feature)
- **File**: `tests/Feature/Tenant/TenantControllerTest.php`
- **Setup**: Create Landlord user via `Landlord::factory()->createQuietly()`, `actingAs()`
- **Tests**:
  - `test_index_returns_ok` — GET /admin/tenants returns 200
  - `test_create_returns_ok` — GET /admin/tenants/create returns 200
  - `test_store_creates_tenant` — POST /admin/tenants with valid data, `assertDatabaseHas`
  - `test_show_returns_ok` — GET /admin/tenants/{tenant} returns 200
  - `test_edit_returns_ok` — GET /admin/tenants/{tenant}/edit returns 200
  - `test_update_modifies_tenant` — PUT /admin/tenants/{tenant}, `assertDatabaseHas`
  - `test_destroy_deletes_tenant` — DELETE /admin/tenants/{tenant}, `assertDatabaseMissing`
  - `test_unauthenticated_redirected` — GET /admin/tenants without login, `assertRedirect`
  - `test_non_admin_forbidden` — GET /admin/tenants as regular User, `assertForbidden()`
- **Lines**: ~80
- **Skill**: pest-testing — `test()` syntax, `assertSuccessful()`, `assertForbidden()`, `assertDatabaseHas`/`Missing`
- **Note**: Store test uses `Tenant::withoutEvents()` to bypass provisioning (no real DB creation in tests)

#### T1.4: MultitenancyConfigTest (Feature)
- **File**: `tests/Feature/Tenant/MultitenancyConfigTest.php`
- **Tests**:
  - `test_multitenancy_config_loads` — config key exists and is array
  - `test_tenant_finder_is_domain` — DomainTenantFinder::class
  - `test_switch_tenant_tasks_registered` — array contains PrefixCacheTask, SwitchTenantDatabaseTask
  - `test_tenant_model_is_correct` — Tenant::class
  - `test_landlord_connection_is_landlord` — config value
  - `test_tenant_connection_is_tenant` — config value
- **Lines**: ~35
- **Skill**: pest-testing — `expect()` assertions, `toBe()`, `toContain()`

#### T1.5: TenantCrudBrowserTest (Browser)
- **File**: `tests/Browser/Tenant/TenantCrudBrowserTest.php`
- **Prerequisite**: `pestphp/pest-browser` installed (P1)
- **Tests**:
  - `test_tenant_list_page_loads` — admin sees tenant list with names
  - `test_tenant_creation_flow` — fill form, submit, see new tenant in list
  - `test_tenant_creation_validation_errors` — empty submit shows errors
  - `test_tenant_detail_page` — admin views tenant details
  - `test_tenant_edit_flow` — edit name, submit, see updated in list
  - `test_tenant_delete_flow` — delete tenant, name disappears from list
  - `test_unauthenticated_access_redirects` — guest redirected to login
- **Lines**: ~130
- **Skill**: browser-testing — no HTTP calls, `actingAs()` for auth, factories BEFORE `browse()`, `data-testid` selectors, `->waitFor()` not `sleep()`, meaningful assertions (not just `assertPathIs`)
- **Skill**: pest-testing — `it()` syntax, `assertNoJavaScriptErrors()`

---

## Task 2: Logging Context (SwitchTenantLoggingTask)

**Goal**: Inject tenant ID into logger context on tenant switch.

**Estimated lines**: ~60

### Subtasks

#### T2.1: Create SwitchTenantLoggingTask
- **File**: `app/Multitenancy/Tasks/SwitchTenantLoggingTask.php`
- **What**: Implements `SwitchTenantTask`. `makeCurrent()` calls `Log::shareContext(['tenant_id' => $tenant->getKey()])`. `forgetCurrent()` calls `Log::shareContext([])`.
- **Lines**: ~25

#### T2.2: Register in config
- **File**: `config/multitenancy.php`
- **What**: Add `SwitchTenantLoggingTask::class` to `switch_tenant_tasks` array
- **Lines**: +2

#### T2.3: SwitchTenantLoggingTaskTest (Feature)
- **File**: `tests/Feature/Tenant/SwitchTenantLoggingTaskTest.php`
- **Tests**:
  - `test_make_current_sets_tenant_id_in_context` — creates tenant (quietly), calls makeCurrent, checks Log context
  - `test_forget_current_clears_tenant_context` — calls forgetCurrent, checks empty context
- **Lines**: ~30
- **Skill**: pest-testing — `test()` syntax, specific assertions

---

## Task 3: Filesystem Isolation (SwitchFilesystemTask)

**Goal**: Tenant-scoped file storage using Laravel's native `scoped` disk driver with dynamic prefix. Integrates with MediaLibrary via `media-library.disk_name` config.

**Estimated lines**: ~120

### Subtasks

#### T3.1: Install league/flysystem-path-prefixing
- **Command**: `composer require league/flysystem-path-prefixing`
- **What**: Required for Laravel's `scoped` disk driver. It's a transitive dep of Laravel but must be explicitly installed.
- **Lines**: +1 (composer.json change)

#### T3.2: Add tenant disk to filesystems config
- **File**: `config/filesystems.php`
- **What**: Add `'tenant'` disk with `driver => 'scoped'`, `disk => 'local'`, `prefix => 'tenant'`
- **Lines**: +5

#### T3.3: Create SwitchFilesystemTask
- **File**: `app/Multitenancy/Tasks/SwitchFilesystemTask.php`
- **What**: Implements `SwitchTenantTask`. Follows community pattern from GitHub Discussion #480. Saves original prefix and MediaLibrary disk on construction. `makeCurrent()` sets `config('filesystems.disks.tenant.prefix')` to `'tenant_{id}'` and `config('media-library.disk_name')` to `'tenant'`. `forgetCurrent()` restores originals. Calls `app()->forgetInstance('filesystem')` on both.
- **Lines**: ~35

#### T3.4: Register in config
- **File**: `config/multitenancy.php`
- **What**: Add `SwitchFilesystemTask::class` to `switch_tenant_tasks` array (before logging task)
- **Lines**: +2

#### T3.5: SwitchFilesystemTaskTest (Feature)
- **File**: `tests/Feature/Tenant/SwitchFilesystemTaskTest.php`
- **Tests**:
  - `test_make_current_sets_tenant_prefix` — creates tenant (ID 7), calls makeCurrent, asserts `config('filesystems.disks.tenant.prefix')` equals `'tenant_7'`
  - `test_forget_current_restores_original_prefix` — calls forgetCurrent, asserts prefix restored to `'tenant'`
  - `test_tenant_prefixes_are_different_per_tenant` — tenants 1 and 2 get different prefixes
  - `test_make_current_sets_media_library_disk` — asserts `config('media-library.disk_name')` equals `'tenant'`
  - `test_forget_current_restores_media_library_disk` — asserts original value restored
- **Lines**: ~55
- **Skill**: pest-testing — `test()` syntax, config assertions

---

## Execution Summary

| Task | Description | Lines | Dependencies |
|------|-------------|-------|--------------|
| Prereqs | Install pest-browser, create test DB, update phpunit.xml, create .env.testing | ~20 | none |
| T1 | Tests foundation (feature + browser) | ~320 | Prereqs |
| T2 | Logging context | ~60 | T1 (tests exist first) |
| T3 | Filesystem isolation | ~120 | T1, T2 |
| **Total** | | **~520** | |

## PR Strategy

- **2 chained PRs** (total ~520 lines exceeds 400-line budget)
- **PR1**: Prereqs + T1 + T2 — ~400 lines (within budget)
- **PR2**: T3 — ~120 lines (well within budget)
- **Commit order**: P1 → P2 → P3 → P4 → T1.1 → T1.2 → T1.3 → T1.4 → T1.5 → T2.1 → T2.2 → T2.3 → T3.1 → T3.2 → T3.3 → T3.4
