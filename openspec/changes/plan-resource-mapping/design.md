# Design: Plan-Resource Mapping

## Technical Approach

Replace the blanket `premium-content` feature flag with a `plan_resource` pivot table for granular plan-resource assignment. Access control reads the pivot at download time — no entitlement duplication. Entitlements remain for direct purchases only (permanent, plan-independent). The `premium-content` flag stays on the model as informational only; all existing data is throwaway seed data, so no migration needed.

## Architecture Decisions

### Decision: Pivot-only access (no entitlement duplication)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Mirror pivot rows as Entitlements on plan assignment | Double-write sync, stale data risk, complex revocation | **Rejected** |
| Read pivot at access time only | Single source of truth, no sync, simpler | **Chosen** |

Pivot is the canonical source for "what does this plan include." Entitlements only record direct purchases — they are permanent and never expire.

### Decision: Follow existing inline validation pattern

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Create Form Request classes | More files, inconsistent with existing PlanController/ResourceController inline validates | **Rejected** |
| Add `resource_ids` inline in PlanController | Consistent with existing code, fewer files | **Chosen** |

Both PlanController and Landlord\ResourceController validate inline today. Adding `resource_ids` alongside existing rules keeps the pattern uniform.

### Decision: Single Inertia page component for both shop and resources index

Both pages already exist independently. The three-state card logic is duplicated intentionally — each page has different layout context (shop has plan cards too). We add `is_included_in_plan` to the serialized resource shape and let each page's React component render the third state.

## Data Flow

```
Admin assigns resources to plan
  → PlanController::store/update accepts resource_ids[]
  → Plan::resources()->sync($resource_ids)

Admin assigns plans to resource
  → Landlord\ResourceController::store/update accepts plan_ids[]
  → Resource::plans()->sync($plan_ids)

Tenant accesses resource
  → ResourceController::download()
  → userCanAccess():
      1. !is_premium → true (free)
      2. plan->resources()->where('resource_id', $r->id)->exists() → true (plan-included)
      3. Entitlement::where(tenant_id, resource_id, not expired)->exists() → true (purchased)
      4. → false (denied)

Shop/resources index serialization
  → serializeResource() adds is_included_in_plan: bool
  → is_included_in_plan is set via $tenant->subscription->plan->resources()->where('resource_id', $r->id)->exists()
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/landlord/2026_06_30_000001_create_plan_resource_table.php` | Create | `plan_resource` pivot with FK cascades + unique pair constraint |
| `app/Models/Plan.php` | Modify | Add `resources(): BelongsToMany` via `plan_resource` |
| `app/Models/Resource.php` | Modify | Add `plans(): BelongsToMany` via `plan_resource` |
| `app/Http/Controllers/Resource/ResourceController.php` | Modify | Rewrite `userCanAccess()` — replace `$tenant->hasFeature('premium-content')` with pivot check; add `is_included_in_plan` to `serializeResource()` |
| `app/Http/Controllers/Tenant/ShopController.php` | Modify | Replace inline `has_entitlement` logic; add `has_entitlement` + `is_included_in_plan` to resource array |
| `app/Http/Controllers/Landlord/PlanController.php` | Modify | Add `resource_ids` validation in store/update; `$plan->resources()->sync($validated['resource_ids'] ?? [])` |
| `app/Http/Controllers/Landlord/ResourceController.php` | Modify | Add `plan_ids` validation in store/update; `$resource->plans()->sync($validated['plan_ids'] ?? [])` |
| `resources/js/types/models.ts` | Modify | Add `is_included_in_plan?: boolean` to `Resource` type; add `resources_count?: number` to `Plan` type |
| `resources/js/components/landlord/plan-form.tsx` | Modify | Add resource multi-select (prop: `resources: Resource[]`, state: `selectedResourceIds`) |
| `resources/js/pages/landlord/plans/edit.tsx` | Modify | Pass `plan.resources` as defaults to PlanForm |
| `resources/js/pages/landlord/plans/create.tsx` | Modify | Fetch available resources, pass to PlanForm |
| `resources/js/pages/landlord/plans/index.tsx` | Modify | Show `plan.resources_count` in plan rows |
| `resources/js/components/landlord/resource-form.tsx` | Modify | Add plan multi-select (prop: `plans: Plan[]`, state: `selectedPlanIds`) |
| `resources/js/pages/landlord/resources/create.tsx` | Modify | Fetch available plans, pass to ResourceForm |
| `resources/js/pages/landlord/resources/edit.tsx` | Modify | Pass `resource.plans` as defaults to ResourceForm |
| `resources/js/pages/shop/index.tsx` | Modify | Add third card state: "Incluido en tu plan" when `is_included_in_plan && !has_entitlement` |
| `resources/js/pages/resources/index.tsx` | Modify | Add "Incluido en tu plan" indicator when `can_download && is_included_in_plan` vs `can_download && has_explicit_entitlement` |

