# Design: S8f — Dashboard de Conciliación Frontend

## Technical Approach

Static Inertia page rendering KPIs, orphan tables, and timeline from server-side props. Shadow toggle via `router.patch`. No client-side state management needed — all data arrives pre-computed from `ReconciliationDashboardController@index`.

## Architecture Decisions

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Sub-components vs single file | Extracting KpiCard / OrphanTable adds indirection for a contained page | **Single file** — under 250 lines, no reuse across pages yet |
| `timeAgo` helper | Currently duplicated in `alerts.tsx`; reconciliation needs it too | **Extract to `utils.ts`** — deduplicate once, use everywhere |
| Shadow toggle: `router.patch` vs fetch | `fetch` is faster but bypasses Inertia flash/progress events | **`router.patch`** — consistent with alerts `markAsRead` pattern |
| Wayfinder vs literal URLs | Admin panel uses both; no Wayfinder function exists for reconciliation routes | **Literal URLs** — `/admin/reconciliation`, `/admin/reconciliation/shadow-mode` (consistent with `admin-panel.tsx`) |

## Data Flow

```
GET /admin/reconciliation
       │
       ▼
ReconciliationDashboardController@index
       │
       ▼
Inertia render → landlord/reconciliation/index.tsx
       │
       ├─ KPI Section (matchRate, autoverifiedToday, activeAlerts, failedNotifications, shadowModeEnabled)
       ├─ Orphan Tables Section (orphanedPayments, orphanedNotifications)
       └─ Timeline Section (timeline)

PATCH /admin/reconciliation/shadow-mode { enabled: bool }
       │
       ▼
toggleShadowMode() → redirect back → page re-renders with new props
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `resources/js/pages/landlord/reconciliation/index.tsx` | Create | Dashboard page: 3-section layout |
| `resources/js/types/models.ts` | Modify | +5 interfaces for reconciliation data |
| `resources/js/lib/utils.ts` | Modify | +`timeAgo()` helper extracted from alerts pattern |
| `tests/Browser/Landlord/ReconciliationDashboardBrowserTest.php` | Create | 5 browser tests covering all sections |

## Interfaces / Contracts

```ts
// Added to resources/js/types/models.ts
export interface MatchRateData {
    percentage: number;
    total: number;
    matched: number;
    by_status: { matched: number; unmatched: number; pending: number; duplicate: number };
}

export interface OrphanedPayment {
    id: number; amount_cents: number; created_at: string; transaction_id: string | null;
}

export interface OrphanedNotification {
    id: number; amount_cents: number; created_at: string;
}

export interface TimelineItem {
    type: 'match' | 'notification' | 'verification';
    description: string; timestamp: string; url: string | null;
}

export interface ReconciliationPageProps {
    matchRate: MatchRateData;
    autoverifiedToday: number;
    activeAlerts: number;
    failedNotifications: number;
    shadowModeEnabled: boolean;
    orphanedPayments: OrphanedPayment[];
    orphanedNotifications: OrphanedNotification[];
    timeline: TimelineItem[];
}
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Browser | Empty state — all sections | `waitForText('Dashboard de Conciliación')`, assert empty states |
| Browser | KPI cards render | Check `[data-testid="kpi-*"]` exist with values |
| Browser | Match rate displays | Factory create matches, assert percentage visible |
| Browser | Shadow toggle | Click `[data-testid="shadow-toggle-btn"]`, assert flash message |
| Browser | Orphan tables render | Factory create orphans, assert rows in tables |
| Browser | Timeline renders | Factory create matches/notifications, assert timeline items |
| Browser | Timeline empty state | No events, assert "No hay actividad reciente" |

Test helper: use `Landlord::factory()->createQuietly()` in `beforeEach`, extend `BrowserTestCase` (already cleans up `payments`, `payment_notifications`, `users`).

## Migration / Rollout

No migration required. Route `/admin/reconciliation` and `PATCH /admin/reconciliation/shadow-mode` already exist from S8f-a. The admin panel card linking to it already exists (`admin-panel.tsx:84`).

## Open Questions

- None. Design is self-contained; backend is already deployed.
