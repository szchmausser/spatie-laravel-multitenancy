# Proposal: Filesystem Isolation + Logging Context

**Change ID**: `filesystem-isolation`
**Status**: proposed
**Created**: 2026-06-02
**Updated**: 2026-06-02

## Intent

Make file storage tenant-aware by adding a scoped filesystem disk and a `SwitchFilesystemTask` that isolates per-tenant file storage. Additionally, inject tenant ID into the logger context for observability. Finally, add comprehensive tests for the existing Tenant model, controller, and multitenancy config — which currently have zero test coverage.

## Motivation

- **Filesystem isolation**: Tenants will upload files (avatars, documents). Without isolation, files collide across tenants. The analysis (`analisis-pendientes-fase-temprana.md`) flagged this as a pre-production need.
- **Logging context**: When debugging production issues, filtering logs by tenant ID is critical. Currently no tenant context is injected into logs.
- **Test coverage**: Tenant provisioning, controller CRUD, and multitenancy config have zero tests. This is a risk before adding more features.

## Testing Infrastructure (NEW — v2)

### PostgreSQL for Tests

All tests run against PostgreSQL (same engine as production) to eliminate SQL semantics drift.

| Property | Value |
|----------|-------|
| Production DB | `spatie-laravel-multitenancy` |
| Test DB | `spatie-laravel-multitenancy-testing` |
| Driver | `pgsql` |
| Config source | `phpunit.xml` + `.env.testing` |

**Manual setup**: The test DB must be created in PostgreSQL before first run:
```sql
CREATE DATABASE "spatie-laravel-multitenancy-testing";
```

**RefreshDatabase**: Uses truncation (not drop/recreate) — faster and avoids permissions issues.

### Factory Bypass

`Tenant::booted()` registers a `creating` callback that executes real PostgreSQL provisioning (CREATE DATABASE, migrations). In tests, we MUST bypass this:

- **`TenantFactory::createQuietly()`** — suppresses all model events
- **`Tenant::withoutEvents(fn() => ...)`** — wraps creation in event-free context
- Raw `DB::table('tenants')->insert()` — bypasses Eloquent entirely

The chosen approach is `createQuietly()` on the factory, consistent with Laravel conventions.

### Skill Compliance

All tests must comply with two skills:
- **`pest-testing`**: `php artisan make:test --pest`, `test()`/`it()` syntax, `assertSuccessful()`, datasets, `php artisan test --compact`
- **`browser-testing`**: No HTTP calls in browser tests, self-sufficient tests, `actingAs()` for preconditions, factories only BEFORE browser interaction, `assertDatabaseHas` FORBIDDEN in browser tests, meaningful assertions, `data-testid` selectors, `->waitFor()` not `sleep()`

### Browser Tests (NEW)

Browser tests for tenant CRUD flow require `pestphp/pest-browser` (Playwright-based). This is a prerequisite install step.

## Scope

