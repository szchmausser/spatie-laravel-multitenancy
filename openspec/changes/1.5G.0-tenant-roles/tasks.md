# Tasks: 1.5G.0 — Tenant Roles & Permissions

## How to use this file

Each task is a work unit that maps to one TDD cycle. The apply phase follows this checklist per task:

1. **Read** the task — understand the files, the test that leads, and the acceptance criteria
2. **Write the test FIRST** (red) — `php artisan test --compact --filter=<TestName>` must fail
3. **Implement** the minimum code to make it pass (green)
4. **Refactor** if needed
5. **Format** — `vendor/bin/pint --dirty --format agent`
6. **Verify no regressions** — `php artisan test --compact` (full suite green)
7. **Mark done** — [ ] → [x] below
8. **Do NOT commit** — per project rules, the user commits manually

Per `work-unit-commits` skill: each task is a reviewable work unit containing its own tests and docs. The apply phase leaves changes uncommitted; the user can commit each task individually or in batches.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~640 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR (work-unit commits) |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium

**Why**: ~640 lines is under the project's 800-line D2 review budget. Each task is individually under 400 lines. The user has explicitly confirmed no `size:exception` is needed. Work-unit commits within a single PR keep review manageable.

### Dependency Graph

```
Task 1 (install Spatie) ──> Task 2 (seeders) ──> Task 3 (DatabaseSeeder) ──> Task 4 (HasRoles) ──> Task 5 (shared prop) ──> Task 6 (badge)
                                                                                                                              └─> Task 8 (doc)
Task 1 ──> Task 7 (verification test) ─── (can run in parallel with Tasks 2-6)
```

---

## 1. [x] Install Spatie Permission + verify tenant table isolation

**Files** (new / modified):
- `composer.json` (modified) — add `"spatie/laravel-permission"` to `require`
- `composer.lock` (new)
- `config/permission.php` (new, published via `vendor:publish`)
- `database/migrations/*_create_permission_tables.php` (new, ×5, published)

**Test that leads**: `tests/Feature/Auth/TenantPermissionsTest.php` (NEW)
- `test('new tenant has all 5 Spatie authorization tables', ...)` — `Schema::connection('tenant')->hasTable()` for each of 5 tables
- `test('cross-tenant authorization is isolated', ...)` — two tenants, grant role in tenant A, assert tenant B unaffected
- `test('landlord database has no Spatie tables', ...)` — landlord connection has none of the 5 tables

**Acceptance**:
- `composer require spatie/laravel-permission` resolves; `php artisan vendor:publish` publishes 5 migrations + config
- All 3 new tests pass (red → green)
- Full suite stays green after install + publish

**Depends on**: none

**Estimated diff size**: ~250 lines (mostly from published migrations)

**Notes**:
- Publish to root `database/migrations/` — NOT `database/migrations/landlord/`. The `Tenant::creating` callback (tenant.php:247) runs `Artisan::call('migrate', ['--database' => 'tenant'])` on the root path, so new tenants automatically get the 5 Spatie tables.
- `config/permission.php` defaults are OK — no edits needed. `teams` mode stays off (physical DB per tenant provides isolation).
- No production code changes needed past the package install + publish — the existing tenant replication plumbing handles the new tables.

---

## 2. [x] Create idempotent TenantPermissionsSeeder

**Files**:
- `database/seeders/TenantPermissionsSeeder.php` (NEW)

**Test that leads**: `tests/Feature/Auth/TenantPermissionsTest.php` (modified — add 5 methods)
- `test('seeder creates change-plan permission', ...)` — `Permission::where('name', 'change-plan')->exists()`
- `test('seeder creates tenant-admin role', ...)` — `Role::where('name', 'tenant-admin')->exists()`
- `test('tenant-admin role has change-plan permission', ...)` — `$role->hasPermissionTo('change-plan')`
- `test('seeder is idempotent on double run', ...)` — run twice, exactly 1 role + 1 permission
- `test('seeder flushes permission cache', ...)` — verifies `forgetCachedPermissions()` was called

**Acceptance**:
- `TenantPermissionsSeeder::run()` calls `app()[PermissionRegistrar::class]->forgetCachedPermissions()` first
- `Permission::findOrCreate('change-plan', 'web')` and `Role::findOrCreate('tenant-admin', 'web')` work
- `$role->givePermissionTo('change-plan')` creates mapping
- Double-run produces no duplicates

**Depends on**: Task 1

**Estimated diff size**: ~80 lines

**Notes**:
- `forgetCachedPermissions()` must run BEFORE creating permissions (per `laravel-permission-development` skill). Without this, a previous test's cached state can mask a broken seeder.
- Use `findOrCreate` (not `create`) — that's what makes the seeder idempotent.
- Guard name is `web` (Spatie default for this project).

