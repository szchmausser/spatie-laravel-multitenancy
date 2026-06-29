# Proposal: Auto-approve Payments

## Intent

Payments with bank-confirmed matching never auto-approve because the `ActivateSubscription` listener is unregistered. Fix the wiring so `PaymentVerified` events trigger subscription activation + entitlement grants, default shadow mode to off (auto-approve on), and make entitlements tenant-level (1 row per tenant, not N rows per user).

## Scope

### In Scope
- Register `ActivateSubscription` listener for `PaymentVerified` in `AppServiceProvider::boot()`
- Change shadow mode code defaults from `true` to `false` in `ReconciliationOrchestrator` + `IngestPaymentNotification`
- Drop `user_id` from `entitlements` table (migration) + model, update `grantResourceEntitlement()` to create 1 row per tenant
- Update `ResourceController::userCanAccess()` / `userHasExplicitEntitlement()` to check by `tenant_id` only
- Integration tests for full event dispatch path

### Out of Scope
- Cloud storage migration (deployment concern)
- Plan change flow refactor (works via direct mutation)
- Stuck order reprocessing (no stuck orders exist)
- UI changes for entitlement display

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `payment-reconciliation`: Shadow mode default changes from `true` to `false`. Auto-approve is now the default for matched payments.

## Approach

1. **Wire the listener**: `Event::listen(PaymentVerified::class, ActivateSubscription::class)` in `AppServiceProvider::boot()`
2. **Shadow mode off by default**: Flip code defaults from `SystemConfig::get(..., true)` → `false` in `ReconciliationOrchestrator` (2 sites) and `IngestPaymentNotification` (1 site). Seeder already stores `false`.
3. **Tenant-level entitlements**: New migration drops `user_id` from `entitlements`. Model removes `user_id` from `$fillable`. `grantResourceEntitlement()` creates 1 row per tenant+resource (no user loop). `userCanAccess()` checks by `tenant_id` only — if the tenant has an entitlement row, ALL its users can download.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Providers/AppServiceProvider.php` | Modified | Register `Event::listen()` in `boot()` |
| `app/Listeners/ActivateSubscription.php` | Modified | `grantResourceEntitlement()` → 1 row per tenant |
| `app/Models/Entitlement.php` | Modified | Remove `user_id` from `$fillable` |
| `app/Http/Controllers/Resource/ResourceController.php` | Modified | `userCanAccess()` / `userHasExplicitEntitlement()` → check by `tenant_id` only |
| `app/Services/Payment/ReconciliationOrchestrator.php` | Modified | Default `true` → `false` (×2 sites) |
| `app/Jobs/IngestPaymentNotification.php` | Modified | Default `true` → `false` |
| `database/migrations/landlord/` | New | Drop `user_id` column |
| `openspec/specs/payment-reconciliation/spec.md` | Modified | Shadow mode default `true` → `false` |
| Tests | New | Event dispatch path, full flow integration |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing entitlements with `user_id` orphaned after migration | Low | Migration drops column, existing rows remain tenant-scoped |
| Shadow mode OFF causes unexpected auto-approvals in production | Low | Seeder already `false`; code default change is explicit |
| Ingest flow bypasses listener | Low | Test asserts `PaymentVerified` fires → listener handles |

## Rollback Plan

1. Revert code defaults to `true` in `ReconciliationOrchestrator` + `IngestPaymentNotification`
2. Revert `AppServiceProvider::boot()` — remove the `Event::listen()` line
3. Revert migration: run a new migration to add `user_id` back
4. Run `php artisan optimize:clear` to flush event cache

## Dependencies

- None

## Success Criteria

- [ ] `PaymentVerified` event dispatch triggers `ActivateSubscription::handle()`
- [ ] Matched payment auto-approves when `shadow_mode_enabled` is `false` (new default)
- [ ] Resource purchase creates 1 entitlement row per tenant (not per user)
- [ ] All tenant users can download a purchased resource (no `user_id` check)
- [ ] All existing tests pass
