# Design: 1.5H-expire — Subscription Expiration

## Technical Approach

Date-aware validity check at the model layer, daily Artisan command for bulk status transitions, and queued notification dispatch. The command handles both pre-expiry warnings (3-day window) and post-expiry notifications in a single pass, using notification existence checks for idempotency.

## Architecture Decisions

### Decision: Warning idempotency via notification query

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `notification_sent_at` column on subscriptions | Extra migration, couples notification tracking to model | Rejected — too coupled |
| Query `notifications` table for recent entry | No schema change, uses Laravel's built-in notifications table | **Chosen** — zero schema overhead |
| Cache-based flag (Redis key with TTL) | Fast but fragile — cache flush = duplicate sends | Rejected |

**Rationale**: Querying the `notifications` table (keyed by type + notifiable) is idempotent by construction. The 24h window check uses `created_at` from the notifications table. No extra columns needed.

### Decision: Single command handles warnings + expiry

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Separate commands: `subscriptions:warn` + `subscriptions:expire` | More granular scheduling, but two cron entries and duplicated query logic | Rejected |
| Single `subscriptions:expire` with both phases | One cron entry, shared query, simpler ops | **Chosen** |

**Rationale**: The warning query is a subset of the expiry query (Active AND `ends_at` between now and now+3d). Running both in one pass avoids redundant DB queries and simplifies deployment. The command is fast (two small queries + notification dispatch).

### Decision: Notification dispatch pattern

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `Notification::send()` (synchronous) | Blocks command, slower | Rejected |
| `Notification::send()` + queue driver | Fast but no retry visibility | Rejected |
| `Notification::create()` + queued mailable | In-app stored + email dispatched via queue | **Chosen** |

**Rationale**: `Notification::create()` persists the in-app notification (landlord dashboard) synchronously. The email mailable is dispatched via `Mail::to()->queue()` which leverages Laravel's queue system with retry and dead letter support.

### Decision: Notification recipients

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Separate notification classes per recipient | More classes, clearer intent | Rejected — overkill for same payload |
| Single class, resolved recipients in `via()` | One class, dynamic routing | **Chosen** |

**Rationale**: Both `SubscriptionExpiringWarning` and `SubscriptionExpired` notify the same set of recipients (tenant admins + landlord). Using `via()` to resolve recipients dynamically keeps the notification class count minimal. The `toArray()` payload is identical for both recipient types.

## Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     subscriptions:expire                         │
│                     (runs daily via Scheduler)                    │
└──────────────┬──────────────────────────────┬────────────────────┘
               │                              │
       ┌───────▼────────┐            ┌────────▼─────────┐
       │ Phase 1: WARN  │            │ Phase 2: EXPIRE  │
       │ Active AND     │            │ Active AND       │
       │ ends_at BETWEEN│            │ ends_at < now()  │
       │ now & now+3d   │            │                  │
       └───────┬────────┘            └────────┬─────────┘
               │                              │
       ┌───────▼──────────────────────────────▼─────────────────┐
       │ For each subscription:                                 │
       │  1. Check notification existence (idempotency)         │
       │  2. If Phase 2: update status → Expired                │
       │  3. Dispatch notification (in-app + email queue)       │
       └─────────────────────────────────────────────────────────┘
               │                              │
       ┌───────▼────────┐            ┌────────▼─────────┐
       │ In-app:        │            │ In-app:          │
       │ Notification:: │            │ Notification::   │
       │ create()       │            │ create()         │
       │ (landlord DB)  │            │ (landlord DB)    │
       ├────────────────┤            ├──────────────────┤
       │ Email:         │            │ Email:           │
       │ Mail::to()->   │            │ Mail::to()->     │
       │ queue()        │            │ queue()          │
       └────────────────┘            └──────────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Models/Subscription.php` | Modify | Add `isCurrentlyValid(): bool` method |
| `app/Models/Tenant.php` | Modify | `hasFeature()` and `activeSubscription()` delegate to `isCurrentlyValid()` |
| `app/Console/Commands/ExpireSubscriptions.php` | Create | Artisan command: queries, transitions, dispatches |
| `app/Notifications/SubscriptionExpiringWarning.php` | Create | In-app + queued email notification |
| `app/Notifications/SubscriptionExpired.php` | Create | In-app + queued email notification |
| `app/Mail/SubscriptionExpiringWarningMail.php` | Create | Mailable for warning email |
| `app/Mail/SubscriptionExpiredMail.php` | Create | Mailable for expiry email |
| `app/Http/Controllers/NotificationController.php` | Create | API: mark-as-read, mark-all-as-read |
| `app/Http/Middleware/HandleInertiaRequests.php` | Modify | Add `unread_notifications_count` shared prop |
| `resources/js/components/notifications/notification-bell.tsx` | Create | Bell icon with unread count badge |
| `resources/js/components/notifications/notification-dropdown.tsx` | Create | Dropdown list with mark-all-read |
| `resources/js/components/notifications/notification-row.tsx` | Create | Individual notification item |
| `resources/js/pages/notifications/index.tsx` | Create | Full notifications page |
| `resources/js/components/app-header.tsx` | Modify | Add NotificationBell to header |
| `resources/js/types/auth.ts` | Modify | Add `unread_notifications_count` to Auth type |
| `routes/console.php` | Modify | Register `$schedule->command('subscriptions:expire')->daily()` |
| `routes/web.php` | Modify | Add notification routes (GET index, PUT update, PUT markAllRead) |
| `database/migrations/landlord/xxxx_create_notifications_table.php` | Create | Laravel notifications table (landlord connection) |

## Interfaces / Contracts

```php
// Subscription — new method
public function isCurrentlyValid(): bool
{
    return $this->status === SubscriptionStatus::Active
        && ($this->ends_at === null || $this->ends_at->isFuture());
}

// Tenant — modified methods
public function activeSubscription(): ?Subscription
{
    return $this->subscription?->isCurrentlyValid() ? $this->subscription : null;
}

public function hasFeature(string $feature): bool
{
    $subscription = $this->subscription;
    if (! $subscription || ! $subscription->isCurrentlyValid()) {
        return false;
    }
    return $subscription->hasFeature($feature);
}

// ExpireSubscriptions command signature
protected $signature = 'subscriptions:expire';
protected $description = 'Expire overdue subscriptions and send notifications';
```

**Notification interface** (both classes implement):
```php
public function via(object $notifiable): array       // ['database', 'mail']
public function toArray(object $notifiable): array   // in-app payload
public function toMail(object $notifiable): MailMessage // email content
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `Subscription::isCurrentlyValid()` | 4 scenarios: Active+future, Active+past, Active+null, Expired |
| Unit | `Tenant::hasFeature()` with expired date | Assert returns false when ends_at is past |
| Unit | `Tenant::activeSubscription()` with expired date | Assert returns null when ends_at is past |
| Feature | `subscriptions:expire` transitions Active→Expired | Create subscription with past ends_at, run command, assert status |
| Feature | `subscriptions:expire` skips already Expired | Create Expired subscription, run command, assert no change |
| Feature | `subscriptions:expire` skips Active with future ends_at | Create subscription with future ends_at, run command, assert no change |
| Feature | Warning notification dispatched within 3-day window | Create subscription with ends_at = now+2d, run command, assert notification exists |
| Feature | Warning not duplicated within 24h | Send warning, run command again within 24h, assert no duplicate |
| Feature | Expired notification dispatched on transition | Run command on past-due subscription, assert notification exists |

## Migration / Rollout

1. Create `notifications` table migration on landlord connection (Laravel's built-in schema)
2. Deploy model changes — `isCurrentlyValid()` is additive, no breaking change
3. Deploy command + notifications — inactive until scheduler registered
4. Register scheduler in `routes/console.php` — command begins running daily
5. Existing Active subscriptions with past `ends_at` will be expired on first command run

No feature flags needed. The change is safe to deploy incrementally.

## Open Questions

- None — all design decisions resolved based on proposal and spec.
