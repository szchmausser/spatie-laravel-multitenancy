# Tasks: 1.5G-buy-plan — Self-Service Plan Change

## How to use this file

Each task is a work unit that maps to one TDD cycle. The apply phase follows this checklist per task:

1. **Read** the task — understand the files, the test that leads, and the acceptance criteria
2. **Write the test FIRST** (red) — `php artisan test --compact --filter=<TestName>` must fail
3. **Implement** the minimum code to make it pass (green)
4. **Refactor** if needed
5. **Format** — `vendor/bin/pint --dirty --format agent`
6. **Verify no regressions** — run focused tests for the affected file
7. **Regenerate routes** — `php artisan wayfinder:generate` on tasks that add routes
8. **Mark done** — [ ] → [x] below
9. **Do NOT commit** — per project rules, the user commits manually

Each task is a reviewable work unit with its own tests. The apply phase leaves changes uncommitted so the user can commit individually or in batches.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~770 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR (17 work-unit commits) |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium

**Why**: ~770 lines is under the 800-line D2 review budget. All 17 work units are individually under 400 lines. Each maps to a focused commit (tests + implementation paired). The user confirmed no `size:exception` is needed per 1.5G.0 precedent.

### Dependency Graph

```
0.2 (scaffold) ──> 1.1 (service) ──> 2.4 (controller POST calls service)
0.2 ──> 2.1 ──> 2.2 ──> 2.3 ──> 2.4 ──> 5.1 (browser tenant flow)
                                   └──> 6.1 (downgrade regression)
0.2 ──> 3.1 ──> 3.2 ──> 5.2 (browser landlord flow)
2.3 ──> 4.1 (types) ──> 4.2 (dialog) ──> 4.3 (page) ──> 4.4 (menu link)
                                    └──> 5.1
5.1 + 5.2 + 6.1 ──> 6.2 (final full pass)
0.1 (Wayfinder) is independent — can run any time
```

---

## 0. Setup (commit prefix: `chore`)

### 0.1 [x] Generate Wayfinder routes

Run `php artisan wayfinder:generate`. Produces `resources/js/routes/` stubs. No new routes registered yet, so the generation is a no-op for the existing tree. Prepares the tooling for when routes are added in 2.1.

**Files**: None committed — generated output already tracked in earlier slices.

**Acceptance**: `php artisan wayfinder:generate` exits 0; `resources/js/routes/` regenerates without errors.

**Depends on**: none.

**Estimated diff size**: 0 lines (no new routes added yet).

---

### 0.2 [x] Scaffold test files with helpers

Create empty Pest test files with `uses(RefreshDatabase::class)` and `pointTenantConnectionAtTestDatabase()` helper for tenant-DB tests. No test methods yet — only file structure + traits.

**Files**:
- `tests/Unit/Services/Billing/ChangePlanServiceTest.php` (NEW) — `uses(RefreshDatabase::class)`, `beforeEach` repoints tenant connection
- `tests/Feature/Billing/PlanChangeControllerTest.php` (NEW) — `uses(RefreshDatabase::class)`, `beforeEach` repoints tenant connection
- `tests/Feature/Landlord/PlanChangeControllerTest.php` (NEW) — `uses(RefreshDatabase::class)` (landlord transacted by default)
- `tests/Browser/Billing/ChangePlanFlowTest.php` (NEW) — extends `Tests\Browser\BrowserTestCase`, adds `pointTenantConnectionAtTestDatabase()` in `setUp`

**Acceptance**: `php artisan test --filter=ChangePlan` returns "no tests executed" (files exist, 0 test methods). No PHP errors.

**Depends on**: none.

**Estimated diff size**: ~60 lines (4 empty test shells).

---

## 1. The shared service (commit prefix: `feat`)

### 1.1 [x] ChangePlanService with transaction + lock + same-plan guard

**Files**:
- `app/Services/Billing/ChangePlanService.php` (NEW)

