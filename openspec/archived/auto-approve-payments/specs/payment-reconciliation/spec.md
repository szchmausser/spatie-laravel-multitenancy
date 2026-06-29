# Delta for payment-reconciliation

## ADDED Requirements

### Requirement: Payment Verification → Subscription Activation

When shadow mode is OFF and a payment is auto-verified, the `PaymentVerified` event SHALL trigger subscription activation for plan orders and entitlement granting for resource orders. The handler SHALL be idempotent — if the order is already `Paid`, the system MUST return without creating duplicates.

#### Scenario: Plan payment creates or updates subscription

- GIVEN a pending plan order with `total_cents` fully covered by verified payments
- WHEN `PaymentVerified` dispatches (shadow mode OFF)
- THEN the order status becomes `Paid`
- AND a landlord `Subscription` is created (or updated) with `status = Active`
- AND a `subscription_history` row is recorded with snapshot data

#### Scenario: Resource payment grants tenant-level entitlement

- GIVEN a pending resource order fully paid
- WHEN `PaymentVerified` dispatches
- THEN one `Entitlement` row is created for the (tenant, resource) pair
- AND no subscription is created

#### Scenario: Already-paid order ignored

- GIVEN an order with `status = Paid`
- WHEN `PaymentVerified` dispatches
- THEN the handler returns without creating records

#### Scenario: Partial payment skipped

- GIVEN a pending order not fully covered by verified payments
- WHEN `PaymentVerified` dispatches
- THEN no subscription or entitlement is created
- AND the order remains `Pending`

### Requirement: Tenant-Level Entitlements

The `entitlements` table SHALL have a unique constraint on `(tenant_id, resource_id)`. Access checks SHALL query by `tenant_id` only — any authenticated user of the tenant can download a resource the tenant owns.

#### Scenario: Single row per tenant+resource

- GIVEN a tenant purchases a resource
- WHEN the entitlement grant runs
- THEN exactly one `Entitlement` row exists for that tenant+resource

#### Scenario: All users inherit tenant entitlement

- GIVEN a tenant with a non-expired entitlement for resource R
- WHEN any user of that tenant requests a download
- THEN the access check returns `true` (no `user_id` filter)

## MODIFIED Requirements

### Requirement: Shadow Mode

The system SHALL operate in shadow mode by default (`reconciliation.shadow_mode_enabled = false`). When shadow mode is ON, matches are recorded but payments are NOT auto-verified.
(Previously: default was `true` — auto-approve required explicit opt-in)

#### Scenario: Shadow mode ON — match suggested not verified

- GIVEN `reconciliation.shadow_mode_enabled = true`
- AND a notification matches a pending payment
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'pending'`
- AND the payment remains `Pending`
- AND an admin notification is sent with the match suggestion

#### Scenario: Shadow mode OFF — auto-verify on match

- GIVEN `reconciliation.shadow_mode_enabled = false` (new default)
- AND a notification matches exactly one pending payment
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'matched'`
- AND `PaymentService::verifyPayment($payment, null)` is called
- AND a `PaymentVerified` event is dispatched after commit
