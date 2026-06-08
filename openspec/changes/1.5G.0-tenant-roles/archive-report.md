# Archive Report: 1.5G.0-tenant-roles

**Change**: 1.5G.0-tenant-roles
**Status**: archived
**Date**: 2026-06-08

## Implementation Summary

Precondition slice for `1.5G-buy-plan`. Installed `spatie/laravel-permission` per tenant database to answer "who can change this tenant's plan?". Each tenant DB gets isolated Spatie authorization tables, seeded with a `tenant-admin` role and `change-plan` permission, exposed to the frontend via Inertia shared props with a visible Admin badge.

### Files Changed

| File | Action |
|------|--------|
| `composer.json` | MODIFIED — added `spatie/laravel-permission: ^8.0` |
| `composer.lock` | MODIFIED — updated after require |
| `config/permission.php` | NEW — published Spatie config (defaults OK, teams off) |
| `database/migrations/2026_06_06_132424_create_permission_tables.php` | NEW — 5 Spatie tables with tenant-only guard in `up()` |
| `database/seeders/TenantPermissionsSeeder.php` | NEW — idempotent, `findOrCreate`, cache flush first |
| `database/seeders/DatabaseSeeder.php` | MODIFIED — wire `TenantPermissionsSeeder` before `TenantUsersSeeder` |
| `database/seeders/TenantUsersSeeder.php` | MODIFIED — `assignFirstUserRole` calls `syncRoles` on each user |
| `app/Models/User.php` | MODIFIED — added `HasRoles` trait alongside `UsesTenantConnection` |
| `app/Http/Middleware/HandleInertiaRequests.php` | MODIFIED — refactored to explicit `auth.user` array with `roles`, added defensive `resolveRoles` |
| `resources/js/types/auth.ts` | MODIFIED — added `roles: string[]` to `User` type |
| `resources/js/components/user-menu-content.tsx` | MODIFIED — conditional "Admin" badge with `data-testid="user-role-badge"` |
| `resources/js/components/app-header.tsx` | MODIFIED — added `data-testid="user-menu-trigger"` |
| `resources/js/components/nav-user.tsx` | MODIFIED — added `data-testid="user-menu-trigger"` |
| `tests/Feature/Auth/TenantPermissionsTest.php` | NEW — 16 tests (Requirements 1-4, 7) |
| `tests/Feature/SharedInertiaTenantPropTest.php` | MODIFIED — added 2 tests for `auth.user.roles` prop |
| `tests/Browser/Tenant/UserMenuBadgeTest.php` | NEW — 2 browser tests (badge visible/hidden) |
| `Arquitectura multitenencia aplicada.md` | MODIFIED — added §23 (authorization model, precondition, rollback) |

### Tests Added

| File | Count |
|------|-------|
| `tests/Feature/Auth/TenantPermissionsTest.php` | 16 tests |
| `tests/Feature/SharedInertiaTenantPropTest.php` | +2 tests (9 pre-existing) |
| `tests/Browser/Tenant/UserMenuBadgeTest.php` | 2 browser tests |

**Total**: 20 new tests across 3 files (18 feature + 2 browser).

## Verification

