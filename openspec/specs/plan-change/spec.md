# plan-change — Delta Spec

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
