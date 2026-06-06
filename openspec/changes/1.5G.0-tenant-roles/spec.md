# Spec: 1.5G.0-tenant-roles

## Purpose

Install `spatie/laravel-permissions` per tenant to answer "who can change this tenant's plan?" — the precondition slice for `1.5G-buy-plan`. Each tenant DB gets isolated authorization tables seeded with the `tenant-admin` role and `change-plan` permission, exposed to the frontend via Inertia shared props with a visible Admin badge.

## ADDED Requirements

### Requirement: Tenant has isolated authorization state

The system SHALL store authorization tables (`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`) in each tenant database, not in the landlord database.

#### Scenario: New tenant has all 5 Spatie tables

- GIVEN a new tenant is created via `Tenant::create()`
- WHEN the tenant's database schema is inspected
- THEN all 5 Spatie authorization tables exist with expected columns (`permissions.id`, `permissions.name`, `roles.id`, `roles.name`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`)

#### Scenario: Cross-tenant authorization isolation

- GIVEN two tenants (A and B) with their own databases
- WHEN a role is assigned to a user in tenant A
- THEN tenant B's authorization tables are unaffected
- AND the user in tenant B does not have that role

#### Scenario: Landlord has no Spatie tables

- GIVEN the landlord database schema is inspected
- WHEN checking for Spatie authorization tables
- THEN `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` do NOT exist in the landlord database

### Requirement: Tenant permissions and roles are seeded idempotently

The system SHALL seed permissions and roles for each tenant via a `TenantPermissionsSeeder` that is idempotent (safe to run multiple times). The seeder SHALL create the `change-plan` permission and the `tenant-admin` role with `change-plan` granted to it.

#### Scenario: First seeding creates role and permission

- GIVEN a fresh tenant with an empty authorization schema
- WHEN `TenantPermissionsSeeder` runs
- THEN exactly 1 role exists: `tenant-admin`
- AND exactly 1 permission exists: `change-plan`
- AND `tenant-admin` has the `change-plan` permission granted

#### Scenario: Second seeding produces no duplicates

- GIVEN a tenant where `TenantPermissionsSeeder` has already run
- WHEN `TenantPermissionsSeeder` runs again
- THEN there is exactly 1 `tenant-admin` role
- AND exactly 1 `change-plan` permission
- AND no duplicate records exist

#### Scenario: Seeder flushes permission cache

- GIVEN `TenantPermissionsSeeder` runs on a tenant
- WHEN the seeder completes
- THEN `app()[PermissionRegistrar::class]->forgetCachedPermissions()` was called
- AND subsequent `$user->can('change-plan')` checks reflect the freshly seeded state

#### Scenario: Role-to-permission mapping is correct

- GIVEN `TenantPermissionsSeeder` has run
- WHEN `Role::findByName('tenant-admin')` is resolved
- THEN the role has the `change-plan` permission via `givePermissionTo`
- AND the permission is accessible through `getPermissionNames()`

### Requirement: First tenant user is automatically assigned the tenant-admin role

When `TenantUsersSeeder` creates users for a tenant, the first user SHALL be assigned the `tenant-admin` role using `syncRoles(['tenant-admin'])` (idempotent).

#### Scenario: First user has tenant-admin role

- GIVEN a fresh tenant with permissions seeded
- WHEN `TenantUsersSeeder` runs and creates users
- THEN the first user has exactly one role: `tenant-admin`

#### Scenario: Re-seeding does not duplicate roles

- GIVEN a tenant where `TenantUsersSeeder` has already run
- WHEN `TenantUsersSeeder` runs again
- THEN the first user still has exactly one role: `tenant-admin`
- AND no duplicate role assignments exist

#### Scenario: Other users do not have tenant-admin

- GIVEN a tenant with multiple users created by `TenantUsersSeeder`
- WHEN checking roles for any user after the first
- THEN those users do NOT have the `tenant-admin` role

### Requirement: Authorization is checked via Spatie's can() method

The system SHALL use the `change-plan` permission (not a direct role check) for authorization. The `User` model SHALL use the `HasRoles` trait from `Spatie\Permission\Traits\HasRoles`.

#### Scenario: User with tenant-admin passes can()

- GIVEN a user with the `tenant-admin` role (which has `change-plan` granted)
- WHEN `$user->can('change-plan')` is evaluated
- THEN it returns `true`

#### Scenario: User without tenant-admin fails can()

- GIVEN a user with no roles
- WHEN `$user->can('change-plan')` is evaluated
- THEN it returns `false`

#### Scenario: Revoked permission returns false even with role

- GIVEN a user with the `tenant-admin` role
- AND the `change-plan` permission is revoked from the role
- WHEN `$user->can('change-plan')` is evaluated
- THEN it returns `false`

### Requirement: Current user's roles are exposed to the frontend via Inertia shared props

The system SHALL expose the authenticated user's roles (an array of role name strings) via `HandleInertiaRequests` middleware as `$page.props.auth.user.roles`. The `auth.user` TypeScript type SHALL include `roles: string[]`.

#### Scenario: Tenant-admin user sees roles in shared props

- GIVEN a user with the `tenant-admin` role is authenticated
- WHEN the Inertia page loads
- THEN `$page.props.auth.user.roles` contains `['tenant-admin']`

#### Scenario: User without roles sees empty array

- GIVEN a user with no roles is authenticated
- WHEN the Inertia page loads
- THEN `$page.props.auth.user.roles` is `[]`

#### Scenario: TypeScript type enforces roles shape at build time

- GIVEN `resources/js/types/auth.ts` defines the User type
- WHEN TypeScript compiles
- THEN the `User` type includes `roles: string[]`
- AND `HandleInertiaRequests` shares `roles` as part of `auth.user`

### Requirement: User menu shows an Admin badge for tenant admins

The system SHALL render a small "Admin" badge in `resources/js/components/user-menu-content.tsx` when the authenticated user has the `tenant-admin` role. The badge SHALL have `data-testid="user-role-badge"` as a stable selector. The badge SHALL NOT appear for users without the `tenant-admin` role.

#### Scenario: Badge visible for tenant-admin (browser test — principles 1, 3, 5)

- GIVEN a tenant-admin user is authenticated via `actingAs()` (principle 3: auth is precondition, not behavior under test)
- WHEN the browser visits a page and opens the user menu
- THEN an element with `data-testid="user-role-badge"` is visible
- AND the badge text contains "Admin"
- AND no HTTP calls are made to verify auth state (principle 1: no direct HTTP)
- AND the test uses `data-testid` selector, not text-based or CSS class (browser-testing §3.5)

#### Scenario: Badge hidden for non-admin user (browser test — principles 1, 3, 7)

- GIVEN a user without `tenant-admin` role is authenticated via `actingAs()`
- WHEN the browser visits a page and opens the user menu
- THEN the element with `data-testid="user-role-badge"` is NOT visible
- AND the test asserts the badge absence, not just route arrival (principle 7: test must verify concrete outcome)
- AND no `assertDatabaseHas` is used (browser test prohibition from §6 of browser-testing skill)

### Requirement: tenants:artisan migrate propagates Spatie permission tables to existing tenants

The system SHALL ensure that running `php artisan tenants:artisan migrate` on existing tenants creates the Spatie permission tables. Tenants created after Spatie is installed SHALL get the tables automatically via `Tenant::creating` callback.

#### Scenario: Pre-existing tenant gets tables after migrate

- GIVEN a tenant created before Spatie was installed
- WHEN `php artisan tenants:artisan migrate` runs
- THEN the tenant database contains all 5 Spatie authorization tables

#### Scenario: New tenant gets tables via creating callback

- GIVEN Spatie migrations are published to `database/migrations/`
- WHEN a new tenant is created via `Tenant::create()`
- THEN the tenant database contains all 5 Spatie authorization tables without manual migration

## Out of Scope

- UI to invite users, grant/revoke permissions within a tenant (next per-tenant slice)
- Landlord-side Spatie Permissions (`1.5G.1-landlord-roles`, separate future slice)
- The actual `1.5G-buy-plan` feature that will consume this precondition
- The `PaymentGatewayInterface` and the `Tenant::upgradeTo()` method (Phase 2 / `1.5G-buy-plan`)
- `subscriptions` table changes (`1.5G/H`), `SubscriptionStatus::Expired` (`1.5H`)
