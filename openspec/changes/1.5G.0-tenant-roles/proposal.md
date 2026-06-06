# Proposal: 1.5G.0 — Tenant Roles & Permissions

## Intent

**Precondition slice for `1.5G-buy-plan`.** Before `UpgradePlanDialog` and `Tenant::upgradeTo()` ship: **who can change this tenant's plan?**

This slice installs `spatie/laravel-permissions` per tenant with the `tenant-admin` role and `change-plan` permission, exposed through Inertia shared props and a visible UI badge.

## Scope

### In Scope
- Add `spatie/laravel-permissions`; publish 5 migrations to `database/migrations/`
- Idempotent `TenantPermissionsSeeder`; add `HasRoles` to `User`; extend `TenantUsersSeeder` to `syncRoles(['tenant-admin'])` on first user
- Wire `TenantPermissionsSeeder` into `DatabaseSeeder` before `TenantUsersSeeder`
- `app/Http/Middleware/HandleInertiaRequests.php` — add `auth.user.roles: string[]` shared prop
- `resources/js/types/global.d.ts` — extend `auth.user` with `roles: string[]`
- `resources/js/components/user-menu-content.tsx` — conditional "Admin" badge with `data-testid="user-role-badge"` when `roles.includes('tenant-admin')`
- Feature tests: `tests/Feature/Auth/TenantPermissionsTest.php`
- Browser tests: `tests/Browser/Tenant/UserMenuBadgeTest.php` (visible with role, hidden without)

### Out of Scope
- `UpgradePlanDialog` UI, `Tenant::upgradeTo()` → `1.5G` (proper)
- UI for tenant admin to invite users / grant-revoke permissions → next per-tenant slice
- Landlord-side Spatie Permissions → `1.5G.1-landlord-roles` (parallel future slice)
- `subscriptions` table → `1.5G/H`; `SubscriptionStatus::Expired` → `1.5H`; `PaymentGatewayInterface` → Phase 2

## Capabilities

### New Capabilities
- `tenant-authorization`: `tenant-admin` role with `change-plan`. Auth via `$user->can('change-plan')` / `Gate::allows('change-plan')` only — never string-compare role names.

### Modified Capabilities
None — no existing spec.

## Approach

**Replication**: Spatie migrations → `database/migrations/`; `Tenant::creating → runMigrations()` replicates them.

**Auth API**: `$user->can('change-plan')` / `Gate::allows('change-plan')` only. String-compare forbidden.

**Seeding**: `TenantPermissionsSeeder` idempotent; `TenantUsersSeeder` calls `syncRoles(['tenant-admin'])`.

**Frontend surface**: user-menu badge is the production expression of auth state and the deterministic browser-test target.

**Estimated diff**: ~830 lines (~30 over the 800-line D2 budget). Frontend test surface (~200 lines) is the reason; without it the slice is incomplete per project convention. Recommend `size:exception`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `composer.json` | Modified | New dep |
| `database/migrations/*_create_permission_tables.php` | New (×5) | Replicated per tenant |
| `config/permission.php` | New | Defaults OK |
| `app/Models/User.php` | Modified | Add `HasRoles` |
| `database/seeders/TenantPermissionsSeeder.php` | New | Idempotent role+permission |
| `database/seeders/TenantUsersSeeder.php` | Modified | `syncRoles` on first user |
| `database/seeders/DatabaseSeeder.php` | Modified | New seeder wired first |
| `app/Http/Middleware/HandleInertiaRequests.php` | Modified | `auth.user.roles` shared prop |
| `resources/js/types/global.d.ts` | Modified | Extends `auth.user.roles` |
| `resources/js/components/user-menu-content.tsx` | Modified | Conditional "Admin" badge |
| `tests/Feature/Auth/TenantPermissionsTest.php` | New | 5 feature tests |
| `tests/Browser/Tenant/UserMenuBadgeTest.php` | New | 2 browser tests |

## Risks

- **Stale permission cache on tenant switch** (Med): per-connection cache flushes via `DB::purge('tenant')`.
- **Spatie tables absent on landlord DB** (Info): intentional — `1.5G.1` publishes a separate copy.
- **Future users get `change-plan`** (Low): no invite flow yet.
- **Unnamespaced `change-plan`** (Low): refactor to `billing.*` at 3+ billing perms.

## Rollback Plan

1. `composer remove spatie/laravel-permissions`; delete Spatie migrations + `config/permission.php`
2. Revert seeders; drop `TenantPermissionsSeeder.php`; remove `HasRoles`
3. `Gate::allows('change-plan')` returns `false` — acceptable (1.5G not shipped)

## Dependencies

- `1.5G-buy-plan` (downstream) — first consumer of `Gate::allows('change-plan')`

## Success Criteria

- [ ] `composer require` resolves; Spatie tables land on `default` after `migrate`
- [ ] First user of new tenant has `change-plan`; cross-tenant isolation holds
- [ ] `TenantPermissionsSeeder` idempotent on double-run
- [ ] `Gate::allows('change-plan')` true for admin, false for non-admin
- [ ] Browser: badge visible with `tenant-admin`, hidden without
