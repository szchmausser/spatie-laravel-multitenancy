# Tasks: Plan — Resource Mapping

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~550–600 (17 files) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (Backend) → PR 2 (Access) → PR 3 (Frontend) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Migration + Models + Landlord Controllers + Backend tests | PR 1 | ~180 lines. Foundation: pivot table, BelongsToMany, store/update with resource_ids/plan_ids |
| 2 | Tenant access logic + Serialization + TS types | PR 2 | ~180 lines. userCanAccess rewrite, is_included_in_plan in serializeResource + ShopController, types |
| 3 | Admin UI forms + Three-state cards + Browser tests | PR 3 | ~220 lines. Multi-select on plan/resource forms, index counts, shop/resources three-state cards |

## Phase 1: Migration & Models (Foundation)

- [ ] **1.1 Create `plan_resource` pivot migration** — `database/migrations/landlord/2026_06_30_000001_create_plan_resource_table.php` with `plan_id`/`resource_id` FK cascades + unique pair constraint. **R1**. No deps. Small. Test: migration runs, unique constraint enforced, cascade on delete.
- [ ] **1.2 Add `resources()` BelongsToMany to Plan model** — `app/Models/Plan.php`. **R2**. Depends: 1.1. Small. Test: factory asserts pivot rows returned.
- [ ] **1.3 Add `plans()` BelongsToMany to Resource model** — `app/Models/Resource.php`. **R2**. Depends: 1.1. Small. Test: factory asserts pivot rows returned.

## Phase 2: Landlord Controllers (Assignment)

- [ ] **2.1 Add `resource_ids` validation + sync to PlanController** — `app/Http/Controllers/Landlord/PlanController.php`: add `resource_ids`/`resource_ids.*` rules in store+update, call `$plan->resources()->sync(...)`. **R3**. Depends: 1.2. Small. Test: store with resources creates pivot rows, update syncs, empty is allowed, invalid ID rejected.
- [ ] **2.2 Add `plan_ids` validation + sync to Landlord ResourceController** — `app/Http/Controllers/Landlord/ResourceController.php`: add `plan_ids`/`plan_ids.*` rules in store+update, call `$resource->plans()->sync(...)`. **R4**. Depends: 1.3. Small. Test: store with plans creates pivot rows, update syncs replaces correctly.

## Phase 3: Tenant Access & Serialization

- [ ] **3.1 Rewrite `userCanAccess()` in tenant ResourceController** — `app/Http/Controllers/Resource/ResourceController.php`: replace `$tenant->hasFeature('premium-content')` with pivot check `plan->resources()->where('resource_id', $r->id)->exists()`. Logic order: free → plan-included → entitlement → denied. **R6 (R1 of spec 2), R10 (R5 of spec 2)**. Depends: 1.2. Medium. Test: 4 branches (free, plan-included, entitlement, denied), entitlement persists after plan change.
- [ ] **3.2 Add `is_included_in_plan` to `serializeResource()`** — `app/Http/Controllers/Resource/ResourceController.php:227-242`: add `is_included_in_plan` boolean. **R7 (R2 of spec 2)**. Depends: 3.1. Small. Test: flag true when resource in current plan, false when no subscription.

## Phase 4: Shop Serialization

- [ ] **4.1 Add `is_included_in_plan` + `has_entitlement` to ShopController** — `app/Http/Controllers/Tenant/ShopController.php`: replace inline `$hasEntitlement` with proper flag, add `is_included_in_plan` via pivot check. **R7 (R2 of spec 2)**. Depends: 1.2. Small. Test: serialized fields match plan membership, entitlement independent.

## Phase 5: TypeScript Types

- [ ] **5.1 Add `is_included_in_plan` to Resource type** — `resources/js/types/models.ts`: add `is_included_in_plan?: boolean` to `Resource`. **R7, R8, R9**. Depends: none (backend independent). Small.
- [ ] **5.2 Add `resources_count` to Plan type** — `resources/js/types/models.ts`: add `resources_count?: number` to `Plan`. **R5**. Depends: none. Small.

## Phase 6: Admin UI — Plan Form

- [ ] **6.1 Add resource multi-select to PlanForm** — `resources/js/components/landlord/plan-form.tsx`: add `resources` prop + `selectedResourceIds` state + multi-select UI. **R5 (R5 of spec 1)**. Depends: 5.2. Medium. Test: render shows multi-select, pre-selected on edit.
- [ ] **6.2 Wire resources into Plan create/edit pages** — `resources/js/pages/landlord/plans/create.tsx` (fetch resources, pass to form) + `resources/js/pages/landlord/plans/edit.tsx` (pass `plan.resources` defaults). **R5**. Depends: 6.1. Small.
- [ ] **6.3 Show resource count on Plan index** — `resources/js/pages/landlord/plans/index.tsx`: display `plan.resources_count` in each row. **R5 (scenario 5)**. Depends: 1.2 (resources_count via withCount). Small.

## Phase 7: Admin UI — Resource Form

- [ ] **7.1 Add plan multi-select to ResourceForm** — `resources/js/components/landlord/resource-form.tsx`: add `plans` prop + `selectedPlanIds` state + multi-select UI. **R5**. Depends: 5.1. Medium. Test: render shows multi-select, pre-selected on edit.
- [ ] **7.2 Wire plans into Resource create/edit pages** — `resources/js/pages/landlord/resources/create.tsx` (fetch plans, pass to form) + `resources/js/pages/landlord/resources/edit.tsx` (pass `resource.plans` defaults). **R5**. Depends: 7.1. Small.

## Phase 8: Frontend Three-State Cards

- [ ] **8.1 Add three-state card to shop index** — `resources/js/pages/shop/index.tsx`: render "Adquirido" (entitlement) / "Incluido en tu plan" (pivot) / "Comprar" (neither) states. **R8 (R3 of spec 2)**. Depends: 5.1, 4.1. Medium. Test: all 4 scenarios (entitlement wins, plan-included shows download, free shows download, premium non-included shows buy).
- [ ] **8.2 Add three-state indicator to resources index** — `resources/js/pages/resources/index.tsx`: show "Incluido en tu plan" badge when `can_download && is_included_in_plan`, download badge without plan indicator when `can_download && has_explicit_entitlement`. **R9 (R4 of spec 2)**. Depends: 5.1, 3.2. Medium. Test: download button with plan badge vs without.

## Phase 9: Seed Data & Cleanup

- [ ] **9.1 Update seeders to use pivot assignments** — Replace `premium-content` feature flag in plan seeders with `plan_resource` pivot rows. **R1**. Depends: 1.1. Small. No data migration needed per design.
- [ ] **9.2 Update existing PlanController/ResourceController tests for pivot assertions** — `tests/Feature/Landlord/PlanControllerTest.php` + `tests/Feature/Landlord/ResourceControllerTest.php`: add pivot row assertions to existing tests. Depends: 2.1, 2.2. Small.

## Verification

- [ ] **V.1 Run full test suite** — `php artisan test --compact`. Assert all existing + new tests pass. Depends: all prior. Small.
- [ ] **V.2 Run Pint** — `vendor/bin/pint --format agent`. Depends: all prior. Small.
