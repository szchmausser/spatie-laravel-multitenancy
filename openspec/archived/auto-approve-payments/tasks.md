# Tasks: Auto-Approve Payments

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~115 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr (exception-ok) |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

## Phase 1: Migration + Model

- [x] 1.1 Create `database/migrations/landlord/2026_06_29_000003_drop_user_id_from_entitlements.php` — drop `user_id` column, drop old unique `(tenant_id, user_id, resource_id)`, add `unique(tenant_id, resource_id)`, drop index `(tenant_id, user_id)`. No dedup (test data only).
- [x] 1.2 Modify `app/Models/Entitlement.php` — remove `user_id` from `$fillable`, update PHPDoc to reflect tenant-level scope.
- [x] 1.3 Modify `database/factories/EntitlementFactory.php` — remove `user_id` from `definition()`, remove `forTenantAndUser()` method.

## Phase 2: Event Wiring

- [x] 2.1 Modify `app/Providers/AppServiceProvider.php` — add `Event::listen(PaymentVerified::class, ActivateSubscription::class)` in `boot()`, add imports.
- [x] 2.2 Modify `app/Listeners/ActivateSubscription.php` — simplify `grantResourceEntitlement()`: create 1 row per tenant (no user loop), remove `user_id` from `updateOrCreate`.

## Phase 3: Shadow Mode Fix

- [x] 3.1 Modify `app/Services/Payment/ReconciliationOrchestrator.php` — change both `SystemConfig::get(..., true)` to `SystemConfig::get(..., false)` (lines 65, 123).
- [x] 3.2 Modify `app/Jobs/IngestPaymentNotification.php` — change `SystemConfig::get(..., true)` to `SystemConfig::get(..., false)` (line 78).

## Phase 4: Controller Access Changes

- [x] 4.1 Modify `app/Http/Controllers/Resource/ResourceController.php` — remove `$user` param from `userCanAccess()` and `userHasExplicitEntitlement()`, remove `where('user_id', ...)` from query, update call sites in `download()`, `index()`, `show()`, and `serializeResource()`.

## Phase 5: Test Cleanup + New Tests

- [x] 5.1 Modify `tests/Feature/Listeners/ActivateSubscriptionTest.php` — remove `user_id` assertion in resource entitlement test, verify only 1 row per tenant (no user loop).
- [x] 5.2 Modify `tests/Feature/Models/EntitlementTest.php` — remove `user_id` from all `Entitlement::factory()->create()` calls.
- [x] 5.3 Modify `tests/Feature/Migrations/ResourcesAndEntitlementsMigrationTest.php` — remove `user_id` from column assertion, update unique constraint check to `(tenant_id, resource_id)`.
- [x] 5.4 Modify `tests/Feature/Resource/ResourceControllerTest.php` — remove `user_id` from `Entitlement::factory()->create()` calls, update comment about old unique constraint.
- [x] 5.5 Add integration test: dispatch `PaymentVerified` for resource order, assert exactly 1 `Entitlement` row per `(tenant_id, resource_id)` — no user loop.

## Phase 6: Final Verification

- [x] 6.1 Run `vendor/bin/pint --format agent` to fix formatting.
- [x] 6.2 Run `php artisan test --compact` — all tests green.
