# Tasks: Browser Test Coverage Expansion

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 550–700 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (trait + component fixes + refactor existing) → PR 2 (billing tests) → PR 3 (shop + tenant + settings tests) |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Trait extraction + component data-testid fixes + refactor existing tests | PR 1 | Foundation — all subsequent tests depend on this |
| 2 | Billing browser tests (OrdersIndex, OrdersShow, History) | PR 2 | Depends on PR 1 for trait; base = PR 1 branch |
| 3 | Shop + Tenant + Settings browser tests (4 files) | PR 3 | Depends on PR 1; base = PR 2 branch |

---

## Phase 1: Foundation — Trait + Component Fixes

- [ ] 1.1 Create `tests/Browser/Concerns/HasTenantConnectionHelpers.php` trait with methods: `pointTenantConnectionAtTestDatabase()`, `setDefaultConnectionToTenant(): string`, `restoreDefaultConnection(string)`, `runSpatiePermissionMigration()`, `runTenantPermissionsSeeder()`. Extract logic from `UserMenuBadgeTest` lines 36–90.
- [ ] 1.2 Fix `resources/js/pages/settings/profile.tsx` line 92: change `data-test="update-profile-button"` to `data-testid="update-profile-btn"`.
- [ ] 1.3 Fix `resources/js/pages/settings/security.tsx` line 111: change `data-test="update-password-button"` to `data-testid="update-password-btn"`.
- [ ] 1.4 Add `data-testid="appearance-tab-{value}"` on each theme button in `resources/js/components/appearance-tabs.tsx` (light, dark, system).
- [ ] 1.5 Refactor `tests/Browser/Tenant/UserMenuBadgeTest.php` to use `HasTenantConnectionHelpers` trait — remove duplicated helper functions, add `uses()` import.
- [ ] 1.6 Refactor `tests/Browser/Billing/ChangePlanFlowTest.php` to use `HasTenantConnectionHelpers` trait — remove duplicated helper functions, add `uses()` import.
- [ ] 1.7 Verify existing tests still pass: `php artisan test --compact --filter=Browser/Tenant/UserMenuBadgeTest && php artisan test --compact --filter=Browser/Billing/ChangePlanFlowTest`.

**Acceptance criteria:**
- Trait file exists with all 5 methods.
- No `data-test=` in `settings/profile.tsx` or `settings/security.tsx` (grep confirms zero).
- `appearance-tabs.tsx` has `data-testid="appearance-tab-light"`, `appearance-tab-dark`, `appearance-tab-system`.
- Both refactored tests pass without changes to test logic.

---

## Phase 2: Billing Browser Tests

- [ ] 2.1 Create `tests/Browser/Billing/OrdersIndexTest.php` (3 tests): orders list renders rows, empty state, view button navigation. Uses trait + `actAs()`.
- [ ] 2.2 Create `tests/Browser/Billing/OrdersShowTest.php` (3 tests): order detail shows items/total, payment method selection, report payment form fields visible. Uses trait + `actAs()`.
- [ ] 2.3 Create `tests/Browser/Billing/HistoryTest.php` (3 tests): history entries render, empty state, pagination controls. Uses trait + `actAs()`.
- [ ] 2.4 Run scoped tests: `php artisan test --compact --filter=Browser/Billing/OrdersIndexTest` and same for OrdersShowTest and HistoryTest.

**Acceptance criteria:**
- Each file has exactly the specified number of test cases.
- Each test uses `data-testid` selectors from the spec (orders-list, order-row-{id}, history-list, etc.).
- No `assertDatabaseHas` or `sleep()` in any test.

---

## Phase 3: Shop + Tenant + Settings Tests

- [ ] 3.1 Create `tests/Browser/Shop/ShopIndexTest.php` (4 tests): plan cards with current plan indicator, empty plans/resources, free resource download button, premium resource buy button. Uses trait.
- [ ] 3.2 Create `tests/Browser/Tenant/UsersCrudTest.php` (7 tests): user list renders, search filters, create button navigation, create with valid data, validation errors, user details display, edit user name. Uses trait.
- [ ] 3.3 Create `tests/Browser/Tenant/SettingsProfileTest.php` (2 tests): profile form shows current values, update profile name with flash. Uses trait.
- [ ] 3.4 Create `tests/Browser/Tenant/SettingsSecurityTest.php` (2 tests): password form renders, validation errors on empty submit. Uses trait.
- [ ] 3.5 Create `tests/Browser/Tenant/SettingsAppearanceTest.php` (2 tests): theme toggle buttons render, click dark mode adds `dark` class. Uses trait.
- [ ] 3.6 Run scoped tests for each new file: `php artisan test --compact --filter=Browser/Shop/ShopIndexTest` (and same for each Tenant/Settings test).

**Acceptance criteria:**
- Each file has exactly the specified number of test cases.
- All tests use `data-testid` selectors from the spec.
- No `assertDatabaseHas` or `sleep()` in any test.
- UserMenuBadgeTest and ChangePlanFlowTest still pass after all changes.

---

## Summary

| Phase | Tasks | Files touched |
|-------|-------|---------------|
| 1: Foundation | 7 | 1 new trait, 2 component fixes, 2 refactored tests |
| 2: Billing Tests | 4 | 3 new test files |
| 3: Shop + Tenant + Settings | 6 | 5 new test files |
| **Total** | **17** | **11 files created, 4 files modified** |
