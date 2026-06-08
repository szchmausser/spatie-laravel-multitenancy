# Design: 1.5G-buy-plan — Self-Service Plan Change

## Context

First consumer of the `change-plan` permission shipped in `1.5G.0-tenant-roles`. The slice adds two parallel write surfaces — a tenant-side `GET/POST /billing/change-plan` guarded by `$user->can('change-plan')`, and a landlord backdoor `POST /admin/tenants/{tenant}/subscription/change` guarded by `EnsureUserIsAdmin` — plus a shared mutation `App\Services\Billing\ChangePlanService::applyPlanChange(Subscription, Plan)` that both controllers call. The shared service is the only piece that knows about the same-plan guard and the `ends_at` reset; the controllers differ only in authorization and how they resolve the target subscription.

Per project convention this is a full vertical: backend (controller pair, shared service, two routes), frontend (page, dialog component, "Change plan" link in the user menu), and tests (~8 new tests targeting the 8 requirements / 24 scenarios of the `plan-change` spec). The `plan-change` capability is new; no existing capability is modified. The read-path entitlement behavior on downgrade is already correct (`ResourceController::userCanAccess` rules 1-3 plus `EnsureTenantHasFeature`), so no `Entitlement` rows are mutated.

## Goals

- Wire the `change-plan` permission (delivered by `1.5G.0`) to a usable UI: a page listing available plans minus the current one, and a confirmation dialog.
- Provide a landlord-side escape hatch that does NOT consult the tenant `change-plan` permission (the landlord DB has no Spatie tables per §23.1 of the architecture doc).
- Make the plan change immediate and simple: `abort_if` same-plan guard, `update` with `plan_id` and `ends_at` reset to `now()->addMonth()`, `trial_ends_at` untouched. No explicit transaction or row lock needed — PHP-FPM request isolation is sufficient for this non-payment use case.
- Prove end-to-end that the read-path gate blocks premium-only routes on downgrade without any new entitlement-management code.

## Non-Goals

Payment gateway, refunds, prorated credits, scheduled changes, grace periods, cooldowns, audit log, status transitions (Expired, Cancelled), `1.5G.1-landlord-roles`, and any mutation of `Entitlement` rows on plan change. All deferred to Phase 2 / `1.5H` / by design.

## Architecture Overview

```
Tenant side (subdomain, tenant DB):
  User menu Link -> route('billing.change-plan.show')
    -> Billing\ChangePlanController::show (Inertia render)
       -> ChangePlanDialog (useForm POST)
          -> Billing\ChangePlanController::update
             -> Gate::allows('change-plan')  | abort 403
             -> same-plan guard             | abort 422
   -> ChangePlanService::applyPlanChange
                   -> abort_if same plan | update
              -> back()->with('success', ...)

Landlord side (landlord domain, landlord DB):
  Tenant show page "Change plan" form
    -> POST admin/tenants/{tenant}/subscription/change
       -> Landlord\ChangePlanController::update
          -> EnsureUserIsAdmin (route group, no Gate)
          -> ChangePlanService::applyPlanChange
          -> redirect landord.tenants.show with success flash
```

Authorization diverges at the controller level (permission-based vs. identity-based on the `Landlord` model). The mutation converges in a single service so the write — the same-plan guard and the `ends_at` reset — has exactly one implementation.

## Component Breakdown

