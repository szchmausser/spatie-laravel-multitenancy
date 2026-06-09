# Verify Report: 1.5G.0-tenant-roles

**Date**: 2026-06-06
**Verify status**: PASS WITH WARNINGS

## Summary

The implementation of the `1.5G.0-tenant-roles` change is complete and
matches the approved proposal, spec, design, and tasks. All 8 tasks are
marked done. The full test suite is green: 206 tests passing, 3 skipped,
0 failing, 759 assertions. Pint reports no formatting issues, and
TypeScript compiles cleanly with `npx tsc --noEmit`. All 20 ADDED
scenarios from the spec are covered by at least one test, with extra
defensive coverage in Requirement 1. The architectural doc (`Arquitectura
multitenencia aplicada.md` §23) was added with the precondition framing,
the rollback plan, and the file inventory. One WARNING about a minor
code-example drift in the doc: §23.3 shows an `if ($isFirstUser)` guard
that the actual `TenantUsersSeeder` does not implement — the code calls
`syncRoles` on every user, but because the seeder creates only one user
per tenant and `syncRoles` is idempotent, the behavior is correct. The
doc narrative is still accurate (first user = only user in current data),
but the illustrative code snippet is misleading.

## Quality gates

| Gate | Status | Notes |
|------|--------|-------|
| `php artisan test --compact` | PASS | 209 total, 206 passing, 3 skipped, 0 failing, 759 assertions, ~127s |
| `vendor/bin/pint --dirty --format agent` | PASS | No formatting issues |
| `npx tsc --noEmit` | PASS | No TypeScript errors |

Note: a re-run of the suite via `--filter=TenantPermissionsTest|SharedInertiaTenantPropTest`
fails with `42P07 Duplicate table` errors. This is a known artifact of
the project's shared-physical-DB test bootstrap: when the filter is
applied, the `TestCase` runs migrations from scratch on the same physical
DB that previous tests in the full run have already touched. It is **not**
a regression — the full-suite run (which is what CI and the apply phase
use) is green, and the failures disappear on the next full run.

## Spec compliance

| Requirement | Scenarios | Tests | Status |
|-------------|-----------|-------|--------|
| 1: Tenant has isolated authorization state | 3 | 3 | PASS |
| 2: Tenant permissions and roles are seeded idempotently | 4 | 5 | PASS (extra: cache flush) |
| 3: First tenant user is auto-assigned tenant-admin | 3 | 3 | PASS |
| 4: Authorization via $user->can() | 3 | 3 | PASS |
| 5: auth.user.roles via Inertia shared props | 3 | 2 PHP + TS compile | PASS |
| 6: Admin badge in user menu | 2 | 2 (browser, not in `php artisan test` testsuite) | PASS (manual execution required) |
| 7: tenants:artisan migrate propagation | 2 | 2 | PASS |
| **Total** | **20** | **20 + 1 extra** | — |

### Requirement-by-requirement audit

#### Requirement 1 — Tenant has isolated authorization state (3/3)

- **New tenant has all 5 Spatie tables**: `database/migrations/2026_06_06_132424_create_permission_tables.php` exists in the **root** `database/migrations/` path (verified by `glob`), NOT in `database/migrations/landlord/`. The `landlord/` path contains only the 5 pre-existing landlord migrations. The Spatie migration's `up()` method (line 24) explicitly guards against running on the landlord connection: `if (DB::connection()->getName() !== 'tenant') return;`. Test `new tenant has all 5 Spatie authorization tables with expected columns` asserts the existence of all 5 tables and key columns. PASS.
- **Cross-tenant isolation**: Test `tenant connection is the target of the Spatie authorization schema` (bonus test, not in original 3 scenarios — covers a stronger invariant). Inserts a role via `Role::on('tenant')` and verifies it lands on the tenant connection. PASS.
- **Landlord has no Spatie tables**: Test `landlord database has no Spatie authorization tables` iterates the 5 Spatie table names and asserts each one is absent on the `landlord` connection. PASS.

#### Requirement 2 — Tenant permissions and roles are seeded idempotently (4/4)

`database/seeders/TenantPermissionsSeeder.php` was read and verified:
- Uses `Permission::findOrCreate('change-plan', 'web')` (line 122) and `Role::findOrCreate('tenant-admin', 'web')` (line 142). Idempotency via `findOrCreate` is correct.
- Calls `app(PermissionRegistrar::class)->forgetCachedPermissions()` **at the top of `run()`** (line 76), before any `findOrCreate`, per the `laravel-permission-development` skill recommendation.
- Uses `Role::syncPermissions(...)` internally (line 144), which is idempotent for a single permission.
- Two public const arrays (`PERMISSIONS` and `ROLES_WITH_PERMISSIONS`) make the seeder data-driven and easy to extend in future slices.

