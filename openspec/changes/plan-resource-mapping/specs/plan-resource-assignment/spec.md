# Plan — Resource Assignment

## Purpose

Admin assigns premium resources to plans via a many-to-many pivot. The pivot is the source of truth for "what resources are included in plan X" — read at access time, never duplicated to entitlements.

## Requirements

### R1: Pivot table `plan_resource`

The system SHALL create a `plan_resource` pivot table with `plan_id` (FK → plans), `resource_id` (FK → resources), and a UNIQUE constraint on the pair. Both FKs MUST cascade on delete.

#### Scenario: Pivot stores plan-resource pairs
- GIVEN a Plan and a Resource exist
- WHEN the admin assigns the resource to the plan
- THEN `plan_resource` contains exactly one row with their IDs

#### Scenario: Duplicate pair is rejected
- GIVEN the pair (plan_id, resource_id) already exists in the pivot
- WHEN a second INSERT for the same pair is attempted
- THEN the UNIQUE constraint rejects it

#### Scenario: Deleting a plan cleans its pivot rows
- GIVEN two resources assigned to a plan
- WHEN the plan is deleted
- THEN both pivot rows are deleted (CASCADE)

### R2: Plan `resources()` BelongsToMany

The Plan model MUST define `resources(): BelongsToMany` via `plan_resource`. The Resource model MUST define `plans(): BelongsToMany` via the same pivot.

### R3: Plan form accepts `resource_ids`

`PlanController::store` and `update` MUST accept an optional `resource_ids` array. Validation MUST require each ID to exist in the `resources` table. Only resource IDs are accepted — no other pivot fields.

#### Scenario: Plan creation with assigned resources
- GIVEN 3 active resources exist
- WHEN the admin creates a plan with `resource_ids: [1, 2]`
- THEN the plan has exactly 2 resources in the pivot

#### Scenario: Invalid resource ID is rejected
- GIVEN a resource ID 999 does not exist
- WHEN the admin submits a plan with `resource_ids: [999]`
- THEN validation fails with a resource ID error

#### Scenario: Empty resource_ids is allowed
- GIVEN no resources are selected on the plan form
- WHEN the admin creates/updates a plan
- THEN the plan has zero resources in the pivot

### R4: Resource form accepts `plan_ids`

`Landlord\ResourceController::store` and `update` MUST accept an optional `plan_ids` array. Validation MUST require each ID to exist in the `plans` table.

#### Scenario: Resource creation with assigned plans
- GIVEN 2 active plans exist
- WHEN the admin creates a resource with `plan_ids: [1]`
- THEN the resource has exactly 1 plan in the pivot

#### Scenario: Resource update syncs plan_ids
- GIVEN a resource has plan [1]
- WHEN the admin updates with `plan_ids: [2, 3]`
- THEN the resource has plans [2, 3] and plan 1 is removed

### R5: Admin UI shows assigned resources

The plan edit/create form MUST include a resource multi-select component. The resource edit/create form MUST include a plan multi-select component.

#### Scenario: Plan edit shows currently assigned resources
- GIVEN a plan has resources [A, B]
- WHEN the admin opens the plan edit form
- THEN resources A and B are pre-selected in the multi-select

#### Scenario: Plan index shows assigned resource count
- GIVEN a plan has 3 resources
- WHEN the admin views the plan list
- THEN each plan row displays its resource count
