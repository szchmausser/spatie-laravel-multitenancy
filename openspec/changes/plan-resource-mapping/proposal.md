# Proposal: Plan-Resource Mapping

## Intent

Replace the blanket `premium-content` feature flag with a granular many-to-many pivot between plans and resources. This lets the business define which premium resources are included in each plan while keeping individually purchased Entitlements as permanent, plan-independent grants.

## Scope

### In Scope
- `plan_resource` pivot table (migration)
- BelongsToMany on Plan and Resource models
- Replace `userCanAccess()` logic: check pivot instead of `premium-content` flag
- Admin CRUD: assign resources from plan form; assign plans from resource form
- Frontend: show "Incluido en tu plan" badge + download on shop/resource pages
- Feature tests for access scenarios

### Out of Scope
- Entitlement syncing on plan change — access reads live from pivot
- Expiration/grace periods for plan-included access
- Payment/billing changes
- Removing `premium-content` flag — kept informational

## Capabilities

### New Capabilities
- `plan-resource-assignment`: Admin assigns resources to plans and plans to resources via pivot table. Both plan form (resource multi-select) and resource form (plan multi-select).
- `plan-included-resource-display`: Frontend shows "Incluido en tu plan" badge with download capability for resources included in the tenant's current plan.

### Modified Capabilities
- None — existing resource access behavior is currently unspecced

## Approach

1. Create `plan_resource` migration (plan_id FK, resource_id FK, unique pair)
2. Add `resources()` to Plan and `plans()` to Resource (BelongsToMany)
3. Rewrite `ResourceController::userCanAccess()` — check pivot membership first, then Entitlement, remove `premium-content` check
4. Add `resource_ids` to PlanController store/update validation
5. Add `plan_ids` to Landlord ResourceController store/update
6. Add resource multi-select to plan-form.tsx; add plan multi-select to resource create/edit pages
7. Update shop/index.tsx and resources/index.tsx with three-state logic (owned / plan-included / buyable)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | `plan_resource` pivot table |
| `app/Models/Plan.php` | Modified | Add `resources()` BelongsToMany |
| `app/Models/Resource.php` | Modified | Add `plans()` BelongsToMany |
| `app/Http/Controllers/Resource/ResourceController.php` | Modified | Replace `userCanAccess()` logic |
| `app/Http/Controllers/Landlord/PlanController.php` | Modified | Accept `resource_ids` |
| `app/Http/Controllers/Landlord/ResourceController.php` | Modified | Accept `plan_ids` |
| `resources/js/components/landlord/plan-form.tsx` | Modified | Add resource multi-select |
| `resources/js/pages/landlord/resources/*.tsx` | Modified | Add plan multi-select |
| `resources/js/pages/shop/index.tsx` | Modified | Three-state resource card |
| `resources/js/pages/resources/index.tsx` | Modified | Three-state resource card |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Plan change revokes plan-included access mid-session | Low | Auth check on every request — no stale session |
| No existing spec for resource access | Low | Cover all three access paths (free, pivot, entitlement) in tests |

## Rollback Plan

Revert the migration, restore `premium-content` check in `userCanAccess()`, revert admin UI changes and frontend components.

## Dependencies

- None — all within existing Plan, Resource, and Entitlement models

## Success Criteria

- [ ] `plan_resource` pivot table exists with correct FK constraints
- [ ] Tenant can download a premium resource included in their current plan via pivot
- [ ] Tenant cannot download a premium resource NOT included in their current plan
- [ ] Tenant CAN download a resource they own via Entitlement regardless of plan
- [ ] Admin can assign resources from the plan form; plans from the resource form
- [ ] Shop page shows distinct states: "Adquirido", "Incluido en tu plan", "Comprar"
- [ ] All existing Entitlement and free-resource tests still pass
