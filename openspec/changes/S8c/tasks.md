# Tasks: S8c — Alert Dashboard (SystemAlert)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~280–330 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

## Phase 1: Foundation — Shared Props (HandleInertiaRequests)

- [ ] 1.1 Add `$user instanceof Landlord` to `resolveUnreadNotificationsCount()` guard in `HandleInertiaRequests.php`
- [ ] 1.2 Add `resolveUnreadSystemAlertsCount()` method — query `data->>category='system'` with schema guard, only for Landlord
- [ ] 1.3 Wire `unread_system_alerts_count` into `auth` shared props array

## Phase 2: Backend — Controller + Routes

- [ ] 2.1 Create `AlertController::index()` — paginated 20/page, filters: severity (CSV, silencio inválidos), read=true/false, from/to date range
- [ ] 2.2 Create `AlertController::read()` — `findOrFail` string binding, `markAsRead()`, redirect back; 404 if not-owned or not-system
- [ ] 2.3 Register GET `/admin/alerts` and POST `/admin/alerts/{notification}/read` inside admin group in `landlord.php`

## Phase 3: Frontend — Alerts Page (landlord/alerts.tsx)

- [ ] 3.1 Create Inertia page with severity select (critical/warning/info), read toggle, date range inputs that trigger Inertia visit on change
- [ ] 3.2 Render alert list: severity badge (`bg-red-500`/`bg-yellow-500`/`bg-blue-500`), title, message, timestamp, mark-as-read button
- [ ] 3.3 Add empty state: "No hay alertas de sistema" when `data` is empty

## Phase 4: Frontend — Sidebar Badge (app-sidebar.tsx)

- [ ] 4.1 Add admin "Alertas" `<SidebarGroup>` after `<NavMain>` with `<Bell>` icon and inline red badge showing `unread_system_alerts_count`

## Phase 5: Tests

- [ ] 5.1 Feature test: `AlertController::index` — default list, severity filter, read=false filter, date range, pagination, empty state, invalid severity ignored
- [ ] 5.2 Feature test: `AlertController::read` — mark as read, idempotent, 404 for non-system notification, 404 for non-owned UUID
- [ ] 5.3 Feature test: Inertia shared props — `unread_notifications_count` for Landlord, `unread_system_alerts_count` reflects system-only count

### Commit Guidance (per work-unit-commits skill)

Keep tests with the code they verify:
- Commit 1: Phase 1 (HandleInertiaRequests) + Phase 5.3 (shared props test)
- Commit 2: Phase 2 (controller+routes) + Phase 5.1 + 5.2 (controller tests)
- Commit 3: Phase 3 (alerts page)
- Commit 4: Phase 4 (sidebar badge)
