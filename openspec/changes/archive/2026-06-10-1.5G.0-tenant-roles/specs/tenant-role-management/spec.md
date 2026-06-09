# Spec: Tenant Role Management

**Change ID**: `1.5G.0-tenant-roles`
**Delta**: `specs/tenant-role-management/spec.md`
**Type**: NEW (no existing spec)

## Purpose

Role-based access control for tenant users. Defines three fixed roles (`owner`, `tenant-admin`, `member`), their permission sets, role assignment/removal with authorization gates, and UI for viewing roles and their permissions.

## Requirements

### Requirement: Tenant seeder creates owner, tenant-admin, and member roles with correct permissions

The system SHALL seed three roles per tenant via `TenantPermissionsSeeder`: `owner` (all permissions), `tenant-admin` (`manage-users`, `change-plan`), and `member` (basic read). The seeder MUST be idempotent.

| Role | Permissions |
|------|-------------|
| `owner` | `manage-users`, `change-plan`, plus any future permissions |
| `tenant-admin` | `manage-users`, `change-plan` |
| `member` | (none — read-only by default) |

#### Scenario: Fresh tenant gets all three roles

- GIVEN a new tenant with empty authorization schema
- WHEN `TenantPermissionsSeeder` runs
- THEN roles `owner`, `tenant-admin`, `member` exist
- AND `owner` has `manage-users` and `change-plan` permissions
- AND `tenant-admin` has `manage-users` and `change-plan` permissions
- AND `member` has no permissions

#### Scenario: Re-seeding produces no duplicates

- GIVEN a tenant where `TenantPermissionsSeeder` has already run
- WHEN `TenantPermissionsSeeder` runs again
- THEN exactly one record exists for each role name
- AND no duplicate role or permission records exist

#### Scenario: Permission cache is flushed

- GIVEN `TenantPermissionsSeeder` runs on a tenant
- WHEN the seeder completes
- THEN `forgetCachedPermissions()` was called
- AND subsequent `$user->can()` checks reflect the seeded state

### Requirement: First user in a tenant is automatically assigned the owner role

When the first user is created in a tenant (via seeder or `UserController::store`), the system SHALL assign the `owner` role automatically. Subsequent users SHALL NOT receive any role by default.

#### Scenario: Seeder assigns owner to first user

- GIVEN a fresh tenant with seeded permissions
- WHEN `TenantUsersSeeder` creates users
- THEN the first user has exactly one role: `owner`

#### Scenario: UserController assigns owner to first user via store

- GIVEN a tenant with zero users
- WHEN a user is created via `UserController::store`
- THEN the new user is assigned the `owner` role

#### Scenario: Subsequent users get no default role

- GIVEN a tenant with at least one existing user
- WHEN a new user is created via `UserController::store`
- THEN the new user has no roles

#### Scenario: Re-seeding does not duplicate roles

- GIVEN a tenant where `TenantUsersSeeder` has already run
- WHEN `TenantUsersSeeder` runs again
- THEN the first user still has exactly one role: `owner`

### Requirement: Role index page lists all tenant roles

The system SHALL display a page listing all roles in the current tenant with role name, permission count, and user count.

#### Scenario: Authenticated user views role index

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to the roles index page
- THEN a table of all tenant roles is displayed
- AND each row shows role name, number of permissions, and number of users

#### Scenario: Roles are sorted consistently

- GIVEN a tenant with `owner`, `tenant-admin`, `member` roles
- WHEN the role index loads
- THEN roles appear in a consistent order (alphabetical or defined hierarchy)

### Requirement: Role detail page shows permissions and users

The system SHALL display a detail page for a specific role showing all permissions granted to it and all users assigned that role.

#### Scenario: Authenticated user views role detail

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a role's detail page
- THEN all permissions for that role are listed
- AND all users with that role are listed

#### Scenario: Non-existent role returns 404

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a role ID that does not exist
- THEN a 404 response is returned

### Requirement: Role assignment is restricted to owner and tenant-admin