## Interfaces / Contracts

### Migration schema

```php
Schema::create('plan_resource', function (Blueprint $table) {
    $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
    $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
    $table->unique(['plan_id', 'resource_id']);
});
```

### Model relationships

```php
// Plan.php
public function resources(): BelongsToMany
{
    return $this->belongsToMany(Resource::class)->using(UsesLandlordConnection::class);
}

// Resource.php
public function plans(): BelongsToMany
{
    return $this->belongsToMany(Plan::class)->using(UsesLandlordConnection::class);
}
```

### Access logic (replaces `userCanAccess`)

```php
private function userCanAccess(?Tenant $tenant, Resource $resource): bool
{
    if (! $resource->is_premium) return true;
    if ($tenant && $tenant->subscription?->plan?->resources()
        ->where('resource_id', $resource->id)->exists()) return true;
    return $this->tenantHasExplicitEntitlement($tenant, $resource);
}
```

### Serialized resource shape (added field)

```php
'is_included_in_plan' => $tenant && $tenant->subscription?->plan?->resources()
    ->where('resource_id', $r->id)->exists(),
```

### Controller sync pattern

```php
// PlanController::store / update
$plan->resources()->sync($validated['resource_ids'] ?? []);

// Landlord\ResourceController::store / update
$resource->plans()->sync($validated['plan_ids'] ?? []);
```

### Validation rules (add to existing inline arrays)

```php
// PlanController store/update
'resource_ids' => ['nullable', 'array'],
'resource_ids.*' => ['exists:resources,id'],

// Landlord\ResourceController store/update
'plan_ids' => ['nullable', 'array'],
'plan_ids.*' => ['exists:plans,id'],
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | Plan/Resource BelongsToMany relationships | Factory tests asserting `resources()`/`plans()` returns pivot rows |
| Unit | `userCanAccess()` logic | Direct call with mocked Tenant/Subscription/Plan — test all 4 branches (free, plan-included, entitlement, denied) |
| Feature | Plan CRUD with `resource_ids` | Store plan with resources → assert pivot rows exist; update → assert sync replaces correctly; empty → assert no rows |
| Feature | Resource CRUD with `plan_ids` | Store resource with plans → assert pivot rows exist; update → assert sync replaces correctly |
| Feature | ShopController serialization | Assert `is_included_in_plan` matches plan membership; assert `has_entitlement` independent |
| Browser | Three-state card rendering | Plan-included → badge + download; Entitlement → "Adquirido" badge; Neither → "Comprar" button; Free → Download |

## Migration / Rollout

No data migration needed — the `premium-content` feature flag on existing seed plans was example data. Seeders will be updated to use pivot assignments instead. Rollback: drop the `plan_resource` table and revert the four controller files.

## Open Questions

None — all design decisions are resolved by existing patterns and the specs.

## Ordering

1. **Migration** — `create_plan_resource_table` (foundation)
2. **Models** — Add `resources()`/`plans()` BelongsToMany
3. **Landlord controllers** — Add `resource_ids`/`plan_ids` validation + sync (backend complete for assignment)
4. **Tenant access logic** — Rewrite `userCanAccess()`, add `is_included_in_plan` to both `serializeResource()` and `ShopController::index()`
5. **TypeScript types** — Add `is_included_in_plan` and `resources_count`
6. **Plan form** — Resource multi-select component
7. **Resource form** — Plan multi-select component
8. **Plan index** — Resource count display
9. **Shop index** — Three-state card
10. **Resources index** — Three-state card
