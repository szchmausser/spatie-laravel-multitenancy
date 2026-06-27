# S8c — Archive Report

## Change Summary
S8c — Alert Dashboard (SystemAlert)

## What was built
- Fix HandleInertiaRequests for Landlord (unread notifications count)
- AlertController with index (paginated, filterable) and read actions
- Routes: GET /admin/alerts, POST /admin/alerts/{notification}/read
- alerts.tsx Inertia page with severity/read/date filters, badges, pagination, empty state
- Sidebar "Alertas" nav item with unread badge

## Files created/modified
| File | Action |
|------|--------|
| app/Http/Middleware/HandleInertiaRequests.php | Modified |
| app/Http/Controllers/Landlord/AlertController.php | Created |
| routes/landlord.php | Modified |
| resources/js/pages/landlord/alerts.tsx | Created |
| resources/js/components/app-sidebar.tsx | Modified |
| resources/js/types/auth.ts | Modified |
| tests/Feature/Landlord/AlertControllerTest.php | Created |

## Test Results
- 13 new feature tests (76 assertions)
- 100 existing landlord tests pass (no regressions)
- 13 browser tests pass (no regressions)

## Plan document
- docs/plan-conciliacion-automatica.md updated
- S8c status: ✅ Completed

## Lessons Learned
- PostgreSQL TEXT columns require `data::json->>'key'` cast for JSON access
- DatabaseNotification model casts 'data' as array — passing json_encode() causes double-encoding, always pass direct arrays
- Browser tests confirmed using dedicated testing database (spatie-laravel-multitenancy-testing)

## Artifacts
- **Engram**: sdd/S8c/archive-report
- **OpenSpec**: openspec/changes/S8c/archive-report.md