Only users with the `owner` or `tenant-admin` role SHALL be able to assign roles to other users. Unauthenticated users and `member` users SHALL be denied.

#### Scenario: Owner assigns a role to a user

- GIVEN an authenticated user with the `owner` role
- WHEN the user assigns the `member` role to another user
- THEN the target user receives the `member` role

#### Scenario: Tenant-admin assigns a role to a user

- GIVEN an authenticated user with the `tenant-admin` role
- WHEN the user assigns the `member` role to another user
- THEN the target user receives the `member` role

#### Scenario: Member cannot assign roles

- GIVEN an authenticated user with only the `member` role
- WHEN the user attempts to assign a role to another user
- THEN a 403 forbidden response is returned

#### Scenario: Unauthenticated user cannot assign roles

- GIVEN no authenticated user
- WHEN a request is made to assign a role
- THEN the user is redirected to the login page

#### Scenario: Non-existent user returns 404

- GIVEN an authenticated user with the `owner` role
- WHEN the user attempts to assign a role to a user ID that does not exist
- THEN a 404 response is returned

### Requirement: Self-protection prevents users from downgrading their own role

A user SHALL NOT be able to remove their own `owner` or `tenant-admin` role. This prevents accidental lockout.

#### Scenario: Owner cannot remove their own owner role

- GIVEN an authenticated user with the `owner` role
- WHEN the user attempts to remove their own `owner` role
- THEN the operation is rejected with an error message
- AND the user retains the `owner` role

#### Scenario: Tenant-admin cannot remove their own tenant-admin role

- GIVEN an authenticated user with the `tenant-admin` role
- WHEN the user attempts to remove their own `tenant-admin` role
- THEN the operation is rejected with an error message
- AND the user retains the `tenant-admin` role

### Requirement: Owner role cannot be removed from any user

The `owner` role SHALL NOT be removable from any user, regardless of who performs the action. This is a hard constraint — the tenant must always have at least one owner.

#### Scenario: Tenant-admin cannot remove owner role from another user

- GIVEN an authenticated user with the `tenant-admin` role
- WHEN the user attempts to remove the `owner` role from another user
- THEN the operation is rejected with an error message

#### Scenario: Owner cannot remove owner role from another user

- GIVEN an authenticated user with the `owner` role
- WHEN the user attempts to remove the `owner` role from another user
- THEN the operation is rejected with an error message

### Requirement: Role removal is restricted to owner and tenant-admin

Only users with the `owner` or `tenant-admin` role SHALL be able to remove roles from other users.

#### Scenario: Owner removes a role from a user

- GIVEN an authenticated user with the `owner` role
- WHEN the user removes the `member` role from another user
- THEN the target user no longer has the `member` role

#### Scenario: Member cannot remove roles

- GIVEN an authenticated user with only the `member` role
- WHEN the user attempts to remove a role from another user
- THEN a 403 forbidden response is returned

### Requirement: Role operations are tenant-scoped

All role management operations SHALL be scoped to the authenticated user's tenant. Cross-tenant role access MUST NOT be possible.

#### Scenario: Cannot manage roles in another tenant

- GIVEN an authenticated user in tenant A
- WHEN the user attempts to assign a role to a user in tenant B
- THEN a 404 response is returned

### Requirement: Permission cache is cleared on role mutations

The system SHALL call `forgetCachedPermissions()` after any role assignment or removal to prevent stale permission state.

#### Scenario: Permission changes take effect immediately

- GIVEN a user has the `member` role
- WHEN an admin assigns `tenant-admin` to that user
- AND `forgetCachedPermissions()` is called
- THEN `$user->can('manage-users')` returns `true` immediately

### Requirement: Unauthenticated access to role endpoints

- GIVEN no authenticated user
- WHEN a request is made to any roles endpoint
- THEN the user is redirected to the login page

## Out of Scope

- Custom role creation (fixed three-role set)
- Permission editing UI (permissions defined in code only)
- Landlord-level role management
- Bulk role assignment
- User invitation flow
