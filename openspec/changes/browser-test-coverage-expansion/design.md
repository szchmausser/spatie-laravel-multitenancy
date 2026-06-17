# Design: Browser Test Coverage Expansion

## Technical Approach

Extract duplicated tenant connection helpers into a trait, then create 8 new browser test files covering billing, shop, tenant users, and settings domains. Component data-testid fixes are minimal (3 files). All tests follow existing browser-testing principles: no HTTP calls, self-sufficient setup, `actingAs()` for auth, factories before browser starts, UI-only assertions.

## Architecture Decisions

### Decision: Trait vs Base Class for Tenant Helpers

**Choice**: PHP trait `HasTenantConnectionHelpers` at `tests/Browser/Concerns/HasTenantConnectionHelpers.php`

**Alternatives considered**:
- Extending BrowserTestCase (rejected: BrowserTestCase is already the base for all Browser/ tests via Pest.php line 18; adding tenant logic there would force non-tenant tests to inherit tenant setup)
- Standalone helper functions (rejected: duplicated today, no encapsulation of the `beforeEach` drop sequence)

**Rationale**: A trait gives composability — tenant tests `uses()` it, non-tenant tests don't. The trait includes both the 5 helper methods AND the `beforeEach` Spatie table drop sequence via a static `register()` method that test files call in their `beforeEach`.

### Decision: Trait Registration Pattern

**Choice**: Each tenant test file calls `HasTenantConnectionHelpers::register($this)` in its `beforeEach`, which registers a callback that drops Spatie tables and purges the tenant connection.

