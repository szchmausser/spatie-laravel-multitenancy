# Design: Payment Notification Multichannel

## Technical Approach

Five coordinated changes that decouple the notification pipeline from the Android-only assumption, making it channel-aware without breaking the existing SMS flow. The pipeline remains: **DeviceController (or future source) → PaymentNotification (landlord) → IngestPaymentNotification job → PaymentNotificationParser (channel-aware regex) → PaymentMatch (by-reference dedup) → ReconciliationOrchestrator**.

The proposal's table maps directly to deliverables: (1) schema changes, (2) SourceType enum, (3) channel-aware parser, (4) server-side dedup hash, (5) by-reference PaymentMatch dedup, plus a data migration for regex entries.

## Architecture Decisions

### Decision: 4-step dedup instead of partial unique index on `parsed_reference`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Partial unique index `WHERE match_status='unmatched'` | Strong race-condition protection. But: requires `match_status` in the index, increases complexity, may conflict with existing duplicate references | **Rejected** — race window is narrow (inside `DB::transaction`) |
| Application-level dedup with retry | Simpler, no DDL changes. 4-step `createFromParsed()` algorithm covers all cases | **Chosen** — the existing transaction in `IngestPaymentNotification::handle()` wraps both the match creation and reconciliation. First-by-notification-ID check provides idempotency for retries |

**Rationale**: The transaction scope already uses `SELECT ... FOR UPDATE` in the orchestrator. The by-reference SELECT runs inside the same transaction, so concurrent workers for the same reference are serialized at the DB level when they reach the orchestrator's `lockForUpdate()` call. The partial index would add schema maintenance cost for theoretical edge-case protection in a low-volume pipeline.

### Decision: Per-channel regex without fallback

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Try `regex_{b}_{s}` first, fallback to `regex_{b}` | Backward-compatible but silently masks missing push regexes with SMS regex (wrong parsing) | **Rejected** — fail closed forces operator to configure the correct regex |
| Require `sourceType`, fail if missing | Explicit, auditable. Existing parser overload unchanged for SMS path | **Chosen** — new 3-arg `parse()` is the channel-aware path; old 2-arg stays for SMS |

### Decision: Data migration copies existing regex to `_{sms}` and `_{android_push}`

**Rationale**: The old `regex_{b}` entries may already match both SMS and push formats for some banks (BDV format may be identical). Copying them ensures the channel-aware path works immediately for SMS. Operators will update `_android_push` entries with bank-specific push regexes after deployment.

### Decision: SourceType as backed string enum, not DB enum

**Rationale**: Follows the existing `BankCode` pattern. PHP backed enums with `tryFrom()` are safer than DB-level ENUM types (no ALTER TYPE for new values). The `values()` method provides validated rule arrays for request validation.

## Data Flow

