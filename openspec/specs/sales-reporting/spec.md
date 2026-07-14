# Sales Reporting Specification

## Purpose

Consolidated sales dashboard for the landlord admin panel. Admins view revenue KPIs, breakdowns, top-selling items, monthly trends, recent orders, and revenue-vs-cancellations summary — all filterable by optional date range.

## Requirements

### Requirement: KPI Cards

The system MUST display five KPI values for the selected period (all time if no range): **Total Revenue** (sum of verified `payments.amount_cents`), **Paid Orders** (count of paid orders), **Average Order Value** (revenue / paid orders, 0.00 when none), **Canceled Amount** (sum of cancelled `amount_cents`), **Total Orders** (all statuses). Each card SHOULD show % change vs prior period.

#### Scenario: Happy path with data
- GIVEN verified payments and orders exist in range
- WHEN the admin visits `/admin/sales`
- THEN KPI cards display correct values for that range

#### Scenario: Empty period
- GIVEN no orders or payments in range
- THEN all cards show 0 or "0.00" — no errors

#### Scenario: Cancellations only
- GIVEN cancelled but no verified payments
- THEN Canceled Amount shows sum, Revenue and AOV show 0

### Requirement: Revenue Breakdowns

The system MUST show revenue by **payment method** (PagoMóvil vs Bank Transfer) and **type** (Plans vs Resources), each with amount_cents and percentage.

#### Scenario: Mixed sources
- GIVEN verified payments from multiple methods and types
- THEN each shows its sum and correct percentage

#### Scenario: Zero revenue
- GIVEN no verified payments
- THEN all rows show 0 / 0%

### Requirement: Top Selling Items

The system MUST rank Plans and Resources by paid order count (descending), showing count and revenue. Ties MUST share the same rank.

#### Scenario: Items ranked
- GIVEN verified payments across multiple plans
- THEN plans sort by paid order count desc with revenue shown

#### Scenario: Tied ranking
- GIVEN two plans with equal paid order count
- THEN they share the same rank (both #1)

### Requirement: Monthly Evolution

The system MUST group verified revenue by month (YYYY-MM), ordered chronologically, for months with data in range.

#### Scenario: Data across months
- GIVEN payments in three distinct months
- THEN each month shows summed revenue chronologically

#### Scenario: Gaps in range
- GIVEN a range where some months have no payments
- THEN only months with data appear

### Requirement: Recent Orders

The system MUST show last 10 orders (`created_at` desc) regardless of status, with tenant name, buyable name, total, status, and date.

#### Scenario: Orders present
- GIVEN orders exist
- THEN the 10 most recent appear with all fields

#### Scenario: Fewer than 10
- GIVEN only 3 orders total
- THEN exactly 3 show — no padding

### Requirement: Revenue vs Cancellations

The system MUST show side-by-side totals of verified revenue vs cancelled amount for the period.

#### Scenario: Both exist
- GIVEN both verified and cancelled payments
- THEN both totals display side by side

#### Scenario: One side empty
- GIVEN only verified or only cancelled
- THEN the absent side shows 0

### Requirement: Tenant Purchase History

On the tenant detail page (`/admin/tenants/{tenant}`), the system MUST list that tenant's orders with amount, date, status, and buyable name. Zero orders MUST show an empty state.

#### Scenario: Tenant with orders
- GIVEN a tenant has orders
- WHEN viewing their detail page
- THEN all orders appear with required fields

#### Scenario: No orders
- GIVEN a tenant has zero orders
- THEN an empty state message is shown

### Requirement: Date Range Filtering

All sections MUST respect optional `from`/`to` ISO date query params. Absent = unfiltered (all time). Large ranges (years) MUST use efficient aggregate queries.

#### Scenario: With date filter
- GIVEN `?from=2026-01-01&to=2026-03-31`
- THEN all sections reflect data only within that range

#### Scenario: No filter
- GIVEN no `from`/`to` params
- THEN all data is unfiltered (all time)

#### Scenario: Large range
- GIVEN thousands of payments across years
- THEN queries complete without timeout
