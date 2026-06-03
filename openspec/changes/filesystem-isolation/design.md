# Design: Filesystem Isolation + Logging Context

**Change ID**: `filesystem-isolation`
**Updated**: 2026-06-02

## Architecture

### Current State

```
config/multitenancy.php
  switch_tenant_tasks: [PrefixCacheTask, SwitchTenantDatabaseTask]

config/filesystems.php
  disks: [local, public, s3]

phpunit.xml
  DB_CONNECTION: sqlite
  DB_DATABASE: :memory:

tests/Pest.php
  RefreshDatabase applied to Feature tests
```

### Target State

```
config/multitenancy.php
  switch_tenant_tasks: [PrefixCacheTask, SwitchTenantDatabaseTask, SwitchFilesystemTask, SwitchTenantLoggingTask]

config/filesystems.php
  disks: [local, public, s3, tenant]

phpunit.xml
  DB_CONNECTION: pgsql
  DB_DATABASE: spatie-laravel-multitenancy-testing

.env.testing
  DB_CONNECTION=pgsql
  DB_DATABASE=spatie-laravel-multitenancy-testing

tests/
  Feature/Tenant/
    TenantTest.php
    TenantControllerTest.php
    MultitenancyConfigTest.php
    SwitchTenantLoggingTaskTest.php
    SwitchFilesystemTaskTest.php
  Browser/Tenant/
    TenantCrudBrowserTest.php
```

## Testing Architecture

### PostgreSQL Test Database

All tests run against PostgreSQL (same engine as production) to eliminate SQL semantics drift.

**Configuration chain**:
1. `phpunit.xml` sets `DB_CONNECTION=pgsql` and `DB_DATABASE=spatie-laravel-multitenancy-testing`
2. `.env.testing` provides connection details (host, port, username, password)
3. `config/database.php` already has `pgsql`, `landlord`, and `tenant` connections configured
4. `RefreshDatabase` truncates tables between tests (no DROP/CREATE DATABASE)

**Manual setup** (one-time):
```sql
CREATE DATABASE "spatie-laravel-multitenancy-testing";
```

### Factory Bypass Approach

`Tenant::booted()` registers a `creating` callback that executes real PostgreSQL provisioning:
```php
static::creating(function (Tenant $tenant) {
    $tenant->assertTenantsTableExists();
    $tenant->createDatabase();        // CREATE DATABASE
    $tenant->configureTenantConnection();
    $tenant->runMigrations();         // php artisan migrate
});
```

**Problem**: In tests, we need to insert tenant records without triggering real DB creation.

**Solution**: `TenantFactory` uses `createQuietly()`:
```php
Tenant::factory()->createQuietly();           // suppresses ALL model events
Tenant::factory()->createQuietly(['database' => 'custom_db']);  // with state
```

For tests that need to test the `creating` callback itself (R4 in tenant-model-tests), use `Tenant::withoutEvents()`:
```php
Tenant::withoutEvents(fn() => Tenant::create([...]));
```

**Why not mock?** Mocking Eloquent events is fragile and doesn't test the actual guard logic. `createQuietly()` is the Laravel-native way to suppress events.

### Browser Test Infrastructure

Browser tests use `pestphp/pest-browser` (Playwright-based) for real browser interaction.

**Prerequisites**:
1. `composer require pestphp/pest-browser --dev`
2. `npx playwright install`
3. Server running: `php artisan serve --env=testing`

**Test structure**:
```php
it('shows tenant list', function () {
    // Arrange: factories BEFORE browser
    $admin = Landlord::factory()->createQuietly();
    $tenants = Tenant::factory()->count(3)->createQuietly();

    // Act + Assert: browser interaction
    $this->actingAs($admin)->browse(function (Browser $browser) use ($tenants) {
        $browser->visit(route('landlord.tenants.index'))
            ->assertSee($tenants->first()->name)
            ->assertNoJavaScriptErrors();
    });
});
```

**Skill compliance** (browser-testing):
- No HTTP calls (`Http::fake`, `$this->post`) — all via UI
- Self-sufficient — no order dependency
- `actingAs()` for auth preconditions
- Factories BEFORE `browse()` block
- No `assertDatabaseHas` in browser tests
- Meaningful assertions (not just `assertPathIs`)
- `data-testid` selectors preferred
- `->waitFor()` not `sleep()`

## Task Implementations

### 1. SwitchFilesystemTask

Follows the community-validated pattern from spatie/laravel-multitenancy GitHub Discussion #480. Uses Laravel's native `scoped` driver with dynamic `prefix` instead of a `local` driver with dynamic `root`.

```php
// app/Multitenancy/Tasks/SwitchFilesystemTask.php
class SwitchFilesystemTask implements SwitchTenantTask
{
    protected ?string $originalPrefix;
    protected ?string $originalMediaLibraryDisk;

    public function __construct()
    {
        $this->originalPrefix ??= config('filesystems.disks.tenant.prefix');
        $this->originalMediaLibraryDisk ??= config('media-library.disk_name');
    }

    public function makeCurrent(Tenant $tenant): void
    {
        $prefix = str($this->originalPrefix)->append('_' . $tenant->getKey());
        config()->set('filesystems.disks.tenant.prefix', (string) $prefix);
        config()->set('media-library.disk_name', 'tenant');
        app()->forgetInstance('filesystem');
    }

    public function forgetCurrent(): void
    {
        config()->set('filesystems.disks.tenant.prefix', $this->originalPrefix);
        config()->set('media-library.disk_name', $this->originalMediaLibraryDisk);
        app()->forgetInstance('filesystem');
    }
}
```

