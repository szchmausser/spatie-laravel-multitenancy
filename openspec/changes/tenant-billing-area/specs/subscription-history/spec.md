# Delta for subscription-history

## ADDED Requirements

### Requirement: Tenant-facing subscription history page

The system SHALL provide `GET /billing/history` (named `billing.history`) that renders an Inertia React page listing the current tenant's subscription history. The route SHALL be protected by the same authorization as `PlanChangeController` (`$user->can('change-plan')`).

#### Scenario: Authorized user sees paginated history

- GIVEN a tenant with 15 history entries
- WHEN a tenant-admin visits `GET /billing/history`
- THEN the page renders 10 entries (first page) with prev/next pagination controls

#### Scenario: History entries exclude audit fields

- GIVEN a tenant with history entries containing `ip_address` and `user_agent`
- WHEN a tenant-admin visits `GET /billing/history`
- THEN entries display event type badge, date, plan cards, feature changes, reason, billing period, and amount — but NOT `ip_address`, `user_agent`, or actor info rows

#### Scenario: Empty state for tenants with no history

- GIVEN a tenant with zero history entries
- WHEN a tenant-admin visits `GET /billing/history`
- THEN the page shows "No subscription history entries yet"

#### Scenario: History is scoped to current tenant

- GIVEN two tenants each with history entries
- WHEN a tenant-admin of tenant A visits `GET /billing/history`
- THEN only tenant A's entries are shown

### Requirement: SubscriptionHistoryController

The system SHALL provide `App\Http\Controllers\Billing\SubscriptionHistoryController` with an `index()` method. The controller SHALL query `SubscriptionHistory` filtered by `tenant_id = Tenant::current()->id`, ordered by `created_at` descending, paginated at 10 per page. The controller SHALL use `UsesLandlordConnection` for cross-DB access.

#### Scenario: Controller returns paginated Inertia response

- GIVEN a tenant with 25 history entries
- WHEN `SubscriptionHistoryController::index()` is called
- THEN the Inertia page receives `history` with `data` (10 items), `current_page`, `last_page`, `per_page`, `total`

#### Scenario: Unauthorized user receives 403

- GIVEN a user without the `change-plan` permission
- WHEN they visit `GET /billing/history`
- THEN the response is 403 Forbidden

### Requirement: Tenant history page reuses landlord history components

The tenant history page SHALL reuse the `PlanCard`, `FeatureChanges`, `eventTypeBadgeVariant`, `eventTypeLabel`, and `eventTypeIcon` helpers from the landlord history page. The page SHALL NOT include the audit toggle section or actor info row present in the landlord view.

#### Scenario: Plan change entries show old and new plan cards

- GIVEN a history entry with `event_type = plan_changed`
- WHEN rendered on the tenant history page
- THEN both old and new plan cards display with feature chips

#### Scenario: Subscription created entries show new plan card only

- GIVEN a history entry with `event_type = subscription_created`
- WHEN rendered on the tenant history page
- THEN only the new plan card is displayed
