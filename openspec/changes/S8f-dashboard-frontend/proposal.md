# Proposal: S8f-dashboard-frontend — Reconciliation Dashboard UI

## Intent

Admins have no unified view of the reconciliation pipeline. S8f-a exposed the backend data; this slice renders the Inertia dashboard page with KPIs, orphan records, and timeline — giving admins a single pane to assess system health.

## Scope

### In Scope
- Inertia page at `landlord/reconciliation/index.tsx` with 3 sections (KPIs grid, orphan tables, timeline list)
- TypeScript types for all Inertia props (matchRate, autoverifiedToday, activeAlerts, failedNotifications, shadowModeEnabled, orphanedPayments, orphanedNotifications, timeline)
- Shadow mode toggle button → `PATCH /admin/reconciliation/shadow-mode`
- Empty states: no orphans, no timeline items
- Responsive layout: KPI grid, side-by-side orphan tables (stack on mobile)

### Out of Scope
- Filters/pagination (dashboard is a snapshot)
- Charts (no chart library installed)
- Auto-refresh/polling (deferred)
- Server-side changes (already shipped in S8f-a)

## Capabilities

### New Capabilities
- None — the `reconciliation-dashboard` backend capability already defines the data contract. A delta spec will document the UI rendering requirements.

### Modified Capabilities
- None — this is a pure UI layer consuming existing props. No existing spec (alert-dashboard, payment-match-ui, system-config-ui) changes its requirements.

## Approach

Page-only change. Single `index.tsx` receiving Inertia props from `GET /admin/reconciliation`.

- **KPIs**: Grid of shadcn `Card` components (pattern from `admin-panel.tsx`) — match rate (big % + `by_status` breakdown), autoverified, active alerts, failed notifications, shadow mode badge + toggle
- **Orphans**: Two `<table>` elements side by side (`md:grid-cols-2`, mobile stack) inside a `Card`
- **Timeline**: Vertical list with type dot/icon, description, timestamp, optional link
- **Shadow toggle**: `router.patch()` via Inertia, `preserveScroll`, button disabled during request
- **Empty states**: Centered icon + message per section when collections are empty

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `resources/js/pages/landlord/reconciliation/index.tsx` | New | Dashboard page component |
| `resources/js/types/models.ts` | Modified | +ReconciliationPageProps, OrphanPayment, OrphanNotification, TimelineItem |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Orphan tables overflow in side-by-side layout | Low | `overflow-x-auto` + `text-xs` + truncate long IDs |
| Shadow toggle lacks loading feedback | Low | Disable button + show spinner via `router.on('start',...)` |
| Match rate by_status keys mismatch backend | Low | Align types with backend JSON shape — test against real response |

## Rollback Plan

1. Delete `resources/js/pages/landlord/reconciliation/index.tsx`
2. Revert type additions in `resources/js/types/models.ts`
3. Deploy — no data, schema, or route changes to revert

## Dependencies

- S8f-a backend (already deployed) — this page consumes its Inertia props
- Existing shadcn components: Card, Badge, Button

## Success Criteria

- [ ] KPI grid renders all 5 metrics with correct values
- [ ] Shadow mode badge reflects current state; toggle triggers PATCH and updates
- [ ] Orphan tables render payments and notifications with all columns
- [ ] Timeline renders items with type indicator, description, timestamp, optional link
- [ ] Empty states render per section when collections are empty
- [ ] All sections have `data-testid` attributes for browser tests
