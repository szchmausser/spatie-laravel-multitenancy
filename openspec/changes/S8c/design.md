# Design: S8c — Alert Dashboard (SystemAlert)

## Technical Approach

Single-page Inertia dashboard at `/admin/alerts` that queries the landlord `notifications` table for `data->>'category' = 'system'`. Backend fix in `HandleInertiaRequests` enables the shared prop for Landlord instances. Badge renders inline in the admin sidebar, following the existing pattern (tenant "Notificaciones" badge at `app-sidebar.tsx:108`).

**5 features**: F1 (handle guard fix), F2+F3 (AlertController), F4 (alerts page), F5 (sidebar badge).

## Architecture Decisions

### Decision: Sidebar badge approach

| Option | Tradeoff |
|--------|----------|
| A: Extend `NavItem` + `NavMain` | Clean but touches shared components used everywhere |
| B: Inline render in `AppSidebar` | Follows existing pattern (tenant badge at line 108), no type changes |
| C: Separate admin nav group | Conceptually clean but more code than B |

**Choice**: **Option B** — render the "Alertas" nav item inline outside `<NavMain>` for admin only, badge as a `<span>` inside `SidebarMenuButton`. Same approach as the tenant "Notificaciones" badge. No changes to `NavItem` type or `NavMain`.

### Decision: Badge count scope

| Option | Tradeoff |
|--------|----------|
| Single `unread_notifications_count` for both | Mixes system + general notifications for Landlord |
| Separate `unread_system_alerts_count` prop | Clean semantics, minimal new code |

**Choice**: **Dual props** — `unread_notifications_count` shows all unread (both User/Landlord), `unread_system_alerts_count` shows only `category=system` for Landlord. Sidebar uses the system-specific count for the Alertas badge.

### Decision: Query filter params

| Strategy | Chosen? |
|----------|---------|
| `?severity=critical,warning` → `whereIn('data->>severity', [...])` | Yes |
| `?read=true/false` → `whereNull/whereNotNull('read_at')` | Yes |
| `?from=2026-06-01&to=2026-06-22` → `whereBetween('created_at', [$from, $to])` | Yes |
| Invalid severity → silently ignore (not 400) | Yes — simpler UX |

## Data Flow

```
Browser GET /admin/alerts?severity=critical&read=false
  │
  ▼
HandleInertiaRequests::share()
  │  auth.unread_notifications_count → Landlord fix (F1)
  │  auth.unread_system_alerts_count → new system-scoped prop (F5)
  │
  ▼
AlertController::index()
  │  WHERE notifiable_type = Landlord
  │  AND notifiable_id = auth()->id()
  │  AND data->>'category' = 'system'
  │  [AND data->>'severity' IN ('critical')]
  │  [AND read_at IS NULL]
  │  ORDER BY created_at DESC
  │  PAGINATE 20
  │
  ▼
Inertia → landlord/alerts.tsx
  │  alerts: paginated items
  │  filters: { severity, read, from, to }
  │
  ▼
POST /admin/alerts/{notification}/read
  │  → $notification->markAsRead()
  │  → redirect back → updated shared props
  │  → badge decrements on next Inertia response
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Middleware/HandleInertiaRequests.php` | Modify | F1: add `$user instanceof Landlord` guard; F5: add `unread_system_alerts_count` prop |
| `app/Http/Controllers/Landlord/AlertController.php` | Create | F2: `index()` with filters + pagination; F3: `read()` |
| `routes/landlord.php` | Modify | F2+F3: add alerts routes inside `prefix('admin')->name('landlord.')` group |
| `resources/js/pages/landlord/alerts.tsx` | Create | F4: filterable alerts page with empty state |
| `resources/js/components/app-sidebar.tsx` | Modify | F5: add "Alertas" inline nav item with badge for admin |
| `tests/Feature/Landlord/AlertControllerTest.php` | Create | Controller tests covering index filters + mark as read |

## Interfaces / Contracts

### Shared props (HandleInertiaRequests)

```php
'auth' => [
    'unread_notifications_count' => $this->resolveUnreadNotificationsCount($user),
    'unread_system_alerts_count' => $this->resolveUnreadSystemAlertsCount($user),
],
```

### Inertia page props (landlord/alerts.tsx)