**Key decisions**:
- Uses Laravel's `scoped` driver — wraps the `local` disk with a path prefix (`tenant_{id}`)
- The base disk (`local`) stays untouched — only the prefix changes
- Prefix becomes `tenant_1`, `tenant_2`, etc. — cleaner than manipulating `root`
- `app()->forgetInstance('filesystem')` is CRITICAL — without it, FilesystemManager caches disk instances and config changes don't take effect
- MediaLibrary integration: `media-library.disk_name` is set to `'tenant'` on makeCurrent, reverted on forgetCurrent
- Does NOT change `filesystems.default` — only `Storage::disk('tenant')` and MediaLibrary are tenant-aware. Landlord code stays on `local`
- Portable: if the project later moves to S3, only the base disk config changes (`'disk' => 's3'`) — the prefix logic stays identical
- Requires `league/flysystem-path-prefixing` ^3.0 (transitive dep of Laravel, but must be explicitly installed)

### 2. SwitchTenantLoggingTask

```php
// app/Multitenancy/Tasks/SwitchTenantLoggingTask.php
class SwitchTenantLoggingTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        Log::shareContext(['tenant_id' => $tenant->getKey()]);
    }

    public function forgetCurrent(): void
    {
        Log::shareContext([]);
    }
}
```

**Key decisions**:
- Uses `Log::shareContext()` (Laravel 11+) — adds context to all subsequent log entries
- Empty array on forget clears the context
- Minimal, no config needed

### 3. Filesystem Config

```php
// config/filesystems.php — add to 'disks' array
'tenant' => [
    'driver' => 'scoped',
    'disk' => 'local',  // base disk — could be 's3' in production
    'prefix' => 'tenant',  // placeholder, overridden at runtime by SwitchFilesystemTask
],
```

**Key decisions**:
- Uses Laravel's native `scoped` driver (requires `league/flysystem-path-prefixing` ^3.0)
- Default prefix is `'tenant'` — safe fallback if no tenant is active
- The task overrides this at runtime: prefix becomes `tenant_1`, `tenant_2`, etc.
- Base disk is `local` — changing to `s3` in the future only requires editing this one value
- No symlink needed — `storage:link` only applies to `public` disk
- MediaLibrary integration via `config('media-library.disk_name')` — set to `'tenant'` when a tenant is current

### 4. MediaLibrary Integration

```php
// In SwitchFilesystemTask::makeCurrent()
config()->set('media-library.disk_name', 'tenant');

// In SwitchFilesystemTask::forgetCurrent()
config()->set('media-library.disk_name', $this->originalMediaLibraryDisk);
```

**Key decisions**:
- MediaLibrary uses the `tenant` disk (scoped version of `local`) when a tenant is active
- When `forgetCurrent()` runs, MediaLibrary reverts to its original disk (`public` or whatever was configured)
- Requires `config('media-library.disk_name')` to be readable — the task stores the original on construction
- If MediaLibrary is not installed, `config('media-library.disk_name')` returns `null` and the task is a no-op for that part
- Model traits (`InteractsWithMedia`) are NOT added in this change — deferred to per-model decisions

## File Inventory

| File | Action | Lines |
|------|--------|-------|
| `phpunit.xml` | edit | +4 |
| `.env.testing` | create | ~12 |
| `database/factories/TenantFactory.php` | create | ~30 |
| `tests/Feature/Tenant/TenantTest.php` | create | ~45 |
| `tests/Feature/Tenant/TenantControllerTest.php` | create | ~80 |
| `tests/Feature/Tenant/MultitenancyConfigTest.php` | create | ~35 |
| `tests/Browser/Tenant/TenantCrudBrowserTest.php` | create | ~130 |
| `app/Multitenancy/Tasks/SwitchFilesystemTask.php` | create | ~35 |
| `app/Multitenancy/Tasks/SwitchTenantLoggingTask.php` | create | ~25 |
| `tests/Feature/Tenant/SwitchFilesystemTaskTest.php` | create | ~45 |
| `tests/Feature/Tenant/SwitchTenantLoggingTaskTest.php` | create | ~30 |
| `config/filesystems.php` | edit | +7 |
| `config/multitenancy.php` | edit | +4 |
| **Total** | | **~482** |

## Dependencies

- `spatie/laravel-multitenancy` ^4.1 (already installed)
- `league/flysystem-path-prefixing` ^3.0 — NEW, required for `scoped` disk driver (transitive dep of Laravel, must be explicitly installed)
- `pestphp/pest-browser` — NEW, required for browser tests
- `spatie/laravel-medialibrary` — optional, config-ready but not installed (deferred to T3). Task integrates with `media-library.disk_name` config if the package is present.
- PostgreSQL with `CREATE DATABASE` permission for test DB setup