---

## 3. [x] Wire DatabaseSeeder order + assign tenant-admin to first user

**Files**:
- `database/seeders/DatabaseSeeder.php` (modified) — add `TenantPermissionsSeeder::class` BEFORE `TenantUsersSeeder::class`
- `database/seeders/TenantUsersSeeder.php` (modified) — after `updateOrCreate`, if first user for that tenant, call `$user->syncRoles(['tenant-admin'])`

**Test that leads**: `tests/Feature/Auth/TenantPermissionsTest.php` (modified — add 3 methods)
- `test('first user has tenant-admin role', ...)` — first user has exactly 1 role: `tenant-admin`
- `test('re-seeding does not duplicate roles', ...)` — `syncRoles` is idempotent
- `test('other users do not have tenant-admin', ...)` — all users after the first have no roles

**Acceptance**:
- After `DatabaseSeeder::run()`, the first user per tenant has `tenant-admin` via `syncRoles`
- Re-running produces no duplicate role assignments
- Users beyond the first per tenant have no roles assigned

**Depends on**: Task 2

**Estimated diff size**: ~30 lines

**Notes**:
- The "first user" check: count existing users in that tenant's DB before calling `updateOrCreate`. `TenantUsersSeeder` already loops `foreach (Tenant::query()->orderBy('id')->get() as $tenant)` — the first user per tenant is the first in iteration order.
- `syncRoles()` is inherently idempotent (calling it again with the same role = no change).
- `TenantPermissionsSeeder` MUST run first, or `syncRoles(['tenant-admin'])` will fail because the role doesn't exist.

---

## 4. [x] Add HasRoles trait to User model

**Files**:
- `app/Models/User.php` (modified) — add `use Spatie\Permission\Traits\HasRoles;` import and `HasRoles` to trait list

**Test that leads**: `tests/Feature/Auth/TenantPermissionsTest.php` (modified — add 3 methods)
- `test('user with tenant-admin can change plan', ...)` — `$user->can('change-plan')` returns `true`
- `test('user without roles cannot change plan', ...)` — `$user->can('change-plan')` returns `false`
- `test('revoked permission returns false even with role', ...)` — revoke `change-plan` from role, then `$user->can('change-plan')` returns `false`

**Acceptance**:
- `$user->can('change-plan')` works correctly for both authorized and unauthorized users
- All 14+ feature tests in `TenantPermissionsTest` pass (Tasks 1-4 combined)
- Full suite stays green

**Depends on**: Task 3

**Estimated diff size**: ~50 lines (mostly tests; trait is 1 line + 1 import)

**Notes**:
- `UsesTenantConnection` is already present; `HasRoles` is the second trait. Order doesn't matter.
- Authorization API: `$user->can('change-plan')` only — never string-compare role names (per spec).
- The "revoked permission" test is critical: it proves authorization goes through the permission check, not just the role presence.

---

## 5. [x] Expose `auth.user.roles` via Inertia shared props

**Files**:
- `app/Http/Middleware/HandleInertiaRequests.php` (modified) — refactor `auth.user` from `$request->user()` (full model) to an explicit array that includes `roles`
- `resources/js/types/auth.ts` (modified) — add `roles: string[]` to the `User` type

**Test that leads**: `tests/Feature/SharedInertiaTenantPropTest.php` (modified — add 2 methods)
- `test('tenant-admin user sees roles array in shared props', ...)` — `auth.user.roles` contains `['tenant-admin']`
- `test('user without roles sees empty roles array', ...)` — `auth.user.roles` is `[]`
- (TypeScript compile — no PHP test needed; `npx tsc --noEmit` must pass)

**Acceptance**:
- Tenant-admin user: `$page.props.auth.user.roles === ['tenant-admin']`
- Non-admin user: `$page.props.auth.user.roles === []`
- Landlord user: `$page.props.auth.user.roles === []` (or `null` handled gracefully)
- TypeScript compilation passes with `roles: string[]` in `User` type
- All 8 existing tests in `SharedInertiaTenantPropTest.php` stay green (no regression on `tenant` prop)

**Depends on**: Task 4

**Estimated diff size**: ~40 lines

**Notes**:
- The refactored `share()` builds an explicit array: `'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar' => $user->avatar, 'email_verified_at' => $user->email_verified_at, 'roles' => $user?->roles?->pluck('name')->toArray() ?? []]`
- On landlord routes where `$request->user()` is a `Landlord` instance (not a `User`), roles default to `[]`
- Do NOT add `roles` to User model's `$appends` — it would conflict with Spatie's `roles` relationship. Build it explicitly in the middleware instead.
- TypeScript: `auth.ts` needs `roles: string[]` added to `User`; `global.d.ts` needs no change (it already imports `Auth` from `auth.ts`).

