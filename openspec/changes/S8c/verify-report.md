# S8c — Verification Report

## Summary
**PASS** — All 5 functional requirements are correctly implemented. 13 feature tests pass with 76 assertions. Full landlord regression suite passes (100 tests, 526 assertions). Pint reports clean. No issues found.

## Spec Conformance

| RF | Description | Status | Evidence |
|----|-------------|--------|----------|
| RF-1 | HandleInertiaRequests Landlord fix | ✅ | `HandleInertiaRequests.php` L244: guard includes `$user instanceof Landlord`. Also added `resolveUnreadSystemAlertsCount()` (L274-300) with schema guard + `data::json->>'category' = 'system'` query. |
| RF-2 | AlertController::index with filters | ✅ | `AlertController.php` L22-65: paginated 20/page, filters severity (CSV, valid intersection), read (boolean), from/to date range. Returns Inertia response with `alerts` + `filters`. |
| RF-3 | AlertController::read | ✅ | `AlertController.php` L75-86: scoped to `$request->user()->notifications()` + `data->>'category' = 'system'` + `->firstOrFail()` → 404 if not-owned or not-system. `markAsRead()` idempotent. Redirect back. |
| RF-4 | alerts.tsx Inertia page | ✅ | `landlord/alerts.tsx` (392 lines): select filters (severity, read status, date range), severity badges (red/yellow/blue), mark-as-read button, pagination nav, empty state "No hay alertas de sistema". Filter changes trigger Inertia visit with query params. |
| RF-5 | Sidebar badge | ✅ | `app-sidebar.tsx` L84-107: admin-only `<SidebarGroup>` with Bell icon, "Alertas", red badge using `unread_system_alerts_count`. Tenant section (L108-155) does NOT show "Alertas". |

**Note**: RF-5 spec text mentions `unread_notifications_count` but implementation correctly uses `unread_system_alerts_count` (a separate, more specific prop introduced in the design/tasks phase). The sidebar badge uses the correct system-specific count.

## Test Results

| Suite | Passed | Failed | Assertions |
|-------|--------|--------|------------|
| AlertControllerTest | 13 | 0 | 76 |
| Full Landlord Feature suite | 100 | 0 | 526 |

### Test coverage (per spec scenarios)

| Scenario | Covered | Test name |
|----------|---------|-----------|
| Index default list | ✅ | `index loads with paginated system alerts` |
| Filter severity | ✅ | `index filters by severity` |
| Filter read=false | ✅ | `index filters by read status` |
| Filter date range | ✅ | `index filters by date range` |
| Empty state | ✅ | `empty state when no system alerts exist` |
| Invalid severity | ✅ | `invalid severity filter is silently ignored` |
| Mark as read | ✅ | `read action marks notification as read` |
| Idempotent read | ✅ | `read action is idempotent when already read` |
| Non-system 404 | ✅ | `read action returns 404 for non-system notification` |
| Non-owned 404 | ✅ | `read action returns 404 for non-owned notification` |
| Non-existent 404 | ✅ | `read action returns 404 for non-existent notification` |
| Auth guard | ✅ | `unauthenticated user cannot access alerts index` |
| Tenant guard | ✅ | `non-admin tenant user gets 403 on alerts index` |

## Code Quality

| Check | Result |
|-------|--------|
| Pint | **Clean** — no issues found |
| N+1 prevention | ✅ `count()` not `get()` per RNF-1 |
| PostgreSQL compat | ✅ `data::json->>'key'` syntax per RNF-2 |
| No new migrations | ✅ Reuses `notifications` table per RNF-3 |
| Auth middleware | ✅ Routes in `auth` + `verified` + `EnsureUserIsAdmin` per RNF-4 |

## Route Verification

| Route | Expected | Actual | Status |
|-------|----------|--------|--------|
| Index | `GET /admin/alerts` (via prefix) | `GET /admin/alerts` name: `landlord.alerts.index` | ✅ |
| Read | `POST /admin/alerts/{notification}/read` | `POST /admin/alerts/{notification}/read` name: `landlord.alerts.read` | ✅ |

Routes are registered inside `['auth', 'verified', EnsureUserIsAdmin::class]` with `prefix('admin')` and `name('landlord.')`.

## Cross-check against Plan (Fase 6)

| Requirement | Status |
|------------|--------|
| Route `GET /landlord/alerts` → AlertController::index | ✅ (actual URL: `/admin/alerts` due to `prefix('admin')`, named `landlord.alerts.index`) |
| Route `POST /landlord/alerts/{notification}/read` → AlertController::read | ✅ (actual URL: `/admin/alerts/{notification}/read`, named `landlord.alerts.read`) |
| Badge on navbar with unread count | ✅ |
| Filter by severity | ✅ |
| Mark as read button | ✅ |

## Risks

1. **Static plan doc outdated**: The plan `docs/plan-conciliacion-automatica.md` S8c section (L2931-2936) still shows all entries as ⬜ Pendiente. Requires archive phase to update.
2. **Minor field name discrepancy**: RF-5 in the spec refers to `unread_notifications_count` but the sidebar correctly uses `unread_system_alerts_count`. This is not a bug — it reflects the architectural decision to introduce a dedicated prop for system alerts. No action needed.

## Verdict

**PASS** — The implementation fully satisfies all spec requirements:
- ✅ RF-1: Admin sees real unread count via `$user instanceof Landlord` guard
- ✅ RF-2: Paginated, filterable system alerts at `/admin/alerts`
- ✅ RF-3: Mark-as-read with 404 for invalid/not-owned/not-system
- ✅ RF-4: Inertia page with working filters, severity badges, pagination, empty state
- ✅ RF-5: Admin sidebar shows "Alertas" with badge; tenant sidebar hides it
- ✅ 13/13 tests pass, 100/100 landlord tests pass, no regressions
- ✅ Pint clean

## Artifacts

- **Engram**: `sdd/S8c/verify-report` (obs-6747e20d76b5b5d0)
- **OpenSpec**: `openspec/changes/S8c/verify-report.md`
