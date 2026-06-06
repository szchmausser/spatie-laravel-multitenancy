# plan-change — Delta Spec

## Purpose

Self-service plan change for tenants whose users hold `change-plan`. The tenant selects a new plan from a UI that excludes the current plan; confirming immediately updates the subscription. The landlord can also change a tenant's plan from the admin panel. No proration, no payment gateway, no entitlement writes.

## ADDED Requirements

### Requirement: Tenant-side plan change requires the `change-plan` permission

The system SHALL gate the `GET/POST /billing/change-plan` route behind `Gate::allows('change-plan')`. The check is permission-based, not role-based, so a user with the permission granted directly (not via a role) MUST also be authorized.

#### Scenario: Tenant admin with `change-plan` can access the change-plan page

- GIVEN a user authenticated as a tenant-admin (role has `change-plan` granted)
- WHEN the user visits `GET /billing/change-plan`
- THEN the response is 200 and the change-plan page renders

#### Scenario: Tenant user without `change-plan` gets 403

- GIVEN a user authenticated in a tenant with no roles
- WHEN the user visits `GET /billing/change-plan`
- THEN the response is 403

#### Scenario: User with `change-plan` granted directly (not via role) is authorized

- GIVEN a user authenticated in a tenant with `change-plan` granted directly (no role)
- WHEN the user submits `POST /billing/change-plan` with a valid `plan_id`
- THEN the response is a redirect (not 403) and the subscription is updated

### Requirement: Change-plan UI excludes the current plan

The system SHALL present only plans the tenant is NOT currently subscribed to. The current plan's ID SHALL be excluded from the options list on the change-plan page.

#### Scenario: Tenant on `basic` sees `free` and `premium`

- GIVEN a tenant subscribed to the `basic` plan
- WHEN the tenant-admin loads the change-plan page
- THEN the available options are `free` and `premium`
- AND `basic` is NOT among the options

#### Scenario: Tenant on `free` sees `basic` and `premium`

- GIVEN a tenant subscribed to the `free` plan
- WHEN the tenant-admin loads the change-plan page
- THEN the available options are `basic` and `premium`

#### Scenario: Tenant on `premium` sees `free` and `basic`

- GIVEN a tenant subscribed to the `premium` plan
- WHEN the tenant-admin loads the change-plan page
- THEN the available options are `free` and `basic`

### Requirement: Confirming a plan change updates `plan_id` and resets `ends_at`

The system SHALL update `subscriptions.plan_id` to the new plan and set `subscriptions.ends_at` to `now()->addMonth()` within the same transaction. The `trial_ends_at` field SHALL NOT be modified. The mutation SHALL be wrapped in `DB::transaction` with `lockForUpdate()` on the subscription row.

#### Scenario: POST updates `plan_id` to the new plan

- GIVEN a tenant subscribed to `basic`
- WHEN the tenant-admin submits `POST /billing/change-plan` with `plan_id` pointing to `premium`
- THEN the `subscriptions` table row for this tenant has `plan_id` matching `premium`

#### Scenario: POST sets `ends_at` to one month from now

- GIVEN a tenant subscribed to `basic`
- WHEN the tenant-admin submits `POST /billing/change-plan` with a different `plan_id`
- THEN `subscriptions.ends_at` is within a few seconds of `now()->addMonth()`

#### Scenario: `trial_ends_at` is left untouched

- GIVEN a tenant with `trial_ends_at` set to a future date
- WHEN the tenant-admin submits `POST /billing/change-plan` with a different `plan_id`
- THEN `subscriptions.trial_ends_at` remains unchanged

#### Scenario: Re-POSTing the same plan returns 422

- GIVEN a tenant subscribed to `basic`
- WHEN the tenant-admin submits `POST /billing/change-plan` with `plan_id` matching `basic`
- THEN the response is 422 with a validation error indicating the plan is already active

### Requirement: Downgrade immediately blocks premium-only features

On downgrade, the read-path feature gate (`EnsureTenantHasFeature` middleware and `ResourceController::userCanAccess`) SHALL enforce the new plan's feature set immediately. No grace period. The `premium-content` feature is available on `basic` and `premium` plans, not on `free`.

#### Scenario: After `premium → free`, `premium-content`-gated route returns 403

- GIVEN a tenant on `premium` with access to a `premium-content`-gated route
- WHEN the tenant-admin changes the plan to `free`
- AND a user hits the `premium-content`-gated route
- THEN the response is 403

#### Scenario: After `premium → basic`, `premium-zone` (premium-only) returns 403

- GIVEN a tenant on `premium` with access to a `premium-zone`-gated route
- WHEN the tenant-admin changes the plan to `basic`
- AND a user hits the `premium-zone`-gated route
- THEN the response is 403

#### Scenario: `premium-content` still works after `premium → basic`

- GIVEN a tenant on `premium`
- WHEN the tenant-admin changes the plan to `basic`
- AND a user hits a `premium-content`-gated route
- THEN the response is 200 (because `basic` includes `premium-content`)

### Requirement: Purchase and Direct entitlements persist across plan changes

