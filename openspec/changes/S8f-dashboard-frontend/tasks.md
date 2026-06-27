# Tasks: S8f — Dashboard de Conciliación Frontend

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~350-400 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | single PR |
| Delivery strategy | single-pr-default |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Types + Page Scaffold

- [ ] 1.1 Add `MatchRateData`, `OrphanedPayment`, `OrphanedNotification`, `TimelineItem`, `ReconciliationPageProps` interfaces to `resources/js/types/models.ts`
- [ ] 1.2 Create `resources/js/pages/landlord/reconciliation/index.tsx` with layout, breadcrumbs (`Admin > Dashboard de Conciliación`), `Head` title, and scroll vertical container

## Phase 2: KPIs Section

- [ ] 2.1 Implement 5-column KPI card grid: Match Rate (% + breakdown or "N/A"), Autoverified Today, Active Alerts (red if > 0), Failed Notifications (yellow if > 0), Shadow Mode badge
- [ ] 2.2 Implement shadow toggle button calling `router.patch('/admin/reconciliation/shadow-mode', {enabled: !shadowModeEnabled})` with `data-testid="shadow-toggle-btn"`

## Phase 3: Orphans + Timeline

- [ ] 3.1 Implement orphan tables section (2-col grid, stacks mobile): payments table (ID, Monto Bs. X, Transacción, Creado) + notifications table (ID, Monto, Creado), empty state "No hay registros huérfanos"
- [ ] 3.2 Implement timeline section with type icons (match→CheckCircle, notification→Bell, verification→ShieldCheck), relative timestamps "hace X min/h/d", optional link, empty state "No hay actividad reciente"
- [ ] 3.3 Add `data-testid` attributes: `orphaned-payments-table`, `orphaned-notifications-table`, `timeline-list`, `timeline-item-{index}`

## Phase 4: Browser Tests

- [ ] 4.1 Create `tests/Browser/Landlord/ReconciliationDashboardBrowserTest.php` with 6 tests: KPIs render, Match Rate percentage display, Shadow toggle (PATCH), Orphan payments table, Timeline with mixed events, Empty state when no data exists
