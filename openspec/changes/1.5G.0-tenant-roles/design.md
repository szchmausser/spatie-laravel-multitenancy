# Design: 1.5G.0-tenant-roles

## Context

This slice installs `spatie/laravel-permission` inside every tenant database and defines a `tenant-admin` role that grants the `change-plan` permission. It is the **precondition slice** for `1.5G-buy-plan`: before `UpgradePlanDialog` and `Tenant::upgradeTo()` can ship, the system must answer "who is allowed to change this tenant's plan?" with a deterministic, testable authorization model.

Per project convention, this is a **full vertical** — backend (Spatie installation, seeders, `HasRoles` trait) plus frontend (Inertia shared prop, conditional Admin badge) plus tests (5 feature + 2 browser). The `tenant-authorization` capability is introduced as a brand-new spec, with no modifications to existing capabilities.

## Goals

- Establish **physical per-tenant authorization isolation** — every tenant DB has its own Spatie tables, no cross-tenant leakage.
- Provide a **deterministic authorization API** — authorization is checked via `$user->can('change-plan')` (or `Gate::allows('change-plan')`); string-comparing role names is forbidden.
- **Expose auth state to the frontend** through Inertia shared props as `auth.user.roles: string[]`, the single source of truth on the client.
- **Visualize auth state** in the user menu via a stable `data-testid="user-role-badge"` selector — the deterministic browser-test target.
- **Lay the groundwork** for `1.5G-buy-plan` and any future per-tenant feature that needs to gate on a permission.

## Non-Goals

- UI for inviting users or granting/revoking permissions within a tenant (next per-tenant slice).
- Landlord-side Spatie permissions (`1.5G.1-landlord-roles`, parallel future slice).
- The `1.5G-buy-plan` feature itself (`UpgradePlanDialog`, `Tenant::upgradeTo()`).
- `PaymentGatewayInterface` and the `Tenant::upgradeTo()` method (Phase 2).
- A Super Admin role or `Gate::before` global bypass — we use permission-based checks only, per Spatie best practices.
- The `subscriptions` table changes and `SubscriptionStatus::Expired` (separate slices).

## Architecture Overview

Spatie's permissions package is a **per-tenant concern** in this project: each tenant has its own PostgreSQL database, and authorization tables live alongside tenant data. The `Tenant::creating` callback in `app/Models/Tenant.php` already runs `runMigrations()` (line 247) — once the 5 Spatie migrations are published to `database/migrations/`, that callback automatically replicates them to every new tenant DB. The `tenants:artisan migrate` command (driven by `Spatie\Multitenancy\Actions\MigrateTenantAction` via `SwitchTenantDatabaseTask`) takes care of pre-existing tenants.

The `User` model already uses the `UsesTenantConnection` trait from Spatie Multitenancy. Adding `HasRoles` from Spatie Permission makes the `roles` relationship honor the model's connection — so role/permission queries transparently hit the tenant DB. The `User` model lives in the tenant DB (`database/migrations/0001_01_01_000000_create_users_table.php` is in the root migrations folder, replicated to tenants), so all of this is consistent without further changes to the model file beyond the trait import.

Frontend exposure is straightforward: `HandleInertiaRequests::share()` already returns an `auth.user` payload; this slice refactors that payload to an explicit array shape that includes `roles`, and the React user menu renders a small "Admin" badge when `roles.includes('tenant-admin')`. TypeScript types are extended in `resources/js/types/auth.ts` and `resources/js/types/global.d.ts`.

```
Login → HandleInertiaRequests::share() (builds auth.user with roles from $user->roles)
     → Inertia::render() → React user menu → roles.includes('tenant-admin') → Admin badge

Authorization check → $user->can('change-plan') → Spatie permission lookup
     → model_has_permissions OR (model_has_roles JOIN role_has_permissions) → bool
```

## Data Model

All 5 Spatie tables are published to **root `database/migrations/`** (not `database/migrations/landlord/`), so they are replicated to every tenant DB. The landlord DB is **untouched** in this slice — landlord-side authorization is deferred to `1.5G.1-landlord-roles`.