| File | Kind | Purpose |
|------|------|---------|
| `app/Http/Controllers/Billing/ChangePlanController.php` | NEW Controller | Tenant. `show()` renders; `update(Plan $newPlan)` runs `Gate::allows('change-plan')`, the same-plan guard, calls the service. |
| `app/Http/Controllers/Landlord/ChangePlanController.php` | NEW Controller | Landlord. `update(Tenant, Plan)` runs the same-plan guard, calls the service. No `Gate` — `EnsureUserIsAdmin` middleware is the only auth. |
| `app/Services/Billing/ChangePlanService.php` | NEW Service | `applyPlanChange(Subscription, Plan): void` — same-plan `abort_if` + `plan_id`/`ends_at` update. Simple inline validation, no explicit transaction or row lock. First file under `app/Services/`; establishes the "shared mutations in a service" convention. |
| `routes/web.php` | MODIFIED | Adds `prefix('billing')->name('billing.')` group with `GET/POST billing/change-plan` (`billing.change-plan.show` / `.update`), inside the existing `tenant + auth + verified` group. |
| `routes/landlord.php` | MODIFIED | Adds `POST admin/tenants/{tenant}/subscription/change` (`landlord.subscriptions.change`) inside the existing `auth + verified + EnsureUserIsAdmin` group. Distinct from the existing `landlord.subscriptions.assign` (initial assignment vs. mid-life switch). |
| `resources/js/pages/billing/change-plan.tsx` | NEW Inertia page | Lists `availablePlans` minus `currentPlan`, renders one `ChangePlanDialog` per option. |
| `resources/js/components/billing/change-plan-dialog.tsx` | NEW React component | shadcn `Dialog` + `useForm({})`; POSTs to `route('billing.change-plan.update', { plan: plan.id })`; frozen `data-testid="change-plan-dialog-{planSlug}"` and `data-testid="change-plan-confirm-btn-{planSlug}"`. Mirrors `BuyResourceDialog` line-for-line (controlled-open, `useEffect` on `wasSuccessful`, `useEffect` on `!open` for `clearErrors` + `reset`). |
| `resources/js/components/user-menu-content.tsx` | MODIFIED | Adds a `Link` to `route('billing.change-plan.show')` inside the `DropdownMenuGroup`, visible only when `user.roles?.includes('tenant-admin')`. `data-testid="change-plan-link"`. |
| `resources/js/types/auth.ts` | (verify) | No change required (`roles: string[]` is already in `User` from `1.5G.0`); verification only. |
| `tests/Feature/Billing/ChangePlanControllerTest.php` | NEW Pest | 6 tests covering Requirements 1-7 (tenant side). Reuses the `pointTenantConnectionAtTestDatabase()` helper from `TenantPermissionsTest.php`. |
| `tests/Feature/Landlord/ChangePlanControllerTest.php` | NEW Pest | 2 tests for Requirement 6 (landlord succeeds, tenant user 403s). |
| `tests/Feature/Auth/TenantPermissionsTest.php` | MODIFIED | +1 test for the "downgrade blocks premium" scenario (Req 3 of the new spec) — proves the read-path gate works on downgrade with no new production code. |
| `tests/Browser/Billing/ChangePlanFlowTest.php` | NEW Pest | 2 browser tests: tenant-admin click-confirm; landlord change-plan from tenant show page. `actingAs()` + `data-testid` + `assertNoJavaScriptErrors()`. |
| `openspec/changes/1.5G-buy-plan/design.md` + `tasks.md` | NEW OpenSpec | This file + the next-phase work units. |

13 production + test files, 2 OpenSpec artifacts.

## Data Flow

### Tenant side

1. User opens the user menu. `user-menu-content.tsx` renders the "Change plan" `Link` only when `user.roles?.includes('tenant-admin')`.
2. `Billing\ChangePlanController::show()` resolves `Tenant::current()`, loads `Plan::query()->active()->get()`, and passes `availablePlans` + `currentPlan` to the Inertia view. The page filters out `currentPlan.id` and renders one `ChangePlanDialog` per remaining option.
3. User clicks "Change to {{ plan.name }}". `useForm` POSTs to `route('billing.change-plan.update', { plan: plan.id })` with `preserveScroll: true`.
4. `Billing\ChangePlanController::update(Plan $newPlan)` runs three checks in order:
   - `abort_unless($request->user()->can('change-plan'), 403)`
   - `abort_unless($newPlan->id !== $tenant->subscription->plan_id, 422, 'You are already on this plan.')`
   - `ChangePlanService::applyPlanChange($tenant->subscription, $newPlan)`
5. Redirect to `route('billing.change-plan.show')` with success flash.

### Landlord side

The tenant show page already has a "plan select" + "Assign" form wired to `landlord.subscriptions.assign` (the "no subscription" path). This slice adds a sibling "Change plan" form that POSTs to the new `landlord.subscriptions.change` (the "tenant already has a plan" path). The two coexist.

`Landlord\ChangePlanController::update(Tenant $tenant, Plan $newPlan)`:

- `EnsureUserIsAdmin` middleware in the route group — 403 on a tenant user.
- Resolve `$subscription = $tenant->subscription` (lazy via the `HasOne`).
- `abort_unless($newPlan->id !== $subscription->plan_id, 422, ...)`.
- `ChangePlanService::applyPlanChange($subscription, $newPlan)`.
- Redirect to `route('landlord.tenants.show', $tenant)` with success flash.

