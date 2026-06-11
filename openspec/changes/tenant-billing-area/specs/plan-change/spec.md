# Delta for plan-change

## MODIFIED Requirements

### Requirement: Confirming a plan change updates `plan_id` and resets `ends_at`

The system SHALL update `subscriptions.plan_id` to the new plan and set `subscriptions.ends_at` to `now()->addMonth()` within the same transaction. The `status` field SHALL NOT be modified by `applyPlanChange()`. The `trial_ends_at` field SHALL NOT be modified. The mutation SHALL be wrapped in `DB::transaction` with `lockForUpdate()` on the subscription row.
(Previously: did not explicitly state that `status` is untouched)

#### Scenario: POST updates `plan_id` to the new plan

- GIVEN a tenant subscribed to `basic`
- WHEN the tenant-admin submits `POST /billing/change-plan` with `plan_id` pointing to `premium`
- THEN the `subscriptions` table row for this tenant has `plan_id` matching `premium`

#### Scenario: POST sets `ends_at` to one month from now

- GIVEN a tenant subscribed to `basic`
- WHEN the tenant-admin submits `POST /billing/change-plan` with a different `plan_id`
- THEN `subscriptions.ends_at` is within a few seconds of `now()->addMonth()`

#### Scenario: `status` is not modified on plan change

- GIVEN a tenant with `status = Active`
- WHEN the tenant-admin submits `POST /billing/change-plan` with a different `plan_id`
- THEN `subscriptions.status` remains `Active`

#### Scenario: `trial_ends_at` is left untouched

- GIVEN a tenant with `trial_ends_at` set to a future date
- WHEN the tenant-admin submits `POST /billing/change-plan` with a different `plan_id`
- THEN `subscriptions.trial_ends_at` remains unchanged

#### Scenario: Re-POSTing the same plan returns 422

- GIVEN a tenant subscribed to `basic`
- WHEN the tenant-admin submits `POST /billing/change-plan` with `plan_id` matching `basic`
- THEN the response is 422 with a validation error indicating the plan is already active

## ADDED Requirements

### Requirement: Change-plan page displays subscription metadata

The change-plan page SHALL render the current subscription's status, renewal date, and plan features alongside the existing plan name and price. `PlanChangeController::show()` SHALL pass `subscription` (status, ends_at, trial_ends_at) and `currentPlan.features` to the Inertia page.

#### Scenario: Active subscription shows status badge and renewal date

- GIVEN a tenant with an Active subscription on "Basic" with `ends_at = 2026-07-15`
- WHEN the tenant-admin visits `GET /billing/change-plan`
- THEN the current plan card displays a status badge "Active" and a renewal date "July 15, 2026"

#### Scenario: Trial subscription shows trial end date instead of renewal

- GIVEN a tenant with a Trialing subscription and `trial_ends_at = 2026-06-20`
- WHEN the tenant-admin visits `GET /billing/change-plan`
- THEN the current plan card displays "Trial ends June 20, 2026" instead of a renewal date

#### Scenario: Current plan card shows feature chips

- GIVEN a tenant on "Premium" plan with features `{email: true, reports: true}`
- WHEN the tenant-admin visits `GET /billing/change-plan`
- THEN the current plan card renders green checkmark chips for "Email" and "Reports"

#### Scenario: No subscription shows empty state

- GIVEN a tenant with no subscription record
- WHEN the tenant-admin visits `GET /billing/change-plan`
- THEN the page shows "No active subscription" and the available plans grid

#### Scenario: Change-plan page includes link to history

- GIVEN any tenant with or without a subscription
- WHEN the tenant-admin visits `GET /billing/change-plan`
- THEN a "View history" link navigates to `GET /billing/history`
