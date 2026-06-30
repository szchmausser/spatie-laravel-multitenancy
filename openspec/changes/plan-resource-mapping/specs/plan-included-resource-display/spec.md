# Plan-Included Resource Display

## Purpose

Frontend shows "Incluido en tu plan" badge with download for resources covered by the tenant's current plan. Three distinct states on resource cards: acquired (entitlement), plan-included (pivot), buyable (premium, not owned, not plan-included).

## Requirements

### R1: `userCanAccess()` reads pivot first

The access gate MUST check `plan_resource` pivot membership before falling through to entitlement. Logic order: free → plan-included → entitlement.

#### Scenario: Premium resource included in current plan
- GIVEN a tenant on plan "Pro", plan "Pro" includes premium resource "R"
- WHEN the tenant attempts to download "R"
- THEN access is granted (pivot check passes)

#### Scenario: Premium resource NOT included in current plan, no entitlement
- GIVEN a tenant on plan "Free", premium resource "R" is NOT in "Free"
- WHEN the tenant attempts to download "R"
- THEN access is denied

#### Scenario: Premium resource owned via entitlement, not plan-included
- GIVEN a tenant has entitlement for "R" but "R" is not in current plan
- WHEN the tenant attempts to download "R"
- THEN access is granted (entitlement check passes)

#### Scenario: Free resource always accessible
- GIVEN a non-premium resource
- WHEN any tenant attempts to download it
- THEN access is granted regardless of plan or entitlements

### R2: Serialization includes `is_included_in_plan`

Both `ResourceController::serializeResource()` and `ShopController::index()` MUST add a boolean `is_included_in_plan` to each resource response.

#### Scenario: Plan-included flag set true
- GIVEN resource "R" is in the tenant's current plan
- WHEN the shop or resources index loads
- THEN `is_included_in_plan: true` for resource "R"

#### Scenario: No current subscription
- GIVEN a tenant has no subscription
- WHEN the shop loads
- THEN `is_included_in_plan: false` for all premium resources

### R3: Shop card shows three states

Shop resource cards MUST display one of three mutually exclusive states:
- **Adquirido** (has active entitlement) — gray badge, no action
- **Incluido en tu plan** (is_included_in_plan) — green badge + download button
- **Comprar** (premium, no entitlement, not plan-included) — buy button

#### Scenario: Entitlement takes precedence over plan-included
- GIVEN resource "R" has an active entitlement AND is in current plan
- WHEN the shop renders
- THEN the card shows "Adquirido" (entitlement wins)

#### Scenario: Plan-included resource shows download
- GIVEN resource "R" is in current plan but has no entitlement
- WHEN the shop renders
- THEN the card shows "Incluido en tu plan" badge with Download button

#### Scenario: Free resource shows Download directly
- GIVEN a non-premium resource
- WHEN the shop renders
- THEN the card shows Download button, no state badge

#### Scenario: Premium non-included resource shows Buy
- GIVEN premium resource "R" with price_cents > 0, not in plan, no entitlement
- WHEN the shop renders
- THEN the card shows "Comprar" button

### R4: Resources index shows same three states

The `resources/index` page MUST render the same three-state logic using `can_download` (which now includes plan-included) and `is_included_in_plan`.

#### Scenario: Download button for plan-included resource
- GIVEN `can_download: true` and `is_included_in_plan: true`
- WHEN the resource card renders
- THEN a Download button is shown with "Incluido en tu plan" indicator

#### Scenario: Download for entitlement-only resource
- GIVEN `can_download: true` and `has_explicit_entitlement: true`
- WHEN the resource card renders
- THEN a Download button is shown without plan badge

### R5: Entitlement persists across plan changes

Individually purchased resources MUST remain accessible even if the tenant switches to a plan that does not include them.

#### Scenario: Plan change does not revoke entitlement access
- GIVEN a tenant owns entitlement for "R" (via direct purchase)
- WHEN the tenant changes from plan "Pro" (includes "R") to plan "Free" (excludes "R")
- THEN the tenant can still download "R" (entitlement is permanent)
