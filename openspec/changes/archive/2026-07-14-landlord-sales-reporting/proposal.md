# Proposal: landlord-sales-reporting

## Intent

The admin panel has no consolidated view of revenue, orders, or payment trends. Admins must manually cross-reference Orders, Payments, and Reconciliation pages to understand sales health. This proposal adds a dedicated Sales Reporting page that works as both a quick daily snapshot and a custom-date-range reporting tool.

## Scope

### In Scope
- New `SalesDashboardController` with `index()` (KPIs + recent orders) and `stats(date_from, date_to)` (aggregated data)
- New Inertia page `admin/sales/index.tsx`: KPI cards (revenue, orders, avg value, canceled amount), revenue by payment method / type, top plans/resources, monthly evolution, recent orders, total revenue vs canceled summary
- Date range filter (from/to) on all stats
- Route `GET /admin/sales` → `landlord.sales.index`
- Link to sales page from admin panel (`/admin`)
- Tenant purchase history section on tenant detail page (`landlord/tenants/show.tsx`) — orders list with amounts, dates, status

### Out of Scope
- Charts/graphs (tables + numeric KPIs only)
- CSV/PDF export
- Sales thresholds / alerts
- New DB tables or migrations
- Sidebar navigation changes (access is via admin panel link)
- Per-admin sales attribution
- Tenant-side changes

## Capabilities

### New Capabilities
- `sales-reporting`: Consolidated sales dashboard for the landlord admin showing revenue KPIs, breakdowns by payment method and type, top-selling plans/resources, monthly trends, and recent orders — all filterable by date range

### Modified Capabilities
- None

## Approach

1. Create `SalesDashboardController` in `App\Http\Controllers\Landlord\` with two actions: `index()` returns the Inertia page with KPIs computed via aggregate queries on `Order` and `Payment` (all landlord-connection models, no new tables). `stats()` returns JSON for AJAX-driven date range filtering. Both use date filter scopes already present in existing controllers.

2. Build the Inertia page at `resources/js/pages/admin/sales/index.tsx` with reusable KPI card components and filter form. Wire the date filter to call `stats()` via Inertia replace or manual reload with query params.

3. Register route in `routes/landlord.php` inside the existing admin group.

4. Add a link on the `admin-panel.tsx` page pointing to `/admin/sales`.

5. Modify `TenantController@show` to eager-load recent orders and pass them as `recentOrders` to the tenant detail page. Add an "Orders" section to `landlord/tenants/show.tsx`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/SalesDashboardController.php` | New | Sales KPIs + stats endpoint |
| `routes/landlord.php` | Modified | Add `/admin/sales` route |
| `resources/js/pages/admin/sales/index.tsx` | New | Sales reporting page |
| `resources/js/pages/landlord/admin-panel.tsx` | Modified | Add link to sales page |
| `app/Http/Controllers/Landlord/TenantController.php` | Modified | Add orders to tenant show |
| `resources/js/pages/landlord/tenants/show.tsx` | Modified | Add purchase history section |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Large aggregate queries across all payments/orders | Low | All models on landlord connection, indexes exist on `status`, `created_at` |
| UX complexity from many KPI sections | Low | Follow existing card/table patterns from reconciliation dashboard |

## Rollback Plan

1. Remove the route `landlord.sales.index` from `routes/landlord.php`
2. Delete `SalesDashboardController`
3. Delete `resources/js/pages/admin/sales/` directory
4. Revert admin-panel.tsx link addition
5. Revert TenantController show + tenant show page changes

## Dependencies

- None (all data exists in current Order + Payment models)

## Success Criteria

- [ ] Admin can visit `/admin/sales` and see KPI cards with current-period data
- [ ] Date range filter updates all stats sections correctly
- [ ] Monthly evolution table shows correct revenue per month
- [ ] Tenant detail page shows that tenant's orders with amounts and statuses
- [ ] All existing tests continue to pass
- [ ] Feature tests for new controller cover date filtering and edge cases
