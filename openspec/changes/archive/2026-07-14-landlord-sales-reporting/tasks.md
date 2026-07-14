# Tasks: landlord-sales-reporting

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~550–650 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr-default |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Full sales dashboard (KPI component + controller + route + frontend + test) | Single PR | `php artisan test --compact --filter=SalesDashboardController` | Visit `/admin/sales` with existing test tenants and orders | Remove route + delete controller + delete page dir + revert frontend changes |

## Phase 1: Foundation — KPI Component + RED Tests

- [x] 1.1 Create `resources/js/components/ui/kpi-card.tsx` — reusable `<KpiCard>` with `label`, `value`, `change?`, `trend?` props, colored trend indicator
- [x] 1.2 Write RED test: create `tests/Feature/Landlord/SalesDashboardControllerTest.php` — assert GET /admin/sales returns 200, Inertia props `kpis`, `revenueByMethod`, `revenueByType`, `topPlans`, `topResources`, `monthlyEvolution`, `recentOrders`, `revenueVsCancellations`, `filters` exist and are typed correctly

## Phase 2: Controller — SalesDashboardController

- [x] 2.1 Create `app/Http/Controllers/Landlord/SalesDashboardController.php` — `index(Request)`, validate optional `from`/`to` ISO date params via `date|nullable`, private `scopeDateRange($query, $from, $to, $column)` helper
- [x] 2.2 Implement KPI helpers: `totalRevenue()` → sum verified payments, `paidOrdersCount()`, `averageOrderValue()` → revenue / paid count, `canceledAmount()`, `totalOrders()`
- [x] 2.3 Implement breakdown + list helpers: `revenueByPaymentMethod()`, `revenueByType()`, `topPlans()`, `topResources()`, `monthlyEvolution()`, `recentOrders()` (last 10), `revenueVsCancellations()`
- [x] 2.4 Implement `periodChange()` — re-run all KPI queries for prior equal-length period, compute % change, return full Inertia render with all props

## Phase 3: Route + Frontend

- [x] 3.1 Add `Route::get('sales', [SalesDashboardController::class, 'index'])->name('sales.index')` in `routes/landlord.php` inside admin group
- [x] 3.2 Create `resources/js/pages/admin/sales/index.tsx` — date filter form (from/to inputs), 5 KPI cards row using `<KpiCard>`, revenue-by-method table, revenue-by-type table, top plans table, top resources table, monthly evolution table, recent orders list, revenue-vs-cancellations summary
- [x] 3.3 Add "Sales" link/card to BILLING group in `resources/js/pages/landlord/admin-panel.tsx`
- [x] 3.4 Make tests pass: date range filtering, empty range shows zeros, cancellations-only shows 0 revenue, period comparison shows correct %, ties share rank, large range completes

## Phase 4: Tenant Purchase History

- [x] 4.1 Add orders eager load + Inertia prop in `app/Http/Controllers/Landlord/TenantController@show`
- [x] 4.2 Add orders section (amount_cents, created_at, status, buyable name) + empty state message in `resources/js/pages/landlord/tenants/show.tsx` after subscription card
- [x] 4.3 Write test: tenant with orders shows history on detail page, tenant without orders shows empty state

## Phase 5: Security + Edge Case Tests

- [x] 5.1 Write tests: unauthenticated user gets 403, non-admin authenticated user gets 403 on /admin/sales
- [x] 5.2 Write test: large date range (5 years) with 100+ orders returns without error

## Phase 6: Cleanup

- [x] 6.1 Run `vendor/bin/pint --dirty --format agent`
- [x] 6.2 Run full test suite `php artisan test --compact`