---

## 6. [x] Render conditional "Admin" badge in user menu

**Files**:
- `resources/js/components/user-menu-content.tsx` (modified) — add `<span data-testid="user-role-badge">Admin</span>` after `<UserInfo>`, conditionally rendered when `user.roles.includes('tenant-admin')`

**Test that leads**: `tests/Browser/Tenant/UserMenuBadgeTest.php` (NEW — 2 browser tests)
- `test('admin badge is visible for tenant-admin user', ...)` — `data-testid="user-role-badge"` visible, text contains "Admin"
- `test('admin badge is hidden for non-admin user', ...)` — `data-testid="user-role-badge"` NOT visible

**Acceptance**:
- Badge renders after `<UserInfo>` when `user.roles.includes('tenant-admin')` is `true`
- Badge uses `data-testid="user-role-badge"` as the stable selector (browser-testing §3.5 top priority)
- Badge text is "Admin"
- No badge for non-admin users
- Browser tests pass (red → green)
- `actingAs()` used for auth precondition (browser-testing principle 3)
- No HTTP calls in browser tests (principle 1)
- No `assertDatabaseHas` in browser tests (browser-testing §6 prohibition)

**Depends on**: Task 5

**Estimated diff size**: ~80 lines (mostly browser tests; component change is ~5 lines)

**Notes**:
- Browser tests extend `Tests\Browser\BrowserTestCase` (same connection config as feature tests — per browser-testing Part 2.3 multitenancy convention).
- The badge test reads Inertia props only, no POSTs → `withoutMiddleware(ValidateCsrfToken::class)` NOT needed (browser-testing design risk #8 confirmed).
- Selector: `->waitFor('[data-testid="user-role-badge"]')` for visible; `->waitUntilMissing('[data-testid="user-role-badge"]')` or `->assertMissing('[data-testid="user-role-badge"]')` for hidden.
- The `user` prop uses `User` from `@/types` (imported as `type { User } from '@/types'` in `user-menu-content.tsx`). The `roles: string[]` type from Task 5 is already available.

---

## 7. [x] Verify `tenants:artisan migrate` propagates Spatie tables

**Files**: No production code changes — verification test only

**Test that leads**: `tests/Feature/Auth/TenantPermissionsTest.php` (modified — add 2 methods)
- `test('pre-existing tenant gets Spatie tables after tenants:artisan migrate', ...)` — create tenant via `createQuietly()`, drop Spatie tables, run `tenants:artisan migrate`, assert tables restored
- `test('new tenant gets tables via creating callback', ...)` — create tenant normally, assert 5 tables exist without manual intervention

**Acceptance**:
- Pre-existing tenant gets all 5 Spatie tables after `php artisan tenants:artisan migrate`
- New tenant gets all 5 tables automatically via the `Tenant::creating` → `runMigrations()` callback
- Both tests pass with the existing tenant migration plumbing (no new code required)

**Depends on**: Task 1 (can run in parallel with Tasks 2-6)

**Estimated diff size**: ~80 lines (test only)

**Notes**:
- Parallel task: does NOT depend on seeders, HasRoles, or frontend changes. The dependency graph allows Task 7 to execute any time after Task 1.
- Uses `Tenant::factory()->createQuietly()` to avoid triggering the `creating` callback, then manually manipulates the tenant database schema.
- Verifies that `Spatie\Multitenancy\Actions\MigrateTenantAction` (via `SwitchTenantDatabaseTask`) picks up the published Spatie migrations on existing tenants.

---

## 8. [x] Document the authorization model in architecture doc

**Files**:
- `Arquitectura multitenencia aplicada.md` (modified) — add new section (§23 or appropriate)

**Test that leads**: None (documentation)

**Acceptance**:
- New section explains the per-tenant authorization model (Spatie tables replicated to each tenant DB)
- Documents the precondition framing (this slice unblocks `1.5G-buy-plan`)
- Lists seeded values: `change-plan` permission, `tenant-admin` role
- States the auth rule: `$user->can('change-plan')` only — never string-compare role names
- Links to OpenSpec change artifacts
- Includes the rollback plan from the proposal

**Depends on**: Tasks 1-7 (to document what was actually built)

**Estimated diff size**: ~30 lines

**Notes**:
- Final task; do it after all implementation and tests are verified.
- The architecture doc is at project root: `Arquitectura multitenencia aplicada.md`.