**Alternatives considered**:
- Auto-registration via Pest `uses()` (rejected: Pest `uses()` applies to the test case class, not individual test closures; the `beforeEach` approach is the existing project pattern)
- Putting drops in `BrowserTestCase::setUp()` (rejected: landlord-only tests don't need tenant DDL cleanup)

**Rationale**: Matches the existing `beforeEach` pattern in `UserMenuBadgeTest` and `ChangePlanFlowTest`. Explicit registration keeps non-tenant test files unaffected.

### Decision: Test File Organization

**Choice**: Mirror existing directory structure. Each domain gets its own test file in the appropriate subdirectory.

**Rationale**: `tests/Browser/Billing/` already exists. Creating `tests/Browser/Shop/`, `tests/Browser/Settings/` follows the same convention.

## Data Flow

```
Test File (e.g., OrdersIndexTest)
  │
  ├─ beforeEach
  │   ├─ HasTenantConnectionHelpers::register($this)  // drops Spatie DDL
  │   └─ Point tenant connection at test DB
  │
  ├─ test body
  │   ├─ Factories (Tenant, User, Plans, Orders) — BEFORE browser starts
  │   ├─ Point tenant connection, set default, try block
  │   │   ├─ Migrate Spatie, run seeder, create user on tenant
  │   │   ├─ $this->actingAs($user)->visit('/path')
  │   │   └─ waitFor/waitForText, assertSee, assertVisible, click
  │   └─ finally: restore default connection, purge tenant
  │
  └─ afterEach (implicit: Spatie drops registered in beforeEach)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `tests/Browser/Concerns/HasTenantConnectionHelpers.php` | Create | Trait with 5 helper methods + `register()` for beforeEach drops |
| `tests/Browser/Billing/OrdersIndexTest.php` | Create | 3 tests: list renders, empty state, view navigates |
| `tests/Browser/Billing/OrdersShowTest.php` | Create | 3 tests: detail shows items, payment method selection, form fields |
| `tests/Browser/Billing/HistoryTest.php` | Create | 3 tests: entries render, empty state, pagination |
| `tests/Browser/Shop/ShopIndexTest.php` | Create | 4 tests: plan cards, empty state, free download, premium buy |
| `tests/Browser/Tenant/TenantUsersBrowserTest.php` | Create | 7 tests: index list, search, create, validation, show, role assign, edit |
| `tests/Browser/Settings/ProfileTest.php` | Create | 2 tests: current values shown, update name |
| `tests/Browser/Settings/SecurityTest.php` | Create | 2 tests: form renders, validation errors |
| `tests/Browser/Settings/AppearanceTest.php` | Create | 2 tests: buttons render, dark mode toggle |
| `tests/Browser/Tenant/UserMenuBadgeTest.php` | Modify | Replace 5 helper functions + beforeEach drops with trait usage |
| `tests/Browser/Billing/ChangePlanFlowTest.php` | Modify | Replace 5 helper functions + beforeEach drops with trait usage |
| `resources/js/pages/settings/profile.tsx` | Modify | `data-test="update-profile-button"` → `data-testid="update-profile-btn"` (line 92) |
| `resources/js/pages/settings/security.tsx` | Modify | `data-test="update-password-button"` → `data-testid="update-password-btn"` (line 111) |
| `resources/js/components/appearance-tabs.tsx` | Modify | Add `data-testid={`appearance-tab-${value}`}` on each button (line 29) |

## Trait Interface

```php
<?php

namespace Tests\Browser\Concerns;

use App\Models\Tenant;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pest\Test;
use Spatie\Permission\PermissionRegistrar;

trait HasTenantConnectionHelpers
{
    /**
     * Register beforeEach callbacks to drop Spatie permission tables
     * on the tenant connection. Call in test file's beforeEach:
     *   HasTenantConnectionHelpers::register($this);
     */
    public static function register(Test $testCase): void
    {
        $testCase->beforeEach(function () {
            $testDatabase = config('database.connections.landlord.database');
            config(['database.connections.tenant.database' => $testDatabase]);
            DB::purge('tenant');

            $tableNames = config('permission.table_names');
            Schema::connection('tenant')->dropIfExists($tableNames['role_has_permissions']);
            Schema::connection('tenant')->dropIfExists($tableNames['model_has_roles']);
            Schema::connection('tenant')->dropIfExists($tableNames['model_has_permissions']);
            Schema::connection('tenant')->dropIfExists($tableNames['roles']);
            Schema::connection('tenant')->dropIfExists($tableNames['permissions']);

            DB::purge('tenant');
        });
    }

    protected function pointTenantConnectionAtTestDatabase(): void { /* ... */ }
    protected function setDefaultConnectionToTenant(): string { /* ... */ }
    protected function restoreDefaultConnection(string $previous): void { /* ... */ }
    protected function runSpatiePermissionMigration(): void { /* ... */ }
    protected function runTenantPermissionsSeeder(): void { /* ... */ }
}
```

**Note**: Since Pest test closures bind to the TestCase, the helpers must be accessible. The trait methods are defined as regular functions on the trait. When a Pest test file `uses(BrowserTestCase::class)` and then calls the helper functions directly (as standalone functions), the existing pattern works because Pest exposes them. The trait will expose the same functions — test files will `use HasTenantConnectionHelpers` and call them the same way.

**Revised approach**: Keep the 5 helpers as standalone functions in the trait file (namespace `Tests\Browser\Concerns`), exported via `use function`. The `register()` static method handles `beforeEach` registration. This matches the existing pattern where `pointTenantConnectionAtTestDatabase()` etc. are called as bare functions.

## Test Data Setup (Per Domain)

### Billing/Orders Index
- Factories: `Tenant`, `User` (on tenant), `Plan`, `Order` (2: one plan, one resource)
- Auth: `actingAs($tenantUser)`
- Visit: `/billing/orders`
- Assertions: `@orders-list` visible, `@order-row-{id}` count, text content

### Billing/Orders Show
- Factories: `Tenant`, `User`, `Plan`, `Order` (pending, $29), `PaymentMethodConfig` (pago_movil + bank_transfer)
- Auth: `actingAs($tenantUser)`
- Visit: `/billing/orders/{id}`
- Assertions: `@payment-section` visible, `@method-pago_movil`/`@method-bank_transfer` visible, form fields visible

### Billing History
- Factories: `Tenant`, `User`, `SubscriptionHistory` (3 entries: created, changed, expired)
- Auth: `actingAs($tenantUser)`
- Visit: `/billing/history`
- Assertions: `@history-list` visible, `@history-entry-{id}` count, `@history-event-type-{id}` text

### Shop Index
- Factories: `Tenant`, `User`, `Plan` (2: basic + premium), `Resource` (free + premium), `Subscription`
- Auth: `actingAs($tenantUser)`
- Visit: `/shop`
- Assertions: `@shop-plans-grid`, `@shop-plan-card-{slug}`, `@shop-resource-buy-btn-{slug}`

### Tenant Users CRUD
- Factories: `Tenant`, `User` (tenant-admin), additional users (3)
- Auth: `actingAs($admin)`
- Visit: `/settings/users`, `/settings/users/create`, `/settings/users/{id}`, `/settings/users/{id}/edit`
- Assertions: `@user-row-{id}`, `@search-input`, `@create-user-btn`, form inputs, `@assign-role-select`

### Settings Profile
- Factories: `User` (landlord or tenant)
- Auth: `actingAs($user)`
- Visit: `/settings/profile`
- Assertions: input values, `@update-profile-btn` click, success flash

### Settings Security
- Factories: `User`
- Auth: `actingAs($user)`
- Visit: `/settings/security`
- Assertions: password inputs visible, `@update-password-btn` visible, validation errors

### Settings Appearance
- Factories: `User`
- Auth: `actingAs($user)`
- Visit: `/settings/appearance`
- Assertions: `@appearance-tab-light`, `@appearance-tab-dark`, `@appearance-tab-system` visible, dark class toggled

## Execution Strategy

1. **Run individual test files** (never full suite from subagent):
   ```
   php artisan test --compact --filter="tests/Browser/Billing/OrdersIndexTest"
   php artisan test --compact --filter="tests/Browser/Settings/ProfileTest"
   ```
2. **Run all Browser tests** (user runs manually):
   ```
   php artisan test --compact --filter=Browser
   ```
3. **Component changes first**: Fix data-testid attributes before writing tests that depend on them.
4. **Trait extraction second**: Refactor existing tests to use the trait before creating new test files.

## Risk Mitigations

| Risk | Mitigation |
|------|-----------|
| Spatie DDL persists across tests | `beforeEach` drops all 5 tables; trait registers this automatically |
| Tenant connection leaks to next test | `finally` block always restores default + purges; trait's `beforeEach` re-purges |
| Browser server can't see uncommitted data | No transactions — cleanup via DELETE in `BrowserTestCase::setUp()` |
| `data-test` vs `data-testid` mismatch | Fix components FIRST, then write tests referencing the correct attribute |
| Slow browser tests | Each file runs independently; use `--filter` for targeted execution |
| Factory order dependencies | Each test creates its own data; no shared state between tests |

## Open Questions

- None — all spec scenarios have concrete data-testid selectors already verified in the codebase.