### In Scope
1. **Task 1 — Tests**: Tenant model provisioning guard, TenantController CRUD, multitenancy config smoke test, PostgreSQL test DB setup, browser tests for tenant CRUD
2. **Task 2 — Logging context**: `SwitchTenantLoggingTask` that sets `Log::shareContext(['tenant_id' => ...])` on tenant switch
3. **Task 3 — Filesystem isolation**: `tenant` disk in `config/filesystems.php` using `scoped` driver, `SwitchFilesystemTask` implementing `SwitchTenantTask` (community pattern from GitHub #480), MediaLibrary integration via `media-library.disk_name` config

### Out of Scope
- MediaLibrary model traits (user decides per-model later) — `spatie/laravel-medialibrary` not installed, deferred to T3
- Actual file upload UI (future feature)
- Tenant cleanup on delete (filesystem pruning)

## Approach

### Task 1: Tests (foundation-first)
- Update `phpunit.xml` to use pgsql with `spatie-laravel-multitenancy-testing` database
- Create `.env.testing` with PostgreSQL config
- Document manual SQL command to create test DB
- Create `TenantFactory` using `createQuietly()` to bypass provisioning events
- Feature test: `TenantTest` — provisioning guard, factory state, basic CRUD
- Feature test: `TenantControllerTest` — index, create, store, show, edit, update, destroy (Inertia rendering + auth)
- Feature test: `MultitenancyConfigTest` — smoke test that config loads, tasks are registered, tenant finder is set
- Browser test: `TenantCrudBrowserTest` — end-to-end CRUD flow (requires `pestphp/pest-browser`)

**Estimated lines**: ~320 (config: 20, factory: 30, feature tests: 150, browser tests: 120)

### Task 2: Logging context
- Create `App\Multitenancy\Tasks\SwitchTenantLoggingTask` implementing `SwitchTenantTask`
- `makeCurrent()`: sets `Log::shareContext(['tenant_id' => $tenant->getKey()])`
- `forgetCurrent()`: clears tenant context from logger
- Register in `config/multitenancy.php` `switch_tenant_tasks`
- Unit test: `SwitchTenantLoggingTaskTest` — verify context set/cleared

**Estimated lines**: ~60 (task: 30, test: 30)

### Task 3: Filesystem isolation
- Install `league/flysystem-path-prefixing` ^3.0 (required for `scoped` disk driver)
- Add `tenant` disk to `config/filesystems.php`: `driver => 'scoped'`, `disk => 'local'`, `prefix => 'tenant'`
- Create `App\Multitenancy\Tasks\SwitchFilesystemTask` implementing `SwitchTenantTask` (community pattern from GitHub Discussion #480)
  - `makeCurrent()`: sets `config('filesystems.disks.tenant.prefix')` to `'tenant_{id}'` and `config('media-library.disk_name')` to `'tenant'`
  - `forgetCurrent()`: restores original prefix and MediaLibrary disk
  - Calls `app()->forgetInstance('filesystem')` to clear cached disk instances
- Register in `config/multitenancy.php`
- Feature test: `SwitchFilesystemTaskTest` — verify prefix changes, MediaLibrary integration

**Estimated lines**: ~120 (task: 40, config: 5, test: 55, prereq: 1)

## Risks

| Risk | Mitigation |
|------|------------|
| `tenant` disk prefix is config-driven; changing at runtime requires careful cache busting | `app()->forgetInstance('filesystem')` clears cached FilesystemManager — same pattern as PrefixCacheTask |
| MediaLibrary not installed yet — `media-library.disk_name` config may not exist | Task stores original value on construction; `config()` with null is safe no-op. Tests will mock or skip if package absent |
| `league/flysystem-path-prefixing` not installed — `scoped` driver won't work | T3.1 explicitly installs it; dry-run confirmed it installs cleanly (3.31.0) |
| PostgreSQL test DB must be created manually before first test run | Document SQL command in `.env.testing` comments and tasks.md |
| `Tenant::creating` callback tries to CREATE DATABASE — breaks in tests | `createQuietly()` suppresses all model events; factory never triggers callback |
| `pestphp/pest-browser` not installed — browser tests require it | Document `composer require pestphp/pest-browser --dev` as prerequisite in tasks |
| Browser tests need running server — Pest Browser auto-manages Playwright | Document `php artisan serve --env=testing` or Pest Browser's built-in server |
| Total change ~500 lines — exceeds 400-line review budget | Split into 2 chained PRs: PR1 (T1+T2 ~380 lines), PR2 (T3 ~120 lines) |

## Decision Needed

1. **Chained PR strategy**: Total estimated lines (~500) exceeds 400-line budget. Recommended: 2 chained PRs.
   - PR1: T1 (tests foundation) + T2 (logging) — ~380 lines
   - PR2: T3 (filesystem isolation) — ~120 lines
2. **MediaLibrary**: Deferred (not installed). Config is ready but package not installed.

## Review Workload Forecast

- **Total estimated lines**: ~500 (exceeds 400-line budget — chained PRs recommended)
- **Chained PRs recommended**: Yes — 2 PRs
- **Per-task breakdown**:
  - Task 1 (tests): ~320 lines — PR1
  - Task 2 (logging): ~60 lines — PR1 (merge with T1)
  - Task 3 (filesystem): ~120 lines — PR2
- **PR1 recommendation**: T1+T2 with 2 commits, ~380 lines (within budget)
- **PR2 recommendation**: T3 with 1 commit, ~120 lines (well within budget)

## Artifacts

- `openspec/changes/filesystem-isolation/proposal.md` — this file
- `openspec/changes/filesystem-isolation/design.md` — technical design
- `openspec/changes/filesystem-isolation/tasks.md` — implementation tasks
- `openspec/changes/filesystem-isolation/specs/` — delta specs
  - `specs/test-database-setup.md` — PostgreSQL test DB config (NEW)
  - `specs/tenant-browser-tests.md` — Browser tests for tenant CRUD (NEW)
  - `specs/tenant-model-tests.md` — Tenant factory + model tests
  - `specs/tenant-controller-tests.md` — TenantController feature tests
  - `specs/multitenancy-config-tests.md` — Config smoke tests
  - `specs/logging-context.md` — SwitchTenantLoggingTask
  - `specs/filesystem-isolation.md` — SwitchFilesystemTask