The system SHALL NOT delete or mutate `Entitlement` rows when a plan changes. Entitlements with `granted_via = Purchase` or `granted_via = Direct` and `expires_at IS NULL` are permanent and remain valid regardless of the new plan. Plan-issued entitlements (`granted_via = Plan`) are NOT explicitly mutated either, but become effectively unreachable when the feature gate fails on downgrade.

#### Scenario: Purchase entitlement survives `premium → free`

- GIVEN a user with a `Purchase` entitlement (non-expired) on a premium resource
- WHEN the tenant plan changes from `premium` to `free`
- THEN the `Entitlement` row still exists in the database
- AND `ResourceController::userCanAccess` returns `true` for that user and resource (rule 3: explicit entitlement)

#### Scenario: Direct entitlement survives any plan change

- GIVEN a user with a `Direct` entitlement (non-expired) on a resource
- WHEN the tenant plan changes to any other plan
- THEN the `Entitlement` row is unchanged and `isValid()` returns `true`

#### Scenario: Plan-issued entitlements become unreachable on downgrade

- GIVEN a resource that was accessible via `granted_via = Plan` entitlement
- WHEN the tenant plan changes from `premium` to `free` (which lacks `premium-content`)
- THEN `ResourceController::userCanAccess` returns `false` for that resource via rule 2 (plan check fails)
- AND the `Entitlement` row is NOT deleted (no write on plan change)

### Requirement: Landlord backdoor bypasses the `change-plan` permission

The system SHALL provide a separate `POST /admin/tenants/{tenant}/subscription/change` route guarded by `EnsureUserIsAdmin` (not by `Gate::allows('change-plan')`). The landlord has no Spatie permission tables and MUST NOT consult them.

#### Scenario: Landlord can change a tenant's plan via the admin route

- GIVEN an authenticated Landlord user (instance of `Landlord` model)
- WHEN the landlord submits `POST /admin/tenants/{tenant}/subscription/change` with a valid `plan_id`
- THEN the tenant's subscription `plan_id` is updated
- AND `ends_at` is reset to `now()->addMonth()`

#### Scenario: Tenant user hitting the admin route is rejected

- GIVEN a tenant user (not a Landlord instance) authenticated on the tenant domain
- WHEN the user submits `POST /admin/tenants/{tenant}/subscription/change`
- THEN the response is 403 from `EnsureUserIsAdmin`

#### Scenario: After landlord-initiated change, the subscription reflects the new plan

- GIVEN a landlord changes tenant T's plan from `basic` to `premium`
- WHEN tenant T's subscription is queried from the landlord database
- THEN `plan_id` matches `premium` and `ends_at` is within a few seconds of `now()->addMonth()`

### Requirement: Cross-tenant isolation on the tenant route

The tenant-side change-plan controller SHALL be scoped to the current tenant via the `tenant` middleware. A user authenticated for one tenant MUST NOT be able to change another tenant's plan through the tenant route.

#### Scenario: User on tenant1 cannot change tenant2's plan

- GIVEN a user authenticated on tenant1 with `change-plan` permission
- WHEN the user submits `POST /billing/change-plan` on tenant1's domain with a `plan_id` from tenant2
- THEN the change applies to tenant1's subscription only (the controller resolves the subscription from the current tenant context)

#### Scenario: Landlord route is tenant-id-keyed

- GIVEN an authenticated Landlord
- WHEN the landlord submits `POST /admin/tenants/{tenant}/subscription/change`
- THEN the `{tenant}` route parameter identifies the target tenant explicitly
- AND the subscription updated is the one belonging to that tenant

### Requirement: `lockForUpdate()` serializes concurrent plan changes

The system SHALL use `lockForUpdate()` on the subscription row within `ChangePlanService::applyPlanChange()` to prevent two concurrent POSTs from producing conflicting state. The second concurrent request SHALL see the already-updated row and return 422 (same-plan check).

#### Scenario: Concurrent POSTs serialize via row lock

- GIVEN a tenant on `basic`
- WHEN two concurrent `POST /billing/change-plan` requests arrive with different `plan_id` values
- THEN the first request succeeds and updates `plan_id`
- AND the second request acquires the lock after the first commits, sees the updated `plan_id`, and returns 422 (the plan it intended is now current, or the same-plan guard triggers)

#### Scenario: A POST during a slow transaction waits briefly

- GIVEN a subscription row is locked by a long-running transaction
- WHEN a second `POST /billing/change-plan` arrives
- THEN the second request waits for the lock (does not fail immediately)
- AND once the lock is released, the request completes normally

## Out of Scope (this change)

- Payment gateway, payment UI, confirmation, refunds — Phase 2
- Scheduled plan changes, grace periods, cooldowns, audit log — 1.5H / Phase 2
- Landlord Spatie roles / `change-plan` for Landlord — `1.5G.1-landlord-roles` (deferred)
- Touching `Entitlement` rows on downgrade — by design; acquired rights are permanent
- Subscription status transitions (e.g., `Expired`, `Cancelled`) — `1.5H`
- Prorated credits or mid-cycle billing — Phase 2