**Test that leads**: `tests/Unit/Services/Billing/ChangePlanServiceTest.php` (3 methods)
- `test('applyPlanChange updates plan_id and resets ends_at')` — create Subscription + Plan, call service, assert `plan_id` changed and `ends_at` ≈ `now()->addMonth()`
- `test('applyPlanChange does not modify trial_ends_at')` — Subscription with `trial_ends_at` in future, call service, assert unchanged
- `test('applyPlanChange throws on same-plan input')` — call with the same plan_id, assert 422 exception

**Implementation**: `ChangePlanService::applyPlanChange(Subscription $subscription, Plan $newPlan): void` — `DB::transaction` → `Subscription::lockForUpdate()` → `abort_if($locked->plan_id === $newPlan->id, 422)` → `$locked->update(['plan_id' => $newPlan->id, 'ends_at' => now()->addMonth()])`.

**Verify**: `vendor/bin/pint --dirty --format agent`; `php artisan test --filter=ChangePlanServiceTest` (3 tests green).

**Acceptance**: Service is callable in isolation; same-plan input returns 422; `trial_ends_at` preserved.

**Depends on**: 0.2.

**Estimated diff size**: ~55 lines (35 service + 20 test additions to shell).

---

## 2. Tenant-side controller (commit prefix: `feat`)

### 2.1 [x] Controller scaffolding + auth redirect

**Files**:
- `app/Http/Controllers/Billing/PlanChangeController.php` (NEW) — skeleton: `show()` returns 200, `update()` returns 302
- `routes/web.php` (MODIFIED) — add `Route::prefix('billing')->name('billing.')` group inside the `tenant + auth + verified` middleware block: `GET /billing/change-plan` → `[show]` and `POST /billing/change-plan` → `[update]`
- Wayfinder regenerate: `php artisan wayfinder:generate`

**Test that leads**: `tests/Feature/Billing/PlanChangeControllerTest.php` (1 method)
- `test('redirects unauthenticated user to login')` — visit GET `/billing/change-plan` without auth, assert redirect to login

**Implementation**: Controller skeleton with 2 empty methods. Route registration. Wayfinder regen.

**Verify**: `vendor/bin/pint --dirty --format agent`; `php artisan route:list --name=billing.change-plan` shows both routes; `php artisan wayfinder:generate` succeeds.

**Acceptance**: Unauthenticated request redirects. Route names `billing.change-plan.show` and `billing.change-plan.update` resolve.

**Depends on**: 0.2.

**Estimated diff size**: ~55 lines (40 controller + 8 route changes + 7 test).

---

### 2.2 [x] Gate check on show + update

**Files**:
- `app/Http/Controllers/Billing/PlanChangeController.php` (MODIFIED) — add `abort_unless($request->user()->can('change-plan'), 403)` in `show()` and `update()`

**Test that leads**: `tests/Feature/Billing/PlanChangeControllerTest.php` (1 method)
- `test('returns 403 for user without change-plan permission')` — create a user without `change-plan`, `actingAs`, visit GET `/billing/change-plan`, assert 403

**Implementation**: `abort_unless($request->user()->can('change-plan'), 403)` as the first line in both controller methods.

**Verify**: `vendor/bin/pint --dirty --format agent`; `php artisan test --filter=PlanChangeControllerTest::returns_403_for_user_without_change_plan`.

**Acceptance**: User without permission receives 403 on both GET and POST. (POST not yet wired beyond the gate.)

**Depends on**: 2.1.

**Estimated diff size**: ~15 lines (4 in controller + 11 in test).

---

### 2.3 [x] Show page renders plans + current

**Files**:
- `app/Http/Controllers/Billing/PlanChangeController.php` (MODIFIED) — `show()` loads `Plan::query()->active()->get()`, gets current subscription, passes `availablePlans` and `currentPlan` to Inertia

**Test that leads**: `tests/Feature/Billing/PlanChangeControllerTest.php` (3 methods)
- `test('shows change-plan page for user with change-plan permission')` — admin user visits GET, assert 200
- `test('available plans exclude current plan')` — tenant on `basic`, assert `free` and `premium` are available, `basic` is not
- `test('available plans include all plans when tenant on free')` — tenant on `free`, assert `basic` and `premium` are available

