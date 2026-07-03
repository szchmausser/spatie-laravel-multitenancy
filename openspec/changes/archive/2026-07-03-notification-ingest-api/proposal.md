# Proposal: Notification Ingest API

## Intent

The only notification ingestion path (`POST /api/notifications` → `DeviceController::storeNotification()`) hardcodes `source_type = SourceType::AndroidPush`, coupling the entry point to a single device type. Adding SMS, email, or scraper sources would require forking or branching the controller. Refactor the entry point so each notification source is a route + thin controller that delegates to a shared pipeline.

## Scope

### In Scope
- New route `POST /api/ingest/bank-app` → dedicated controller
- Remove old route `POST /api/notifications` (no backward compat — apps in dev)
- Create shared `IngestNotificationAction` (encapsulates hash compute + notification creation + job dispatch)
- Rename `SourceType::AndroidPush` → `SourceType::BankApp`, value `android_push` → `bank-app`
- Update tests to cover new route, remove old route coverage

### Out of Scope
- SMS, email, or scraper endpoints (any new `{source}` beyond `bank-app`)
- Device model changes (no `source_type` on Device)
- Auth changes (device token middleware stays unchanged)
- Heartbeat changes
- Pipeline behavior beyond notification creation (parser → match → orchestrator is untouched)

## Capabilities

### New Capabilities
None — pure refactor of the entry point, no spec-level behavior changes.

### Modified Capabilities
- `payment-reconciliation`: `SourceType` enum case `AndroidPush` renamed to `BankApp` with new value `bank-app`; route path changes in dedup hash scenarios from `/api/device/notifications` to `/api/ingest/bank-app`
- `device-management`: route path `POST /api/device/notifications` removed — device management spec may need a note. (The device registration, heartbeat, and invite code flows are unchanged.)

## Approach

1. Rename `SourceType::AndroidPush` to `SourceType::BankApp` with `value = 'bank-app'`; update `label()`.
2. Create `IngestNotificationAction` class with an `__invoke(Device, string $bankCode, string $rawBody): array` method that computes dedup hash, creates `PaymentNotification`, dispatches `IngestPaymentNotification` job, and returns status.
3. Create single-`__invoke` `IngestController` at `Api\IngestController`. Resolves `SourceType` from `{source}` URL segment via `SourceType::tryFrom()`. Validates auth via existing `device.auth` middleware. Delegates to `IngestNotificationAction` for all source-specific operations.
4. Replace route: remove `POST /notifications` → `DeviceController::storeNotification`, add `POST /ingest/bank-app` → `IngestController`.
5. Remove `DeviceController::storeNotification()` method (the controller retains `register()` and `heartbeat()`).
6. Update existing tests; old notification endpoint tests now test `/api/ingest/bank-app`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Enums/SourceType.php` | Modified | Rename case, update value, update label |
| `app/Http/Controllers/Api/DeviceController.php` | Modified | Remove `storeNotification()` method |
| `app/Http/Controllers/Api/IngestController.php` | New | Single `__invoke` controller |
| `app/Actions/IngestNotificationAction.php` | New | Shared pipeline action class |
| `routes/api.php` | Modified | Replace route, add new one |
| `tests/Feature/Api/` | Modified | Update notification ingest tests |
| `openspec/specs/payment-reconciliation/spec.md` | Modified | Update SourceType value, route paths |
| `openspec/specs/device-management/spec.md` | Modified | Remove/hide dead route path |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Route change breaks Android app in staging | Low | Direct cut with dev-only apps; communicate URL change |
| `IngestNotificationAction` introduces subtle behavior divergence | Low | Extract existing `storeNotification()` body faithfully, no new logic |

## Rollback Plan

Restore `POST /api/notifications` route and `DeviceController::storeNotification()`; revert `SourceType` rename. All changes are within a single session — a simple `git revert` on the change commits.

## Dependencies

None.

## Success Criteria

- [ ] `POST /api/ingest/bank-app` with valid device token creates notification (201)
- [ ] `POST /api/ingest/bank-app` with duplicate hash returns 200 `duplicate_ignored`
- [ ] `POST /api/notifications` returns 404 (removed)
- [ ] `POST /api/ingest/invalid-source` returns 422 (unrecognized source type)
- [ ] All existing notification pipeline tests still pass (unchanged behavior downstream)
