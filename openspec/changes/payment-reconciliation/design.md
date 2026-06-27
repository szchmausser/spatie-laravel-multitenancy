# Design: Payment Reconciliation

## Technical Approach

Automatic bank reconciliation for PagoMóvil payments. Android phone captures bank push notifications → Laravel API stores raw notifications immutably → single `PaymentNotificationParser` applies DB-stored regex → `ReconciliationOrchestrator` matches to pending payments deterministically (reference + amount + time window) → auto-verifies if shadow mode OFF. Reverse matching handles the common case where notification arrives before customer reports payment. All financial operations funnel through the same `PaymentService::verifyPayment()` — no parallel paths.

## Architecture Decisions

### Decision: Single Parser + Regex in DB (not class-per-bank)

**Choice**: One `PaymentNotificationParser` class that applies regex from `system_configs` keyed by bank_code
**Alternatives considered**: Separate `BNCParser`, `BDVParser` etc. classes; compiled regex in code
**Rationale**: Venezuelan banks change notification formats silently. DB-stored regex = update one row, all devices pick up new pattern without Android app update. One class = zero code changes for format updates.

### Decision: Deterministic Matching (no confidence scores)

**Choice**: Binary — exact match (reference + amount + time window) → auto-verify, else → manual review
**Alternatives considered**: Probabilistic scoring with thresholds
**Rationale**: False positives in financial systems are worse than false negatives. Auto-verifying a wrong payment loses money; a false negative just requires manual review.

### Decision: FK Direct in payment_matches (not morphs)

**Choice**: `payment_id` FK directly to `payments` table
**Alternatives considered**: `matchable_type`/`matchable_id` polymorphic
**Rationale**: `payment_matches` only ever matches against `Payment`. Consistent with existing Supertipo/Subtipo FK pattern. Real referential integrity at DB level. Cross-stack readable (Go, Node can understand a FK).

### Decision: SystemConfig with Sentinel-Cache (not config file)

**Choice**: `system_configs` table with `SystemConfig::get()` using atomic sentinel pattern, 1h TTL
**Alternatives considered**: `config/payment.php` + cache; Redis-only config
**Rationale**: No deploy needed to change any value. Sentinel pattern prevents has/get race conditions. Cache invalidation on `set()` is immediate.

### Decision: Explicit Job Dispatch (not Eloquent events)

**Choice**: `IngestPaymentNotification::dispatch($notification)` from controller and backfill command
**Alternatives considered**: `eloquent.created` event listener
**Rationale**: Eloquent events are fragile for backfill — reproducing old notifications to trigger events is hacky. Explicit dispatch = same job for normal flow and backfill, zero duplicated logic.

### Decision: Events After Commit (IC-4)

**Choice**: Callers dispatch events AFTER `DB::transaction()` commits
**Alternatives considered**: Events inside transaction (current `verifyPayment` pattern)
**Rationale**: Current pattern fires `PaymentVerified` inside `DB::transaction()` — if the commit fails, listeners already ran. Moving events after commit eliminates partial side effects. `PaymentService` methods become pure DB updaters; callers own event dispatch.

### Decision: Reverse Match Synchronous (not Job)

**Choice**: `attemptReverseMatch()` runs synchronously inside `recordPayment()` transaction
**Alternatives considered**: Queued Job for reverse matching
**Rationale**: Matching is a simple indexed SELECT — microseconds, not seconds. Async would add queue latency, DTO complexity, and worse UX (user waits for HTTP response). Extract to Job later only if heavy steps added.

## Architecture Overview