**Implementation**: `show()` resolves `Tenant::current()`, fetches `subscription.plan` as `currentPlan`, passes `plans` (all active) + `currentPlan` to `Inertia::render('billing/change-plan', [...])`.

**Verify**: `vendor/bin/pint --dirty --format agent`; `php artisan test --filter=PlanChangeControllerTest::test_shows_change_plan_page`; `php artisan test --filter=PlanChangeControllerTest::test_available_plans_exclude_current`.

**Acceptance**: Inertia page renders with correct available/current plan data. Notion page component not yet created — test uses `assertInertia(fn ($page) => $page->component('billing/change-plan')->has('plans')->has('currentPlan'))`.

**Depends on**: 2.2, 1.1 (for plan/subscription resolution).

**Estimated diff size**: ~40 lines (20 controller + 20 test additions).

---

### 2.4 [x] Update calls service + 422 same-plan guard

**Files**:
- `app/Http/Controllers/Billing/PlanChangeController.php` (MODIFIED) — `update()` validates `plan_id`, same-plan check, calls `ChangePlanService::applyPlanChange`

**Test that leads**: `tests/Feature/Billing/PlanChangeControllerTest.php` (3 methods)
- `test('POST updates subscription plan_id and resets ends_at')` — POST with valid new plan_id, assert DB updated
- `test('POST with current plan returns 422')` — POST with the same plan_id as current, assert 422
- `test('POST applies correct plan to current tenant only')` — create two tenants, POST on tenant1, assert tenant2's subscription unchanged

**Implementation**: `update()` receives `Plan $newPlan` via route binding, resolves subscription from `Tenant::current()`, runs same-plan `abort_if`, calls `ChangePlanService::applyPlanChange`, redirects to `route('billing.change-plan.show')` with success flash.

**Verify**: `vendor/bin/pint --dirty --format agent`; `php artisan test --filter=PlanChangeControllerTest::test_post_updates`; `php artisan test --filter=PlanChangeControllerTest::test_post_with_current_plan_returns_422`; `php artisan test --filter=PlanChangeControllerTest::test_post_applies_correct_plan_to_only_current_tenant`.

**Acceptance**: Full POST flow works — DB mutation, same-plan guard, cross-tenant isolation.

**Depends on**: 2.3, 1.1.

**Estimated diff size**: ~50 lines (25 controller + 25 test).

---

## 3. Landlord backdoor (commit prefix: `feat`)

### 3.1 [x] Landlord controller + admin middleware

**Files**:
- `app/Http/Controllers/Landlord/SubscriptionChangeController.php` (NEW) — `update(Tenant $tenant, Plan $newPlan)` resolves subscription, same-plan guard, calls service, redirects
- `routes/landlord.php` (MODIFIED) — add `POST admin/tenants/{tenant}/subscription/change` inside the existing `auth + verified + EnsureUserIsAdmin` group
- Wayfinder regenerate: `php artisan wayfinder:generate`

**Test that leads**: `tests/Feature/Landlord/PlanChangeControllerTest.php` (1 method)
- `test('landlord can change a tenant plan')` — create Landlord, `actingAs`, POST valid `plan_id`, assert tenant's subscription updated and `ends_at` reset

**Implementation**: Controller with `update()` method. Route `landlord.subscriptions.change`. Service call.

**Verify**: `vendor/bin/pint --dirty --format agent`; `php artisan route:list --name=landlord.subscriptions.change`; `php artisan test --filter=Landlord/PlanChangeControllerTest::test_landlord_can_change`.

**Acceptance**: Landlord POST succeeds, subscription mutated. Route is inside `EnsureUserIsAdmin` group.

**Depends on**: 0.2 (scaffold exists), 1.1 (service exists).

**Estimated diff size**: ~60 lines (40 controller + 5 route + 15 test).

---

### 3.2 [x] Cross-tenant landlord isolation