| Table | Purpose | Key columns / constraints |
|-------|---------|----------------------------|
| `permissions` | permission catalog | `id`, `name`, `guard_name`, timestamps; `UNIQUE(name, guard_name)` |
| `roles` | role catalog | `id`, `name`, `guard_name`, timestamps; `UNIQUE(name, guard_name)` |
| `model_has_permissions` | direct user-permission grants | `permission_id`, `model_type`, `model_id`; composite PK |
| `model_has_roles` | user-role assignments | `role_id`, `model_type`, `model_id`; composite PK |
| `role_has_permissions` | role-permission grants | `permission_id`, `role_id`; composite PK |

Seeded values for this project (no migration is added — these are inserted by `TenantPermissionsSeeder`):

| Seed | `name` | `guard_name` | Notes |
|------|--------|--------------|-------|
| Permission | `change-plan` | `web` | Only permission in this slice. Refactor to `billing.*` when 3+ billing perms exist. |
| Role | `tenant-admin` | `web` | Granted `change-plan` via `givePermissionTo`. |

**No `teams` mode is enabled** in `config/permission.php`. Cross-tenant isolation is enforced by physical DB separation, not by Spatie's teams feature.

## Component Breakdown

| File | Type | Purpose | Tests |
|------|------|---------|-------|
| `composer.json` | Modify | Add `spatie/laravel-permission` to `require`. | (Sanity) `php artisan test --compact` green after install. |
| `config/permission.php` | Create (published) | Default Spatie config. No edits required for this slice. | — |
| `database/migrations/*_create_permission_tables.php` (×5) | Create (published) | Replicated to every tenant DB via `Tenant::runMigrations()`. Root path, not `database/migrations/landlord/`. | Requirement 1 scenarios. |
| `app/Models/User.php` | Modify | Add `use Spatie\Permission\Traits\HasRoles;` — second trait alongside the existing `UsesTenantConnection`. | Requirement 4 scenarios. |
| `database/seeders/TenantPermissionsSeeder.php` | **New** | Idempotent: flush cache, `findOrCreate` permission, `findOrCreate` role, `givePermissionTo` to role. | Requirement 2 scenarios. |
| `database/seeders/TenantUsersSeeder.php` | Modify | After `updateOrCreate` of the user, if it's the first user for that tenant, call `$user->syncRoles(['tenant-admin'])`. Idempotent by design. | Requirement 3 scenarios. |
| `database/seeders/DatabaseSeeder.php` | Modify | Add `TenantPermissionsSeeder::class` to the seeder chain, **before** `TenantUsersSeeder::class`. | Verified transitively by Requirements 2 + 3. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Modify | Refactor `auth.user` from `$request->user()` to an explicit array shape that includes `roles: $user?->roles->pluck('name')->toArray() ?? []`. `is_admin` flag stays as-is. | Requirement 5 scenarios. |
| `resources/js/types/auth.ts` | Modify | Add `roles: string[]` to the `User` type. Remove `[key: string]: unknown` index signature for that field's path (or leave the signature, since `roles` is more specific). | TS compile passes. |
| `resources/js/types/global.d.ts` | Modify | No shape change — the `Auth` type from `auth.ts` already flows through. | TS compile passes. |
| `resources/js/components/user-menu-content.tsx` | Modify | Render `<span data-testid="user-role-badge">Admin</span>` after `<UserInfo>` when `user.roles.includes('tenant-admin')`. | Requirement 6 scenarios. |
| `tests/Feature/Auth/TenantPermissionsTest.php` | **New** | 5+ feature tests covering Requirements 1–4 + 7. | — |
| `tests/Browser/Tenant/UserMenuBadgeTest.php` | **New** | 2 browser tests covering Requirement 6 (badge visible / hidden). | — |
| `tests/Feature/SharedInertiaTenantPropTest.php` | Modify | Add 3 tests for the new `auth.user.roles` prop (Requirement 5). | — |
| `tests/TestCase.php` | (no change) | Verify the dual-connection transaction setup (`[null, 'landlord']`) and `RefreshLandlordDatabase` trait still work. Spatie tables are created on the tenant connection, not in the `null`/`landlord` set. | Existing tests stay green. |
| `tests/Support/RefreshLandlordDatabase.php` | (verify) | Confirm this trait does not inadvertently run tenant migrations on landlord. | — |
| `Arquitectura multitenencia aplicada.md` | Modify | Add a new section (e.g. §23) documenting the authorization model, the precondition decision, and the rollback path. | — |