```
┌──────────────────┐     POST /api/device/notifications     ┌─────────────────────────┐
│  Android Phone   │ ──────────────────────────────────────→ │  DeviceNotificationCtrl │
│  (NotificationListenerService)                            │  (validates X-Device-Token)│
│  Captures bank push notifications                         └───────────┬─────────────┘
│  Stores in local SQLite buffer                                       │
│  Retries with exponential backoff                                    │ creates PaymentNotification
└──────────────────┘                                                    │ dispatches Job
                                                                        ▼
                                                            ┌─────────────────────────┐
                                                            │ IngestPaymentNotification│ (ShouldQueue)
                                                            │  1. Parse via regex      │
                                                            │  2. Create PaymentMatch  │
                                                            │  3. Run Orchestrator     │
                                                            │  4. Update parse_status  │
                                                            └───────────┬─────────────┘
                                                                        │
                                              ┌─────────────────────────┼─────────────────────────┐
                                              │                         │                         │
                                              ▼                         ▼                         ▼
                                    ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
                                    │ PaymentNotif.   │    │ Reconciliation  │    │ PaymentService  │
                                    │ Parser          │    │ Orchestrator    │    │ ::verifyPayment()│
                                    │ (single class)  │    │ (matching logic)│    │ ::cancelPayment()│
                                    └─────────────────┘    └─────────────────┘    └─────────────────┘
                                              │                         │                         │
                                              │ regex from              │ SELECT FOR UPDATE       │ fires events
                                              │ system_configs          │ in DB transaction       │ AFTER commit
                                              ▼                         ▼                         ▼
                                    ┌─────────────────────────────────────────────────────────────────┐
                                    │                      PostgreSQL (landlord DB)                   │
                                    │  system_configs │ devices │ payment_notifications │            │
                                    │  payment_matches │ payments │ orders │ subscriptions │         │
                                    └─────────────────────────────────────────────────────────────────┘
                                              │
                                              ▼
                                    ┌─────────────────┐    ┌─────────────────┐
                                    │ PaymentVerified │    │ PaymentCancelled│
                                    │ event           │    │ event (NEW)     │
                                    └────────┬────────┘    └────────┬────────┘
                                             │                      │
                                             ▼                      ▼
                                    ┌─────────────────┐    ┌─────────────────┐
                                    │ActivateSubscr.  │    │NotifyPayment    │
                                    │(existing)       │    │Rejected (NEW)   │
                                    └─────────────────┘    └─────────────────┘
```

## Database Design

### New Table: `system_configs`

```sql
CREATE TABLE system_configs (
    id BIGSERIAL PRIMARY KEY,
    "group" VARCHAR(255) NOT NULL,       -- 'payment', 'reconciliation', 'devices'
    key VARCHAR(255) NOT NULL UNIQUE,
    value TEXT NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'string',  -- 'string', 'integer', 'boolean', 'json'
    description TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_system_configs_group ON system_configs("group");
```

### New Table: `devices`

