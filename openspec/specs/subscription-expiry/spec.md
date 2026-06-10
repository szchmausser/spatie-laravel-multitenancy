# subscription-expiry — Specification

## Purpose

Automated subscription expiration: when `ends_at` passes, the subscription becomes Expired immediately. A daily command transitions Active→Expired, dispatches warning/expiry notifications to tenant admins and the landlord. The feature gate uses date-aware validity, not status alone.

## Requirements

### Requirement: Subscription validity requires date-aware check

The system SHALL provide `Subscription::isCurrentlyValid(): bool` that returns `true` when `status = Active` AND (`ends_at IS NULL` OR `ends_at > now()`). Subscriptions with `status = Active` but `ends_at` in the past SHALL return `false`.

#### Scenario: Active subscription with future `ends_at` is valid

- GIVEN a subscription with `status = Active` and `ends_at = now()->addMonth()`
- WHEN `isCurrentlyValid()` is called
- THEN it returns `true`

#### Scenario: Active subscription with past `ends_at` is invalid

- GIVEN a subscription with `status = Active` and `ends_at = now()->subDay()`
- WHEN `isCurrentlyValid()` is called
- THEN it returns `false`

#### Scenario: Active subscription with NULL `ends_at` is valid

- GIVEN a subscription with `status = Active` and `ends_at = null`
- WHEN `isCurrentlyValid()` is called
- THEN it returns `true`

#### Scenario: Expired subscription is always invalid

- GIVEN a subscription with `status = Expired`
- WHEN `isCurrentlyValid()` is called
- THEN it returns `false` regardless of `ends_at`

### Requirement: Feature gate uses date-aware validity

The system SHALL use `Subscription::isCurrentlyValid()` in `Tenant::hasFeature()` and `Tenant::activeSubscription()`. A tenant with `status = Active` but past `ends_at` SHALL be denied feature-gated resources.

#### Scenario: Tenant with past `ends_at` gets 403 on feature-gated route

- GIVEN a tenant with `status = Active` and `ends_at` in the past
- WHEN a user hits a feature-gated route
- THEN the response is 403

#### Scenario: Tenant with NULL `ends_at` retains access

- GIVEN a tenant with `status = Active` and `ends_at = null`
- WHEN a user hits a feature-gated route
- THEN the response is 200

### Requirement: Daily expire command transitions Active→Expired

The system SHALL provide an Artisan command `subscriptions:expire` scheduled daily. It SHALL query subscriptions where `status = Active` AND `ends_at < now()`, set `status = Expired`, and dispatch notifications.

#### Scenario: Command expires past-due Active subscriptions

- GIVEN a subscription with `status = Active` and `ends_at = now()->subDay()`
- WHEN `subscriptions:expire` runs
- THEN the subscription `status` is `Expired`

#### Scenario: Command skips subscriptions already Expired

- GIVEN a subscription with `status = Expired`
- WHEN `subscriptions:expire` runs
- THEN no status change occurs and no notification is dispatched

#### Scenario: Command skips Active subscriptions with future `ends_at`

- GIVEN a subscription with `status = Active` and `ends_at = now()->addWeek()`
- WHEN `subscriptions:expire` runs
- THEN the subscription remains `Active`

### Requirement: Expiring warning notification sent 3 days before `ends_at`

The system SHALL dispatch `SubscriptionExpiringWarning` to all tenant admin users and the landlord when `ends_at` is between now and now+3 days. Notification is in-app via `Notification::create()` and email via queued mailable.

#### Scenario: Warning dispatched for subscription expiring in 2 days

- GIVEN a subscription with `ends_at = now()->addDays(2)`
- WHEN `subscriptions:expire` runs
- THEN tenant admin users receive `SubscriptionExpiringWarning` in-app and via email
- AND the landlord receives `SubscriptionExpiringWarning`

#### Scenario: Warning not sent if already dispatched within 24h

- GIVEN a subscription with `ends_at` within 3 days and an existing `SubscriptionExpiringWarning` sent 12 hours ago
- WHEN `subscriptions:expire` runs
- THEN no duplicate notification is dispatched

### Requirement: Expired notification sent on expiry

The system SHALL dispatch `SubscriptionExpired` to all tenant admin users and the landlord when a subscription transitions to `Expired`. Notification is in-app via `Notification::create()` and email via queued mailable.

#### Scenario: Expired notification dispatched on status transition

- GIVEN a subscription transitioning from `Active` to `Expired`
- WHEN `subscriptions:expire` completes the transition
- THEN tenant admin users receive `SubscriptionExpired` in-app and via email
- AND the landlord receives `SubscriptionExpired`

### Requirement: Expire command does NOT handle reactivation

The system SHALL NOT reactivate Expired subscriptions. Reactivation (Expired→Active) is the payment gateway's responsibility in Phase 2. The expire command SHALL NOT modify subscriptions with `status = Expired` or `status = Cancelled`.

#### Scenario: Expired subscription is not reactivated by command

- GIVEN a subscription with `status = Expired`
- WHEN `subscriptions:expire` runs
- THEN the subscription remains `Expired`

## Out of Scope (this change)

- Grace period — hard cutoff at `ends_at`
- Trialing lifecycle — enum value exists, no implementation
- Payment gateway integration
- Proration or credits
- `SubscriptionStatus::Suspended` (Phase 2)
