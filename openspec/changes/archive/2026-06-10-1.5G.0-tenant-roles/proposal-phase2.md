# Proposal: Phase 2 — Tenant Roles & Permissions Management

## Intent

**Role-based access control for tenant users.** Phase 1 established basic user CRUD without authorization. Phase 2 adds role management with three roles (`owner`, `tenant-admin`, `member`), permission assignment, and UI for viewing/managing roles.

## Key Decisions

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | **First user created in a tenant gets `owner` role** (not `tenant-admin`) | The `owner` is the superadmin of the tenant — the only role that can create other `tenant-admin` and `member` users. The first user must have full control from the start. The `owner` role cannot be removed. |
| 2 | Fixed role set: `owner`, `tenant-admin`, `member` | Simplicity. Custom roles are deferred to a future slice. |
| 3 | Permissions defined in code only (no UI editor) | Reduces scope. All three roles map to hardcoded permission sets in the seeder. |

## Scope

### In Scope
- Expand `TenantPermissionsSeeder` with `owner`, `tenant-admin`, `member` roles and granular permissions
- New `RoleController` with index (list roles with permissions/users) and show (role detail)
- Role assignment/removal endpoints on `UserController` (assign/destroy actions)
- Authorization gates: only `owner` and `tenant-admin` can manage roles
- Self-protection: users cannot remove their own `owner` or `tenant-admin` role
- Update user index/show pages to display current roles
- New role index/show pages with permission matrix and user lists
- Feature tests for role management and authorization

### Out of Scope
- Landlord-level role management (separate slice)
- Custom role creation (fixed role set)
- Permission editing UI (permissions defined in code only)
- User invitation flow (future slice)
- Bulk role assignment (future slice)

## Capabilities

### New Capabilities
- `tenant-role-management`: Role listing, detail view, role assignment/removal with authorization gates

### Modified Capabilities
- `tenant-user-crud`: Add role display to user pages, role assignment endpoints

## Approach

**Seeder expansion**: Add `owner` and `member` roles to `TenantPermissionsSeeder`. Define permissions per role:
- `owner`: all permissions (full control)
- `tenant-admin`: `manage-users`, `change-plan`
- `member`: basic read access

**First-user auto-assignment**: When a new tenant is provisioned (seeder) or the first user signs up via `UserController::store`, they automatically receive the `owner` role. This is the critical bootstrap — without an `owner`, no one can create other users or admins. The logic:
- Seeder: attach `owner` role to the first user created during tenant setup
- `UserController::store`: check if tenant has zero users → assign `owner`; otherwise follow explicit role assignment

**Controller**: New `RoleController` for role index/show. Extend `UserController` with `assign` and `destroy` (role removal) actions.

**Authorization**: Gate checks in controllers:
- Role management: `owner` or `tenant-admin` only
- Self-protection: prevent removing own `owner`/`tenant-admin` role
- Last admin: allow removal (owner role cannot be removed)

**UI**: 
- Role index: table with role name, permission count, user count
- Role show: permission list, users with that role
- User pages: role badges/columns

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/seeders/TenantPermissionsSeeder.php` | Modified | Add `owner`, `member` roles and permissions |
| `app/Http/Controllers/Tenant/RoleController.php` | New | Role index/show with authorization |
| `app/Http/Controllers/Tenant/UserController.php` | Modified | Add `assign`/`destroy` role actions; `store` auto-assigns `owner` to first user |
| `routes/web.php` | Modified | Add role routes |
| `resources/js/pages/roles/index.tsx` | New | Role listing page |
| `resources/js/pages/roles/show.tsx` | New | Role detail page |
| `resources/js/pages/users/index.tsx` | Modified | Add role column |
| `resources/js/pages/users/show.tsx` | Modified | Add role badges |
| `tests/Feature/Tenant/RoleManagementTest.php` | New | Role management tests |
| `tests/Feature/Tenant/RoleAuthorizationTest.php` | New | Authorization gate tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Stale permission cache on role changes | Med | Call `forgetCachedPermissions()` after role mutations |
| Self-protection bypass attempts | Low | Server-side validation, not just UI hiding |
| Owner role removal attempt | Low | Explicit check: reject owner role removal always |
| Cross-tenant role access | Low | Tenant scoping via `UsesTenantConnection` |
| First-user check race condition | Low | Use DB transaction + user count check in store |

## Rollback Plan

1. Revert seeder changes (remove `owner`, `member` roles)
2. Remove `RoleController` and role routes
3. Revert `UserController` changes (remove role actions)
4. Remove role UI pages
5. Remove role tests

## Dependencies

- Phase 1 (tenant-user-crud) — existing user CRUD and Spatie setup
- `spatie/laravel-permission` — already installed

## Success Criteria

- [ ] Seeder creates `owner`, `tenant-admin`, `member` roles with correct permissions
- [ ] Seeder assigns `owner` role to the first user created during tenant setup
- [ ] `UserController::store` assigns `owner` role to the first user in a tenant (zero users → owner)
- [ ] Subsequent users get assigned role explicitly (no auto-owner for 2nd, 3rd, etc.)
- [ ] Only `owner`/`tenant-admin` can assign/remove roles
- [ ] Users cannot remove their own `owner`/`tenant-admin` role
- [ ] Owner role cannot be removed from any user
- [ ] Role index shows roles with permission count and user count
- [ ] Role show displays permissions and users with that role
- [ ] User pages display current roles
- [ ] All tests pass (role management + authorization)