## Sequence Flows

**Flow 1 — Tenant creation (new tenant)**
```
Tenant::create($data)
  └─ creating callback
       ├─ createDatabase()        → CREATE DATABASE "<tenant>"
       ├─ configureTenantConnection() → config('database.connections.tenant.database') = "<tenant>"
       └─ runMigrations()          → artisan migrate --database=tenant
                                      └─ runs ALL migrations in database/migrations/
                                           └─ 5 Spatie permission tables created
  └─ created callback
       └─ ensureDefaultSubscription()
Seeder run (separate, after create)
  └─ TenantPermissionsSeeder
       ├─ forgetCachedPermissions()
       ├─ Permission::findOrCreate('change-plan', 'web')
       └─ Role::findOrCreate('tenant-admin', 'web')->givePermissionTo('change-plan')
  └─ TenantUsersSeeder
       └─ User::on('tenant')->updateOrCreate(...) → $user->syncRoles(['tenant-admin'])
```

**Flow 2 — User login + frontend render**
```
Request lands on tenant subdomain
  └─ HandleInertiaRequests::share()
       ├─ $user = $request->user()              // bound to tenant connection via auth guard
       └─ 'auth' => [
              'user' => [
                  id, name, email, avatar, email_verified_at,
                  'roles' => $user?->roles->pluck('name')->toArray() ?? [],
              ],
              'is_admin' => $user instanceof Landlord,
          ]
  └─ Inertia::render(page, props)
       └─ React user-menu-content.tsx
            └─ user.roles.includes('tenant-admin') ? <span data-testid="user-role-badge">Admin</span> : null
```

**Flow 3 — Authorization check**
```
$user->can('change-plan')
  └─ Spatie HasRoles::callMethod('can', 'change-plan')
       └─ Gate::check('change-plan', ...)
            └─ HasRoles trait resolves the user→roles→permissions chain
                 └─ SELECT 1 FROM model_has_permissions WHERE model_id = ? AND permission_id = (change-plan)
                      └─ if false, JOIN model_has_roles + role_has_permissions
                           └─ returns true / false
                 └─ result cached per-request (PermissionRegistrar)
```

## TDD Order

Strict TDD (`php artisan test --compact`) — each work unit is red → green → refactor, followed by `vendor/bin/pint --dirty --format agent`.

