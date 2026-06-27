# Payment Reconciliation Specification

## Purpose

Automatic bank reconciliation for PagoMóvil payments. An Android phone captures bank push notifications, Laravel parses them via DB-stored regex, and a deterministic matching engine links them to reported payments. The system operates in shadow mode (suggests but doesn't auto-approve) before gradual activation.

**Scope**: PagoMóvil only. Bank Transfer reconciliation is deferred.

---

## Requirements

### Requirement: Centralized Configuration (SystemConfig)

The system SHALL store all payment and reconciliation configuration in a `system_configs` table. `config/payment.php` SHALL be eliminated. All code that reads `config('payment.*')` MUST use `SystemConfig::get()` instead.

#### Scenario: Read configuration value

- GIVEN a `system_configs` row with key `payment.order_expiry_hours` and value `48`
- WHEN `SystemConfig::get('payment.order_expiry_hours', 48)` is called
- THEN the system returns `48` (integer)
- AND the value is cached for 1 hour

#### Scenario: Update configuration at runtime

- GIVEN a `system_configs` row with key `reconciliation.shadow_mode_enabled` and value `true`
- WHEN an admin sets `SystemConfig::set('reconciliation.shadow_mode_enabled', false)`
- THEN the cache for that key is invalidated immediately
- AND subsequent reads return `false`

#### Scenario: Regex validation before save

- GIVEN an admin saves a regex value for key `regex_bdv`
- WHEN the regex does not compile or lacks required named groups (`amount`, `reference`)
- THEN the system rejects the save with HTTP 422 and descriptive error message

---

### Requirement: Device Registration and Authentication

The system SHALL maintain a `devices` table for Android phone registration. Each device MUST authenticate via `X-Device-Token` header on every request. Invalid or inactive tokens SHALL receive HTTP 401.

#### Scenario: Successful device authentication

- GIVEN an active device with token `abc123` in the `devices` table
- WHEN the device sends `POST /api/device/notifications` with header `X-Device-Token: abc123`
- THEN the request is authenticated and processed

#### Scenario: Invalid token rejection

- GIVEN a request with header `X-Device-Token: invalid`
- WHEN the token is not found or device `is_active = false`
- THEN the system responds with HTTP 401

#### Scenario: Heartbeat updates last seen

- GIVEN an active device
- WHEN the device sends `POST /api/device/heartbeat`
- THEN `devices.last_heartbeat_at` is updated to current timestamp

#### Scenario: Offline device alert

- GIVEN a device whose `last_heartbeat_at` is older than `heartbeat_interval_minutes × 2`
- WHEN the scheduled heartbeat check runs
- THEN a `SystemAlert` with `type = heartbeat_offline` and `severity = critical` is sent to all landlord-admin users

---

### Requirement: Notification Ingestion

The system SHALL accept raw bank push notifications from Android devices and store them immutably in `payment_notifications`. The `raw_title` and `raw_body` fields MUST NOT be modified after creation.

#### Scenario: Successful ingestion

- GIVEN an active device sends a notification with valid fields
- WHEN the endpoint creates the `payment_notification` record
- THEN `parse_status` is set to `pending`
- AND the `IngestPaymentNotification` job is dispatched

#### Scenario: Deduplication by hash

- GIVEN a notification with `dedup_hash = SHA256(bank_code + raw_body)`
- WHEN a duplicate notification with the same hash arrives
- THEN the system responds with HTTP 200 and `{status: "duplicate_ignored"}`
- AND no duplicate record is created (unique constraint)

#### Scenario: bank_code normalization

- GIVEN a notification arrives with `bank_code = "BDV"`
- WHEN the endpoint stores the record
- THEN `bank_code` is stored as `"bdv"` (lowercase)

---

### Requirement: Bank Notification Parsing

The system SHALL use a single `PaymentNotificationParser` that applies bank-specific regex patterns stored in `system_configs`. The parser extracts `amount_cents`, `reference`, and optionally `sender_phone_last4` from raw notification text.

#### Scenario: Successful parse — BDV format

- GIVEN a BDV notification: `Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40`
- WHEN the parser processes this text with `bank_code = "bdv"`
- THEN it returns a `ParsedPayment` with `amount_cents = 300000`, `reference = "006236568762"`, `sender_phone_last4 = "3557"`

#### Scenario: Successful parse — BNC format with masked phone

- GIVEN a BNC notification: `BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603`
- WHEN the parser processes this text with `bank_code = "bnc"`
- THEN it returns a `ParsedPayment` with `amount_cents = 1045500`, `reference = "603185603"`, `sender_phone_last4 = "9503"`

#### Scenario: Parse failure — unknown bank

- GIVEN a notification with `bank_code = "unknown_bank"`
- WHEN the parser looks up `regex_unknown_bank` in `system_configs`
- AND no regex is found
- THEN `parse()` returns `null`

#### Scenario: Parse failure — regex mismatch

- GIVEN a BDV notification with unexpected format
- WHEN the BDV regex does not match the text
- THEN `parse()` returns `null`

#### Scenario: Reference normalization

- GIVEN a raw reference value `  006236568762  `
- WHEN `normalizeRef()` is applied
- THEN the result is `"006236568762"` (trimmed, uppercased)

#### Scenario: Amount normalization (Venezuelan format)

- GIVEN a raw amount string `3.000,45`
- WHEN the parser normalizes it
- THEN the result is `300045` cents

---

### Requirement: Parsing Job and Error Handling

The system SHALL process notifications via the `IngestPaymentNotification` job. Parse failures SHALL mark `parse_status = 'failed'` and trigger a `SystemAlert` to landlord admins. The job SHALL NOT continue to matching on parse failure.

#### Scenario: Parse success triggers matching

- GIVEN a notification with `parse_status = 'pending'`
- WHEN `IngestPaymentNotification` runs and parsing succeeds
- THEN a `PaymentMatch` is created via `PaymentMatch::createFromParsed()`
- AND `ReconciliationOrchestrator::run()` is invoked within a `DB::transaction()`
- AND `parse_status` is updated to `'parsed'` after transaction commit

#### Scenario: Parse failure alerts admins

- GIVEN a notification that fails to parse
- WHEN `IngestPaymentNotification` runs and `parse()` returns null
- THEN `parse_status` is set to `'failed'`
- AND a `SystemAlert` with `type = parser_failed` and `severity = warning` is sent to landlord admins
- AND no matching is attempted

#### Scenario: Backfill reprocesses failed notifications

- GIVEN notifications with `parse_status = 'failed'` from a bank whose regex was updated
- WHEN `php artisan reconciliation:reprocess --parse-status=failed` runs
- THEN each failed notification is re-dispatched through `IngestPaymentNotification`
- AND successfully parsed notifications proceed to matching

---

### Requirement: Deterministic Matching Engine

The system SHALL use a deterministic matching engine — no confidence scores, no probabilistic thresholds. A match is either exact (reference + amount + within time window) or absent. The phone number is NOT used for matching (banks mask it).

#### Scenario: Exact match — forward flow (notification arrives first)

- GIVEN a notification parsed with `reference = "006236568762"`, `amount_cents = 300000`
- AND a pending payment with `transaction_id = "006236568762"`, `amount_cents = 300000`, `created_at` within `match_window_hours`
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'matched'`
- AND the payment is verified (if shadow mode is OFF)

#### Scenario: No match found

- GIVEN a notification parsed with `reference = "999999999"`, `amount_cents = 50000`
- AND no pending payment matches those criteria
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'unmatched'`
- AND a `SystemAlert` with `type = no_match_accumulated` is sent to landlord admins

#### Scenario: Multiple candidates

- GIVEN a notification that matches multiple pending payments by reference + amount
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'pending'` (manual review queue)
- AND a notification is sent to the admin with candidate suggestions

#### Scenario: Time window expiry

- GIVEN a notification parsed with `reference = "006236568762"`, `amount_cents = 300000`
- AND the only matching pending payment was created 80 hours ago
- AND `match_window_hours = 72`
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN the payment is NOT matched (outside window)

#### Scenario: SELECT FOR UPDATE prevents race condition

- GIVEN two notifications arrive simultaneously for the same pending payment
- WHEN both jobs enter `DB::transaction()` and execute `SELECT FOR UPDATE`
- THEN the second job waits for the first to commit
- AND the guard of state detects the payment is no longer Pending and discards the second match

---

### Requirement: Duplicate Detection

The system SHALL detect and handle duplicate reference codes. Before any matching, the engine MUST check if a `Verified` payment already exists with the same normalized `transaction_id`.

#### Scenario: Duplicate reference detected

- GIVEN a notification with `reference = "006236568762"`
- AND a payment with `status = Verified` and `transaction_id = "006236568762"` already exists
- WHEN the engine runs duplicate validation (Step 0)
- THEN `PaymentMatch.match_status` is set to `'duplicate_attempt'`
- AND the attempting payment (`status = Pending` with same `transaction_id`) is cancelled with `CancellationType::SystemDuplicate`

#### Scenario: Duplicate with no attempting payment

- GIVEN a notification with `reference = "006236568762"`
- AND a `Verified` payment exists with that reference
- AND no `Pending` payment has that reference
- WHEN the engine runs duplicate validation
- THEN `PaymentMatch.match_status` is set to `'duplicate_attempt'`
- AND a `SystemAlert` is sent (no payment to cancel)

---

### Requirement: Reverse Matching (Payment-First)

When a customer reports a payment, the system SHALL synchronously search for existing unmatched notifications that match by reference + amount + time window. This handles the common case where the bank notification arrives before the customer finishes reporting.

#### Scenario: Reverse match finds existing notification

- GIVEN an unmatched `PaymentMatch` with `reference = "006236568762"`, `amount_cents = 300000`
- AND a customer reports a payment with `transaction_id = "006236568762"`, `amount_cents = 300000`
- WHEN `PaymentService::attemptReverseMatch()` runs synchronously
- THEN the `PaymentMatch` is linked to the new payment
- AND the payment is auto-verified (if shadow mode OFF)
- AND a `PaymentVerified` event is dispatched after commit

#### Scenario: Reverse match with duplicate reference

- GIVEN a `Verified` payment with `transaction_id = "006236568762"`
- AND a customer reports a new payment with the same `transaction_id`
- WHEN `attemptReverseMatch()` runs
- THEN the new payment is cancelled with `CancellationType::SystemDuplicate`
- AND a `PaymentCancelled` event is dispatched after commit

#### Scenario: Reverse match — no notification found

- GIVEN no unmatched `PaymentMatch` exists for the reported reference + amount
- WHEN `attemptReverseMatch()` runs
- THEN the payment remains `Pending` with no match linked

---

### Requirement: Shadow Mode

The system SHALL operate in shadow mode by default (`reconciliation.shadow_mode_enabled = true`). In shadow mode, matches are recorded but payments are NOT auto-verified. The admin reviews suggestions in the dashboard and manually confirms or rejects.

#### Scenario: Shadow mode ON — match suggested but not verified

- GIVEN `reconciliation.shadow_mode_enabled = true`
- AND a notification matches a pending payment
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'pending'` (not `'matched'`)
- AND the payment remains `Pending`
- AND a notification is sent to the admin with match suggestion

#### Scenario: Shadow mode OFF — auto-verify on match

- GIVEN `reconciliation.shadow_mode_enabled = false`
- AND a notification matches exactly one pending payment
- WHEN `ReconciliationOrchestrator::run()` executes
- THEN `PaymentMatch.match_status` is set to `'matched'`
- AND `PaymentService::verifyPayment($payment, null)` is called
- AND a `PaymentVerified` event is dispatched after commit

---

### Requirement: Payment Expiry

Pending payments SHALL expire after `match_window_hours + 24h` (buffer). The `payments:expire-pending` command runs hourly and cancels expired pending payments with `CancellationType::SystemExpired`.

#### Scenario: Expired payment cancelled

- GIVEN a pending payment created 97 hours ago
- AND `match_window_hours = 72` (expiry = 96h)
- WHEN `payments:expire-pending` runs
- THEN the payment is cancelled with `CancellationType::SystemExpired`
- AND a `PaymentCancelled` event is dispatched after commit
- AND the tenant is notified to re-report

#### Scenario: Payment within window not expired

- GIVEN a pending payment created 48 hours ago
- AND `match_window_hours = 72`
- WHEN `payments:expire-pending` runs
- THEN the payment is NOT cancelled

---

### Requirement: PaymentService Signature Changes

`verifyPayment()` SHALL accept nullable `adminId` (null = automatic verification). `cancelPayment()` SHALL accept `CancellationType` enum + `int|string $actorId` + `?string $reason`. Neither method SHALL dispatch events internally — callers are responsible for post-commit event dispatch.

#### Scenario: Auto-verification with null adminId

- GIVEN `PaymentService::verifyPayment($payment, null)` is called
- WHEN the payment is updated to `Verified`
- THEN `verified_by` is set to null
- AND the UI displays "Verificado automáticamente"

#### Scenario: Manual cancellation with enum type

- GIVEN `PaymentService::cancelPayment($payment, CancellationType::Manual, $adminId, 'Changed mind')` is called
- WHEN the payment is cancelled
- THEN `cancellation_type` is set to `'manual'`
- AND `cancellation_reason` is `'Changed mind'`
- AND no event is dispatched internally

---

### Requirement: PaymentCancelled Event and Listener

A new `PaymentCancelled` event SHALL be created carrying `Payment`, `CancellationType`, and optional `reason`. The `NotifyPaymentRejected` listener SHALL route notifications based on cancellation type: `SystemDuplicate` notifies tenant + landlord admins, `SystemExpired` notifies tenant only, `Manual` notifies tenant only.

#### Scenario: Duplicate cancellation notifies both parties

- GIVEN a payment cancelled with `CancellationType::SystemDuplicate`
- WHEN the `PaymentCancelled` event is dispatched
- THEN `NotifyPaymentRejected` sends a notification to the tenant (payment rejected)
- AND sends a notification to landlord admins (potential fraud alert)

#### Scenario: Expiry cancellation notifies tenant only

- GIVEN a payment cancelled with `CancellationType::SystemExpired`
- WHEN the `PaymentCancelled` event is dispatched
- THEN `NotifyPaymentRejected` sends a notification to the tenant only
- AND landlord admins are NOT notified (normal system behavior)

---

### Requirement: System Alerts via Laravel Notifications

Infrastructure alerts (device offline, parser failed, no match accumulated) SHALL use the existing `notifications` table with `category = 'system'` in the JSON `data` field. They are sent to all landlord-admin users via the `database` channel.

#### Scenario: Alert stored and displayed

- GIVEN a `SystemAlert` notification is sent to landlord admins
- WHEN the admin views the alerts dashboard
- THEN unread system alerts (`read_at IS NULL`, `data->>'category' = 'system'`) are displayed
- AND the admin can mark alerts as read (`read_at = now()`)

---

### Requirement: Android Device API

The system SHALL expose two API endpoints for Android devices: `POST /api/device/notifications` for notification ingestion and `POST /api/device/heartbeat` for liveness. Both require `X-Device-Token` authentication and are rate-limited to 60 requests/minute per device.

#### Scenario: Notification ingestion response

- GIVEN a valid device sends a notification
- WHEN the notification is created successfully
- THEN the response is `{status: "created"}` with HTTP 200

#### Scenario: Heartbeat response

- GIVEN a valid device sends a heartbeat
- WHEN the heartbeat is processed
- THEN the response is `{status: "ok", heartbeat_interval_minutes: N}` with HTTP 200

---

### Requirement: Order Expiry from SystemConfig

`PaymentService::createOrder()` SHALL read `order_expiry_hours` from `SystemConfig::get()` instead of hardcoding 48 hours.

#### Scenario: Dynamic order expiry

- GIVEN `SystemConfig::get('payment.order_expiry_hours')` returns `24`
- WHEN `createOrder()` is called
- THEN `expires_at` is set to `now()->addHours(24)`

---

### Requirement: PagoMovilGateway Config Fallback Removal

`PagoMovilGateway::resolveReceivingAccount()` SHALL require an active `PaymentMethodConfig` record. The fallback to `config('payment.pago_movil')` SHALL be removed.

#### Scenario: Missing PaymentMethodConfig

- GIVEN no active `PaymentMethodConfig` with type `pago_movil` exists
- WHEN `PagoMovilGateway` attempts to resolve the receiving account
- THEN the system responds with HTTP 422 and message "No hay cuenta PagoMóvil activa configurada"

---

## Data Models

### system_configs

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | |
| group | string | indexed | Config group (payment, reconciliation, devices) |
| key | string | unique | Config key |
| value | text | | Config value |
| type | string | default: 'string' | string, integer, boolean, json |
| description | text | nullable | Human-readable description |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### devices

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | |
| name | string | | Device label |
| bank_code | string | | Destination bank (lowercase) |
| token | string(64) | unique | Revocable auth token |
| last_heartbeat_at | timestamp | nullable | Last heartbeat received |
| is_active | boolean | default: true | |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### payment_notifications

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | |
| device_id | bigint | FK → devices, cascade delete | |
| bank_code | string | indexed | Lowercase bank identifier |
| raw_title | text | | Original notification title (immutable) |
| raw_body | text | | Original notification body (immutable) |
| dedup_hash | string | unique | SHA256(bank_code + raw_body) |
| received_at | timestamp | | Timestamp from device |
| parse_status | string | default: 'pending', indexed | pending, parsed, failed |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### payment_matches

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | |
| payment_notification_id | bigint | FK → payment_notifications, cascade delete | |
| payment_id | bigint | FK → payments, null on delete, indexed | Direct FK, no morphs |
| parsed_reference | string | nullable | Normalized reference code |
| parsed_amount_cents | integer | | Parsed amount in cents |
| parsed_sender_phone_last4 | string | nullable | Last 4 digits of sender phone |
| match_status | string | indexed | pending, matched, unmatched, duplicate_attempt |
| matched_at | timestamp | nullable | |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Partial unique index**: `CREATE UNIQUE INDEX idx_payment_matches_matched ON payment_matches (payment_id) WHERE match_status = 'matched'`

### payments (modified)

| New Column | Type | Constraints | Description |
|------------|------|-------------|-------------|
| cancellation_type | string | nullable, after cancellation_reason | CancellationType enum value |

### CancellationType Enum

| Case | Value | Description |
|------|-------|-------------|
| Manual | manual | Admin cancels from UI |
| SystemDuplicate | system_duplicate | System detects duplicate reference |
| SystemExpired | system_expired | System expires old payment |
| MethodChanged | method_changed | Tenant changes payment method |

---

## API Contracts

### POST /api/device/notifications

**Auth**: `X-Device-Token` header (validated against `devices.token`)

**Request**:
```json
{
  "bank_code": "bdv",
  "title": "Banco de Venezuela",
  "body": "Recibiste un PagomovilBDV por Bs. 3.000,00...",
  "received_at": "2026-06-18T09:40:00Z",
  "dedup_hash": "abc123..."
}
```

**Response (200)**:
```json
{ "status": "created" }
```
Or on duplicate: `{ "status": "duplicate_ignored" }`

### POST /api/device/heartbeat

**Auth**: `X-Device-Token` header

**Request**:
```json
{
  "battery_level": 85,
  "notifications_pending_count": 0
}
```

**Response (200)**:
```json
{ "status": "ok", "heartbeat_interval_minutes": 5 }
```

### GET /landlord/alerts

**Auth**: Landlord admin session

**Response**: Inertia page with unread system notifications (`read_at IS NULL`, `data->>'category' = 'system'`), filterable by severity.

### POST /landlord/alerts/{notification}/read

**Auth**: Landlord admin session

**Response**: Redirect with success. Sets `read_at = now()`.

---

## Error Handling

| Condition | Behavior |
|-----------|----------|
| Parser regex doesn't match | `parse_status = 'failed'`, `SystemAlert` sent, matching skipped |
| Unknown bank code (no regex) | Same as regex mismatch |
| Dedup hash collision | HTTP 200, `duplicate_ignored`, no record created |
| Invalid device token | HTTP 401 |
| Regex missing required groups on save | HTTP 422 with descriptive error |
| No matching payment found | `match_status = 'unmatched'`, admin alert |
| Multiple matching payments | `match_status = 'pending'`, admin review queue |
| Duplicate reference (already verified) | `match_status = 'duplicate_attempt'`, attempting payment cancelled |
| Payment expires without match | Cancelled with `SystemExpired`, tenant notified |

---

## Integration Points

- **PaymentService**: `verifyPayment()` and `cancelPayment()` signature changes; `recordPayment()` gains `attemptReverseMatch()` call; `createOrder()` reads expiry from `SystemConfig`
- **PagoMovilGateway**: Remove `config('payment.pago_movil')` fallback, require `PaymentMethodConfig`
- **Payment model**: New `cancellation_type` column + cast, new `paymentMatch()` HasOne relation
- **PaymentVerified event**: Dispatched by callers after commit (not by `verifyPayment()`)
- **PaymentCancelled event**: New, dispatched by callers after commit (not by `cancelPayment()`)
- **SystemAlert notification**: Reuses Laravel `notifications` table with `category = 'system'`
- **Tenant notifications**: Stored in tenant's `notifications` table via existing notification system

---

## Testing Requirements

| Test Type | Scope | Count Target |
|-----------|-------|-------------|
| Unit | Parser: each bank format × edge cases | 10+ per bank |
| Unit | `normalizeRef()`, `normalizeAmount()` | 5+ each |
| Unit | `SystemConfig::get()` cache behavior | 3+ |
| Feature | Ingestion endpoint: auth, dedup, normalization | 5+ |
| Feature | Heartbeat endpoint: auth, update | 3+ |
| Feature | ReconciliationOrchestrator: forward match, no match, multiple, duplicate | 6+ |
| Feature | Reverse match: found, duplicate, not found | 3+ |
| Feature | Shadow mode: ON vs OFF behavior | 2+ |
| Feature | Payment expiry command | 3+ |
| Feature | `cancelPayment()` with new signature | 3+ |
| Feature | `verifyPayment()` with null adminId | 2+ |
| Feature | Alert dashboard: list, mark read | 3+ |
| Feature | SystemConfig CRUD + regex validation | 4+ |
| Feature | Regex test endpoint | 2+ |

**Run command**: `php artisan test --compact`

---

## Questions Flagged

1. **Package IDs**: The plan notes that Android package IDs (`com.banesco.bancamovil`, `com.dinerorapido.bancamovil`) need verification with real devices. The parser code hardcodes `getDateFormat()` per bank — should new banks require a code deploy or should date formats also be DB-stored? The plan says hardcode (low frequency of change), but this is a tradeoff worth confirming.

2. **Heartbeat monitoring interval**: The plan uses `heartbeat_interval_minutes × 2` for offline detection. Should this be configurable per-device or global? Currently global via `system_configs`.

3. **Notification channels for SystemAlert**: The plan sends via `database` channel only. Should infrastructure alerts also send via `mail` to ensure admins see critical issues?