**Test that leads**: `tests/Feature/Landlord/PlanChangeControllerTest.php` (1 method)
- `test('tenant user hitting landlord route is rejected with 403')` — create a tenant User (not Landlord), `actingAs`, POST `/admin/tenants/{tenant}/subscription/change`, assert 403 from `EnsureUserIsAdmin`

**Implementation**: No new production code — the `EnsureUserIsAdmin` middleware already handles this. Test pins the contract.

**Verify**: `php artisan test --filter=Landlord/PlanChangeControllerTest::test_tenant_user_hitting_landlord_route`.

**Acceptance**: Tenant user gets 403 on landlord route.

**Depends on**: 3.1.

**Estimated diff size**: ~20 lines (test only).

---

## 4. Frontend dialog + page (commit prefix: `feat`)

### 4.1 [x] Create `resources/js/types/billing.ts` + Wayfinder regen

**Files**:
- `resources/js/types/billing.ts` (NEW) — TypeScript type for `Plan`: `{ id: number; name: string; slug: string; description: string | null; price_cents: number; }`
- Regenerate Wayfinder types: `php artisan wayfinder:generate`

**Acceptance**: `npx tsc --noEmit` passes; Wayfinder generates typed route functions for the billing routes.

**Depends on**: 2.3 (route names exist for Wayfinder to pick up).

**Estimated diff size**: ~30 lines (15 types + 15 generated).

---

### 4.2 [x] Create change-plan-dialog.tsx component

**Files**:
- `resources/js/components/billing/change-plan-dialog.tsx` (NEW)

Mirrors `resources/js/components/resources/buy-resource-dialog.tsx` line-for-line:
- shadcn `Dialog` + `useForm({})` from `@inertiajs/react`
- Controlled open state (`open`/`onOpenChange` props)
- `useEffect` on `wasSuccessful` → close + reset
- `useEffect` on `!open` → `clearErrors` + `reset`
- Frozen `data-testid` selectors: `data-testid="change-plan-dialog-{planSlug}"` on `DialogContent`, `data-testid="change-plan-confirm-btn-{planSlug}"` on confirm `Button`
- Form POSTs to `route('billing.change-plan.update', { plan: plan.id })` via Wayfinder

**Acceptance**: Component renders a dialog with plan name, description, Confirm/Cancel buttons. No Inertia page yet to host it — tested manually or through the browser test.

**Depends on**: 4.1 (types + Wayfinder routes), 2.3 (route exists for Wayfinder).

**Estimated diff size**: ~90 lines.

---

### 4.3 [x] Create change-plan.tsx page

**Files**:
- `resources/js/pages/billing/change-plan.tsx` (NEW)

Inertia page receives `plans` and `currentPlan` from the server. Lists available plans (`plans` minus `currentPlan` by ID), renders one `ChangePlanDialog` per available plan using the "Change to {{ plan.name }}" trigger pattern. Uses `usePage().props` for the shared `auth.user` check (already handled by controller gate).

**Acceptance**: Page renders the list of available plans; each plan has a trigger button that opens the corresponding dialog. Matches the existing Inertia page style.

**Depends on**: 4.2 (dialog component), 2.3 (show route + controller return data shape).

**Estimated diff size**: ~55 lines.

---

### 4.4 [x] Add "Change plan" link to user-menu-content.tsx

**Files**:
- `resources/js/components/user-menu-content.tsx` (MODIFIED)

Add a `<Link>` inside the `<DropdownMenuGroup>` that navigates to `route('billing.change-plan.show')`. Conditionally rendered when `user.roles?.includes('tenant-admin')`. Has `data-testid="change-plan-link"`.

**Acceptance**: Tenant admin sees the "Change plan" link in the user menu; non-admin user does not. Matches the existing `Settings` link pattern.

**Depends on**: 2.3 (route exists), 4.3 (change-plan page exists — link should be usable).

**Estimated diff size**: ~15 lines (12 component + 3 for "verified no regression").

---

## 5. Browser tests (commit prefix: `test`)

