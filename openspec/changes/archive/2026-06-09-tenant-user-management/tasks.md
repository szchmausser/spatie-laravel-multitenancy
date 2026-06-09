# Tasks: Tenant User Management (Phase 1)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~620 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: Backend ~350 lines → PR 2: Frontend ~270 lines |
| Delivery strategy | ask-on-risk |
| Chain strategy | feature-branch-chain |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Backend CRUD + tests | PR 1 | Feature/tracker branch as base. Verifiable by tests alone. |
| 2 | Frontend pages + sidebar | PR 2 | Base = PR 1 branch. Depends on backend routes + controller. |

---

## Phase 1: Routing & Controller Foundation

- [x] 1.1 **Route + controller skeleton** — Add `Route::resource('users', UserController::class)` to `routes/web.php` section 3 tenant group. Create `app/Http/Controllers/Tenant/UserController.php` with all 7 empty methods returning Inertia/redirect stubs matching `ResourceController` pattern.
- [x] 1.2 **Test scaffold** — Create `tests/Feature/Tenant/UserControllerTest.php` with `beforeEach` tenant DB setup (purge tenant connection, create users on tenant connection). Add test for unauthenticated access (redirect to login) per spec.

## Phase 2: Backend CRUD (test-first per operation)

- [x] 2.1 **List users** — Write failing test: `GET /users` returns paginated list, search filters by name/email, tenant isolation. Implement `UserController::index` with `->paginate(15)`, `->where()` search on name/email, and tenant-scoped query.
- [x] 2.2 **Show user** — Write failing test: show returns user detail, 404 for non-existent/cross-tenant user. Implement `UserController::show` with route model binding scoped to tenant.
- [x] 2.3 **Create user** — Write failing tests: store with valid data succeeds, duplicate email rejected, missing/password-min-length rejected. Implement `UserController::store` with validation rules (name required|max:255, email required|unique:users|email, password required|min:8) and redirect to show.
- [x] 2.4 **Edit user** — Write failing tests: update name/email succeeds, blank password leaves unchanged, new password updates, duplicate email rejected on edit, 404 for cross-tenant. Implement `UserController::update` with conditional password update and unique-email-ignore-self rule.
- [x] 2.5 **Delete user** — Write failing tests: delete succeeds, self-deletion returns error (403/422), 404 for cross-tenant. Implement `UserController::destroy` with `$user->id !== auth()->id()` guard before delete.

## Phase 3: Frontend Pages

- [x] 3.1 **UserForm component** — Create `resources/js/components/tenant/user-form.tsx` matching `tenant-form.tsx` pattern: `mode`, `processing`, `errors`, `defaults` props. Fields: name (Input), email (Input), password (Input type=password, optional in edit mode). data-testid on all inputs.
- [x] 3.2 **Index page** — Create `resources/js/pages/users/index.tsx` with paginated table (name, email columns), search input filtering server-side via Inertia visit, pagination controls. Props: `users` (paginated collection with id/name/email).
- [x] 3.3 **Create page** — Create `resources/js/pages/users/create.tsx` wrapping `UserForm` with `Form` from `@inertiajs/react`, posting via Wayfinder `users.store`. Cancel link back to index.
- [x] 3.4 **Show page** — Create `resources/js/pages/users/show.tsx` displaying user name and email. Props: `user` (id, name, email). Links to edit and back to index.
- [x] 3.5 **Edit page** — Create `resources/js/pages/users/edit.tsx` wrapping `UserForm` with `Form`, posting via Wayfinder `users.update`. Pre-fill defaults from server. Cancel link to show page.

## Phase 4: Integration

- [x] 4.1 **Sidebar nav** — Add "Users" nav item (`/users`, `Users` icon) to `mainNavItems` in `resources/js/components/app-sidebar.tsx` for non-admin tenant users, gated behind `!isAdmin`. Import `Users` from `lucide-react`.
- [x] 4.2 **Full integration test** — Add test to `UserControllerTest` verifying the complete flow: create user → verify in index → edit → verify changes → delete → verify gone. Assert password unchanged on blank-password edit.
