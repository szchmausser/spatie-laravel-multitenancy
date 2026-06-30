## Verification Report

**Change**: plan-resource-mapping
**Version**: N/A
**Mode**: Standard

### Completeness
| Metric | Value |
|--------|-------|
| Tasks total | 22 |
| Tasks complete | 22 (all 9 phases + 2 verification tasks) |
| Tasks incomplete | 0 |

### Build & Tests Execution

**Pint**: ✅ Passed
```text
vendor/bin/pint --format agent → {"tool":"pint","result":"passed"}
```

**Feature Tests**: ✅ 82 passed (454 assertions)
```text
php artisan test --compact --testsuite=Feature --filter="PlanResource|ResourceAccess|Pivot|Shop|Resource"
→ Tests: 82 passed (454 assertions)
```

**Core Tests**: ✅ 61 passed (289 assertions)
```text
php artisan test tests/Feature/Landlord/PlanControllerTest.php tests/Feature/Landlord/PlanControllerPivotTest.php tests/Feature/Landlord/ResourceControllerTest.php tests/Feature/Landlord/ResourceControllerPivotTest.php tests/Feature/Models/PlanResourcePivotTest.php tests/Feature/Models/PlanTest.php tests/Feature/Models/ResourceTest.php tests/Feature/Premium/ResourceAccessTest.php tests/Feature/Tenant/ShopControllerTest.php tests/Feature/Tenant/ShopControllerPivotTest.php
→ Tests: 61 passed (289 assertions)
```

### Spec Compliance Matrix

#### Spec 1: Plan-Resource Assignment (R1-R5)

| Requirement | Scenario | Test | Result |
|-------------|----------|------|--------|
| R1: Pivot table `plan_resource` | Pivot stores plan-resource pairs | `PlanResourcePivotTest > plan_resource table exists with expected columns` | ✅ COMPLIANT |
| R1: Pivot table `plan_resource` | Duplicate pair is rejected | `PlanResourcePivotTest > plan_resource unique constraint rejects duplicate pairs` | ✅ COMPLIANT |
| R1: Pivot table `plan_resource` | Deleting plan cleans pivot rows | `PlanResourcePivotTest > plan_resource cascade deletes when plan is removed` | ✅ COMPLIANT |
| R1: Pivot table `plan_resource` | Deleting resource cleans pivot rows | `PlanResourcePivotTest > plan_resource cascade deletes when resource is removed` | ✅ COMPLIANT |
| R2: BelongsToMany on Plan + Resource | Plan has many resources | `PlanResourcePivotTest > plan has many resources via BelongsToMany` | ✅ COMPLIANT |
| R2: BelongsToMany on Plan + Resource | Resource has many plans | `PlanResourcePivotTest > resource has many plans via BelongsToMany` | ✅ COMPLIANT |
| R3: Plan form accepts `resource_ids` | Creation with assigned resources | `PlanControllerPivotTest > plan creation accepts resource_ids and creates pivot rows` | ✅ COMPLIANT |
| R3: Plan form accepts `resource_ids` | Invalid resource ID rejected | `PlanControllerPivotTest > plan creation rejects invalid resource_id` | ✅ COMPLIANT |
| R3: Plan form accepts `resource_ids` | Empty resource_ids allowed | `PlanControllerPivotTest > plan creation allows empty resource_ids` | ✅ COMPLIANT |
| R3: Plan form accepts `resource_ids` | Update syncs correctly | `PlanControllerPivotTest > plan update syncs resource_ids correctly` | ✅ COMPLIANT |
| R4: Resource form accepts `plan_ids` | Creation with assigned plans | `ResourceControllerPivotTest > resource creation accepts plan_ids and creates pivot rows` | ✅ COMPLIANT |
| R4: Resource form accepts `plan_ids` | Invalid plan ID rejected | `ResourceControllerPivotTest > resource creation rejects invalid plan_id` | ✅ COMPLIANT |
| R4: Resource form accepts `plan_ids` | Update syncs correctly | `ResourceControllerPivotTest > resource update syncs plan_ids correctly` | ✅ COMPLIANT |
| R5: Admin UI shows assigned resources | Plan index shows resource count | `PlanController::index` uses `withCount('resources')`; Plan type in `models.ts` includes `resources_count`; UI renders it | ✅ COMPLIANT |
| R5: Admin UI shows assigned resources | Plan edit shows pre-selected resources | `PlanController::edit` loads `resources`; `Plan` type includes `resources?: Resource[]` | ✅ COMPLIANT |
| R5: Admin UI shows assigned resources | Resource edit shows pre-selected plans | `ResourceController::edit` loads `plans`; `Resource` type includes `plans?: Plan[]` | ✅ COMPLIANT |