1. **Package install** — `composer require spatie/laravel-permission`; publish config and 5 migrations to root `database/migrations/`. Run full suite; existing tests must stay green (Requirement 1's first sub-step: tables exist on a new tenant).
2. **Requirement 1 — isolated state** — write `tests/Feature/Auth/TenantPermissionsTest.php::test_new_tenant_has_all_5_spatie_tables` (asserts via `Schema::connection('tenant')->hasTable(...)` for each of the 5 tables) and `::test_cross_tenant_authorization_isolation` (two tenants, one role grant, assert the other tenant's tables are untouched). Run red, then implement just enough to green (publish migrations, ensure `runMigrations()` picks them up).
3. **Requirement 2 — idempotent seeders** — add `::test_seeder_creates_change_plan_permission`, `::test_seeder_creates_tenant_admin_role`, `::test_role_has_change_plan_permission`, `::test_seeder_is_idempotent_on_double_run` (run twice, assert exactly 1 role + 1 permission), `::test_seeder_flushes_permission_cache`. Run red, implement `TenantPermissionsSeeder` using `findOrCreate` + `forgetCachedPermissions()`, green.
4. **Requirement 3 — first user auto-assigned** — add `::test_first_user_has_tenant_admin_role`, `::test_re_seeding_does_not_duplicate_roles`, `::test_other_users_do_not_have_tenant_admin`. Run red, modify `TenantUsersSeeder` to `syncRoles(['tenant-admin'])` on the first user per tenant (track by counting existing users in that tenant's DB), green.
5. **Requirement 4 — can() check works** — add `::test_user_with_tenant_admin_can_change_plan`, `::test_user_without_roles_cannot_change_plan`, `::test_revoked_permission_returns_false`. Run red, add `use HasRoles;` to `User`, green.
6. **Requirement 5 — Inertia shared prop** — add 3 tests to `tests/Feature/SharedInertiaTenantPropTest.php`: tenant-admin sees `['tenant-admin']`, non-admin sees `[]`, the prop is `null` on landlord routes. Run red, refactor `HandleInertiaRequests::share()` to build the explicit user array with `roles`, green.
7. **Requirement 6 — browser badge** — add `tests/Browser/Tenant/UserMenuBadgeTest.php` with 2 tests (badge visible for admin, hidden for non-admin). Use `actingAs()` per browser-testing principle 3; selector is `[data-testid="user-role-badge"]` per §3.5 of the skill. Run red, add `roles: string[]` to `resources/js/types/auth.ts` and the conditional badge to `user-menu-content.tsx`, green.
8. **Requirement 7 — `tenants:artisan migrate` propagation** — write a feature test that creates a tenant via `createQuietly()`, manually points the tenant connection, runs `Artisan::call('tenants:artisan', ['artisanCommand' => 'migrate', '--tenant' => $tenant->id])`, then asserts the 5 tables exist. Run red, confirm Spatie's `MigrateTenantAction` picks up the published migrations on existing tenants, green.
9. **Doc update** — write a new §23 in `Arquitectura multitenencia aplicada.md` capturing the authorization model, the per-tenant replication decision, the role/permission seed values, and the rollback plan.

## Risks and Mitigations

- **Stale permission cache after seeding** — mitigated by calling `app()[PermissionRegistrar::class]->forgetCachedPermissions()` at the top of `TenantPermissionsSeeder::run()`, per the `laravel-permission-development` skill.
- **Spatie migrations running on the default connection during test setup** — harmless. The `TestCase` runs `migrate:fresh` on `null` (default) and re-runs the landlord path; the root `database/migrations/` is the source, and the same set is also applied to the `tenant` connection by `Tenant::runMigrations()`. Schema is identical, so duplicate work is wasted cycles, not corruption.
- **User model query hitting the wrong connection** — `User` already uses `UsesTenantConnection`; the Spatie `HasRoles` trait's relationships respect the model's connection. Verified by the `cross_tenant_authorization_isolation` test.
- **Browser test selector breakage on component refactor** — mitigated by using `data-testid="user-role-badge"`, the top-priority stable selector per browser-testing §3.5. The badge content is "Admin" (asserted via `assertSeeIn` rather than `assertSee` on a generic text).
- **Cross-tenant authorization leak** — physical isolation per tenant DB. Verified by the `test_cross_tenant_authorization_isolation` scenario (Requirement 1) and by the `Role::findByName` query implicitly going through the active tenant connection.
- **`HasRoles` adding eager-loading overhead** — acceptable for this slice. The `auth.user.roles` shared prop triggers one extra query per request; can be optimized later via `$user->loadMissing('roles')` if profiling shows it matters.
- **`User` `appends` array growth** — we are **not** adding `roles` to `$appends` to avoid conflicting with Spatie's `roles` relationship. The `roles` array is built explicitly in `HandleInertiaRequests::share()`.
- **CSRF on browser tests** — the badge test reads Inertia props, no POSTs, so `withoutMiddleware(ValidateCsrfToken::class)` is **not** required (browser-testing principle 1: no direct HTTP calls).

## Open Questions

None — all decisions resolved in the proposal/spec phase.
