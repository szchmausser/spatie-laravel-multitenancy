# Tasks: 1.5H-expire — Subscription Expiration

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~840 |
| 800-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR (budget extended) |
| Delivery strategy | ask-on-risk |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
800-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Full feature delivery (backend + frontend) | Single PR | All phases in one PR — budget extended to 840 lines |

## Phase 1: Infrastructure

- [x] 1.1 Create `database/migrations/landlord/xxxx_create_notifications_table.php` — Laravel notifications table schema on landlord connection (~30 lines)
- [x] 1.2 Run migration to verify: `php artisan migrate --path=database/migrations/landlord --database=landlord`

## Phase 2: Model Layer — TDD

- [x] 2.1 RED: Add 4 tests to `tests/Feature/Models/SubscriptionTest.php` for `isCurrentlyValid()` — Active+future, Active+past, Active+null, Expired (~40 lines)
- [x] 2.2 GREEN: Add `isCurrentlyValid(): bool` to `app/Models/Subscription.php` — checks status=Active AND (ends_at IS NULL OR ends_at > now()) (~10 lines)
- [x] 2.3 RED: Add 2 tests to `tests/Feature/Models/TenantFeatureTest.php` — tenant with past ends_at returns null/hasFeature=false, tenant with NULL ends_at retains access (~25 lines)
- [x] 2.4 GREEN: Update `Tenant::activeSubscription()` and `Tenant::hasFeature()` to delegate to `isCurrentlyValid()` instead of status-only check (~8 lines changed in `app/Models/Tenant.php`)

## Phase 3: Notifications — TDD

- [x] 3.1 RED: Create `tests/Feature/Notifications/SubscriptionExpiringWarningTest.php` — assert notification created when ends_at is within 3-day window, assert not duplicated within 24h (~50 lines)
- [x] 3.2 GREEN: Create `app/Notifications/SubscriptionExpiringWarning.php` — in-app via `Notification::create()` + email via queued mailable; `via()` returns `['database', 'mail']` (~45 lines)
- [x] 3.3 GREEN: Create `app/Mail/SubscriptionExpiringWarningMail.php` — mailable with subscription details and days remaining (~25 lines)
- [x] 3.4 RED: Create `tests/Feature/Notifications/SubscriptionExpiredTest.php` — assert notification created on status transition, assert sent to tenant admins + landlord (~40 lines)
- [x] 3.5 GREEN: Create `app/Notifications/SubscriptionExpired.php` — in-app + queued email; notifies tenant admins + landlord (~45 lines)
- [x] 3.6 GREEN: Create `app/Mail/SubscriptionExpiredMail.php` — mailable with subscription details (~25 lines)

## Phase 4: Expire Command — TDD

- [x] 4.1 RED: Create `tests/Feature/Console/ExpireSubscriptionsTest.php` — test Active→Expired transition, skip already Expired, skip Active with future ends_at, warning dispatch, expired dispatch, landlord notification (~80 lines)
- [x] 4.2 GREEN: Create `app/Console/Commands/ExpireSubscriptions.php` — queries Active+past ends_at, transitions status, dispatches warnings (ends_at between now and now+3d), dispatches expiry notifications, idempotent via notification query (~90 lines)
- [x] 4.3 Register `$schedule->command('subscriptions:expire')->daily()` in `routes/console.php` (~3 lines changed)

## Phase 5: Notification Controller — TDD

- [x] 5.1 RED: Create `tests/Feature/NotificationControllerTest.php` — test mark-as-read, mark-all-as-read, 403 for another user's notification (~80 lines)
- [x] 5.2 GREEN: Create `app/Http/Controllers/NotificationController.php` — PUT update (mark one), PUT markAllRead (~40 lines)
- [x] 5.3 Add routes to `routes/web.php` — GET /notifications (page), PUT /notifications/{notification}, PUT /notifications/read-all (~20 lines)
- [x] 5.4 Add `unread_notifications_count` shared prop to `HandleInertiaRequests.php` with resilient table check (~30 lines)
- [x] 5.5 Add `unread_notifications_count` to `resources/js/types/auth.ts` (~2 lines)

## Phase 6: Frontend Notification UI

- [x] 6.1 Create `resources/js/components/notifications/notification-row.tsx` — individual notification item with mark-as-read (~50 lines)
- [x] 6.2 Create `resources/js/components/notifications/notification-dropdown.tsx` — dropdown list with mark-all-read button (~80 lines)
- [x] 6.3 Create `resources/js/components/notifications/notification-bell.tsx` — bell icon with unread count badge (~60 lines)
- [x] 6.4 Create `resources/js/pages/notifications/index.tsx` — full notifications page (~80 lines)
- [x] 6.5 Add `NotificationBell` to `resources/js/components/app-header.tsx` — bell icon in header (~5 lines)

## Phase 7: Verify

- [x] 7.1 Run full test suite: `php artisan test --compact` — 299 passed, 3 skipped
- [x] 7.2 Run Pint on dirty files: `vendor/bin/pint --dirty --format agent` — 1 file fixed
- [x] 7.3 Verify TypeScript: `npx tsc --noEmit` — 0 errors