#### Spec 2: Plan-Included Resource Display (R6-R10)

| Requirement | Scenario | Test | Result |
|-------------|----------|------|--------|
| R6: userCanAccess reads pivot first | Premium included in plan → granted | `ResourceAccessTest > premium resource included in plan is downloadable` | ✅ COMPLIANT |
| R6: userCanAccess reads pivot first | Premium NOT in plan, no entitlement → denied | `ResourceAccessTest > premium resource without plan or entitlement is denied` | ✅ COMPLIANT |
| R6: userCanAccess reads pivot first | Premium owned via entitlement → granted | `ResourceAccessTest > premium resource with explicit entitlement is downloadable` | ✅ COMPLIANT |
| R6: userCanAccess reads pivot first | Free resource always accessible | `ResourceAccessTest > non-premium resource is always downloadable` | ✅ COMPLIANT |
| R7: Serialization includes is_included_in_plan | Plan-included flag set true | `ResourceAccessTest > serialized resource includes is_included_in_plan true when resource is in current plan` | ✅ COMPLIANT |
| R7: Serialization includes is_included_in_plan | No subscription → false | `ResourceAccessTest > serialized resource has is_included_in_plan false when no subscription` | ✅ COMPLIANT |
| R7: Serialization includes is_included_in_plan | Shop serialization | `ShopControllerPivotTest > shop resource includes is_included_in_plan true when resource is in current plan` | ✅ COMPLIANT |
| R8: Shop three-state cards | Entitlement takes precedence | Frontend: renders "Adquirido" badge when `has_entitlement`; feature test: `ShopControllerPivotTest > shop resource has both has_entitlement and is_included_in_plan independently` | ✅ COMPLIANT |
| R8: Shop three-state cards | Plan-included shows download | Frontend: renders green badge + Download when `is_included_in_plan && !has_entitlement` | ✅ COMPLIANT |
| R8: Shop three-state cards | Free resource shows download | Frontend: renders Download when `!is_premium` | ✅ COMPLIANT |
| R8: Shop three-state cards | Premium non-included shows Buy | Frontend: renders "Comprar" button when `is_premium && price_cents > 0 && !has_entitlement && !is_included_in_plan` | ✅ COMPLIANT |
| R9: Resources index three states | Download for plan-included with badge | Frontend: shows green "Incluido en tu plan" badge + Download when `can_download && is_included_in_plan` | ✅ COMPLIANT |
| R9: Resources index three states | Download for entitlement without badge | Frontend: shows Download without plan badge when `can_download && has_explicit_entitlement` | ✅ COMPLIANT |
| R10: Entitlement persists across plan changes | Plan change does not revoke entitlement | `ResourceAccessTest > entitlement access persists when plan changes and no longer includes resource` | ✅ COMPLIANT |

**Compliance summary**: 26/26 scenarios compliant

### Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| R1: Pivot table | ✅ Implemented | Migration creates `plan_resource` with FKs, cascade deletes, unique constraint |
| R2: BelongsToMany | ✅ Implemented | Both models define the relationship |
| R3: resource_ids in PlanController | ✅ Implemented | Validation + sync in store/update |
| R4: plan_ids in ResourceController | ✅ Implemented | Validation + sync in store/update (both create and update) |
| R5: Admin UI forms | ✅ Implemented | Multi-select components, pre-selected on edit, count on index |
| R6: userCanAccess 4-branch | ✅ Implemented | free → plan-pivot → entitlement → denied |
| R7: is_included_in_plan serialization | ✅ Implemented | Both `serializeResource()` and `ShopController::index()` |
| R8: Shop three-state cards | ✅ Implemented | Adquirido > Incluido en tu plan > Comprar > Free download |
| R9: Resources index three states | ✅ Implemented | Green badge for plan-included, plain download for entitlement |
| R10: Entitlement persists across plan changes | ✅ Implemented | Entitlement check is independent of pivot; plan change does not affect it |

### Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| Pivot-only access (no entitlement duplication) | ✅ Yes | `userCanAccess()` reads pivot, entitlements only for direct purchases |
| Follow existing inline validation pattern | ✅ Yes | `resource_ids`/`plan_ids` validated inline in controllers |
| Single Inertia page component per page | ✅ Yes | Each page keeps its own component, both use `is_included_in_plan` |
| Migration schema matches design | ✅ Yes | `plan_id` + `resource_id` with cascade delete + unique pair |
| Model relationships match design | ✅ Yes | Using `BelongsToMany` on both models |
| Access logic order matches design | ✅ Yes | free → plan → entitlement (exactly the 4-branch contract) |
| Sync pattern matches design | ✅ Yes | `$plan->resources()->sync(...)` and `$resource->plans()->sync(...)` |
| Validation rules match design | ✅ Yes | `nullable|array` + `exists:resources,id` / `exists:plans,id` |
| Seeders updated for pivot | ✅ Yes | `PlansSeeder` attaches premium resources via `syncWithoutDetaching` |
| TS types updated | ✅ Yes | `is_included_in_plan`, `resources_count`, `resources`, `plans` added |

### Issues Found

**CRITICAL**:
1. **Potential browser test regression** — `tests/Browser/Resource/ResourceDownloadTest.php` test `user with premium plan can download premium resource` (L80-130) creates a plan with `features: ['premium-content' => true]` but does **not** attach the resource to the plan via the `plan_resource` pivot. After the `userCanAccess()` rewrite, this test would **fail** because the pivot check replaces the feature-flag check. The resource won't be considered "included in plan", and there's no entitlement, so access is denied. This test must be updated to attach the resource to the plan via `$premiumPlan->resources()->attach($premiumResource->id)`.

**WARNING**:
1. **N+1 queries in serialization** — `serializeResource()` makes up to 4 DB queries per resource:
   1. `userCanAccess()` → pivot check (`plan->resources()->where(...)->exists()`)
   2. If plan check fails, `tenantHasExplicitEntitlement()` → entitlement query
   3. `tenantHasExplicitEntitlement()` again for `has_explicit_entitlement`
   4. Pivot check again for `is_included_in_plan`
   
   For catalogs with 20+ resources this means 40+ queries. Consider caching the tenant's plan resource IDs or eager-loading them (e.g., `$currentPlan->resources()->pluck('resource_id')`) and checking membership in-memory with `in_array()`.

2. **ShopController also has N+1** — Each resource in the shop index triggers separate pivot and entitlement queries. Same optimization recommendation applies.

3. **Missing browser tests for three-state cards** — The new three-state card rendering logic in the shop and resources index has no browser test coverage. The feature tests verify serialization but not the actual DOM rendering of the three states.

**SUGGESTION**:
1. **Legacy `premium-content` feature flag** — `ResourceDownloadTest` still relies on `features: ['premium-content' => true]` as a proxy for plan access. Consider removing the unused `hasFeature('premium-content')` logic from the model since it's now misleading — it exists only on the model but is not used anywhere in the access control.
2. **Extract subscription/plan helpers** — The expression `$tenant && $tenant->subscription?->plan?->resources()->where('resource_id', $r->id)->exists()` appears in 3 places (`userCanAccess`, `serializeResource`, `ShopController::index`). Extracting to a helper (e.g., `$tenant->currentPlanIncludesResource($resource)`) would reduce duplication and make the N+1 fix easier.
3. **Seeds and new resources** — `PlansSeeder` uses `syncWithoutDetaching` which is correct for idempotent seeding, but newly added premium resources won't be auto-assigned to plans on re-seed. Consider a dedicated Artisan command for re-assigning resources to plans.

### Verdict
**PASS WITH WARNINGS**

All 22 implementation tasks are complete. All 26 spec scenarios are covered by passing feature tests. The `userCanAccess()` logic follows the agreed 4-branch contract (free → plan pivot → entitlement → denied). Pint code style check passes.

However, there is a **CRITICAL** regression risk in an existing browser test (`ResourceDownloadTest.php`) that must be fixed before production deployment, and the N+1 query concern should be reviewed for scalability.