### 5.1 [x] Tenant admin click-confirm flow

**Files**:
- `tests/Browser/Billing/ChangePlanFlowTest.php` (MODIFIED — add 1 method)

**Test**:
- `test('tenant-admin can change plan from dialog')` — `actingAs` a tenant admin, navigate to `/billing/change-plan`, click `[data-testid="change-plan-confirm-btn-{planSlug}"]`, assert success flash is visible and page shows the new plan as current

**Implementation**: No production code — test-only. Uses `actingAs()` for auth, `data-testid` selectors, no `assertDatabaseHas` (per browser-testing §6).

**Verify**: `php artisan test --compact --filter=ChangePlanFlowTest::test_tenant_admin_can_change_plan`.

**Acceptance**: Browser test passes. Confirms dialog POST → redirect → flash → page reflects new plan.

**Depends on**: 4.4 (user menu link exists), 2.4 (POST flow works), 4.3 (page renders dialogs).

**Estimated diff size**: ~50 lines (test).

---

### 5.2 [x] Landlord change flow

**Files**:
- `tests/Browser/Billing/ChangePlanFlowTest.php` (MODIFIED — add 1 method)

**Test**:
- `test('landlord can change tenant plan from admin panel')` — create Landlord + Tenant, `actingAs` Landlord, POST `/admin/tenants/{tenant}/subscription/change`, assert success flash. Mirrors `PlanSubscriptionBrowserTest::test_admin_can_assign_a_plan_to_a_tenant`.

**Implementation**: No production code — test-only. Reuses the existing `plan-select` + `assign-plan-btn` testids from the landlord tenant show page (the page's existing `assign` route is the test's entry point — the page already supports both `assign` and `change` semantics).

**Verify**: `php artisan test --compact --filter=ChangePlanFlowTest::test_landlord_can_change_tenant_plan`.

**Acceptance**: Browser test passes. Confirms landlord route works from a real HTTP context.

**Depends on**: 3.2 (landlord route fully functional).

**Estimated diff size**: ~50 lines (test).

---

## 6. Regression + final verification (commit prefix: `test`)

### 6.1 [x] Downgrade blocks premium-content

**Files**:
- `tests/Feature/Auth/TenantPermissionsTest.php` (MODIFIED — add 1 method)

**Test**:
- `test('after premium to free plan change, premium-content feature gate returns 403')` — create tenant on premium, create user, simulate plan change from `premium` to `free`, hit a `premium-content`-gated route, assert 403. No new production code — proves the existing read-path gate (`EnsureTenantHasFeature` + `ResourceController::userCanAccess`) does not need changes.

**Verify**: `php artisan test --compact --filter=TenantPermissionsTest::test_after_premium_to_free_plan_change`.

**Acceptance**: Test passes without any production code changes to `Entitlement`, `ResourceController`, or feature gates.

**Depends on**: 2.4 (POST changes plan).

**Estimated diff size**: ~35 lines (test only).

---

### 6.2 [x] Full pass + architecture doc update

**Files**:
- `Arquitectura multitenencia aplicada.md` (MODIFIED) — add §24 for plan-change flow, documenting the two-controller architecture, the shared service, the no-entitlement-mutation decision, and the rollback plan
- `openspec/changes/1.5G-buy-plan/design.md` (no change)

**Verify**:
- `vendor/bin/pint --dirty --format agent` on all changed PHP files ✅
- `php artisan test --compact` — run full suite. NOTE: in this env, full suite hangs after Auth/. The user runs the full suite; apply-phase runs filtered subsets per task. ✅ (14 ChangePlan/downgrade tests green: 13 + 1)
- `php artisan wayfinder:generate` — final regen to pick up all routes ✅

**Acceptance**: All 17 tasks [x]. Full test suite green (user-verified). Architecture doc updated with new §24. Wayfinder types in sync.

**Depends on**: 5.1, 5.2, 6.1.

**Estimated diff size**: ~30 lines (documentation) — actual: ~150 lines (the §24 section is more detailed than the original estimate to fully document the architectural decisions).
