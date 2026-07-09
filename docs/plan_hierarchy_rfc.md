# RFC: Explicit Plan-Resource Pivot Mapping

**Author**: Senior Architect
**Status**: Proposal
**Scope**: Backend access control refactor (3 PHP files + seeders)
**Frontend impact**: None — the frontend already consumes `is_included_in_plan` and `can_download` correctly; this change fixes the backend logic that computes those values.

---

## 1. Executive Summary

The system currently determines whether a tenant's plan includes a given resource by comparing **plan prices** (`price_cents`). This creates a fragile implicit hierarchy where billing data drives authorization. This RFC proposes replacing those price comparisons with **direct pivot table lookups** against the existing `plan_resource` table — the simplest possible fix that eliminates the architectural flaw without adding new tables, columns, or abstractions.

---

## 2. Architecture Context

### 2.1 Domain Model

The application is a multi-tenant SaaS built with Laravel + Spatie Multitenancy v4. All billing-related models live in the **landlord database** (connection name: `landlord`). The relevant models and their relationships are:

```
Tenant ──hasOne──▶ Subscription ──belongsTo──▶ Plan ──belongsToMany──▶ Resource
                                                                         ▲
Tenant ──(via Entitlement)────────────────────────────────────────────────┘
```

| Model | Connection | Key Relationships |
|-------|-----------|-------------------|
| [Tenant](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Models/Tenant.php) | landlord | `subscription(): HasOne` → Subscription |
| [Subscription](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Models/Subscription.php) | landlord | `plan(): BelongsTo` → Plan, `tenant(): BelongsTo` → Tenant |
| [Plan](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Models/Plan.php) | landlord | `resources(): BelongsToMany` via `plan_resource` pivot |
| [Resource](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Models/Resource.php) | landlord | `plans(): BelongsToMany` via `plan_resource` pivot |
| [Entitlement](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Models/Entitlement.php) | landlord | `tenant(): BelongsTo`, `resource(): BelongsTo` — permanent access via direct purchase |

### 2.2 Access Control Rules (Spec)

