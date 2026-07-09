# Proposal: plan-hierarchy-pivot

## Intent

Replace price-based plan access checks (`price_cents` comparisons) with direct pivot table lookups in the `plan_resource` table. This eliminates the fragile implicit hierarchy where billing data drives authorization, which breaks under promotional discounts, horizontal segmentation, and price adjustments.

## Scope

### In Scope

- `ResourceController.php`: Replace `userCanAccess()` price query (L193-196) with `$tenant->subscription->plan->resources()->where('resource_id', $resource->id)->exists()`
- `ResourceController.php`: Replace `serializeResource()` price query (L248-252) with same pivot lookup
- `ShopController.php`: Replace `$isIncludedInPlan` price query (L42-46) with `$currentPlan->resources()->where('resource_id', $r->id)->exists()`

### Out of Scope

- No frontend changes (React components already consume booleans correctly)
- No migrations (pivot table exists)
- No new files
- `included_in_plan_names` — stays unfiltered (correct behavior)
- `has_plans_assigned` — stays as-is (correct behavior)
- `ResourcesSeeder.php` — already correct (uses `syncWithoutDetaching`)

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `plan-resource-mapping`: Access control query direction flips from price-based to pivot-based. No spec-level behavior change — the **intended** behavior was always pivot-based; this fix aligns the code with the spec.

## Approach

Query direction flip on the existing `plan_resource` pivot:

```
BEFORE: $resource->plans()->where('price_cents', '<=', $plan->price_cents)->exists()
AFTER:  $plan->resources()->where('resource_id', $resource->id)->exists()
```

Both traverse the same pivot table. The semantic intent becomes explicit: "does the tenant's plan include this resource?" instead of "does any plan that includes this resource cost less than the tenant's plan?"

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Resource/ResourceController.php` | Modified | 2 query replacements (~6 lines) |
| `app/Http/Controllers/Tenant/ShopController.php` | Modified | 1 query replacement (~3 lines) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Query produces different result than price-based query | None | Existing 49 tests already use pivot-based setup and will catch any divergence |

## Rollback Plan

Revert the 2 PHP files to their previous state. No data changes needed — the pivot table is unchanged.

## Dependencies

None. The `plan_resource` pivot table and model relationships already exist.

## Success Criteria

- [ ] `php artisan test --compact --filter=ResourceAccessTest` passes (9 tests)
- [ ] `php artisan test --compact --filter=ShopControllerPivotTest` passes (3 tests)
- [ ] `php artisan test --compact --filter=ResourceControllerTest` passes (37 tests)
- [ ] No price-based queries remain in ResourceController.php or ShopController.php