```sql
CREATE TABLE devices (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    bank_code VARCHAR(50) NOT NULL,              -- destination bank, always lowercase
    token VARCHAR(64) NOT NULL UNIQUE,           -- revocable auth token
    last_heartbeat_at TIMESTAMP NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### New Table: `payment_notifications` (immutable)

```sql
CREATE TABLE payment_notifications (
    id BIGSERIAL PRIMARY KEY,
    device_id BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    bank_code VARCHAR(50) NOT NULL,              -- always lowercase
    raw_title TEXT NOT NULL,
    raw_body TEXT NOT NULL,
    dedup_hash VARCHAR(64) NOT NULL UNIQUE,      -- SHA256(bank_code + raw_body)
    received_at TIMESTAMP NOT NULL,
    parse_status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending|parsed|failed
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_pn_bank_code ON payment_notifications(bank_code);
CREATE INDEX idx_pn_parse_status ON payment_notifications(parse_status);
```

### New Table: `payment_matches`

```sql
CREATE TABLE payment_matches (
    id BIGSERIAL PRIMARY KEY,
    payment_notification_id BIGINT NOT NULL REFERENCES payment_notifications(id) ON DELETE CASCADE,
    payment_id BIGINT NULL REFERENCES payments(id) ON DELETE SET NULL,
    parsed_reference VARCHAR(20) NULL,
    parsed_amount_cents INTEGER NOT NULL,
    parsed_sender_phone_last4 VARCHAR(4) NULL,
    match_status VARCHAR(30) NOT NULL,           -- pending|matched|unmatched|duplicate_attempt
    matched_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_pm_match_status ON payment_matches(match_status);
CREATE INDEX idx_pm_payment_id ON payment_matches(payment_id);
```

After table creation, add partial unique index:
```sql
CREATE UNIQUE INDEX idx_payment_matches_matched ON payment_matches(payment_id) WHERE match_status = 'matched';
```

### Modified Table: `payments` (add column)

```sql
ALTER TABLE payments ADD COLUMN cancellation_type VARCHAR(30) NULL;
-- Positioned after cancellation_reason for logical grouping
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/landlord/XXXX_create_system_configs_table.php` | Create | Centralized config table |
| `database/migrations/landlord/XXXX_create_devices_table.php` | Create | Device registration table |
| `database/migrations/landlord/XXXX_create_payment_notifications_table.php` | Create | Raw notification storage |
| `database/migrations/landlord/XXXX_create_payment_matches_table.php` | Create | Matching results table |
| `database/migrations/landlord/XXXX_add_cancellation_type_to_payments.php` | Create | New column on payments |
| `database/migrations/landlord/XXXX_add_partial_unique_index_to_payment_matches.php` | Create | Race condition safety net |
| `database/seeders/landlord/SystemConfigSeeder.php` | Create | Seed initial config values |
| `database/seeders/landlord/PaymentMethodConfigSeeder.php` | Create | Ensure active PagoMóvil account exists |
| `app/Models/SystemConfig.php` | Create | Config model with sentinel-cache |
| `app/Models/Device.php` | Create | Device registration model |
| `app/Models/PaymentNotification.php` | Create | Immutable notification model |
| `app/Models/PaymentMatch.php` | Create | Matching result model |
| `app/Enums/CancellationType.php` | Create | Enum: Manual, SystemDuplicate, SystemExpired, MethodChanged |
| `app/Services/Payment/PaymentNotificationParser.php` | Create | Single parser, regex from DB |
| `app/Services/Payment/ParsedPayment.php` | Create | DTO: amount_cents, reference, sender_phone_last4 |
| `app/Services/Payment/ReconciliationOrchestrator.php` | Create | Matching logic (forward + reverse) |
| `app/Services/Payment/ReconciliationResult.php` | Create | DTO for orchestrator → job communication |
| `app/Jobs/IngestPaymentNotification.php` | Create | Orchestrator job: parse → match → verify |
| `app/Console/Commands/ReprocessFailedNotifications.php` | Create | Backfill command for failed parses |
| `app/Console/Commands/ExpirePendingPayments.php` | Create | Cancel payments older than window+24h |
| `app/Console/Commands/CheckDeviceHeartbeats.php` | Create | Alert on offline devices |
| `app/Console/Commands/SimulatePaymentNotification.php` | Create | Dev tool: insert fake notifications |
| `app/Http/Controllers/Api/DeviceNotificationController.php` | Create | POST /api/device/notifications |
| `app/Http/Controllers/Api/DeviceHeartbeatController.php` | Create | POST /api/device/heartbeat |
| `app/Http/Controllers/Landlord/AlertController.php` | Create | GET /landlord/alerts, POST .../read |
| `app/Http/Middleware/DeviceAuth.php` | Create | Validate X-Device-Token header |
| `app/Events/PaymentCancelled.php` | Create | Event: payment + CancellationType + reason |
| `app/Listeners/NotifyPaymentRejected.php` | Create | Route notifications by CancellationType |
| `app/Notifications/SystemAlert.php` | Create | Infrastructure alerts via notifications table |
| `app/Helpers/normalizeRef.php` | Create | Global helper: trim + uppercase |
| `app/Services/Payment/PaymentService.php` | Modify | verifyPayment(?int), cancelPayment(enum), attemptReverseMatch(), getPendingEvents() |
| `app/Services/Payment/PagoMovilGateway.php` | Modify | Remove config fallback, require PaymentMethodConfig |
| `app/Models/Payment.php` | Modify | Add cancellation_type cast, paymentMatch() relation |
| `app/Http/Controllers/Landlord/PaymentController.php` | Modify | Pass cancellation_type from form, dispatch events after commit |
| `app/Http/Controllers/Tenant/PaymentController.php` | Modify | Replace config() with SystemConfig::get(), dispatch pending events |
| `app/Providers/AppServiceProvider.php` | Modify | Register ReconciliationOrchestrator binding |
| `routes/landlord.php` | Modify | Add alert routes |
| `routes/api.php` | Create | Device API routes with DeviceAuth middleware |
| `routes/console.php` | Modify | Schedule new commands |
| `config/payment.php` | Delete | Migrated entirely to system_configs |

## Class Structure

### SystemConfig (Model)
```
app/Models/SystemConfig.php
├── Properties: group, key, value, type, description
├── UsesLandlordConnection trait
├── getValue(): string|int|bool|array — type-casts value
├── static get(string $key, mixed $default): mixed — sentinel-cache pattern, 1h TTL
├── static set(string $key, mixed $value, string $type): static — upsert + cache::forget
└── save() — cache::forget on update
```

### PaymentNotificationParser
```
app/Services/Payment/PaymentNotificationParser.php
├── parse(string $bankCode, string $text): ?ParsedPayment
│   ├── SystemConfig::get("regex_{$bankCode}") → regex
│   ├── preg_match(regex, text, matches)
│   ├── normalizeAmount(matches['amount']) → cents
│   ├── normalizeRef(matches['reference']) → string
│   ├── extractLast4(matches['phone'] ?? null) → ?string
│   └── parseDate(matches['date'], matches['time'], getDateFormat(bankCode)) → ?Carbon
├── getDateFormat(string $bankCode): string — hardcoded per bank
├── normalizeAmount(string $raw): int — "3.000,45" → 300045
└── extractLast4(?string $phone): ?string — strips non-digits, takes last 4
```

### ReconciliationOrchestrator
```
app/Services/Payment/ReconciliationOrchestrator.php
├── run(PaymentMatch $match): ReconciliationResult
│   ├── Step 0: Duplicate check — verified Payment with same reference?
│   │   ├── YES → match_status = 'duplicate_attempt', cancel attempting payment
│   │   └── NO → continue
│   ├── Step 1: Normal match — WHERE status=Pending, amount, reference, within window
│   │   └── SELECT FOR UPDATE (inside caller's transaction)
│   ├── Step 2: One candidate → match_status = 'matched' (or 'pending' in shadow mode)
│   │   └── Guard: verify payment still Pending before verifyPayment()
│   ├── Step 3: Multiple candidates → match_status = 'pending' (manual review)
│   └── Step 4: No candidates → match_status = 'unmatched'
├── runReverse(PaymentMatch $match, Payment $payment): void
│   ├── Guard: payment.status === Pending
│   ├── Shadow mode check → 'matched' or 'pending'
│   └── If matched: PaymentService::verifyPayment($payment, null)
└── Returns ReconciliationResult with verified/cancelled payment references
```

### IngestPaymentNotification (Job)
```
app/Jobs/IngestPaymentNotification.php implements ShouldQueue
├── __construct(PaymentNotification $notification)
├── handle(): void
│   ├── Parse: parser.parse(bank_code, raw_body)
│   ├── If null → mark failed + SystemAlert + return
│   ├── DB::transaction:
│   │   ├── PaymentMatch::createFromParsed(notification, parsed)
│   │   └── orchestrator.run(match)
│   ├── Mark parse_status = 'parsed'
│   └── Dispatch events AFTER commit (IC-4):
│       ├── PaymentVerified if result.verifiedPayment && !shadow_mode
│       └── PaymentCancelled if result.cancelledPayment
└── failed(?Throwable $e): void — log + mark parse_status = 'failed'
```

### PaymentService (Modified)
```
app/Services/Payment/PaymentService.php
├── verifyPayment(Payment $payment, ?int $adminId = null): void
│   ├── Status guard: only Pending
│   ├── Update: status=Verified, verified_by=adminId, verified_at=now()
│   └── NO event dispatch (caller responsibility)
├── cancelPayment(Payment $payment, CancellationType $type, int|string $actorId, ?string $reason): void
│   ├── Status guard: only Pending or Verified
│   ├── Update: status=Cancelled, cancellation_type=type, cancellation_reason=reason, cancelled_by, cancelled_at
│   ├── recalculateOrder() for Verified → Pending fallback
│   └── NO event dispatch (caller responsibility)
├── recordPayment(...): Payment
│   ├── Existing logic + normalizeRef($transaction_id) before save
│   ├── attemptReverseMatch($payment)
│   └── Return $payment (interface unchanged)
├── attemptReverseMatch(Payment $payment): void
│   ├── Guard: Pending + pago_movil
│   ├── Duplicate check: verified Payment with same reference?
│   ├── Query payment_matches: unmatched + same reference + amount + within window
│   └── If found: orchestrator.runReverse(match, payment)
└── getPendingEvents(): array — returns + clears accumulated events
```

## Data Flow: Notification Ingestion (Forward)

```
1. Android captures bank push notification
2. App sends POST /api/device/notifications
   Headers: X-Device-Token: <token>
   Body: { bank_code, title, body, received_at, dedup_hash }
3. DeviceAuth middleware validates token → resolves device_id
4. Controller creates PaymentNotification (dedup_hash unique constraint → 200 duplicate_ignored)
5. IngestPaymentNotification::dispatch($notification)
6. Job: parser.parse(bank_code, raw_body)
   ├── null → parse_status = 'failed', SystemAlert, STOP
   └── ParsedPayment → continue
7. DB::transaction:
   ├── PaymentMatch::createFromParsed(notification, parsed) → match_status='unmatched'
   └── ReconciliationOrchestrator::run(match)
       ├── Step 0: Duplicate? → cancel attempting payment, STOP
       ├── Step 1: SELECT FOR UPDATE on pending payments
       ├── Step 2: One match → match_status='matched' (or 'pending' in shadow)
       ├── Step 3: Multiple → match_status='pending'
       └── Step 4: None → match_status='unmatched'
8. parse_status = 'parsed'
9. AFTER commit: dispatch PaymentVerified / PaymentCancelled
```

## Data Flow: Reverse Matching (Payment-First)

```
1. Customer fills form: reference + amount + sender fields
2. Tenant PaymentController::store() → PaymentService::recordPayment()
3. recordPayment() saves Payment (status=Pending)
4. attemptReverseMatch($payment) — synchronous
   ├── Guard: status=Pending + payment_method=pago_movil
   ├── Duplicate check: verified Payment with same reference?
   │   └── YES → cancelPayment(SystemDuplicate), add to pendingEvents, STOP
   ├── Query payment_matches: match_status='unmatched' + same reference + amount + within window
   └── If found → ReconciliationOrchestrator::runReverse(match, payment)
       ├── Shadow mode OFF → verifyPayment($payment, null) → match_status='matched'
       └── Shadow mode ON → match_status='pending' (suggestion)
5. recordPayment() returns Payment (interface unchanged)
6. Controller dispatches getPendingEvents() AFTER response
7. Controller: if payment.status === Verified → "Auto-verified instantly!"
```

## Event Architecture

### Events

```php
// NEW — app/Events/PaymentCancelled.php
class PaymentCancelled {
    public function __construct(
        public readonly Payment $payment,
        public readonly CancellationType $type,
        public readonly ?string $reason = null,
    ) {}
}
```

### Listeners

```php
// NEW — app/Listeners/NotifyPaymentRejected.php
class NotifyPaymentRejected {
    public function handle(PaymentCancelled $event): void {
        match ($event->type) {
            CancellationType::SystemDuplicate => $this->handleDuplicateFraud($event),
            CancellationType::SystemExpired => $this->handleExpiredPayment($event),
            default => $this->handleNormalRejection($event),
        };
    }
    // SystemDuplicate → notify tenant + landlord admins
    // SystemExpired → notify tenant only
    // Manual → notify tenant only
}
```

### Registration (EventServiceProvider or attributes)

```php
// In EventServiceProvider::$listen or via attribute:
PaymentCancelled::class => [
    NotifyPaymentRejected::class,
],
```

### Event Dispatch Responsibility (IC-4)

| Caller | Dispatches | After |
|--------|-----------|-------|
| `IngestPaymentNotification` job | `PaymentVerified` / `PaymentCancelled` | `DB::transaction()` commit |
| `Landlord\PaymentController::verify()` | `PaymentVerified` | After `verifyPayment()` call |
| `Landlord\PaymentController::cancel()` | `PaymentCancelled` | After `cancelPayment()` call |
| `Tenant\PaymentController::store()` | Via `getPendingEvents()` | After HTTP response commit |
| `ExpirePendingPayments` command | `PaymentCancelled` | After per-payment `DB::transaction()` |

## API Design

### POST /api/device/notifications

**Middleware**: `device.auth`, `throttle:60,1,device`

**Request**:
```json
{
    "bank_code": "bdv",
    "title": "Banco de Venezuela",
    "body": "Recibiste un PagomovilBDV por Bs. 3.000,00...",
    "received_at": "2026-06-18T09:40:00Z",
    "dedup_hash": "a1b2c3d4e5f6..."
}
```

**Response 200 (created)**:
```json
{ "status": "created" }
```

**Response 200 (duplicate)**:
```json
{ "status": "duplicate_ignored" }
```

**Response 401**: `{ "message": "Invalid or inactive device token." }`
**Response 422**: Validation errors

### POST /api/device/heartbeat

**Middleware**: `device.auth`, `throttle:60,1,device`

**Request**:
```json
{
    "battery_level": 85,
    "notifications_pending_count": 0
}
```

**Response 200**:
```json
{
    "status": "ok",
    "heartbeat_interval_minutes": 5
}
```

### GET /landlord/alerts

**Middleware**: `auth`, `verified`, `EnsureUserIsAdmin`

**Response**: Inertia page with unread system notifications (`read_at IS NULL`, `data->>'category' = 'system'`), filterable by severity.

### POST /landlord/alerts/{notification}/read

**Middleware**: `auth`, `verified`, `EnsureUserIsAdmin`

**Response**: Redirect with success flash. Sets `read_at = now()`.

## Error Handling Strategy

| Failure Point | Handling | Alert |
|---------------|----------|-------|
| Parser regex doesn't match | `parse_status = 'failed'`, job returns early | SystemAlert: `parser_failed`, severity: warning |
| Unknown bank (no regex in DB) | Same as regex mismatch | SystemAlert: `parser_failed` |
| Dedup hash collision | HTTP 200 `duplicate_ignored`, unique constraint prevents insert | None (expected) |
| Invalid device token | HTTP 401 | None (expected) |
| Regex missing required groups on save | HTTP 422 with descriptive error | None (validation) |
| No matching payment | `match_status = 'unmatched'` | SystemAlert: `no_match_accumulated` |
| Multiple matching payments | `match_status = 'pending'` | Admin notification with candidates |
| Duplicate reference (already verified) | `match_status = 'duplicate_attempt'`, cancel attempting payment | SystemAlert + tenant notification |
| Payment expires without match | Cancelled with `SystemExpired` | Tenant notification to re-report |
| Device offline (>2× heartbeat interval) | SystemAlert | `heartbeat_offline`, severity: critical |
| Job failure | Laravel retries (default 3), then `failed_jobs` table | Log entry |

**Retry logic**: `IngestPaymentNotification` uses Laravel's default job retry (3 attempts). No custom retry — the parser is deterministic; if it fails 3 times, it's a format issue, not transient.

## Performance Considerations

### Indexes (Critical)

```sql
-- payment_notifications: lookup by bank and status
CREATE INDEX idx_pn_bank_code ON payment_notifications(bank_code);
CREATE INDEX idx_pn_parse_status ON payment_notifications(parse_status);

-- payment_matches: matching queries
CREATE INDEX idx_pm_match_status ON payment_matches(match_status);
CREATE INDEX idx_pm_payment_id ON payment_matches(payment_id);
-- Partial unique: prevents duplicate matched records
CREATE UNIQUE INDEX idx_payment_matches_matched ON payment_matches(payment_id) WHERE match_status = 'matched';

-- payments: matching query (existing + new)
-- Existing index on status+created_at is sufficient for the window query
-- Consider composite: CREATE INDEX idx_payments_pending_ref ON payments(status, transaction_id, amount_cents, created_at);
```

### Query Optimization

The critical matching query in `ReconciliationOrchestrator::run()`:
```sql
SELECT * FROM payments
WHERE status = 'pending'
  AND transaction_id = :normalized_ref
  AND amount_cents = :amount
  AND created_at >= :window_start
FOR UPDATE
```
This uses the existing `status` index + the new composite index. `FOR UPDATE` locks the row within the transaction to prevent race conditions.

### Caching

- `SystemConfig::get()` caches per-key for 1 hour with sentinel pattern
- Regex patterns are cached via `SystemConfig::get("regex_{$bankCode}")` — single DB query per bank per hour
- No additional caching needed for matching queries (indexed, low cardinality)

### Queue Driver

Use `database` queue driver (already configured). The `IngestPaymentNotification` job is lightweight (parse + match). If throughput becomes an issue (>100 notifications/minute), consider Redis queue driver.

## Security Considerations

### Device Authentication

- `X-Device-Token` header validated against `devices.token` (SHA-256 hash stored)
- Device must be `is_active = true`
- Token is revocable — admin can deactivate device, immediately invalidates all requests
- Token should be 64-char random string generated at device registration

### Rate Limiting

- 60 requests/minute per device token on both notification and heartbeat endpoints
- Use Laravel's `throttle` middleware with `device` prefix for per-token limiting

### Input Validation

- `bank_code`: lowercase enforced at controller level, `strtolower()` before storage
- `dedup_hash`: validated as hex string (SHA-256 format)
- `received_at`: validated as ISO 8601 timestamp
- `raw_title`, `raw_body`: stored as-is (immutability guarantee — no sanitization needed for storage)

### Regex Validation

Before saving regex to `system_configs`:
1. Validate compilation: `preg_match($value, 'test')` — reject if `preg_last_error() !== PREG_NO_ERROR`
2. Validate required named groups: `amount`, `reference` must exist
3. Reject with HTTP 422 and descriptive error message

### API Rate Limiting

Apply `throttle:60,1,device` middleware to device API routes. The `device` parameter ensures rate limiting is per-device-token, not per-IP.

## Migration Strategy

### Implementation Order (Phase Dependencies)

```
Phase 0: Simulator (standalone)
Phase 1: Migrations + Models → Phase 2: Parser → Phase 3: Job → Phase 4: Matching
Phase 5.1: verifyPayment nullable (prereq for Phase 4)
Phase 5.2-5.4: PaymentCancelled + listener (parallel to Phase 4)
Phase 6: Dashboard
Phase 7: Android app (parallel to backend)
Phase 8: Shadow mode activation
```

### Backward Compatibility

1. **config/payment.php removal**: SystemConfigSeeder runs first, populates all values. Code changes from `config('payment.*')` to `SystemConfig::get('payment.*')` happen in the same migration PR.
2. **PaymentService::verifyPayment()**: New signature `?int $adminId = null` is backward compatible — existing callers passing `int` still work.
3. **PaymentService::cancelPayment()**: New signature requires updating all callers. Two callers exist: `Landlord\PaymentController::cancel()` and the `recordPayment()` method-change path. Both are updated in the same PR.
4. **Payment::transaction_id normalization**: `recordPayment()` saves `normalizeRef($transaction_id)`. Existing payments may have non-normalized references. The `reconciliation:reprocess` command can normalize existing data if needed.

### Feature Flag (Shadow Mode)

`reconciliation.shadow_mode_enabled` defaults to `true`. System runs fully but only suggests matches. Admin activates when confident after 1-2 weeks of observation. Rollback: set back to `true` (already verified payments are not reverted).

## Open Questions

- [ ] **Package IDs**: `com.banesco.bancamovil` and `com.dinerorapido.bancamovil` need verification with real devices. Should date formats also be DB-stored or remain hardcoded in `getDateFormat()`? Plan says hardcode (low frequency), but worth confirming.
- [ ] **Heartbeat monitoring per-device vs global**: Currently global via `system_configs`. Should per-device intervals be supported?
- [ ] **SystemAlert channels**: Currently `database` only. Should critical alerts (device offline, parser failure) also send via `mail`?
- [ ] **Queue driver**: Currently `database`. For production, should we require Redis? The job is lightweight, but high throughput may benefit.
- [ ] **Existing payment normalization**: How to handle existing `payments.transaction_id` values that aren't normalized (lowercase, with spaces)? A one-time migration command may be needed.