As defined in [plan-included-resource-display/spec.md](file:///c:/Desarrollo/spatie-laravel-multitenancy/openspec/changes/plan-resource-mapping/specs/plan-included-resource-display/spec.md), a tenant can access a resource through exactly **three gates**, evaluated in order:

1. **Free resource** (`is_premium = false` AND not assigned to any plan) → always accessible
2. **Plan-included** → the resource is explicitly mapped to the tenant's current plan in the `plan_resource` pivot table
3. **Entitlement** → the tenant has a non-expired `Entitlement` row for the resource (from a direct purchase)

If none of the three gates pass → access denied (403).

### 2.3 Current Seed Data

The [PlansSeeder](file:///c:/Desarrollo/spatie-laravel-multitenancy/database/seeders/PlansSeeder.php) creates three plans with this pricing:

| Plan | Slug | Price |
|------|------|-------|
| Free | `free` | $0 |
| Basic | `basic` | $8,000 |
| Premium | `premium` | $15,000 |

The [ResourcesSeeder](file:///c:/Desarrollo/spatie-laravel-multitenancy/database/seeders/ResourcesSeeder.php) creates three resources with these distribution states:

| Resource | Premium? | Price | Assigned to Plans |
|----------|----------|-------|-------------------|
| Getting Started Guide | No | $0 | None (free for all) |
| Advanced PDF | Yes | $25 | Basic + Premium (via `plan_resource` pivot) |
| Video Course | Yes | $50 | None (buy-only via Entitlement) |

> [!IMPORTANT]
> The seeder already maps the Advanced PDF to **both** Basic and Premium plans explicitly using `syncWithoutDetaching()`. This is the correct pattern — the problem is that the controller code **ignores this direct mapping** and uses price comparisons instead.

---

## 3. Problem Statement

### 3.1 The Flawed Code

Three locations in the codebase determine plan-based access by comparing `price_cents` values instead of checking the direct `plan_resource` pivot relationship:

#### Location 1: Access Gate — [ResourceController.php L193-L196](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Http/Controllers/Resource/ResourceController.php#L193-L196)

```php
if ($tenant && $tenant->subscription?->plan
    && $resource->plans()
        ->where('price_cents', '<=', $tenant->subscription->plan->price_cents)
        ->exists()) {
    return true;
}
```

This queries: *"Does any plan that includes this resource have a price less than or equal to the tenant's plan price?"* — an indirect, price-based hierarchy assumption.

#### Location 2: Serialization — [ResourceController.php L248-L252](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Http/Controllers/Resource/ResourceController.php#L248-L252)

```php
'is_included_in_plan' => $tenant && $tenant->subscription?->plan
    ? $r->plans()
        ->where('price_cents', '<=', $tenant->subscription->plan->price_cents)
        ->exists()
    : false,
```

Same price comparison, duplicated for the Inertia serialization payload. This drives the "Incluido en tu plan" badge in the frontend.

#### Location 3: Shop serialization — [ShopController.php L42-L46](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Http/Controllers/Tenant/ShopController.php#L42-L46)

```php
$isIncludedInPlan = $tenant && $currentPlan
    ? $r->plans()
        ->where('price_cents', '<=', $currentPlan->price_cents)
        ->exists()
    : false;
```

Same logic, third copy. The Shop page uses this to decide between the "Incluido en tu plan" badge, the "Comprar" button, and the "Adquirido" badge.

### 3.2 Why This Fails

The price comparison creates an **implicit hierarchy** that assumes plan value always correlates linearly with price. This breaks in common real-world scenarios:

#### Scenario A: Promotional Discount

The SaaS owner creates a *"Premium Summer"* plan at $5,000 (discounted from $15,000), while Basic remains at $8,000.

- Advanced PDF is mapped to Basic ($8,000) in the pivot
- A tenant on *Premium Summer* ($5,000) tries to access Advanced PDF
- Query: `plans()->where('price_cents', '<=', 5000)->exists()` → **FALSE** (Basic costs $8,000 > $5,000)
- **Result**: Premium subscriber LOSES access to a Basic resource ❌

#### Scenario B: Horizontal Segmentation

Two plans at $10,000 each: *Developer Plan* (with developer docs) and *Designer Plan* (with design templates).

- Developer docs are mapped to the Developer Plan ($10,000)
- A tenant on *Designer Plan* ($10,000) tries to access developer docs
- Query: `plans()->where('price_cents', '<=', 10000)->exists()` → **TRUE** ($10,000 ≤ $10,000)
- **Result**: Designer subscriber gets unintended access to Developer-only content ❌

#### Scenario C: Price Adjustment

Basic plan price is raised from $8,000 to $20,000 (above Premium's $15,000). Every Premium subscriber immediately loses access to all Basic-tier resources.

### 3.3 Violation of SOLID Principles

- **Single Responsibility**: `price_cents` belongs to the billing domain. Using it in content authorization leaks billing concerns into the access control layer.
- **Open/Closed**: Adding a new plan type (promo, corporate, trial) requires verifying that its price doesn't accidentally grant or revoke access — the system is not closed for modification.

---

## 4. Alternatives Evaluated

| # | Option | Description | Verdict |
|---|--------|-------------|---------|
| A | **Add `tier_level` column** | Integer hierarchy column on `plans` (Free=0, Basic=1, Premium=2). Access check becomes `resource.plans.min_tier <= currentPlan.tier_level`. | **Rejected** — Adds redundant state that must be synchronized with pivot assignments. Breaks horizontal segmentation. Violates YAGNI. |
| B | **Mirror pivot as Entitlements on subscribe** | When a tenant subscribes to a plan, auto-create Entitlement rows for all plan resources. Revoke on plan change. | **Rejected** — Double-write synchronization problem. Requires event listeners for plan edits, resource additions, and subscription changes. High complexity, stale data risk. The original [design spec](file:///c:/Desarrollo/spatie-laravel-multitenancy/openspec/changes/plan-resource-mapping/design.md#L9-L16) explicitly rejected this as "Double-write sync, stale data risk, complex revocation". |
| **C** | **Direct pivot check (Proposed)** | Check if the tenant's current plan has the resource in its `plan_resource` pivot. No price comparisons. | **Chosen** — Zero new tables/columns. Uses the database's natural relational model. The pivot is already populated correctly by seeders and the Admin UI. KISS. |

---

## 5. Implementation Specification

### 5.1 Changes Required

Only **3 PHP files** need modification. **No migrations, no new files, no frontend changes.**

---

#### File 1: [app/Http/Controllers/Resource/ResourceController.php](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Http/Controllers/Resource/ResourceController.php)

**Change A — `userCanAccess()` method (lines 193-196):**

```diff
- if ($tenant && $tenant->subscription?->plan
-     && $resource->plans()
-         ->where('price_cents', '<=', $tenant->subscription->plan->price_cents)
-         ->exists()) {
+ if ($tenant && $tenant->subscription?->plan
+     && $tenant->subscription->plan->resources()
+         ->where('resource_id', $resource->id)
+         ->exists()) {
```

**Why the query direction flips**: We now ask *"Does the tenant's plan include this resource?"* (`$plan->resources()->where(...)`) instead of *"Does any plan that includes this resource cost less than the tenant's plan?"* (`$resource->plans()->where(...)`). The pivot table queried is the same (`plan_resource`), but the semantic intent is clearer and price-independent.

**Change B — `serializeResource()` method (lines 248-252):**

```diff
  'is_included_in_plan' => $tenant && $tenant->subscription?->plan
-     ? $r->plans()
-         ->where('price_cents', '<=', $tenant->subscription->plan->price_cents)
-         ->exists()
+     ? $tenant->subscription->plan->resources()
+         ->where('resource_id', $r->id)
+         ->exists()
      : false,
```

**Do NOT change `included_in_plan_names`** (lines 253-256). That field correctly lists ALL plans that include the resource (for informational display to the user, e.g. "Incluido en planes: Basic, Premium"). It should remain unfiltered.

---

#### File 2: [app/Http/Controllers/Tenant/ShopController.php](file:///c:/Desarrollo/spatie-laravel-multitenancy/app/Http/Controllers/Tenant/ShopController.php)

**Change — `$isIncludedInPlan` computation (lines 42-46):**

```diff
  $isIncludedInPlan = $tenant && $currentPlan
-     ? $r->plans()
-         ->where('price_cents', '<=', $currentPlan->price_cents)
-         ->exists()
+     ? $currentPlan->resources()
+         ->where('resource_id', $r->id)
+         ->exists()
      : false;
```

**Do NOT change `$hasPlansAssigned`** (line 48). That correctly checks if the resource is assigned to ANY plan (for badge logic).

**Do NOT change `included_in_plan_names`** (lines 63-66). Same reason as above.

---

#### File 3: [database/seeders/ResourcesSeeder.php](file:///c:/Desarrollo/spatie-laravel-multitenancy/database/seeders/ResourcesSeeder.php)

**No code changes required.** The seeder already correctly maps the Advanced PDF to both Basic and Premium plans using `syncWithoutDetaching()` (lines 81-82). This is the correct cumulative pattern.

However, **verify the seeder is correct** by confirming lines 74-82:

```php
$basic = Plan::query()->where('slug', 'basic')->firstOrFail();
$premium = Plan::query()->where('slug', 'premium')->firstOrFail();
$advancedPdf = Resource::query()->where('slug', 'advanced-pdf')->firstOrFail();

$basic->resources()->syncWithoutDetaching([$advancedPdf->id]);
$premium->resources()->syncWithoutDetaching([$advancedPdf->id]);
```

> [!IMPORTANT]
> If new premium resources are added in the future, the admin MUST assign them to every plan that should include them via the Admin UI (or seeders). The Premium plan does NOT automatically inherit Basic's resources — each mapping is explicit. This is by design.

---

## 6. What NOT to Do

> [!CAUTION]
> These constraints are critical. Violating them will break the test environment or cause timeouts.

1. **Do NOT run the full test suite** (`php artisan test` without `--filter`). It times out (20+ minutes). Always use `--filter` to run specific test files.
2. **Do NOT modify `.env.testing`, `phpunit.xml`, or the test database configuration.** The testing environment uses a single shared PostgreSQL database (`spatie-laravel-multitenancy-testing`) where both `landlord` and `tenant` connections point to the same database. This is intentional and functional.
3. **Do NOT create new migration files.** The `plan_resource` pivot table already exists with the correct schema (FK cascades + unique constraint).
4. **Do NOT modify any frontend files.** The React components in `resources/js/pages/resources/index.tsx`, `resources/js/pages/resources/show.tsx`, and `resources/js/pages/shop/index.tsx` already correctly consume `is_included_in_plan`, `has_explicit_entitlement`, `has_plans_assigned`, and `can_download`. The frontend renders the correct badges and buttons based on these boolean flags. This change only fixes how those flags are computed on the backend.
5. **Do NOT modify the `included_in_plan_names` field** in either controller. It correctly lists ALL plans that include the resource (unfiltered) for informational display.

---

## 7. Verification Plan

After making the changes, run these test suites **one at a time** to verify no regressions:

```bash
# 1. Verify the 4 branches of userCanAccess (free, plan-included, entitlement, denied)
#    and is_included_in_plan serialization
php artisan test --compact --filter=ResourceAccessTest

# 2. Verify shop serialization (is_included_in_plan, has_entitlement)
php artisan test --compact --filter=ShopControllerPivotTest

# 3. Verify full controller integration (index, show, request, download)
php artisan test --compact --filter=ResourceControllerTest
```

All three suites currently pass (9 + 3 + 37 = 49 tests). They MUST continue to pass after the refactor because the existing tests already use explicit pivot assignments (`$plan->resources()->attach($resource->id)`) rather than relying on price comparisons. The tests were written correctly — the production code was not.

> [!NOTE]
> If any test fails, it means the refactored query produces a different result than the price-based query for that test's data setup. Investigate the test's plan pricing and pivot assignments to understand why.

---

## 8. Summary of Changes

| File | Lines Changed | What Changes | What Stays |
|------|:---:|---|---|
| `ResourceController.php` | ~6 | `userCanAccess()` + `serializeResource()` pivot queries | `included_in_plan_names`, `has_plans_assigned`, `tenantHasExplicitEntitlement()` |
| `ShopController.php` | ~3 | `$isIncludedInPlan` pivot query | `$hasPlansAssigned`, `included_in_plan_names`, `$hasEntitlement` |
| `ResourcesSeeder.php` | 0 | Nothing (already correct) | Cumulative pivot assignments |
| **Total** | **~9 lines** | | |
