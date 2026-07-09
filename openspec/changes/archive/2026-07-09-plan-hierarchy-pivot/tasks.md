# Tasks: Plan-Hierarchy-Pivot

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~9 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | size-exception |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

## Phase 1: Core Implementation — Pivot Lookup Replacement

- [x] T1: **Modify `userCanAccess()` in `ResourceController.php`** (lines 193-196)
  - **Before**: `$resource->plans()->where('price_cents', '<=', $tenant->subscription->plan->price_cents)->exists()`
  - **After**: `$tenant->subscription->plan->resources()->where('resource_id', $resource->id)->exists()`
  - **Assert**: `is_premium || plans()->exists()` guard above is unchanged. `tenantHasExplicitEntitlement()` fallthrough is unchanged.

- [x] T2: **Modify `serializeResource()` `is_included_in_plan` in `ResourceController.php`** (lines 248-252)
  - **Before**: `$r->plans()->where('price_cents', '<=', $tenant->subscription->plan->price_cents)->exists()`
  - **After**: `$tenant->subscription->plan->resources()->where('resource_id', $r->id)->exists()`
  - **Assert**: `included_in_plan_names` (lines 253-256) and `has_plans_assigned` (line 241) are NOT modified.

- [x] T3: **Modify `$isIncludedInPlan` in `ShopController.php`** (lines 42-46)
  - **Before**: `$r->plans()->where('price_cents', '<=', $currentPlan->price_cents)->exists()`
  - **After**: `$currentPlan->resources()->where('resource_id', $r->id)->exists()`
  - **Assert**: `$hasPlansAssigned` (line 48) and `included_in_plan_names` (lines 63-66) are NOT modified.

## Phase 2: Verification

- [x] T4: **Run 4 test suites to verify no regressions**
  1. `php artisan test --compact --filter="Tests\\Feature\\Premium\\ResourceAccessTest"` — verify userCanAccess branches (9 tests)
  2. `php artisan test --compact --filter="Tests\\Feature\\Tenant\\ShopControllerPivotTest"` — verify shop serialization (3 tests)
  3. `php artisan test --compact --filter="Tests\\Feature\\Resource\\ResourceControllerTest"` — verify resource controller (21 tests)
  4. `php artisan test --compact --filter="Tests\\Feature\\Landlord\\ResourceControllerTest"` — verify landlord controller (16 tests)
  - **Assert**: All 49 tests pass. If any fail, investigate test data setup — pivot mapping may differ from price comparison result.
  - **Note**: Pre-existing test environment issues (unique constraint violations, missing tables unrelated to this change) may cause failures. Run `git diff --stat` to confirm only the 2 expected files changed (~9 lines).

## Unchanged Areas (must NOT modify)

| Location | File | Reason |
|----------|------|--------|
| `included_in_plan_names` | Both controllers | Lists ALL plans for the resource (informational) — must stay unfiltered |
| `has_plans_assigned` | Both controllers | Boolean check for ANY plan assignment — badge logic |
| `tenantHasExplicitEntitlement()` | ResourceController | Direct purchase entitlement — unrelated |
| `ResourcesSeeder.php` | `database/seeders/` | Already uses `syncWithoutDetaching()` — correct |
| All frontend files | `resources/js/` | Already consumes backend booleans correctly |