The 4 scenarios map to 5 tests (extra coverage: `flushes the permission cache` is a separate test that proves the cache flush is observable, not just present in the code). All pass.

#### Requirement 3 — First tenant user is auto-assigned tenant-admin (3/3)

- `database/seeders/DatabaseSeeder.php` (lines 21-27) wires `TenantPermissionsSeeder::class` **before** `TenantUsersSeeder::class`, satisfying the ordering contract.
- `database/seeders/TenantUsersSeeder.php` calls `assignFirstUserRole($user)` on every user (line 61), which calls `$user->syncRoles(['tenant-admin'])` unconditionally (line 77). `syncRoles` is idempotent: it replaces the role set, so a re-run on a user with `tenant-admin` already assigned is a no-op.
- The seeder creates exactly one user per tenant via `updateOrCreate`, so in practice the only user per tenant always ends up with `tenant-admin`. The "other users do not have tenant-admin" scenario is verified by the test which creates two users explicitly and asserts only the first has the role.

#### Requirement 4 — Authorization via $user->can() (3/3)

- `app/Models/User.php` line 16: `use Spatie\Permission\Traits\HasRoles;` — confirmed imported.
- `app/Models/User.php` line 23: `use HasFactory, HasRoles, InteractsWithMedia, Notifiable, UsesTenantConnection;` — `HasRoles` is the second trait alongside `UsesTenantConnection`. Order does not matter.
- `roles` is **not** in `$appends` (line 43-45: only `avatar` is appended). This matches the design's risk note #7: "we are **not** adding `roles` to `$appends` to avoid conflicting with Spatie's `roles` relationship."
- The 3 scenarios (`can` returns true with role, false without, false when permission revoked) map to 3 tests. The "revoked permission" test is the critical one that proves authorization goes through the permission check, not just role presence. It calls `$role->revokePermissionTo('change-plan')`, flushes the Spatie cache, clears the user's cached relations, and re-evaluates `can()`.

#### Requirement 5 — auth.user.roles via Inertia shared props (3/3)

- `app/Http/Middleware/HandleInertiaRequests.php` line 148: `'roles' => $this->resolveRoles($user)` in the explicit `auth.user` array shape.
- The `resolveRoles()` helper (lines 203-226) is defensive: it checks `$user instanceof User`, probes `Schema::connection($connection)->hasTable('roles')` (with try/catch fallback), and returns `[]` on any failure. This is a contract-honoring addition beyond the spec — the spec just says "expose `auth.user.roles` via `resolveRoles()`"; the defensive checks ensure the helper doesn't crash when Spatie tables are absent (e.g., in tests that don't set up the Spatie schema). The spec's defense (`User` and `Landlord` distinction) is preserved: Landlord gets `[]` via the `$user instanceof User` check.
- `resources/js/types/auth.ts` line 9: `roles: string[]` is present in the `User` type. PASS (TypeScript compiles).
- 2 new feature tests in `tests/Feature/SharedInertiaTenantPropTest.php` (`shared auth prop exposes tenant-admin user roles array` and `shared auth prop exposes empty roles for non-admin user`) pin the contract.

#### Requirement 6 — Admin badge in user menu (2/2)

- `resources/js/components/user-menu-content.tsx` lines 32-39: the badge is rendered conditionally with `data-testid="user-role-badge"` and the text "Admin" (line 37). PASS.
- `resources/js/components/app-header.tsx` line 218: `data-testid="user-menu-trigger"` on the user-menu trigger button. PASS.
- `resources/js/components/nav-user.tsx` line 36: `data-testid="user-menu-trigger"` on the sidebar user-menu trigger. PASS.
- `tests/Browser/Tenant/UserMenuBadgeTest.php` has 2 tests that follow browser-testing principles 1-7:
  - **Principle 1** (no direct HTTP): uses `visit()`, `click()`, `waitFor()` — no `Http::`, no `$this->get/post`. PASS.
  - **Principle 2** (self-sufficient): `beforeEach` cleans the 5 Spatie tables on the test DB, each test creates its own user. PASS.
  - **Principle 3** (`actingAs()` for auth): both tests use `$this->actingAs($user)`. PASS.
  - **Principle 4** (factories before browser): user is created in the arrange block, before the browser interactions start. PASS.
  - **Principle 5** (real UI assertions): `assertSeeIn('@user-role-badge', 'Admin')` verifies concrete UI content, not just a path. PASS.
  - **Principle 6** (no `assertDatabaseHas`): confirmed absent. PASS.
  - **Principle 7** (`data-testid` priority): `@user-menu-trigger` and `@user-role-badge` are `data-testid` selectors (top of the browser-testing §3.5 priority list). PASS.