```
┌──────────────────────────────────────────────────────────────────┐
│  NOTIFICATION ARRIVAL (multichannel)                             │
│                                                                  │
│  Android Push     Future Source  Future Source                   │
│  (DeviceController)  (webhook)     (scraper)                    │
│         │               │              │                         │
│         ▼               ▼              ▼                         │
│  ┌─────────────┐  ┌──────────┐  ┌──────────┐                    │
│  │ source_type  │  │source_typ│  │source_typ│                    │
│  │ =android_push│  │=webhook  │  │=scraper  │                    │
│  └──────┬──────┘  └────┬─────┘  └────┬─────┘                    │
│         │              │              │                          │
│         └──────────────┴──────────────┘                          │
│                        │                                         │
│                        ▼                                         │
│  ┌─────────────────────────────────────┐                         │
│  │ PaymentNotification (landlord)       │                         │
│  │ - device_id: nullable               │                         │
│  │ - source_type: string(20)           │                         │
│  │ - dedup_hash: server-computed        │                         │
│  └────────────────┬────────────────────┘                         │
│                   │ dispatch                                      │
│                   ▼                                              │
│  ┌─────────────────────────────────────┐                         │
│  │ IngestPaymentNotification job       │                         │
│  │ DB::transaction {                   │                         │
│  │   parse(sourceType channel-aware)   │                         │
│  │   createFromParsed(by-reference)    │                         │
│  │   reconciliation_orchestrator.run() │                         │
│  │ }                                   │                         │
│  └────────────────┬────────────────────┘                         │
│                   │                                              │
│                   ▼                                              │
│  ┌─────────────────────────────────────┐                         │
│  │ PaymentNotificationParser::parse()   │                         │
│  │                                     │                         │
│  │ Input: bankCode, rawBody, sourceType│                         │
│  │                                     │                         │
│  │ sourceType=null → null (fail)       │                         │
│  │ sourceType='android_push' →         │                         │
│  │   lookup: regex_{bank}_{sourceType} │                         │
│  │   found? → apply → ParsedPayment    │                         │
│  │   not found? → null (fail)          │                         │
│  └────────────────┬────────────────────┘                         │
│                   │                                              │
│                   ▼                                              │
│  ┌─────────────────────────────────────┐                         │
│  │ PaymentMatch::createFromParsed()     │                         │
│  │                                     │                         │
│  │ 1. By payment_notification_id       │← idempotency (retries) │
│  │    → exists? return it              │                         │
│  │ 2. By parsed_reference (unmatched)   │← cross-channel dedup  │
│  │    → exists? return it              │                         │
│  │ 3. By parsed_reference (matched)     │← duplicate audit      │
│  │    → exists? create 'duplicate'      │                         │
│  │ 4. No match? create 'unmatched'      │                         │
│  └────────────────┬────────────────────┘                         │
│                   │                                              │
│                   ▼                                              │
│  ┌─────────────────────────────────────┐                         │
│  │ ReconciliationOrchestrator::run()    │                         │
│  │ (unchanged)                          │                         │
│  └─────────────────────────────────────┘                         │
└──────────────────────────────────────────────────────────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Enums/SourceType.php` | **Create** | Backed string enum `AndroidPush`, `label()`, `values()` |
| `database/migrations/landlord/YYYY_MM_DD_HHMMSS_add_source_type_to_payment_notifications.php` | **Create** | Alter: drop/re-add FK, add `source_type` varchar(20) + index, update existing rows to `'android_push'` |
| `database/migrations/landlord/YYYY_MM_DD_HHMMSS_seed_channel_regex_entries.php` | **Create** | Seed `regex_{b}_sms` and `regex_{b}_android_push` from existing `regex_{b}` |
| `app/Models/PaymentNotification.php` | **Modify** | Add `source_type` to `$fillable` and casts as `SourceType` |
| `app/Models/PaymentMatch.php` | **Modify** | Rewrite `createFromParsed()` with 4-step by-reference dedup |
| `app/Services/Payment/PaymentNotificationParser.php` | **Modify** | `parse()` and `normalizeForDedup()` accept `?string $sourceType`; resolve `regex_{b}_{s}` |
| `app/Http/Controllers/Api/DeviceController.php` | **Modify** | Remove `dedup_hash` validation + Log::warning + SystemAlert emission; compute server hash; set `source_type` |
| `app/Jobs/IngestPaymentNotification.php` | **Modify** | Pass `notification->source_type` to `parser->parse()` |
| `database/factories/PaymentNotificationFactory.php` | **Modify** | Add `source_type` to `definition()` (default `'android_push'`) |
| `database/factories/PaymentMatchFactory.php` | **Modify** | Add `duplicate()` state |
| `tests/Feature/Api/DeviceNotificationTest.php` | **Modify** | Replace `dedup_hash_mismatch` test with server-hash assertions; add `source_type` checks |
| `tests/Unit/Services/Payment/IngestPaymentNotificationTest.php` | **Modify** | Add by-reference dedup scenario tests |
| `tests/Unit/Services/Payment/PaymentNotificationParserTest.php` | **Modify** | Add channel-aware and missing-regex tests |

## Interfaces / Contracts

### SourceType enum

```php
enum SourceType: string
{
    case AndroidPush = 'android_push';

    public function label(): string;     // 'Android Push'
    public static function values(): array; // ['android_push']
}
```

### Parser signatures (overloaded)

```php
// Backward compat (SMS path): no sourceType → uses regex_{bankCode}
public function parse(string $bankCode, string $text): ?ParsedPayment;
// Channel-aware: sourceType required; fails if regex_{bankCode}_{sourceType} missing
public function parse(string $bankCode, string $text, ?string $sourceType = null): ?ParsedPayment;
// Same overloading for normalizeForDedup
public function normalizeForDedup(string $bankCode, string $rawBody, ?string $sourceType = null): string;
```

### PaymentMatch::createFromParsed algorithm

```
createFromParsed(notification, parsed):
  1. existingByNotification = WHERE payment_notification_id = notification.id
     → if exists: return it (idempotency)
  2. existingUnmatched = WHERE parsed_reference = parsed.reference AND match_status = 'unmatched'
     → if exists: return it (reuse cross-channel)
  3. existingMatched = WHERE parsed_reference = parsed.reference AND match_status = 'matched'
     → if exists: create NEW with match_status = 'duplicate', return it
  4. No match: create NEW with match_status = 'unmatched'
```

## Database Design

### Migration: add source_type to payment_notifications

