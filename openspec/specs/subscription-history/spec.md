# Subscription History Specification

## Purpose

Immutable audit trail for subscription state changes in the landlord database. Records full plan snapshots, billing context, and actor metadata at every mutation point for conflict resolution, refund support, analytics, and compliance.

## Requirements

### Requirement: subscription_history table

The system SHALL create a `subscription_history` table in the landlord database with columns: `id`, `subscription_id` (FK → subscriptions), `tenant_id` (FK → tenants), `event_type` (enum), `actor_id` (nullable FK → users), `ip_address` (nullable VARCHAR(45)), `user_agent` (nullable TEXT), `reason` (nullable TEXT), `old_plan_name`, `old_plan_price_cents`, `old_plan_features` (jsonb), `old_status`, `new_plan_name`, `new_plan_price_cents`, `new_plan_features` (jsonb), `new_status`, `amount_cents` (nullable), `currency` (VARCHAR(3), default 'USD'), `billing_period_start`, `billing_period_end` (nullable), `correlation_id` (nullable UUID), `created_at`, `updated_at`. Composite index on `(tenant_id, created_at)`.

#### Scenario: Migration creates table with all columns

- GIVEN a fresh landlord database
- WHEN the migration runs
- THEN `subscription_history` exists with all columns per schema

#### Scenario: Migration is idempotent

- GIVEN the table already exists
- WHEN the migration runs again
- THEN no error occurs

---

### Requirement: SubscriptionHistory model and SubscriptionEventType enum

The system SHALL provide a `SubscriptionHistory` Eloquent model using `UsesLandlordConnection` with a `record()` static method accepting an attributes array. Fillable fields SHALL include all non-auto-generated columns. Casts: `event_type` → `SubscriptionEventType`, `old_plan_features`/`new_plan_features` → `array`, `correlation_id` → `string`. The `SubscriptionEventType` enum SHALL have values: `subscription_created`, `plan_changed`, `subscription_expired`.

#### Scenario: record() inserts a history row

- GIVEN a subscription and tenant exist
- WHEN `SubscriptionHistory::record([...])` is called with valid attributes
- THEN a row is inserted into `subscription_history` with the provided values

#### Scenario: Model queries against landlord connection

- GIVEN the model is instantiated
- WHEN any query runs
- THEN it executes against the landlord database connection

---

### Requirement: Snapshot denormalization

Plan details (`new_plan_name`, `new_plan_price_cents`, `new_plan_features`) SHALL be copied from the Plan model at write time. History entries SHALL NOT be updated if the Plan row is later modified or deleted.

#### Scenario: Snapshot reflects plan state at mutation time

- GIVEN plan "Basic" with price 2900 cents and features `["email"]`
- WHEN history is recorded for a subscription on that plan
- THEN `new_plan_name = "Basic"`, `new_plan_price_cents = 2900`, `new_plan_features = ["email"]`

#### Scenario: History survives plan edits

- GIVEN a history entry with `new_plan_name = "Basic"`
- WHEN the plan is renamed to "Starter"
- THEN the history entry still shows `new_plan_name = "Basic"`

---

### Requirement: Recording on subscription assignment

`SubscriptionController::assign()` SHALL record a `subscription_created` entry with `actor_id` = authenticated landlord admin, `ip_address`/`user_agent` from the HTTP request, and old snapshot fields null (no previous state).

#### Scenario: Assignment records history with actor context

- GIVEN a landlord admin assigns "Premium" (9900 cents) to a tenant
- WHEN `assign()` completes
- THEN a `subscription_created` entry exists with `actor_id`, `ip_address`, `user_agent` populated, old snapshot null, and `new_plan_name = "Premium"`

---

### Requirement: Recording on plan change

`ChangePlanService::applyPlanChange()` SHALL record a `plan_changed` entry with `actor_id` = authenticated user (tenant-admin or landlord admin), `ip_address`/`user_agent` from request. Old snapshot SHALL capture the previous plan's name, price, features, and status before mutation. New snapshot SHALL capture the new plan. `billing_period_start` and `billing_period_end` SHALL reflect the billing period.

#### Scenario: Plan change captures full old and new snapshots

- GIVEN subscription on "Basic" (2900 cents, Active) changing to "Premium" (9900 cents)
- WHEN `applyPlanChange()` completes
- THEN history has `old_plan_name = "Basic"`, `old_plan_price_cents = 2900`, `old_status = "Active"`, `new_plan_name = "Premium"`, `new_plan_price_cents = 9900`

#### Scenario: Landlord admin plan change records same entry type

- GIVEN a landlord admin changes a tenant's plan via the admin panel
- WHEN `applyPlanChange()` completes
- THEN a `plan_changed` entry exists with the landlord admin as `actor_id`

#### Scenario: Billing period captured

- GIVEN a plan change with billing start today
- WHEN history is recorded
- THEN `billing_period_start` is today and `billing_period_end` is start + 1 month

---

### Requirement: Recording on subscription expiry

`ExpireSubscriptions::expireOverdueSubscriptions()` SHALL record a `subscription_expired` entry with `actor_id`, `ip_address`, `user_agent` all null (CLI command, no HTTP context). Old snapshot SHALL capture plan and status before transition. New snapshot SHALL have `new_status = "Expired"` with previous plan values unchanged.

#### Scenario: Expiry records history without actor

- GIVEN an Active subscription on "Basic" with past `ends_at`
- WHEN the expire command runs
- THEN a `subscription_expired` entry exists with `actor_id = null`, `ip_address = null`, `user_agent = null`, `old_status = "Active"`, `new_status = "Expired"`

---

### Requirement: Recording failure resilience

History recording SHALL be wrapped in try/catch. On failure, the system SHALL log a warning and continue the primary operation. Subscription mutations SHALL NOT be blocked by history recording failures.

#### Scenario: Failure does not block assignment

- GIVEN `SubscriptionHistory::record()` throws an exception
- WHEN `SubscriptionController::assign()` runs
- THEN the subscription is still created and a warning is logged

#### Scenario: Failure does not block plan change

- GIVEN `SubscriptionHistory::record()` throws an exception
- WHEN `ChangePlanService::applyPlanChange()` runs
- THEN the plan change is still applied and a warning is logged

---

### Requirement: History page

`GET /landlord/tenants/{tenant}/subscription-history` SHALL display an Inertia React page with a table of history entries for the specified tenant, sorted by `created_at` descending. Columns: date, event type, old plan, new plan, amount, actor.

#### Scenario: Page lists tenant history sorted by date

- GIVEN a tenant with 3 history entries at different dates
- WHEN landlord admin visits the history page
- THEN all entries display sorted newest-first

#### Scenario: Page shows empty state

- GIVEN a tenant with no history entries
- WHEN landlord admin visits the history page
- THEN an empty state message is displayed

#### Scenario: History is scoped to tenant

- GIVEN two tenants each with history entries
- WHEN landlord admin views tenant A's history
- THEN only tenant A's entries are shown