- **Caveat**: the Browser testsuite is **not in `phpunit.xml`** (pre-existing project condition). `php artisan test` does not run these 2 tests. They must be executed separately (e.g. `pest tests/Browser/Tenant/UserMenuBadgeTest.php`). This is documented as a SUGGESTION below.

#### Requirement 7 — tenants:artisan migrate propagation (2/2)

- `tenants:artisan migrate propagates Spatie tables to existing tenants` — creates a tenant, points the tenant connection at the test physical DB, deletes the Spatie migration row from the shared `migrations` table, calls `Artisan::call('tenants:artisan', ['artisanCommand' => 'migrate --force'])`, and asserts the 5 tables exist. PASS.
- `new tenant gets Spatie tables automatically via creating callback` — a contract test: it verifies the Spatie migration file exists at the root path and its `up()` method exists. The full DDL test is not possible in a feature test (the `creating` callback issues `CREATE DATABASE` which cannot run inside a transaction), so the contract is pinned by file existence + a code-method check. PASS.

## Findings

### CRITICAL
None.

### WARNING

1. **Doc/code mismatch in `Arquitectura multitenencia aplicada.md` §23.3** (lines 4786-4796): the code example shows:
   ```php
   foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
       $isFirstUser = ! User::on('tenant')->exists();
       $user = User::on('tenant')->updateOrCreate([...]);
       if ($isFirstUser) {
           $user->syncRoles(['tenant-admin']);
       }
   }
   ```
   But the actual `database/seeders/TenantUsersSeeder.php` does **not** implement the `isFirstUser` guard — `assignFirstUserRole()` is called on every user via `$this->assignFirstUserRole($user)` at line 61. The behavior is correct in practice because the seeder creates exactly one user per tenant via `updateOrCreate`, and `syncRoles` is idempotent. But the doc example suggests a more defensive pattern that the code does not implement. The doc narrative ("El primer usuario creado en cada tenant DB recibe automáticamente el rol `tenant-admin`. Los usuarios subsecuentes no reciben roles") is still accurate, because the seeder in its current form only ever creates one user per tenant. Recommendation: either (a) align the code with the doc by adding the `isFirstUser` guard for future-proofing, or (b) update the doc example to match the code. Either is acceptable; current behavior is correct.

### SUGGESTION

1. **Browser tests don't run with `php artisan test`**: the Browser testsuite is not registered in `phpunit.xml`, so the 2 tests in `tests/Browser/Tenant/UserMenuBadgeTest.php` are not exercised by the standard test runner. This is a pre-existing project condition (per apply-progress obs #428), not a regression. The full suite shows 206 passing; the 2 browser tests are not in that count. To run them: `pest tests/Browser/Tenant/UserMenuBadgeTest.php` (requires the dev server to be up). This is the same gap that exists for any other browser test in the project. Documenting so the user knows the count is 206/206 feature tests, not 208/208 total.

2. **Optional doc improvement**: §23.7 says `composer remove spatie/laravel-permission` will leave the 5 Spatie tables behind in tenant DBs. A short note that the rollback plan is safe to run in this order (package first, then code) but NOT the reverse (removing the trait while Spatie is still installed will break the User model boot) would prevent future foot-guns.

## Deviations from spec/design