### Shared service

```php
namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;

class ChangePlanService
{
    public function applyPlanChange(Subscription $subscription, Plan $newPlan): void
    {
        abort_if(
            $subscription->plan_id === $newPlan->id,
            422,
            'You are already on this plan.',
        );

        $subscription->update([
            'plan_id' => $newPlan->id,
            'ends_at' => now()->addMonth(),
        ]);
    }
}
```

The service is intentionally simple: validate, then update. No explicit transaction or row lock — PHP-FPM processes one request per worker and the same-plan guard runs against the current model state. This is sufficient for a non-payment, non-proration plan change. If Phase 2 introduces a payment pipeline that requires atomic state across multiple tables, a `DB::transaction` (with or without `lockForUpdate()`) can be added at that point.

## TDD Order

Each step is red → green → refactor. Run `php artisan test --compact` between cycles. Final pass targets 213-216 tests, 0 failing (current: 206).

1. **`ChangePlanService` skeleton + same-plan guard.** Add `tests/Feature/Billing/ChangePlanControllerTest.php::test_post_with_current_plan_returns_422` first. Then add `App\Services\Billing\ChangePlanService` with `applyPlanChange()` and the same-plan `abort_if`. Test goes green by calling the service directly (no controller yet). Pins the guard contract before the controller exists.
2. **Tenant route 401.** `test_redirects_unauthenticated_user_to_login`. Green when the route is placed inside the `tenant + auth + verified` group.
3. **Tenant route 403 without permission.** `test_returns_403_for_user_without_change_plan`. Green when `Billing\ChangePlanController::update` runs `abort_unless($request->user()->can('change-plan'), 403)`.
4. **Tenant route 200 with permission.** `test_shows_change_plan_page_for_admin_excluding_current_plan`. Green when `show()` returns `Inertia::render('billing/change-plan', [...])` with `availablePlans` (filtered) + `currentPlan`.
5. **Tenant route 200 + DB write.** `test_post_updates_plan_id_and_resets_ends_at`. Green when `update()` resolves the subscription from `Tenant::current()` and calls the service.
6. **Cross-tenant isolation.** `test_user_on_tenant1_cannot_change_tenant2_plan`. Pin test — controller already resolves from the `tenant` middleware context.
7. **Landlord controller happy path.** `tests/Feature/Landlord/ChangePlanControllerTest.php::test_landlord_can_change_tenant_plan`. Green when the controller exists and the route is registered.
8. **Landlord controller 403 for tenant user.** `test_tenant_user_hitting_landlord_route_is_rejected`. Green when the route is in the `EnsureUserIsAdmin` group.
9. **Read-path gate proof on downgrade.** `test_downgrade_premium_to_free_blocks_premium_content` in `TenantPermissionsTest.php`. Uses a `premium-content`-gated route, simulates a plan change, asserts 403. No new production code — pins that the slice works with the existing read-path gate.
10. **Browser test: tenant click-confirm.** `test_tenant_admin_can_click_confirm_a_plan_change` in `ChangePlanFlowTest.php`. `actingAs()`, navigate to `billing.change-plan.show`, click `[data-testid="change-plan-confirm-btn-{planSlug}"]`, assert the new plan name is visible.
11. **Browser test: landlord change from admin panel.** `test_landlord_can_change_tenant_plan_from_admin_panel`. Mirrors `PlanSubscriptionBrowserTest::test_admin_can_assign_a_plan_to_a_tenant_from_the_tenant_detail_page`.

After each green, run `vendor/bin/pint --dirty --format agent` on the changed PHP files.

## Key Design Decisions and Tradeoffs

