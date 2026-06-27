# Proposal: S8f-a — Reconciliation Dashboard Backend

## Intent

Admins have no unified view of the reconciliation pipeline. They need a dashboard with KPIs (match rate, orphan payments, active alerts, shadow mode) to assess system health and act on issues.

## Scope

### In Scope
- `ReconciliationDashboardController` — `index()` with 8 KPI queries + combined timeline
- `toggleShadowMode()` — PATCH endpoint to toggle shadow mode on/off
- Route registration: `GET /admin/reconciliation`, `PATCH /admin/reconciliation/shadow-mode`
- Admin panel card: "Dashboard de Conciliación"
- Feature test covering both endpoints

### Out of Scope
- Dashboard React page (S8f-b — separate slice)
- Real-time polling or auto-refresh
- Tenant-specific reconciliation stats
- Historical KPI trends or charts

## Capabilities

### New Capabilities
- `reconciliation-dashboard`: Backend endpoints serving reconciliation KPIs + timeline. Exposes `index()` (Inertia response with 8 metrics) and `toggleShadowMode()` (PATCH with boolean validation). All queries use the landlord connection.

### Modified Capabilities
- None — the dashboard is a read-only consumer. No existing spec (alert-dashboard, system-config-ui, payment-match-ui) changes its requirements.

## Approach

Single controller with private query methods — follows the same pattern as AlertController (no extra service layer, KISS). Each KPI is a dedicated private method.

Timeline: 3 separate queries (recent matches, recent verifications, recent alerts) merged via PHP collections, sorted by timestamp, limited to 20. No UNION SQL — each model maps to a common shape independently.

Match rate: returns `percentage` (computed) + `by_status` (raw group counts). Frontend gets both.

Orphan threshold: 30 minutes, defined as a class constant for easy adjustment. Same threshold for both orphan payments and orphan notifications.

Shadow toggle: delegates to `SystemConfig::set('reconciliation.shadow_mode_enabled', $enabled)`, redirects with flash message.

Controller uses `UsesLandlordConnection` for the landlord DB.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/ReconciliationDashboardController.php` | New | Controller: index() + toggleShadowMode() |
| `routes/landlord.php` | Modified | +2 routes under admin group |
| `resources/js/pages/landlord/admin-panel.tsx` | Modified | +1 card entry |
| `tests/Feature/Landlord/ReconciliationDashboardControllerTest.php` | New | Feature tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Timeline query count (3 queries) | Low | Each query limited to 20 rows — 60 objects in memory, sorting is trivial. No N+1. |
| Shadow toggle bypasses sentinel cache | Low | Use `SystemConfig::set()` — handles cache invalidation |
| Match rate division by zero | Low | Guard with `total > 0 ? ($matched / $total) * 100 : 0` |

## Rollback Plan

1. Remove the 2 routes from `routes/landlord.php`
2. Delete the controller + test file
3. Remove the card entry from `admin-panel.tsx`
4. Deploy — no data migration or schema change involved

## Dependencies

- Existing `PaymentMatch`, `Payment`, `PaymentNotification`, `SystemConfig` models
- Existing `notifications` table for alert count (per-admin `read_at`)

## Success Criteria

- [ ] `index()` returns all 8 KPIs + timeline in Inertia props
- [ ] Match rate includes both `percentage` and `by_status`
- [ ] Timeline contains mix of recent matches, verifications, and alerts
- [ ] `toggleShadowMode()` persists boolean and invalidates cache
- [ ] Admin panel card links to `/admin/reconciliation` with correct icon
- [ ] Feature tests cover: index returns data, toggle stores value, validation rejects non-boolean
