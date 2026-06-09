# Tasks: Tenant Roles & Permissions Management

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 650–750 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (seeders+backend) → PR 2 (frontend) → PR 3 (tests) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Seeders + backend controllers + routes | PR 1 | Foundation; everything depends on this |
| 2 | Frontend: roles pages + user page role badges | PR 2 | Depends on PR 1 routes/controllers |
| 3 | Tests: RoleManagement + RoleAuthorization | PR 3 | Depends on PR 1+2; verifies full stack |

## Phase 1: Seeder Foundation

- [x] 1.1 Update `TenantPermissionsSeeder::PERMISSIONS` — add `manage-users` to array (M)
- [x] 1.2 Update `TenantPermissionsSeeder::ROLES_WITH_PERMISSIONS` — add `owner` (all perms), `member` (empty); keep `tenant-admin` (S)
- [x] 1.3 Update `TenantUsersSeeder::assignFirstUserRole` — change `syncRoles(['tenant-admin'])` to `syncRoles(['owner'])` (S)
- [x] 1.4 Run `php artisan test --compact --filter=TenantPermissionsSeeder` — verify seeder idempotency (S)

## Phase 2: Backend Controllers & Routes

- [x] 2.1 Create `app/Http/Controllers/Tenant/RoleController.php` — `index()` with `Gate::authorize('manage-users')`, eager-load `permissions` + `users` counts, render `roles/index` (M)
- [x] 2.2 Add `RoleController::show()` — load `permissions` + `users`, render `roles/show`, 404 on missing role (S)
- [x] 2.3 Add `UserController::assignRole()` — validate `role` exists, call `assignRole()`, flush cache, redirect (M)
- [x] 2.4 Add `UserController::removeRole()` — reject owner removal (any user), reject self-downgrade, call `removeRole()`, flush cache (L)
- [x] 2.5 Modify `UserController::store()` — wrap in `DB::transaction`, assign `owner` if `User::count() === 1` (M)
- [x] 2.6 Add routes to `routes/web.php` — `GET /roles`, `GET /roles/{role}`, `POST /users/{user}/roles`, `DELETE /users/{user}/roles/{role}` (S)

## Phase 3: Frontend Pages

- [x] 3.1 Create `resources/js/pages/roles/index.tsx` — table with role name, permissions_count, users_count; link to show (M)
- [x] 3.2 Create `resources/js/pages/roles/show.tsx` — permissions checklist (check/x icons), users list, back navigation (L)
- [x] 3.3 Modify `resources/js/pages/users/index.tsx` — add role column with Badge, update `UserRow` type to include `roles` (S)
- [x] 3.4 Modify `resources/js/pages/users/show.tsx` — add Roles card below user details, show role badges (S)
- [x] 3.5 Update `UserController::index()` — add `->with('roles')` eager load (S)
- [x] 3.6 Update `UserController::show()` — add `$user->load('roles')` (S)

## Phase 4: Testing

- [x] 4.1 Create `tests/Feature/Tenant/RoleManagementTest.php` — setup with `TenantPermissionsSeeder::runForCurrentConnection()` (S)
- [x] 4.2 Test role index: requires `manage-users`, shows all roles with counts, 403 for member (M)
- [x] 4.3 Test role show: requires `manage-users`, displays permissions + users, 404 for nonexistent (M)
- [x] 4.4 Test assign role: owner assigns member ✓, tenant-admin assigns ✓, member gets 403, unauthenticated → login redirect, 404 for nonexistent user (L)
- [x] 4.5 Test remove role: owner removes member ✓, tenant-admin removes ✓, member gets 403 (M)
- [x] 4.6 Test self-protection: owner cannot remove own owner role, tenant-admin cannot remove own tenant-admin role (M)
- [x] 4.7 Test owner immutable: tenant-admin cannot remove owner from another user, owner cannot remove owner from another user (M)
- [x] 4.8 Test first-user auto-owner: `store` with zero users → owner assigned; subsequent user → no role (M)
- [x] 4.9 Test permission cache: assign role → `$user->can()` reflects immediately after `forgetCachedPermissions()` (S)
- [x] 4.10 Run `php artisan test --compact` — all tests pass (S)
