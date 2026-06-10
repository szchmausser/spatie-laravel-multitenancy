# Verify Report: 1.5H-expire

**Change**: 1.5H-expire
**Status**: PASS
**Date**: 2026-06-10

## Test Results

| Metric | Value |
|--------|-------|
| Total tests | 299 passed, 3 skipped |
| New tests | 19 (3 NotificationController + 4 Subscription + 3 TenantFeature + 3 ExpiringWarning + 2 Expired + 7 ExpireSubscriptions) |
| Failed | 0 |
| Assertions | 1090 |

## Code Quality

| Check | Status |
|-------|--------|
| PHPStan (LSP) | ✅ 0 errors in new files |
| Pint | ✅ 1 file fixed (imports ordering) |
| TypeScript | ✅ 0 errors |

## Coverage

| Requirement | Covered By | Status |
|-------------|------------|--------|
| `isCurrentlyValid()` | SubscriptionTest (4 tests) | ✅ |
| `hasFeature()` date-aware | TenantFeatureTest (3 tests) | ✅ |
| Expiring warning notification | SubscriptionExpiringWarningTest (3 tests) | ✅ |
| Expired notification | SubscriptionExpiredTest (2 tests) | ✅ |
| `subscriptions:expire` command | ExpireSubscriptionsTest (7 tests) | ✅ |
| Notification mark-as-read | NotificationControllerTest (3 tests) | ✅ |

## Files Created

| File | Lines |
|------|-------|
| `app/Console/Commands/ExpireSubscriptions.php` | ~90 |
| `app/Notifications/SubscriptionExpiringWarning.php` | ~63 |
| `app/Notifications/SubscriptionExpired.php` | ~63 |
| `app/Mail/SubscriptionExpiringWarningMail.php` | ~25 |
| `app/Mail/SubscriptionExpiredMail.php` | ~25 |
| `app/Http/Controllers/NotificationController.php` | ~35 |
| `resources/js/components/notifications/notification-bell.tsx` | ~40 |
| `resources/js/components/notifications/notification-dropdown.tsx` | ~55 |
| `resources/js/components/notifications/notification-row.tsx` | ~55 |
| `resources/js/pages/notifications/index.tsx` | ~85 |
| `database/migrations/landlord/..._create_notifications_table.php` | ~30 |
| `tests/Feature/NotificationControllerTest.php` | ~90 |
| `tests/Feature/Console/ExpireSubscriptionsTest.php` | ~100 |

## Files Modified

| File | Change |
|------|--------|
| `app/Models/Subscription.php` | +`isCurrentlyValid()` |
| `app/Models/Tenant.php` | `hasFeature()` uses `isCurrentlyValid()` |
| `app/Http/Middleware/HandleInertiaRequests.php` | +`unread_notifications_count` shared prop |
| `routes/web.php` | +3 notification routes |
| `routes/console.php` | +scheduler registration |
| `resources/js/components/app-header.tsx` | +NotificationBell |
| `resources/js/types/auth.ts` | +`unread_notifications_count` |

## Known Issues

None — all requirements covered, all tests passing.

## Recommendation

Ready to archive.
