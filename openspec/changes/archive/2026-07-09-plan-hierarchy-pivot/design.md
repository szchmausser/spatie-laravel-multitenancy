# Design: Plan-Hierarchy-Pivot

## Technical Approach

Replace fragile `price_cents` comparisons in 3 access-control locations with direct `plan_resource` pivot lookups using the existing `Plan->resources()` BelongsToMany relationship. The query direction flips: instead of *"does any plan that includes this resource cost ≤ the tenant's plan?"*, ask *"does the tenant's current plan explicitly include this resource?"*. No new tables, columns, models, or files — only 2 PHP files, ~9 lines changed.

The pivot table (`plan_resource`) and seeders are already correct. The production code ignored the direct mapping and used price as a proxy for hierarchy — this change aligns code with the data model.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|---|---|---|---|
| Check direction | `$plan->resources()->where('resource_id', $r->id)` | `$r->plans()->where(...)` (current) | Mirrors the natural relational direction: Plan HAS resources. Same `plan_resource` table queried, but semantics are explicit — no price assumption. |
| Scope of change | Only the 3 pivot-checks | Extracting service class, adding abstraction | KISS. The fix is a one-liner in each of 3 locations. Adding abstraction layers now is premature — YAGNI. |
| `included_in_plan_names` | UNCHANGED | Filter by current plan | That field is for informational display ("Incluido en planes: Basic, Premium") — it should list ALL plans, not just the current one. |

## Data Flow

```
Before (broken):
  Resource::plans() ──where('price_cents', '<=', $plan->price_cents)──> has access?
    ↑ Implicit: "any plan cheaper or equal" — ties billing to auth

After (correct):
  Plan::resources() ──where('resource_id', $resource->id)──> has access?
    ↑ Explicit: "does this plan include this resource?" — pure pivot lookup
```

Both paths query the same `plan_resource` pivot table. The difference is the WHERE clause: price comparison vs. direct resource ID match.

## File Changes

| File | Lines | Action | Description |
|------|-------|--------|-------------|
| `app/Http/Controllers/Resource/ResourceController.php` | 193-196 | Modify | `userCanAccess()` — replace price comparison with `$plan->resources()->where('resource_id', ...)` |
| `app/Http/Controllers/Resource/ResourceController.php` | 248-252 | Modify | `serializeResource()` — same replacement for `is_included_in_plan` |
| `app/Http/Controllers/Tenant/ShopController.php` | 42-46 | Modify | `$isIncludedInPlan` — same replacement for shop serialization |

## Changed Lines (Diff)

### ResourceController.php — userCanAccess() (L188-205)

```diff
     if ($resource->is_premium || $resource->plans()->exists()) {
         if ($tenant && $tenant->subscription?->plan
-            && $resource->plans()
-                ->where('price_cents', '<=', $tenant->subscription->plan->price_cents)
-                ->exists()) {
+            && $tenant->subscription->plan->resources()
+                ->where('resource_id', $resource->id)
+                ->exists()) {
             return true;
         }

         return $this->tenantHasExplicitEntitlement($tenant, $resource);
     }
```

### ResourceController.php — serializeResource() (L233-258)

```diff
     'is_included_in_plan' => $tenant && $tenant->subscription?->plan
-        ? $r->plans()
-            ->where('price_cents', '<=', $tenant->subscription->plan->price_cents)
-            ->exists()
+        ? $tenant->subscription->plan->resources()
+            ->where('resource_id', $r->id)
+            ->exists()
         : false,
```

### ShopController.php — $isIncludedInPlan (L42-46)

```diff
     $isIncludedInPlan = $tenant && $currentPlan
-        ? $r->plans()
-            ->where('price_cents', '<=', $currentPlan->price_cents)
-            ->exists()
+        ? $currentPlan->resources()
+            ->where('resource_id', $r->id)
+            ->exists()
         : false;
```

## Unchanged Areas (Must NOT Modify)

| Location | File | Reason |
|---|---|---|
| `included_in_plan_names` | Both controllers | Lists ALL plans for the resource (informational). NOT filtered by current plan. |
| `has_plans_assigned` | Both controllers | Boolean: is resource assigned to ANY plan? Used for badge logic. |
| `has_explicit_entitlement` / `has_entitlement` | Both controllers | Tenant-level direct purchase check — unrelated to plans. |
| `ResourcesSeeder.php` | `database/seeders/` | Already uses `syncWithoutDetaching()` — correct pattern. |
| All frontend files | `resources/js/` | Already consumes the backend booleans correctly. No changes needed. |

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Existing tests | 49 tests (3 files) | Run with `--filter` — must pass unchanged. Tests use pivot assignments, not price comparisons, so they validate the correct behavior. |
| Verification | `ResourceAccessTest` (9 tests) | `php artisan test --compact --filter=ResourceAccessTest` |
| Verification | `ShopControllerPivotTest` (3 tests) | `php artisan test --compact --filter=ShopControllerPivotTest` |
| Verification | `ResourceControllerTest` (37 tests) | `php artisan test --compact --filter=ResourceControllerTest` |

**Why existing tests already work**: The test factories attach resources to plans explicitly via `$plan->resources()->attach(...)`, never relying on price comparisons. The tests were written correctly — the production code was not.

## Verification Steps for Developer

1. Apply the 3 diffs above (2 files, ~9 lines)
2. Run `vendor/bin/pint --dirty --format agent` to fix any style issues
3. Run the 3 test suites in order:
   ```bash
   php artisan test --compact --filter=ResourceAccessTest
   php artisan test --compact --filter=ShopControllerPivotTest
   php artisan test --compact --filter=ResourceControllerTest
   ```
4. Confirm all 49 tests pass (if any fail, investigate the test data setup — the pivot mapping may differ from the price comparison result)
5. Verify no unintended changes by checking `git diff --stat` shows only the 2 expected files

## Migration / Rollout

No migration required. No feature flag needed. The change is instantaneous and read-only (query logic only, no schema writes). Rollback: revert the 3 diffs.

## Open Questions

None. The spec (RFC `docs/plan_hierarchy_rfc.md`) is authoritative and provides complete detail. Line numbers have been cross-verified against the actual codebase.
