# Design: landlord-sales-reporting

## Technical Approach

Single Inertia page at `/admin/sales` backed by `SalesDashboardController@index` computing all KPIs, breakdowns, and aggregates via raw Eloquent queries — no AJAX, no service class. Date range changes trigger a full Inertia visit with query params (`from`/`to`), re-executing all queries server-side. Tenant detail page gets an "Orders" section via eager-load in the existing `TenantController@show`.

## Architecture Decisions

### Decision: Single index() method (not index + stats)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Single `index()` | All data in one Inertia pass; date filter change = full reload | **Chosen** — simpler, matches OrderController and AdminPanelController patterns |
| `index()` + `stats()` | Partial page updates via AJAX/Inertia replace | Rejected — pre-emptive optimization; aggregate queries are fast with indexed columns |

### Decision: Raw Eloquent aggregates (no service class)

Follow `ReconciliationDashboardController` pattern: `selectRaw` / `DB::raw()` directly in the controller method. Queries are 5-10 lines each; extracting to a service adds indirection without reuse benefit across controllers.

### Decision: KPI card as shared component

Create `resources/js/components/ui/kpi-card.tsx` — reusable `<KpiCard>` accepting `label`, `value`, `change`, `changeType`. Used by the sales page and available for future dashboards.

### Decision: Period-over-period in same query batch

When `from` and `to` are both present, compute prior period of equal length and run all aggregate queries twice — once for current, once for prior. Use a private helper `aggregatePeriod($from, $to)` to avoid duplication.

## Data Flow

```
Admin clicks /admin/sales or date filter
  → Inertia visit GET /admin/sales?from=...&to=...
  → SalesDashboardController@index
    ├─ validate from/to
    ├─ totalRevenue($from, $to)          → selectRaw sum(amount_cents) where verified
    ├─ paidOrdersCount($from, $to)       → count orders with verified payments
    ├─ averageOrderValue($from, $to)     → revenue / paid orders
    ├─ canceledAmount($from, $to)        → selectRaw sum(amount_cents) where cancelled
    ├─ totalOrders($from, $to)           → count all orders
    ├─ revenueByPaymentMethod($from, $to)→ group payments.payment_method
    ├─ revenueByType($from, $to)         → order plan vs resource
    ├─ topPlans($from, $to)              → paid orders grouped by plan_id
    ├─ topResources($from, $to)          → paid orders grouped by resource_id
    ├─ monthlyEvolution($from, $to)      → group by YYYY-MM on verified payments
    ├─ recentOrders($from, $to)          → last 10 orders with tenant + buyable
    ├─ revenueVsCancellations($from, $to)→ verified sum vs cancelled sum
    └─ periodChange(from, to)            → re-runs queries for prior period
  → Inertia render('admin/sales/index', { ... })
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/SalesDashboardController.php` | Create | Single `index()` computing all aggregates |
| `resources/js/pages/admin/sales/index.tsx` | Create | Full sales reporting page with all sections |
| `resources/js/components/ui/kpi-card.tsx` | Create | Reusable KPI card component |
| `routes/landlord.php` | Modify | Add `GET /admin/sales → landlord.sales.index` |
| `resources/js/pages/landlord/admin-panel.tsx` | Modify | Add "Sales" card to BILLING group |
| `app/Http/Controllers/Landlord/TenantController.php` | Modify | Eager-load last 10 orders in `show()` |
| `resources/js/pages/landlord/tenants/show.tsx` | Modify | Add purchase history section after subscription card |
| `tests/Feature/Landlord/SalesDashboardControllerTest.php` | Create | Feature tests for all KPI scenarios |

## Interfaces / Contracts

```typescript
// Inertia page props for admin/sales/index.tsx
type SalesPageProps = {
  kpis: {
    totalRevenue: number;      // cents
    paidOrders: number;
    averageOrderValue: number; // cents
    canceledAmount: number;    // cents
    totalOrders: number;
    changes: {
      totalRevenue: number;      // percentage
      paidOrders: number;
      averageOrderValue: number;
      canceledAmount: number;
      totalOrders: number;
    };
  };
  revenueByMethod: Array<{ method: string; amount_cents: number; percentage: number }>;
  revenueByType: Array<{ type: string; amount_cents: number; percentage: number }>;
  topPlans: Array<{ plan: { id: number; name: string }; order_count: number; revenue_cents: number }>;
  topResources: Array<{ resource: { id: number; name: string }; order_count: number; revenue_cents: number }>;
  monthlyEvolution: Array<{ month: string; revenue_cents: number }>;
  recentOrders: Array<{
    id: number; total_cents: number; status: string; created_at: string;
    tenant: { id: number; name: string };
    buyable: { name: string } | null;
    buyable_type: string;
  }>;
  revenueVsCancellations: { revenue_cents: number; canceled_cents: number };
  filters: { from: string | null; to: string | null };
};

type KpiCardProps = {
  label: string;
  value: string;
  change?: number;     // percentage, null when no prior period
  trend?: 'up' | 'down' | 'neutral';
};
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Controller returns all Inertia props | `assertInertia` with nested assertions per KPI section |
| Feature | Date range filtering | Create payments in/out of range, assert only filtered results |
| Feature | Empty state (no data) | Assert all zeros, no errors |
| Feature | Period-over-period changes | Create payments in "current" and "prior" periods, assert % |
| Feature | Auth guards (unauthenticated, non-admin) | Match patterns from OrderControllerTest |
| Feature | Cancellations-only scenario | Only cancelled payments, verify AOV=0, Revenue=0 |
| Feature | Top items with ties | Two plans with same order count, assert same rank |
| Feature | Tenant purchase history | Orders appear on tenant show page |

## Threat Matrix

N/A — no shell commands, subprocesses, VCS/PR automation, executable-file classification, or process-integration boundary. Route addition is inside the existing admin middleware group (`auth` + `verified` + `EnsureUserIsAdmin`), no new security boundary.

## Migration / Rollout

No migration required. No new DB tables or columns. Existing Order/Payment models on landlord connection provide all data.

## Open Questions

None — all decisions are resolved against existing patterns.
