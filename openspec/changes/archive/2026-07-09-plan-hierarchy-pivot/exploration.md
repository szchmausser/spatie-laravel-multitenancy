# Exploration: plan-hierarchy-pivot

## Current State

The system determines whether a tenant's plan includes a resource by comparing **plan prices** (`price_cents`). Three locations in the codebase implement this flawed logic:

1. **ResourceController.php L193-L196** — `userCanAccess()`: The access gate that blocks/enables downloads.
2. **ResourceController.php L248-L252** — `serializeResource()`: Drives the `is_included_in_plan` boolean in the Inertia response.
3. **ShopController.php L42-L46** — `$isIncludedInPlan`: Drives the "Incluido en tu plan" badge in the Shop page.

All three use the same pattern: `$resource->plans()->where('price_cents', '<=', $currentPlan->price_cents)->exists()`.

The `plan_resource` pivot table already exists and is correctly populated by the seeder (via `syncWithoutDetaching`). The tests already use explicit pivot attachments (`$plan->resources()->attach()`). The bug is that the **production code ignores the pivot** and falls back to price heuristics.

## Affected Areas

- `app/Http/Controllers/Resource/ResourceController.php` — 2 locations: `userCanAccess()` (L193-196) and `serializeResource()` (L248-252)
- `app/Http/Controllers/Tenant/ShopController.php` — 1 location: `$isIncludedInPlan` (L42-46)

## Approaches

### 1. Direct Pivot Lookup (Recommended)

Replace `$resource->plans()->where('price_cents', '<=', ...)` with `$currentPlan->resources()->where('resource_id', $r->id)->exists()`.

- **Pros**: Zero new tables/columns. Uses the database's natural relational model. Simple, semantically clear. Existing seeder and admin UI already populate the pivot correctly.
- **Cons**: Query direction flips (from resource→plans to plan→resources). Same pivot table, different traversal.
- **Effort**: Low — ~9 lines across 2 files.

### 2. Add `tier_level` Column (Rejected in RFC)

Add an integer hierarchy column to `plans`. Breaks horizontal segmentation. Violates YAGNI.

### 3. Mirror Pivot as Entitlements (Rejected in RFC)

Auto-create Entitlement rows on subscribe. Double-write synchronization problem. Complex revocation logic.

## Recommendation

**Approach 1** — Direct pivot lookup. It is the only approach that satisfies the requirements: minimal changes, no new abstractions, semantically correct. The RFC already evaluated alternatives and this is the clear winner.

## Risks

- **None identified**. The existing tests (49 tests across 3 suites) already use pivot-based setup and will validate the change. No frontend changes needed.

## Ready for Proposal

Yes. The exploration is complete. The RFC document (`docs/plan_hierarchy_rfc.md`) provides the full implementation specification with diff blocks for each file. The change is trivial and well-understood.