```sql
-- Step 1: Drop existing FK (from 2026_06_24 migration)
ALTER TABLE payment_notifications DROP CONSTRAINT
  payment_notifications_device_id_foreign;

-- Step 2: Re-add FK with explicit ON DELETE SET NULL (column is already nullable)
ALTER TABLE payment_notifications ADD CONSTRAINT
  payment_notifications_device_id_foreign
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL;

-- Step 3: Add source_type column
ALTER TABLE payment_notifications ADD source_type VARCHAR(20) NOT NULL DEFAULT 'android_push';

-- Step 4: Backfill existing rows
UPDATE payment_notifications SET source_type = 'android_push' WHERE source_type IS NULL;

-- Step 5: Add index
CREATE INDEX payment_notifications_source_type_index ON payment_notifications (source_type);
```

### Migration: seed channel regex entries

```php
$map = [
    'bdv' => ['regex_bdv_sms' => 'regex_bdv', 'regex_bdv_android_push' => 'regex_bdv'],
    'bnc' => ['regex_bnc_sms' => 'regex_bnc', 'regex_bnc_android_push' => 'regex_bnc'],
];
foreach ($map as $bank => $entries) {
    $source = SystemConfig::get($entries[array_key_first($entries)]);
    if ($source === null) continue;
    foreach ($entries as $newKey => $oldKey) {
        $value = SystemConfig::get($oldKey);
        if ($value && !SystemConfig::where('key', $newKey)->exists()) {
            SystemConfig::create(['group' => 'reconciliation', 'key' => $newKey, 'value' => $value, 'type' => 'string']);
        }
    }
}
```

### Down migration

```sql
DROP INDEX payment_notifications_source_type_index;
ALTER TABLE payment_notifications DROP COLUMN source_type;
```

## Impact Analysis

- **Queries affected by nullable device_id**: None directly query `device_id` on `payment_notifications` for filtering. The `Device::paymentNotifications()` relation (`hasMany`) handles nulls transparently. The dashboard join uses `bank_code` only.
- **Existing tests needing update**: `DeviceNotificationTest.php` tests `dedup_hash_mismatch` assertion → must be removed. `PaymentNotificationParserTest.php` tests `regex_{bank}` lookup unchanged; new channel-aware tests added.
- **Frontend views**: `payment-details-card.tsx` and `orders/show.tsx` type `PaymentMatch` — no `device_id` or `source_type` displayed. `notification-row.tsx` / `notification-dropdown.tsx` handle `SystemAlert` type `Notification` — removing `dedup_hash_mismatch` type means it won't appear in the dropdown.

## Verification Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `SourceType` enum values, labels, `tryFrom()` | `expect($sourceType->value)->toBe('android_push')` |
| Unit | `PaymentNotificationParser::parse()` with `sourceType` | Test `regex_{b}_{s}` resolution, null on missing key, null on null sourceType |
| Unit | `PaymentMatch::createFromParsed()` 4-step dedup | Test: idempotency (same notification), reuse (unmatched ref), duplicate (matched ref), fresh (no ref) |
| Unit | `DeviceController::storeNotification()` server hash | Test: old `dedup_hash` ignored, server hash stored, no SystemAlert emitted |
| Integration | Full pipeline SMS unchanged | Run existing `IngestPaymentNotificationTest` — no changes expected |
| Integration | Push notification → channel-aware parse → by-reference dedup | Create push notification with `source_type='android_push'`, verify parser uses `regex_{b}_{s}` |
| Integration | Same payment via SMS + push → 1 PaymentMatch | Create SMS notification (parsed, unmatched), then push with same ref → `createFromParsed` returns existing match |

## Migration / Rollout

1. **Deploy migrations first** (schema + regex seed) — existing code continues working, `regex_{b}_{s}` entries are populated but unused.
2. **Deploy SourceType enum + model changes** — `PaymentNotification` casts `source_type` to enum; existing rows have `android_push`.
3. **Deploy parser + job + PaymentMatch changes** — `IngestPaymentNotification` starts passing `source_type`; `createFromParsed` starts deduplicating by reference.
4. **Deploy DeviceController changes** — removes `dedup_hash` validation; old clients still send it silently ignored.
5. **Monitor** — verify no `dedup_hash_mismatch` SystemAlerts fire. Check `source_type` distribution in DB.

Rollback reverses this order, restoring the old `regex_{bank}` single-lookup, `firstOrCreate` by notification ID, and client-supplied `dedup_hash`.

## Open Questions

- None — spec is fully specified. The `regex_{bank}_android_push` values need actual push notification regex patterns from operators post-deployment, but this is a content issue, not a design blocker.
