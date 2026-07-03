# Delta: Payment Ingestion — Notification Ingest API

## REMOVED Requirements

### Requirement: `POST /api/notifications` route

The `POST /api/notifications` route, handled by `DeviceController::storeNotification()`, is removed.

(Reason: route renamed to `/api/ingest/bank-app`; no backward compatibility — apps are in development.)
(Migration: devices must use `POST /api/ingest/bank-app` with the same body fields.)

## ADDED Requirements

### Requirement: `POST /api/ingest/{source}` route pattern

The system MUST expose `/api/ingest/{source}` as the notification ingestion entry point. The `{source}` segment MUST map to a `SourceType` enum case via `SourceType::tryFrom()`. The controller MUST validate the device token via existing `device.auth` middleware and delegate to `IngestNotificationAction` for hash computation, notification creation, and job dispatch.

#### Scenario: Valid bank-app source returns 201

- GIVEN an authenticated device
- WHEN a POST request to `/api/ingest/bank-app` contains valid `bank_code` and `raw_body`
- THEN the response is 201 with `{ "status": "created" }`

#### Scenario: Unrecognized source returns 422

- GIVEN an authenticated device
- WHEN a POST request to `/api/ingest/invalid-source` is sent
- THEN the response is 422 with an error indicating unrecognized source type

#### Scenario: Removed old route returns 404

- GIVEN any request
- WHEN a POST request to `/api/notifications` is sent
- THEN the response is 404

## RENAMED Requirements

### Requirement: `SourceType::AndroidPush` → `SourceType::BankApp`

(Reason: AndroidPush was overly specific — the source is the bank's mobile app, regardless of platform. The rename decouples the enum from a single push channel.)
(Migration: update `SourceType::AndroidPush` references to `SourceType::BankApp`; update `case value` from `'android_push'` to `'bank-app'`; update `label()` output accordingly.)
