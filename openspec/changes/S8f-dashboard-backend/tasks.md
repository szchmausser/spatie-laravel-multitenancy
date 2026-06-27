# Tasks: S8f — Reconciliation Dashboard Backend

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~180–220 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Controller + routes + admin card + tests | PR 1 | Single PR, base=main |

## Phase 1: Foundation

- [x] 1.1 Create `app/Http/Controllers/Landlord/ReconciliationDashboardController.php` with `index()` and `toggleShadowMode()` methods
- [x] 1.2 Add import + 2 routes in `routes/landlord.php` (`reconciliation.index`, `reconciliation.shadow-mode`) inside admin group

## Phase 2: Core Implementation

- [x] 2.1 Implement `matchRate()` — `PaymentMatch` groupBy `match_status`, compute `percentage` (guard total=0), return `{percentage, total, matched, by_status}`
- [x] 2.2 Implement `autoverifiedToday()`, `activeAlerts()`, `failedNotifications()`, `shadowModeStatus()` — each a single query
- [x] 2.3 Implement `orphanedPayments()` and `orphanedNotifications()` with configurable threshold from `SystemConfig::get()`
- [x] 2.4 Implement `timeline()` — 3 separate queries (recent matches, notifications, verifications), map to `{type, description, timestamp, url}`, `sortByDesc()->take(20)->values()`
- [x] 2.5 Wire `index()` to collect all 8 KPIs + timeline and return `Inertia::render('landlord/reconciliation/index', [...])`
- [x] 2.6 Implement `toggleShadowMode()` — validate `{enabled: bool}`, `SystemConfig::set()`, redirect back with flash

## Phase 3: Integration

- [x] 3.1 Add reconciliation card to `resources/js/pages/landlord/admin-panel.tsx` — `LayoutDashboard` already imported, card entry inserted after "Notificaciones Bancarias"

## Phase 4: Testing

- [x] 4.1 Write `tests/Feature/Landlord/ReconciliationDashboardControllerTest.php` — index returns all KPI keys with expected types
- [x] 4.2 Write test: match rate percentage (total=0 → 0, mixed statuses)
- [x] 4.3 Write test: shadow toggle persists value and redirects back with flash
- [x] 4.4 Write test: shadow toggle rejects non-boolean payload with 422
- [x] 4.5 Write test: guest user redirected to login (auth guard)
- [x] 4.6 Write test: non-admin landlord gets 403 (admin guard)