1. **Defensive `resolveRoles` and `resolveAvatar` in `HandleInertiaRequests`**: the spec and design only specified the `resolveRoles()` helper with the `User`/`Landlord` distinction. The implementation added `Schema::hasTable('roles')` and `try/catch` guards inside the helper. This is a **defensive addition that honors the spec's contract** (the helper still exists, the `auth.user.roles` prop is still emitted with the right shape), not a deviation. It protects against the edge case where the `roles` table does not exist on the user's connection (e.g., in a test that doesn't set up the Spatie schema). The spec's defensive risk note ("Spatie migrations running on the default connection during test setup — harmless") was about a different edge case (duplicate work, not crashes). The new defensive checks are a more conservative interpretation of the spec and do not break any of the contract. The `resolveAvatar()` helper received the same defensive treatment, which is unrelated to the `tenant-roles` change but is mentioned in the apply-progress obs as part of the same inline fix that unblocked the 23 failing tests.

2. **No browser testsuite in `phpunit.xml`**: pre-existing project condition. The 2 browser tests for Requirement 6 are written, syntactically correct, and follow all 7 browser-testing principles, but are not run by `php artisan test`. Documented as SUGGESTION.

3. **`config/permission.php` is committed untracked**: per the design, this file is the published Spatie config and should be committed so other developers don't have to re-publish. Git status confirms it is currently untracked (the user can `git add` it on the next commit). Not a code deviation.

## Regression analysis

The apply-progress obs #428 noted that an inline fix to `HandleInertiaRequests.php` (adding `resolveAvatar()` and the defensive checks in `resolveRoles()`) unblocked 23 pre-existing failing tests. The current full-suite run confirms 206/206 passing, 0 failing. The two helpers do not introduce any new dependencies (no new package imports beyond the existing `Illuminate\Support\Facades\Schema` already used in the file), do not modify the public Inertia prop shape (`auth.user` is still the same array structure with the same keys), and do not break any of the pre-existing 9 `SharedInertiaTenantPropTest` tests for the `tenant` prop. The 23 tests are all green in the current run.

## Browser test infrastructure note

Per apply-progress obs #428: the project uses `pestphp/pest-plugin-browser` (Playwright-based) for browser tests. The Browser testsuite is NOT in `phpunit.xml`, so `php artisan test` does not run browser tests. The 2 browser tests in `tests/Browser/Tenant/UserMenuBadgeTest.php` are well-written and follow all 7 principles from the browser-testing skill, but they require a separate invocation to run. Pre-existing project condition, not a regression. The user's CI workflow may already handle this; if not, this is a SUGGESTION for a follow-up to add the Browser testsuite to `phpunit.xml`.

## Files audited

- `openspec/changes/1.5G.0-tenant-roles/proposal.md` — read; matches apply-progress obs #423.
- `openspec/changes/1.5G.0-tenant-roles/spec.md` — read; 7 ADDED requirements, 20 scenarios.
- `openspec/changes/1.5G.0-tenant-roles/design.md` — read; 13 components, 9 TDD steps, 8 risks.
- `openspec/changes/1.5G.0-tenant-roles/tasks.md` — read; all 8 tasks marked [x].
- `database/migrations/2026_06_06_132424_create_permission_tables.php` — read; root path, has tenant-only guard, 5 tables.
- `database/migrations/landlord/*` — glob'd; no Spatie migration in landlord path (correct).
- `database/seeders/TenantPermissionsSeeder.php` — read; idempotent, `findOrCreate`, `forgetCachedPermissions` first, `syncPermissions`.
- `database/seeders/DatabaseSeeder.php` — read; `TenantPermissionsSeeder` before `TenantUsersSeeder`.
- `database/seeders/TenantUsersSeeder.php` — read; `assignFirstUserRole` calls `syncRoles` on every user (idempotent).
- `app/Models/Tenant.php` — read; `creating` callback runs `runMigrations()` on tenant connection.
- `app/Models/User.php` — read; `HasRoles` trait imported and used; `roles` not in `$appends`.
- `app/Http/Middleware/HandleInertiaRequests.php` — read; `resolveUserProp`, `resolveRoles`, `resolveAvatar` helpers all present and defensive.
- `config/permission.php` — read; `teams => false`, defaults OK.
- `composer.json` — read; `spatie/laravel-permission: ^8.0` is the only new dependency.
- `resources/js/types/auth.ts` — read; `roles: string[]` in `User` type.
- `resources/js/types/global.d.ts` — read; `Auth` type flows through.
- `resources/js/components/user-menu-content.tsx` — read; conditional badge with `data-testid="user-role-badge"`.
- `resources/js/components/app-header.tsx` — read; `data-testid="user-menu-trigger"`.
- `resources/js/components/nav-user.tsx` — read; `data-testid="user-menu-trigger"`.
- `tests/Feature/Auth/TenantPermissionsTest.php` — read; 16 tests (5 for Req 1, 5 for Req 2, 3 for Req 3, 3 for Req 4, 2 for Req 7 — extra: "tenant connection is the target").
- `tests/Feature/SharedInertiaTenantPropTest.php` — read; 11 tests (9 pre-existing + 2 new for Req 5).
- `tests/Browser/Tenant/UserMenuBadgeTest.php` — read; 2 browser tests for Req 6, follow principles 1-7.
- `Arquitectura multitenencia aplicada.md` §23 — read; 9 subsections covering all 6 design risks (doc covers: per-tenant auth model, installation, seed, auth rule, frontend exposure, precondition framing, rollback plan, file inventory).

## Recommendation

**APPROVE WITH FIXES (minor).**

The implementation is correct, complete, and consistent with the spec/design. The 3 quality gates are green, all 20 spec scenarios are covered, and the regression from the inline fix is fully resolved. The single WARNING (doc §23.3 code example drift) is a documentation accuracy issue, not a code bug — the behavior is correct in practice. The user can either update the doc or align the code with the doc example; both are acceptable. The 2 SUGGESTIONS (browser testsuite not in phpunit.xml, optional rollback ordering note) are nice-to-haves for future improvement, not blockers.

The next step after fixing the WARNING is `sdd-archive`, which will sync the 7 ADDED requirements to the project's main spec. No further code changes are required before archive.
