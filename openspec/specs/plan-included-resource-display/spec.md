# Plan-Included Resource Display — Code Alignment

> Esta especificación se basa en `docs/plan_hierarchy_rfc.md` que es el documento autoritativo.

## Purpose

Replaces fragile price-based (`price_cents`) comparisons with direct `plan_resource` pivot lookups in 3 code locations. No behavior change — this aligns production code with the already-correct specification. The existing 49 tests validate the behavior and MUST continue to pass unchanged.

## Affected Code (exact lines confirmed)

| File | Lines | Current (price-based) | Replacement (pivot-based) |
|------|-------|-----------------------|---------------------------|
| `ResourceController.php` | 193-196 (userCanAccess) | `$resource->plans()->where('price_cents', '<=', ...)->exists()` | `$tenant->subscription->plan->resources()->where('resource_id', $resource->id)->exists()` |
| `ResourceController.php` | 248-252 (serializeResource) | `$r->plans()->where('price_cents', '<=', ...)->exists()` | `$tenant->subscription->plan->resources()->where('resource_id', $r->id)->exists()` |
| `ShopController.php` | 42-46 ($isIncludedInPlan) | `$r->plans()->where('price_cents', '<=', ...)->exists()` | `$currentPlan->resources()->where('resource_id', $r->id)->exists()` |

## Requirements

### R1: `userCanAccess()` checks current plan's pivot

The access gate MUST check if the tenant's CURRENT plan has the resource in its `plan_resource` pivot, not via price comparison.

#### Scenario: Resource in current plan's pivot → granted

- GIVEN a tenant on plan "Pro", and `plan_resource` maps "Pro" to resource "R"
- WHEN `userCanAccess()` evaluates resource "R"
- THEN the pivot check passes and access is granted

#### Scenario: Resource NOT in current plan's pivot → falls through to entitlement

- GIVEN a tenant on plan "Free", and `plan_resource` does NOT map "Free" to premium resource "R"
- WHEN `userCanAccess()` evaluates resource "R"
- THEN the pivot check returns false and the entitlement gate is evaluated next

### R2: `is_included_in_plan` serialized from current plan's pivot

Both `serializeResource()` (ResourceController) and `$isIncludedInPlan` (ShopController) MUST compute the flag from the current plan's `plan_resource` pivot, not from price comparisons.

#### Scenario: Resource in current plan → `is_included_in_plan: true`

- GIVEN resource "R" is in current plan's pivot
- WHEN either controller serializes "R"
- THEN `is_included_in_plan` is `true`

#### Scenario: No active subscription → `is_included_in_plan: false`

- GIVEN a tenant has no subscription (or subscription has no plan)
- WHEN either controller serializes any resource
- THEN `is_included_in_plan` is `false`

#### Scenario: Resource not in current plan, no entitlement → shop shows buy

- GIVEN premium resource "R" is NOT in current plan's pivot and has no entitlement
- WHEN ShopController serializes "R"
- THEN `is_included_in_plan` is `false` and shop shows "Comprar"

### R3: `included_in_plan_names` MUST NOT change

The `included_in_plan_names` field MUST continue to list ALL plans that include the resource (unfiltered), for informational display.

#### Scenario: Resource in 2 plans → both listed

- GIVEN resource "R" is in plans "Basic" and "Premium"
- WHEN either controller serializes "R"
- THEN `included_in_plan_names` contains `["Basic", "Premium"]`

### R4: `has_plans_assigned` MUST NOT change

The `has_plans_assigned` field MUST continue to check if the resource is assigned to ANY plan.

#### Scenario: Resource assigned to any plan → true

- GIVEN resource "R" is assigned to one or more plans
- WHEN serialized
- THEN `has_plans_assigned` is `true`

#### Scenario: Resource assigned to no plans → false

- GIVEN resource "R" is assigned to zero plans
- WHEN serialized
- THEN `has_plans_assigned` is `false`

### R5: No test regressions

All 49 existing tests across 3 test files MUST continue to pass without modification.

#### Scenario: Existing test suite passes

- GIVEN the 3 test files (ResourceAccessTest, ShopControllerPivotTest, ResourceControllerTest)
- WHEN each is run with `--filter`
- THEN all pass (9 + 3 + 37 = 49)

## What MUST NOT Change

- `included_in_plan_names` — continues to list all plans (unfiltered)
- `has_plans_assigned` — continues to check assignment to ANY plan
- `ResourcesSeeder.php` — already correct, no changes needed
- No frontend files, migrations, or new files created
- No `.env.testing`, `phpunit.xml`, or test database config changes
