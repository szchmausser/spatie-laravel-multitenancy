# Proposal: Browser Test Coverage Expansion

## Intent

The project has 42 Inertia pages but only 9 browser test files covering ~29 tests (21% page coverage). Critical business flows — billing/orders, shop purchases, tenant user management, and settings self-service — have zero or minimal browser test coverage. A regression in the payment flow or user CRUD would go undetected until production.

This change expands browser test coverage to the highest-value untested domains, prioritized by business risk and user impact.

## Scope

### In Scope

- **Billing/Orders** (3 pages): `billing/orders/index`, `billing/orders/show`, `billing/history` — order listing, order detail, payment history
- **Shop** (1 page): `shop/index` — tenant purchase entry point
- **Tenant Users CRUD** (4 pages): `settings/users/*` — index, create, edit, show
- **Settings** (3 pages): `settings/profile`, `settings/security`, `settings/appearance`
- **data-testid additions** for pages missing stable selectors (roles, notifications, admin orders)
- **BrowserTestCase cleanup**: extract duplicated tenant connection setup into shared trait

### Out of Scope

- Admin orders (`admin/orders/*`) — landlord-only, lower priority than tenant-facing flows
- Roles (`settings/roles/*`) — depends on Spatie permission table DDL pattern already proven in ChangePlanFlowTest; defer to next phase
- Notifications (`notifications/index`) — fragile history-chain pattern needs design discussion first
- Landlord-side pages already covered by existing tests
- E2E tests (full login flows via UI) — `actingAs()` remains the pattern for auth preconditions

## Approach

**Phase 1 — Foundation** (shared infrastructure)
- Extract tenant connection setup + Spatie table DDL into a `TenantBrowserTestCase` base class
- Add `data-testid` attributes to: shop page, settings forms, order cards/rows

**Phase 2 — Billing & Shop** (highest business risk)
- `BillingOrdersIndexTest` — order list renders, status badges, link to detail
- `BillingOrdersShowTest` — order detail shows items, total, status, cancel action
- `BillingHistoryTest` — history page shows past orders
- `ShopIndexTest` — plan cards render, select plan triggers purchase flow

**Phase 3 — Tenant Users & Settings** (self-service CRUD)
- `TenantUsersBrowserTest` — index/create/edit/show with validation errors
- `SettingsProfileTest` — update name/email, validation
- `SettingsSecurityTest` — password change flow
- `SettingsAppearanceTest` — theme toggle

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `tests/Browser/Billing/` | New | 3 test files for orders + history |
| `tests/Browser/Shop/` | New | 1 test file for shop index |
| `tests/Browser/Tenant/` | Modified | New user CRUD tests, shared base class |
| `tests/Browser/Settings/` | New | 3 test files for profile/security/appearance |
| `tests/Browser/BrowserTestCase.php` | Modified | Extract tenant helpers to trait |
| `resources/js/pages/shop/index.tsx` | Modified | Add data-testid attributes |
| `resources/js/pages/settings/*.tsx` | Modified | Add data-testid attributes |
| `resources/js/pages/billing/**/*.tsx` | Modified | Add data-testid attributes |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Tenant connection DDL pattern brittle across test runs | Medium | Extract to shared base class with proper drop/create lifecycle |
| Shop purchase flow involves payment gateway mock | High | Use `Http::fake()` in beforeEach only for gateway response; browser test verifies UI state, not gateway |
| Settings pages use `data-test` instead of `data-testid` | Low | Normalize to `data-testid` during Phase 1 |
| Browser test execution time grows significantly | Medium | Phase work into PRs; each phase is independently mergeable |

## Rollback Plan

Each phase is a standalone PR. If a phase introduces flaky tests:
1. Revert the PR (git revert)
2. Existing tests remain green
3. No production code changes — only test files and data-testid attributes are modified

## Dependencies

- `pestphp/pest-browser` already installed (v4.3.1)
- Existing `BrowserTestCase` and `ChangePlanFlowTest` patterns as reference
- Spatie permission table DDL pattern from `ChangePlanFlowTest` for any role-dependent tests

## Success Criteria

- [ ] Browser test files cover all 11 pages listed in scope
- [ ] Each test verifies concrete UI results (not just page loads)
- [ ] No test depends on execution order
- [ ] `data-testid` attributes added to all targeted pages
- [ ] Shared tenant connection setup extracted to reusable base class
- [ ] All new tests pass: `php artisan test --compact --filter=Browser`