```typescript
// Inertia page props
type AlertsPageProps = {
    alerts: {
        data: AlertItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        severity: string | null;
        read: string | null;   // "true" | "false" | null
        from: string | null;
        to: string | null;
    };
};

type AlertItem = {
    id: string;           // UUID
    type: string;         // Notification class FQN
    data: {
        category: 'system';
        type: string;     // e.g. "heartbeat_offline"
        message: string;
        severity: 'critical' | 'warning' | 'info';
    };
    read_at: string | null;
    created_at: string;
};
```

### Route definitions

```php
Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::post('/alerts/{notification}/read', [AlertController::class, 'read'])->name('alerts.read');
```

Both inside the existing `prefix('admin')->middleware(['auth', 'verified', EnsureUserIsAdmin::class])->name('landlord.')->group(...)`.

## Key Code Sketches

### HandleInertiaRequests — F1 guard fix (line 243)

```php
private function resolveUnreadNotificationsCount(?Authenticatable $user): int
{
    if (! $user instanceof User && ! $user instanceof Landlord) {
        return 0;
    }
    // ... existing table check + count() ...
}

private function resolveUnreadSystemAlertsCount(?Authenticatable $user): int
{
    if (! $user instanceof Landlord) {
        return 0;
    }
    // ... same table/db checks ...
    return $user->notifications()
        ->whereNull('read_at')
        ->where('data->>category', 'system')
        ->count();
}
```

### AlertController::index query

```php
$query = auth()->user()->notifications()
    ->where('data->>category', 'system');

if ($severity = $request->query('severity')) {
    $severities = explode(',', $severity);
    $allowed = ['critical', 'warning', 'info'];
    $query->whereIn('data->>severity', array_intersect($severities, $allowed));
}

if ($request->query('read') === 'true') {
    $query->whereNotNull('read_at');
} elseif ($request->query('read') === 'false') {
    $query->whereNull('read_at');
}

if ($request->query('from')) {
    $query->whereDate('created_at', '>=', $request->query('from'));
}
if ($request->query('to')) {
    $query->whereDate('created_at', '<=', $request->query('to'));
}

return Inertia::render('landlord/alerts', [
    'alerts' => $query->orderByDesc('created_at')->paginate(20),
    'filters' => $request->only(['severity', 'read', 'from', 'to']),
]);
```

### AppSidebar — F5 inline badge (admin section)

Inside `AppSidebar`, after `<NavMain>` and only for `isAdmin`:

```tsx
{isAdmin && (
    <SidebarGroup className="px-2 py-0">
        <SidebarGroupLabel>Alerts</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    isActive={isCurrentUrl('/admin/alerts')}
                    tooltip={{ children: 'Alertas' }}
                >
                    <Link href="/admin/alerts" prefetch>
                        <Bell />
                        <span>Alertas</span>
                        {unreadAlertsCount > 0 && (
                            <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-medium text-white">
                                {unreadAlertsCount > 99 ? '99+' : unreadAlertsCount}
                            </span>
                        )}
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
)}
```

### AlertController::read

```php
public function read(string $notification): RedirectResponse
{
    $notification = auth()->user()->notifications()
        ->where('data->>category', 'system')
        ->findOrFail($notification);

    $notification->markAsRead();

    return redirect()->back();
}
```

Use string binding (not route model binding) since `Notification` is not a regular Eloquent model with UUID binding configured. The `findOrFail` resolves the UUID from the landlord DB.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `resolveUnreadSystemAlertsCount` | Test with/without Landlord, with system/non-system notifications |
| Feature | `AlertController::index` | Filters (severity, read, date range), pagination, empty state, invalid severity ignored |
| Feature | `AlertController::read` | Mark as read, idempotent, 404 for non-system or wrong UUID, not owned |
| Feature | Sidebar badge | Shared prop `unread_system_alerts_count` reflects DB state |
| Inertia | Page render | `assertInertia` with component + props shape |

## Migration / Rollout

No migration required. No new tables. Existing notifications with `category=system` become visible immediately after deploy.

**Rollback**: Revert `HandleInertiaRequests.php` guard, remove routes, delete controller + page + test.

## Open Questions

- None resolved — all design decisions documented above.