- **Two controllers, one service.** Keeping auth in each controller and the mutation in the service means the controller layer expresses the policy (`change-plan` for tenants, `EnsureUserIsAdmin` for landlords) without leaking into the database write. A single controller with conditional auth was rejected: the type system couldn't catch a route that forgot the right check, and a future refactor would re-derive the same branching. Cost: two small controllers. Benefit: auth intent is local to each call site.
- **No locking for this slice.** The initial implementation used `DB::transaction` + `lockForUpdate()` to serialize concurrent plan changes. During review, the lock was deemed unnecessary: PHP-FPM does not multiplex requests within a single worker, and there is no payment gateway, no proration, and no intermediate state that could corrupt. The service is now a simple `abort_if` + `update`. If Phase 2 introduces multi-table writes (e.g., payment ledger + subscription), a transaction with or without row lock should be reintroduced at that point.
- **No entitlement writes on plan change.** `Purchase` and `Direct` rows persist for the audit trail; the read-path gate (`ResourceController::userCanAccess` rule 2 + `EnsureTenantHasFeature`) does the right thing on downgrade without any new code. We add a one-line code comment on `ResourceController::userCanAccess` noting that rule-3 survival of `Purchase` rows is by design, so a future reader doesn't "fix" it.
- **`now()->addMonth()` not `addMonthNoOverflow()`.** Naive add matches the existing `EnsureDefaultSubscription` convention and is good enough for the first iteration. A 31st-of-the-month reset landing on a 28/29-day month is a known trade-off; if it bites, the fix is a column rename to `next_renewal_at` plus a service-level strategy.
- **`Billing\ChangePlanController` as a new namespace.** Phase 1 has no `Tenant::changePlan()` method, and adding one would force every controller to know the entire subscription model. The `Billing\ChangePlanController` namespace signals "this is about billing, not tenant lifecycle" and is forward-compatible with Phase 2 (a `Billing\CheckoutController` will sit next to it).
- **Distinct landlord route `landlord.subscriptions.change`, not a refactor of `assign`.** The existing `landlord.subscriptions.assign` is the "create or replace" path used during tenant provisioning. The new `landlord.subscriptions.change` is the "mid-life switch" path; it shares the service but the URL, name, and intent are different.

## Risks and Mitigations

- **Entitlement "leak" on downgrade** (Low). `Purchase` and `Direct` rows survive a `premium → free` change and keep granting access (rule 3 in `userCanAccess`). **By design** — acquired rights are permanent. Add a one-line comment on `ResourceController::userCanAccess` so future readers don't delete the rows.
- **Stale permission cache on tenant switch** (Med, already mitigated). `TenantPermissionsSeeder` already calls `forgetCachedPermissions()`. No new mitigation in this slice.
- **Landlord route confused with tenant route** (Low). Two distinct prefixes (`admin/tenants/{tenant}/subscription/change` vs `billing/change-plan`) and two distinct route names prevent confusion. The 403 tests pin the contract.
- **Unnamespaced `change-plan` permission name** (Info). Per `1.5G.0`'s "refactor to `billing.*` at 3+ billing perms" note, the name stays as-is. This slice adds zero new perms.
- **Wayfinder regenerates the `routes/` tree** (Info). Adding the two routes triggers `php artisan wayfinder:generate` to write `resources/js/routes/billing/index.ts` and an entry in `landlord/subscriptions/index.ts`. Include the regen command in the apply-phase setup script.
- **Browser test selector drift** (Low). The dialog uses `data-testid="change-plan-dialog-{planSlug}"` and `data-testid="change-plan-confirm-btn-{planSlug}"`, both stable across refactors (per browser-testing §3.5). The browser test asserts the dialog `data-testid` by slug, not by content, so a copy edit doesn't break the test.
- **`Subscription::on('landlord')` resolution in the service** (Info). The `Subscription` model uses `UsesLandlordConnection`, so the same instance is readable from both tenant and landlord contexts. The service does not call `->on('landlord')` explicitly — the model does that itself.

## Open Questions

None — all decisions resolved in the proposal/spec phase. The slice re-uses every existing convention (route group structure, dialog pattern, test layout, Spatie authorization, read-path gate) and the only genuinely new file is `ChangePlanService`. If the user wants the success flash message format to be reviewed before implementation, the recommendation is `"Your plan has been changed to {planName}."` on the tenant side and `"Plan changed to {planName} for tenant {tenantName}."` on the landlord side — both match the existing `back()->with('success', ...)` pattern.

## Out of Scope

Reaffirmed: payment gateway, refunds, prorated credits, scheduled changes, grace periods, cooldowns, audit log, subscription status transitions (`Expired`/`Cancelled`), `1.5G.1-landlord-roles` (landlord Spatie permissions), and any mutation of `Entitlement` rows on plan change. All explicit out-of-scope in the proposal; not introduced by this slice.
