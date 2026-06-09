# Proposal: Tenant User Management (Phase 1)

## Intent

Tenants have no way to view, create, or manage users within their own tenant. The only user lifecycle is self-registration (Fortify), with the first user auto-assigned `tenant-admin`. This slice adds tenant-side user CRUD so admins can invite and manage team members.

## Scope

### In Scope
- **List users** — index page with table, search, pagination
- **Create user** — form with name, email, password
- **Edit user** — form with name, email (password optional)
- **Show user** — detail page with user info
- **Delete user** — with confirmation dialog
- **Authorization** — only `tenant-admin` role can access user management
- **Sidebar** — "Users" link for `tenant-admin`
- **Tests** — feature tests for all CRUD operations + authorization

### Out of Scope
- Role/permission management UI (Phase 2)
- Role assignment/unassignment (Phase 2)
- Landlord user management (Phase 2)
- User invitation flow (future slice)
- Bulk operations (future slice)

## Capabilities

### New Capabilities
- `tenant-user-crud`: Tenant-scoped user management (list, create, read, update, delete) gated by `tenant-admin` role

### Modified Capabilities
None — no existing spec covers this.

## Approach

**Controller**: `App\Http\Controllers\Tenant\UserController` — resource controller inside tenant middleware group. Authorization via `$user->hasRole('tenant-admin')` at the top of each method (not middleware, to match existing patterns like `PlanChangeController`).

**Routes**: `Route::resource('users', UserController::class)` inside the existing `['tenant', 'auth', 'verified']` group in `routes/web.php` section 3.

**Frontend**: Pages under `resources/js/pages/users/` (tenant-scoped path). Index, create, edit, show pages. Use existing UI components (Badge, Button, data-testid pattern).

**Sidebar**: Add "Users" link for tenant-admin users in `app-sidebar.tsx`, gated by `auth.user.roles.includes('tenant-admin')`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Tenant/UserController.php` | New | Resource controller with 7 methods |
| `routes/web.php` | Modified | Add user routes to tenant group |
| `resources/js/pages/users/index.tsx` | New | User list page |
| `resources/js/pages/users/create.tsx` | New | Create user form |
| `resources/js/pages/users/edit.tsx` | New | Edit user form |
| `resources/js/pages/users/show.tsx` | New | User detail page |
| `resources/js/components/app-sidebar.tsx` | Modified | Add Users nav item for tenant-admin |
| `tests/Feature/Tenant/UserControllerTest.php` | New | CRUD + authorization tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Self-deletion (admin deletes own account) | Med | Guard: prevent self-deletion, show warning |
| Password handling in edit (blank = keep) | Med | Only update password when non-empty |
| Spatie cache stale after role checks | Low | `DB::purge('tenant')` in test setup |

## Rollback Plan

1. Remove `Tenant\UserController` and routes
2. Revert `app-sidebar.tsx` changes
3. Delete `resources/js/pages/users/` directory
4. Delete `tests/Feature/Tenant/UserControllerTest.php`

## Dependencies

- `1.5G.0-tenant-roles` (already shipped) — provides `tenant-admin` role and `HasRoles` trait on User model

## Success Criteria

- [ ] `tenant-admin` can list, create, edit, show, delete users
- [ ] Non-admin users cannot access user management (403)
- [ ] Users list shows pagination and search
- [ ] Create user assigns no role by default (admin assigns later in Phase 2)
- [ ] Edit user without password leaves password unchanged
- [ ] Self-deletion prevented with clear message
- [ ] All tests pass with `php artisan test --compact --filter=UserControllerTest`
