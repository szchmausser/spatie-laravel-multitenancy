# Tasks: Notification Ingest API

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~200-300 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

## Phase 1: Foundation — Enum Rename

- [x] 1.1 Rename `SourceType::AndroidPush` → `BankApp` with value `android_push` → `bank-app`; update `label()` return
- [x] 1.2 Update migration `...add_source_type_to_payment_notifications.php`: default value `android_push` → `bank-app`

## Phase 2: Core — Action + Controller + Route

- [x] 2.1 Create `app/Actions/IngestNotificationAction.php` — `__invoke(Device, bankCode, rawBody, SourceType)`: compute dedup hash, `PaymentNotification::forceCreate()`, dispatch `IngestPaymentNotification`, return notification
- [x] 2.2 Create `app/Http/Controllers/Api/IngestController.php` — single `__invoke`: resolve `SourceType::tryFrom($source)` → 422 if null, validate `bank_code` + `raw_body`, delegate to action, catch `QueryException 23505` → 200 `duplicate_ignored`
- [x] 2.3 Add `POST /ingest/{source}` under `device.auth` middleware in `routes/api.php`; import `IngestController`

## Phase 3: Cleanup — Remove Old Path

- [x] 3.1 Remove `DeviceController::storeNotification()` method (lines 97-132)
- [x] 3.2 Remove unused imports from `DeviceController`: `SourceType`, `IngestPaymentNotification`, `PaymentNotification`, `QueryException`, `Str`
- [x] 3.3 Remove old `POST /device/notifications` route (line 31) from `routes/api.php`

## Phase 4: Tests

- [x] 4.1 Update `DeviceNotificationTest`: POST `/api/ingest/bank-app` with valid payload → assert 201 + `source_type` = `bank-app` in DB
- [x] 4.2 Test duplicate: same payload twice → first 201, second 200 `duplicate_ignored`
- [x] 4.3 Test invalid source: POST `/api/ingest/invalid-source` → 422
- [x] 4.4 Test removed route: POST `/api/device/notifications` → 404
- [x] 4.5 Run `vendor/bin/pint --format agent`
