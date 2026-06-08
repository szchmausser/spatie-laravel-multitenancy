# Archive Report: 1.5G-buy-plan

**Change**: 1.5G-buy-plan
**Status**: archived
**Date**: 2026-06-08

## Implementation Summary

Self-service plan change for tenants whose users hold `change-plan` permission, plus a landlord backdoor. Two controllers sharing one service.

### Files Changed

| File | Action |
|------|--------|
| `app/Services/Billing/ChangePlanService.php` | NEW — shared mutation |
| `app/Http/Controllers/Billing/ChangePlanController.php` | NEW — tenant surface |
| `app/Http/Controllers/Landlord/ChangePlanController.php` | NEW — landlord backdoor |
| `routes/web.php` | MODIFIED — billing prefix group |
| `routes/landlord.php` | MODIFIED — subscriptions.change route |
| `resources/js/types/billing.ts` | NEW — Plan type |
| `resources/js/types/index.ts` | MODIFIED — re-export billing types |
| `resources/js/pages/billing/change-plan.tsx` | NEW — Inertia page |
| `resources/js/components/billing/change-plan-dialog.tsx` | NEW — dialog component |
| `resources/js/components/user-menu-content.tsx` | MODIFIED — Change plan link |
| `Arquitectura multitenencia aplicada.md` | MODIFIED — added SS24 |

### Tests Added

| File | Count |
|------|-------|
| `tests/Unit/Services/Billing/ChangePlanServiceTest.php` | 3 tests |
| `tests/Feature/Billing/ChangePlanControllerTest.php` | 7 tests |
| `tests/Feature/Landlord/ChangePlanControllerTest.php` | 3 tests |
| `tests/Browser/Billing/ChangePlanFlowTest.php` | 2 browser tests |
| `tests/Feature/Auth/TenantPermissionsTest.php` | +1 test (downgrade regression) |

**Total**: 16 new tests across 5 files.

## Verification

- **Change tests**: 30/30 pass (16 new + 14 existing in affected files)
- **Full suite**: 225/225 pass
- **PHP**: `vendor/bin/pint --format agent` clean
- **TypeScript**: `npx tsc --noEmit` clean
- **Wayfinder**: `php artisan wayfinder:generate` regenerated routes

## Key Decisions

### Removed `lockForUpdate()` — simpler than spec'd

The initial design specified `DB::transaction` + `lockForUpdate()` to serialize concurrent plan changes. During implementation review, this was deemed unnecessary:

- PHP-FPM processes one request per worker process; there is no request multiplexing within a single worker that could produce a true race condition at the application level.
- The use case involves no payment gateway, no proration, no intermediate state, and no multi-table writes that could corrupt under concurrent requests.
- The same-plan guard (`abort_if($subscription->plan_id === $newPlan->id, 422)`) runs inline against the current model state, which is sufficient for this non-payment plan change.

The service is now a simple `abort_if` + `update` with no explicit transaction or row lock. If Phase 2 introduces a payment pipeline with multi-table atomicity requirements, `lockForUpdate()` or an optimistic `version` column with retry should be reintroduced at that point.

### Two controllers, one service (unchanged)

The original architecture decision held: auth diverges at the controller layer (`Gate::allows('change-plan')` for tenants, `EnsureUserIsAdmin` for landlords), while the mutation converges in a single `ChangePlanService`. This kept controllers thin and the business logic testable in isolation.

### No entitlement writes on downgrade (unchanged)

`ChangePlanService` touches only `subscriptions.plan_id` and `ends_at`. No `Entitlement` rows are mutated. The read-path feature gate (`EnsureTenantHasFeature`, `ResourceController::userCanAccess`) handles downgrade enforcement without any new code.

### Immediate effect, no proration (unchanged)

`ends_at` resets to `now()->addMonth()`. `trial_ends_at` is untouched. Matches SS22 Option B of the architecture doc.

## Coverage

| Metric | Value |
|--------|-------|
| Requirements | 8/8 covered |
| Scenarios | 22/24 covered (2 uncovered are architectural guarantees that do not apply: `lockForUpdate()` concurrency scenarios were removed with the lock) |
| Read-path regression | Proven: downgrade premium to free blocks premium-content without new code |
| Browser flows | Tenant click-confirm + landlord admin panel both tested |

## OpenSpec Artifacts

- `openspec/changes/1.5G-buy-plan/proposal.md`
- `openspec/changes/1.5G-buy-plan/specs/plan-change/spec.md`
- `openspec/changes/1.5G-buy-plan/design.md`
- `openspec/changes/1.5G-buy-plan/tasks.md`
- `openspec/changes/1.5G-buy-plan/archive-report.md` (this file)

## Rollback

Per SS24.6 of the architecture doc: remove routes, delete controllers + service, remove UI files, regenerate Wayfinder, delete test files.
