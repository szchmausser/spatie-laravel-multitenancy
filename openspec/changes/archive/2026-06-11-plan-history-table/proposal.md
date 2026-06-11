# Proposal: Plan History Table

## Intent

No audit trail exists for subscription mutations. When a tenant claims "I paid premium and they downgraded me," there's no record to resolve the dispute. This change adds a `subscription_history` table in the landlord DB that snapshots plan state at each mutation point, providing an immutable audit trail for conflict resolution, refund support, analytics, and compliance. The table includes full context: who made the change, from where (IP/user agent), why, what was charged, and the billing period.

## Scope

### In Scope
- New `subscription_history` migration (landlord DB) with full audit fields:
  - `id`, `subscription_id` (FK), `tenant_id` (FK)
  - `event_type` (enum: subscription_created, plan_changed, subscription_expired)
  - `actor_id` (nullable FK → users) — who initiated the change
  - `ip_address` (nullable VARCHAR(45)) — security audit trail
  - `user_agent` (nullable TEXT) — device/browser context
  - `reason` (nullable TEXT) — why the change was made (free text)
  - Old snapshot: `old_plan_name`, `old_plan_price_cents`, `old_plan_features` (jsonb), `old_status`
  - New snapshot: `new_plan_name`, `new_plan_price_cents`, `new_plan_features` (jsonb), `new_status`
  - Billing: `amount_cents` (nullable — actual amount charged, may differ from plan price), `currency` (VARCHAR(3), default 'USD'), `billing_period_start`, `billing_period_end` (nullable timestamps)
  - `correlation_id` (nullable UUID) — groups related changes (e.g., downgrade + refund)
  - `created_at`, `updated_at`
- `SubscriptionHistory` model with `UsesLandlordConnection`
- `SubscriptionHistory::record()` static factory method
- Recording at 4 mutation points (not 5 — tenant creation does NOT create subscriptions):
  1. **Subscription assigned** — `SubscriptionController::assign()` (landlord admin assigns plan)
  2. **Plan changed (self-service)** — `ChangePlanService::applyPlanChange()` via tenant-admin
  3. **Plan changed (landlord admin)** — `ChangePlanService::applyPlanChange()` via landlord admin
  4. **Subscription expired** — `ExpireSubscriptions::expireOverdueSubscriptions()`
- Snapshot fields: full plan details copied at write time (immune to future plan edits)
- Inertia page: `landlord/subscriptions/history` showing history per tenant with filters
- Route: `GET /landlord/tenants/{tenant}/subscription-history`
- Pest tests for model, recording, and controller

### Out of Scope
- Backfilling historical data (test env, data disposable)
- Automatic cleanup/retention policies (user will delete manually)
- Notifications or alerts on history events
- Landlord admin UI for searching across all tenants (per-tenant view only)
- Payment gateway integration (deferred)
- `subscription_cancelled` or `subscription_reactivated` events (no cancellation flow yet)

## Capabilities

### New Capabilities
- `subscription-history`: Immutable audit trail for subscription state changes — model, migration, recording service, and per-tenant history view

### Modified Capabilities
- `plan-change`: ChangePlanService MUST record a history entry after successful plan mutation (delta to existing spec)
- `subscription-expiry`: ExpireSubscriptions command MUST record a history entry after status transition (delta to existing spec)

## Approach

- **Service-layer recording** (not observers): all 4 mutation points are already centralized in services/controllers, so calling `SubscriptionHistory::record()` inline is clean and explicit.
- **Snapshot over FK**: plan details (`plan_name`, `plan_price_cents`, `plan_features`) are denormalized at write time. This preserves the truth even if the Plan row is later modified or deleted.
- **Full audit context**: `ip_address`, `user_agent`, `actor_id` captured on web requests for security auditing. CLI commands (expiry) leave these null.
- **Billing context**: `amount_cents` (actual charged amount, may differ from plan price due to discounts), `currency` (default USD), `billing_period_start/end` for billing analytics.
- **Correlation**: `correlation_id` groups related changes across tables (e.g., downgrade + refund).
- **Enum-driven event types**: `SubscriptionEventType` enum for type safety and exhaustive switch coverage.
- **Try/catch on recording**: history failure logs a warning but never blocks the primary subscription mutation.
- **Inertia page**: simple table view with tenant selector, sortable by date.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/landlord/` | New | `subscription_history` migration with full audit schema |
| `app/Models/SubscriptionHistory.php` | New | Model with UsesLandlordConnection + `record()` factory |
| `app/Enums/SubscriptionEventType.php` | New | Event type enum |
| `app/Services/Billing/ChangePlanService.php` | Modified | Record history after plan change |
| `app/Http/Controllers/Landlord/SubscriptionController.php` | Modified | Record history on assign |
| `app/Console/Commands/ExpireSubscriptions.php` | Modified | Record history on expiry |
| `app/Http/Controllers/Landlord/SubscriptionHistoryController.php` | New | History index per tenant |
| `resources/js/pages/landlord/subscriptions/history.tsx` | New | Inertia page |
| `routes/landlord.php` | Modified | Add history route |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| History recording failure blocks subscription mutation | Low | Wrap `record()` in try/catch — log warning, never abort the primary mutation |
| High volume of history rows over time | Low | Indefinite retention; user manages manually. Index on `tenant_id + created_at` keeps queries fast |
| Schema evolution of `plan_features` jsonb | Low | jsonb is schema-flexible; no migration needed for feature additions |
| `ip_address` / `user_agent` null for CLI commands | Expected | ExpireSubscriptions runs via cron — these fields are nullable by design. Actor is also null for system events |

## Rollback Plan

1. Drop `subscription_history` table
2. Remove `SubscriptionHistory` model and enum
3. Revert `ChangePlanService`, `SubscriptionController`, `ExpireSubscriptions` changes
4. Drop history route and Inertia page
5. Run `php artisan migrate:refresh` if needed

## Dependencies

- None (self-contained feature, no external packages)

## Success Criteria

- [ ] `subscription_history` table exists in landlord DB with correct schema (including audit fields: ip_address, user_agent, reason, billing fields)
- [ ] Each of the 4 mutation points creates a history record with correct snapshot data
- [ ] History page renders per-tenant history sorted by date
- [ ] `actor_id` is nullable — expiry events record without actor
- [ ] `ip_address` and `user_agent` captured on web requests, null on CLI commands
- [ ] `amount_cents` and `currency` stored for payment-related events
- [ ] `correlation_id` links related changes (e.g., downgrade + refund)
- [ ] `reason` field captured when provided
- [ ] `billing_period_start` and `billing_period_end` captured for billing context
- [ ] All Pest tests pass: model, recording, controller, feature
