# Proposal: Tenant Billing Area

## Intent

Tenants currently see only plan name/price/description on the change-plan page. They cannot see their subscription status, renewal date, or feature list. There is no tenant-side history page — only the landlord admin can view subscription history. This creates a UX gap where tenants lack visibility into their billing state and cannot self-serve historical context.

## Scope

### In Scope
1. **Enhance `change-plan.tsx`**: Add features list (chips), subscription status badge, end/renewal date, and a "View history" link
2. **Create `/billing/history` page**: Route, controller, and Inertia page showing subscription change history for the current tenant
3. **Controller data enrichment**: `PlanChangeController::show()` passes subscription data (status, ends_at, trial_ends_at) alongside the plan

### Out of Scope
- Payment integration (future phase)
- Invoice generation or PDF export
- Audit section (ip_address, user_agent, expandable) — tenant history omits this per spec
- Plan comparison features or trial management

## Capabilities

### New Capabilities
- `tenant-billing-history`: Tenant-side subscription history view (route, controller, Inertia page, tests)

### Modified Capabilities
- `plan-change`: Controller passes subscription data (status, ends_at, trial_ends_at); page renders enhanced current plan card with features, status, renewal date, and history link

## Approach

1. **Modify `PlanChangeController::show()`** to include `$tenant->subscription` in the Inertia payload (not just `$tenant->subscription?->plan`)
2. **Enhance `change-plan.tsx`** to render subscription status, renewal date, feature chips from the subscription's plan, and a "View history" link
3. **Create `Billing\SubscriptionHistoryController`** with `index()` method — query `SubscriptionHistory` for current tenant, paginate, render Inertia page
4. **Create `billing/history.tsx`** — adapt landlord history page without audit section (no ip_address, user_agent, expandable toggle)
5. **Add route** `GET /billing/history` named `billing.history` in the existing billing route group
6. **Write feature tests** for render, empty state, pagination, auth (reuse test patterns from `PlanChangeControllerTest`)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Billing/PlanChangeController.php` | Modified | `show()` passes subscription object in Inertia payload |
| `resources/js/pages/billing/change-plan.tsx` | Modified | Enhanced current plan card: features chips, status badge, renewal date, history link |
| `app/Http/Controllers/Billing/SubscriptionHistoryController.php` | New | `index()` — query history for current tenant, paginate, render page |
| `resources/js/pages/billing/history.tsx` | New | Tenant history page (no audit section) |
| `routes/web.php` | Modified | Add `GET /billing/history` route |
| `tests/Feature/Billing/SubscriptionHistoryControllerTest.php` | New | Feature tests for history page |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Subscription data changes after page load (stale renewal date) | Low | Inertia preserves props on mutations; page refreshes on plan change |
| History query performance on large datasets | Low | Paginate with 20 items/page; composite index on `(tenant_id, created_at)` |
| Tenant user cannot access landlord DB SubscriptionHistory | None | `SubscriptionHistory` uses `UsesLandlordConnection` — already works cross-DB |

## Rollback Plan

Revert the 6 changed/new files to their pre-change state. No database migrations involved — only route, controller, page, and test additions. The enhanced change-plan page degrades gracefully if subscription data is missing (shows plan only, no history link).

## Dependencies

- Existing `SubscriptionHistory` model and table (already in place from `2026-06-11-plan-history-table`)
- Existing `ChangePlanService` already records history on plan changes
- Existing `formatPrice`, `formatDate`, `formatDateTime` utils

## Success Criteria

- [ ] Change-plan page shows subscription status, renewal date, and feature chips
- [ ] "View history" link navigates to `/billing/history`
- [ ] History page lists subscription events sorted newest-first with pagination
- [ ] History page shows empty state when no entries exist
- [ ] Unauthorized users (members, unauthenticated) cannot access either page
- [ ] All existing tests continue to pass
- [ ] New tests cover: render, empty state, pagination, auth checks