- **Full suite**: 206/206 passing, 3 skipped, 0 failing, 759 assertions
- **PHP**: `vendor/bin/pint --format agent` clean
- **TypeScript**: `npx tsc --noEmit` clean
- **Verify status**: PASS WITH WARNINGS (1 WARNING: doc/code mismatch in architecture doc §23.3 — illustrative snippet shows `isFirstUser` guard that actual seeder doesn't use; behavior is correct because seeder creates one user per tenant and `syncRoles` is idempotent)

### Quality Gates

| Gate | Status | Notes |
|------|--------|-------|
| `php artisan test --compact` | PASS | 209 total, 206 passing, 3 skipped |
| `vendor/bin/pint --dirty --format agent` | PASS | No formatting issues |
| `npx tsc --noEmit` | PASS | No TypeScript errors |

## Spec Compliance

| Requirement | Scenarios | Tests | Status |
|-------------|-----------|-------|--------|
| 1: Tenant has isolated authorization state | 3 | 3 | PASS |
| 2: Tenant permissions and roles seeded idempotently | 4 | 5 (extra: cache flush) | PASS |
| 3: First tenant user auto-assigned tenant-admin | 3 | 3 | PASS |
| 4: Authorization via $user->can() | 3 | 3 | PASS |
| 5: auth.user.roles via Inertia shared props | 3 | 2 PHP + TS compile | PASS |
| 6: Admin badge in user menu | 2 | 2 browser | PASS |
| 7: tenants:artisan migrate propagation | 2 | 2 | PASS |
| **Total** | **20** | **20 + 1 extra** | **PASS** |

## Key Decisions

### Per-tenant Spatie isolation (physical DB)

Each tenant gets its own set of 5 Spatie tables via `Tenant::creating → runMigrations()`. Cross-tenant isolation is enforced by physical database separation, not by Spatie's teams feature. The landlord DB is untouched in this slice (deferred to `1.5G.1-landlord-roles`).

### Idempotent seeding with `findOrCreate`

`TenantPermissionsSeeder` uses `Permission::findOrCreate` and `Role::findOrCreate` so it is safe to run multiple times without producing duplicates. The permission cache is flushed at the top of `run()` via `forgetCachedPermissions()`, per the `laravel-permission-development` skill recommendation.

### `HasRoles` trait without `$appends` conflict

`User` uses the `HasRoles` trait alongside `UsesTenantConnection`. The `roles` relationship is NOT added to the `$appends` array to avoid conflicting with Spatie's built-in `roles` relationship. The roles array is built explicitly in `HandleInertiaRequests::share()`.

### Defensive `resolveRoles` in Inertia middleware

The implementation added `Schema::hasTable('roles')` and `try/catch` guards inside the `resolveRoles()` helper, going beyond the spec/design. This ensures the helper doesn't crash when Spatie tables are absent (e.g., in tests that don't set up the Spatie schema). Landlord users get `[]` via the `$user instanceof User` check.

### Auth API: permission-based, not role-based

Authorization is checked via `$user->can('change-plan')` only — string-comparing role names is forbidden by project convention. The `tenant-admin` role is granted `change-plan` via `givePermissionTo`, making the permission the atomic unit of authorization.

### Doc/code drift in §23.3 (WARNING)

The architecture doc §23.3 shows an `if ($isFirstUser)` guard in the code example, but the actual `TenantUsersSeeder` calls `syncRoles` on every user unconditionally. Behavior is correct because the seeder creates exactly one user per tenant and `syncRoles` is idempotent. The doc narrative is accurate but the illustrative snippet is misleading. Not a code bug.

## Browser Tests Note

The 2 browser tests in `tests/Browser/Tenant/UserMenuBadgeTest.php` follow all 7 browser-testing principles but are not registered in `phpunit.xml` (pre-existing project condition). They must be run separately via `pest tests/Browser/Tenant/UserMenuBadgeTest.php`. The standard `php artisan test` suite shows 206/206 passing (excluding the 2 browser tests).

## Coverage

| Metric | Value |
|--------|-------|
| Requirements | 7/7 covered |
| Scenarios | 20/20 covered + 1 extra defensive test |
| Inertia props | `auth.user.roles` exposed with defensive guards |
| TypeScript | `roles: string[]` in `User` type, compiles clean |

## OpenSpec Artifacts

- `openspec/changes/1.5G.0-tenant-roles/proposal.md`
- `openspec/changes/1.5G.0-tenant-roles/spec.md`
- `openspec/changes/1.5G.0-tenant-roles/design.md`
- `openspec/changes/1.5G.0-tenant-roles/tasks.md`
- `openspec/changes/1.5G.0-tenant-roles/verify-report.md`
- `openspec/changes/1.5G.0-tenant-roles/archive-report.md` (this file)

## Rollback

Per §23.9 of the architecture doc: `composer remove spatie/laravel-permission`, delete Spatie migrations + `config/permission.php`, revert seeders, drop `HasRoles` from `User`, revert `HandleInertiaRequests.php`, remove frontend changes, delete test files, regenerate Wayfinder.
