# Proposal: 1.5G — Self-Service Plan Change (Buy Plan)

## Intent

**First consumer of the `change-plan` permission shipped in `1.5G.0-tenant-roles`.** Tenants whose users hold `change-plan` self-serve a plan change from the tenant UI; the landlord can also change a tenant's plan from the admin panel. Immediate effect, no proration, read path naturally blocks the feature gate on downgrade.

## Scope

### In Scope
- Tenant action: `GET/POST /billing/change-plan` guarded by `Gate::allows('change-plan')`. New `Billing\PlanChangeController::update()`.
- Landlord backdoor: `POST /admin/tenants/{tenant}/subscription/change` guarded by `EnsureUserIsAdmin` (no `Gate` call — permission tables absent from the landlord DB per §23.1). New `Landlord\SubscriptionChangeController::update()`.
- Shared mutation: `App\Services\Billing\ChangePlanService::applyPlanChange(Subscription, Plan)` — `DB::transaction` + `lockForUpdate()`, updates `plan_id` and resets `ends_at` to `now()->addMonth()`. Both controllers call it.
- New `resources/js/components/billing/change-plan-dialog.tsx` mirroring `buy-resource-dialog.tsx` (`useForm` + Wayfinder, frozen `data-testid`).
- "Change plan" link in `user-menu-content.tsx` for `user.roles.includes('tenant-admin')`.
- Tests: `tests/Feature/Billing/PlanChangeControllerTest.php` (~6) + `tests/Browser/Billing/ChangePlanFlowTest.php` (~2). Target: 206 → 213–216 passing.

### Out of Scope
- Payment gateway, payment UI, confirmation, refunds → Phase 2
- Scheduled plan changes, grace periods, cooldowns, audit log → 1.5H / Phase 2
- Landlord Spatie roles / `change-plan` for Landlord → `1.5G.1-landlord-roles` (deferred)
- Touching `Entitlement` rows on downgrade — not needed; `ResourceController::userCanAccess` already handles the read-path block. Flagged as a non-finding to prevent scope creep.

## Capabilities

### New Capabilities
- `plan-change`: self-service plan change for tenant users with `change-plan`; landlord backdoor bypassing the permission. Immediate effect, no proration, no entitlement write.

### Modified Capabilities
None.

## Approach

**Authorization = permission, tenant side only.** Two distinct controllers, two URL prefixes, two route names. Only the mutation is shared (isolated in `ChangePlanService`) so the auth concern stays in each controller.

**Immediate effect, no proration.** `subscriptions.plan_id` updates; `ends_at` resets to `now()->addMonth()`; `trial_ends_at` left untouched. Matches §22 "Option B" of the architecture doc. Phase 2 can add `prorated_credit_cents` without changing the data shape.

**Entitlements = read path, no write on plan change.** On downgrade premium → free, no `Entitlement` rows mutate. `EnsureTenantHasFeature::premium-content` 403s; `ResourceController::userCanAccess` falls through to rule 3 (no explicit row → false). `EntitlementGrantVia::Purchase` / `:Direct` with `expires_at IS NULL` stay permanent per `Entitlement::isValid()`. **No new entitlement-management code required** — single source of truth = the current plan.

**Idempotency + edge cases.** `lockForUpdate()` serialises concurrent POSTs; re-POSTing the same plan returns 422. Tenant on `free` sees only `basic` / `premium` (current-plan-excluded); expired tenants can still change (status is `1.5H`'s concern); tenant without a subscription row (shouldn't happen — `Tenant::created` calls `ensureDefaultSubscription()`) is treated as on the default plan.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Billing/PlanChangeController.php` | New | Tenant; `Gate::allows('change-plan')` |
| `app/Http/Controllers/Landlord/SubscriptionChangeController.php` | New | Landlord; `EnsureUserIsAdmin` |
| `app/Services/Billing/ChangePlanService.php` | New | Shared `applyPlanChange()` |
| `routes/web.php` / `routes/landlord.php` | Modified | `GET/POST billing/change-plan` + `POST admin/tenants/{tenant}/subscription/change` |
| `resources/js/components/billing/change-plan-dialog.tsx` | New | shadcn Dialog (mirrors `buy-resource-dialog.tsx`) |
| `resources/js/components/user-menu-content.tsx` | Modified | Conditional "Change plan" link for `tenant-admin` |
| `tests/Feature/Billing/PlanChangeControllerTest.php` + `tests/Browser/Billing/ChangePlanFlowTest.php` | New | ~8 tests total |

## Risks

- **Entitlement "leak" on downgrade** (Low): `Purchase`/`Direct` entitlements persist. **By design** (acquired rights are permanent); add a code comment on `ResourceController::userCanAccess` so future readers don't "fix" it.
- **Stale permission cache on tenant switch** (Med): already mitigated in `TenantPermissionsSeeder` (§23.3). Row lock is the second defence.
- **Landlord route confused with tenant route** (Low): distinct prefixes and names (`landlord.subscriptions.change` vs `billing.change-plan`).
- **Unnamespaced `change-plan`** (Info): matches `1.5G.0`; refactor to `billing.*` at 3+ billing perms.

## Rollback Plan

1. Remove the two new routes from `routes/web.php` and `routes/landlord.php`
2. Delete the two new controllers, `ChangePlanService`, the dialog component
3. Revert `user-menu-content.tsx`; delete the two new test files
4. Only DB write is `ChangePlanService::applyPlanChange` — removal reverts cleanly, no data corruption

## Dependencies

- `1.5G.0-tenant-roles` (shipped) — `change-plan` permission + `tenant-admin` role
- `1.5A` (shipped) — `Plan`, `Subscription`, `EnsureTenantHasFeature`, and the read-path gate in `ResourceController::userCanAccess`
- `1.5F-buy-flow` (shipped) — `BuyResourceDialog` pattern the new dialog mirrors

## Success Criteria

- [ ] `tenant-admin` sees and uses the "Change plan" action; non-admin users do not
- [ ] Confirming updates `subscriptions.plan_id` and resets `ends_at` to `now() + 1 month`; re-POSTing the same plan returns 422
- [ ] After downgrade premium → free, `premium-content` route returns 403; a user with a `Purchase` entitlement on a resource can still download it
- [ ] Landlord changes a tenant's plan from the admin panel; route does not consult `change-plan`
- [ ] Feature + browser tests green: 206 → 213–216 passing, 0 failing
