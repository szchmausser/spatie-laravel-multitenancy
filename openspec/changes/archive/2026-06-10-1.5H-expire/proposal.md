# Proposal: 1.5H-expire — Subscription Expiration

## Intent

When a subscription's `ends_at` date passes, the tenant retains `Active` status indefinitely. There is no scheduled command, no date validation in the feature gate, and no notification infrastructure. This means tenants with expired dates never lose access to paid features, and landlords are never informed. The `SubscriptionStatus::Expired` enum exists but is never used.

## Scope

### In Scope

- `Subscription::isCurrentlyValid()` — checks status=Active AND (ends_at IS NULL OR ends_at > now())
- `Tenant::hasFeature()` and `activeSubscription()` use `isCurrentlyValid()` instead of status-only check
- `ChangePlanService::applyPlanChange()` does NOT touch `status` — reactivation is the payment gateway's responsibility (Phase 2)
- Scheduled Artisan command `subscriptions:expire` — daily, transitions Active→Expired for past-due subscriptions
- Notification classes: `SubscriptionExpiringWarning` (3 days before ends_at), `SubscriptionExpired` (on expiry)
- Notification dispatch: in-app via `Notification::create()`, email via queued mail
- Landlord notification when a tenant subscription expires
- Unit tests for model methods, feature tests for command, feature tests for notification dispatch

### Out of Scope

- Grace period — hard cutoff at `ends_at`
- Trialing lifecycle — enum value exists, no implementation
- Payment gateway integration
- Proration or credits
- `SubscriptionStatus::Suspended` (Phase 2)

## Capabilities

### New Capabilities

- `subscription-expiry`: Scheduled expiration command, model date validation, notification classes, status transitions (Active→Expired), landlord notification on expiry

### Modified Capabilities

- `plan-change`: `ChangePlanService::applyPlanChange()` does NOT modify status — reactivation requires payment gateway confirmation (Phase 2)

## Approach

1. **Model layer**: Add `isCurrentlyValid(): bool` to `Subscription` — checks status=Active AND (ends_at IS NULL OR ends_at > now()). Update `Tenant::hasFeature()` and `activeSubscription()` to call this method.
2. **Service layer**: `ChangePlanService::applyPlanChange()` does NOT modify `status`. Reactivation (Cancelled/Expired → Active) is the payment gateway's responsibility in Phase 2. Currently, only Active subscribers can change plans, so `status` stays unchanged.
3. **Scheduler**: Register `subscriptions:expire` in `routes/console.php` as `daily()`. Command queries Active subscriptions with `ends_at < now()`, sets status=Expired, dispatches notifications.
4. **Notifications**: Create `SubscriptionExpiringWarning` (mailable + in-app notification, sent 3 days before ends_at) and `SubscriptionExpired` (mailable + in-app notification, sent on expiry). Both notify the tenant's admin users. `SubscriptionExpired` also notifies the Landlord.
5. **Warning dispatch**: The expire command also checks for subscriptions where `ends_at` is between now and now+3 days and dispatches `SubscriptionExpiringWarning` (idempotent — only if not already sent).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Models/Subscription.php` | Modified | Add `isCurrentlyValid()` method |
| `app/Models/Tenant.php` | Modified | `hasFeature()` and `activeSubscription()` use `isCurrentlyValid()` |
| `app/Services/Billing/ChangePlanService.php` | Modified | No status change — reactivation deferred to Phase 2 payment gateway |
| `routes/console.php` | Modified | Register `subscriptions:expire` daily schedule |
| `app/Console/Commands/` | New | `ExpireSubscriptions` Artisan command |
| `app/Notifications/` | New | `SubscriptionExpiringWarning`, `SubscriptionExpired` |
| `app/Mail/` | New | Mailable classes for email notifications |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Race condition: user mid-request when scheduler flips status | Low | `isCurrentlyValid()` on next request handles gracefully; no mid-flight termination |
| Notification sent twice if command runs twice in a day | Low | Idempotent check: only dispatch if no existing notification for that subscription+type in last 24h |
| Expired tenant cannot reactivate without payment | Med | By design — Phase 2 payment gateway handles reactivation |

## Rollback Plan

1. Revert `isCurrentlyValid()` usage in `Tenant` methods (restore status-only check)
2. Remove scheduled command registration from `routes/console.php`
4. Delete notification classes and mailables
5. Run `php artisan test --compact` to verify no regressions

## Dependencies

- None external. All changes are within the existing Laravel app.

## Success Criteria

- [ ] Tenant with `ends_at` in the past gets 403 on feature-gated routes
- [ ] `subscriptions:expire` command transitions Active→Expired and sends notifications
- [ ] `SubscriptionExpiringWarning` dispatched 3 days before `ends_at`
- [ ] `SubscriptionExpired` dispatched on expiry, notifying tenant admins + landlord
- [ ] `ChangePlanService` does NOT reactivate Cancelled/Expired subscriptions (deferred to Phase 2)
- [ ] All existing tests pass; new tests cover model method, command, and notification dispatch
