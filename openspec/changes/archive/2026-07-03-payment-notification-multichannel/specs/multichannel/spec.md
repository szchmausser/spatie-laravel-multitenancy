# Spec: Payment Notification Multichannel

> **Phase**: SDD Spec
> **Derived from**: `openspec/changes/payment-notification-multichannel/proposal.md`
> **Date**: 2026-07-03

---

## Table of Contents

1. [Deliverable 1: Nullable `device_id` + `source_type` Column](#deliverable-1-nullable-device_id--source_type-column)
2. [Deliverable 2: `SourceType` Backed Enum](#deliverable-2-sourcetype-backed-enum)
3. [Deliverable 3: Per-Channel Regex Parser](#deliverable-3-per-channel-regex-parser)
4. [Deliverable 4: Server-Side Dedup Hash](#deliverable-4-server-side-dedup-hash)
5. [Deliverable 5: By-Reference PaymentMatch Dedup](#deliverable-5-by-reference-paymentmatch-dedup)
6. [Data Migration: Per-Channel Regex Entries](#data-migration-per-channel-regex-entries)
7. [Affected Files Summary](#affected-files-summary)

---

## Deliverable 1: Nullable `device_id` + `source_type` Column

### What

Modify the `payment_notifications` table to accept null `device_id` (to support non-Android sources) and add a `source_type` column to track the origin channel of each notification.

### Current Schema State

The `payment_notifications` table already has `device_id` as `unsignedBigInteger` with a nullable column definition in the base migration. A foreign key constraint exists (added in `2026_06_24_000002_add_android_fields_to_payment_notifications.php`) with `nullOnDelete` (`ON DELETE SET NULL`). `device_id` has no `NOT NULL` constraint at the column level — but the column is effectively required because all existing code paths always set it.

### Migration: `payment_notifications` Table

```php
Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
    // 1. Drop existing FK to redefine it
    $table->dropForeign(['device_id']);

    // 2. Ensure device_id is explicitly nullable (schema is already nullable, reinforce)
    //    No change needed: column is already nullable. But we re-add the FK
    //    with ON DELETE SET NULL for clarity.

    // 3. Re-add the FK with explicit ON DELETE SET NULL
    $table->foreign('device_id')
          ->references('id')
          ->on('devices')
          ->nullOnDelete();

    // 4. Add source_type column
    $table->string('source_type', 20)
          ->default('android_push')
          ->after('device_id');          // position after device_id

    // 5. Add index for source_type queries (filtering, stats)
    $table->index('source_type');
});
```

**Exact column definition**:

| Column       | Type             | Constraints                          | Default         | Notes                             |
|-------------|------------------|--------------------------------------|-----------------|-----------------------------------|
| `device_id` | `unsigned bigint` | `FOREIGN KEY REFERENCES devices(id) ON DELETE SET NULL`, nullable | — | Already nullable; FK with SET NULL |
| `source_type` | `varchar(20)`   | `NOT NULL`                           | `'android_push'` | Backed by `SourceType` enum values |

### Data Migration: Populate Existing Rows

All existing rows have `device_id` set (Android path). Set their `source_type`:

```php
DB::connection('landlord')
    ->table('payment_notifications')
    ->whereNull('source_type')
    ->update(['source_type' => 'android_push']);
```

### Acceptance Criteria

- [ ] **AC1.1**: New notification without `device_id` creates successfully when `source_type` is provided.
- [ ] **AC1.2**: Existing notifications all have `source_type = 'android_push'` after migration.
- [ ] **AC1.3**: Deleting a `Device` sets `device_id` to null on linked notifications (no cascade delete, no FK violation).
- [ ] **AC1.4**: Querying by `source_type` uses the index (verify via `EXPLAIN`).
- [ ] **AC1.5**: Migration is reversible (down: drop `source_type` column + its index, restore the FK without nullOnDelete if needed — or simply reaffirm the existing FK).

### Down Migration

```php
Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
    $table->dropIndex(['source_type']);
    $table->dropColumn('source_type');
    // FK stays — it already existed. Dropping it would break referential integrity.
    // Keep the FK as-is for rollback safety.
});
```

### Scenarios

**Happy Path — Non-Android notification**:
```
GIVEN: A notification arrives via future webhook
WHEN:  It is stored without a device_id (source_type = 'webhook')
THEN:  PaymentNotification.device_id IS NULL
THEN:  PaymentNotification.source_type = 'webhook'
THEN:  No FK constraint violation occurs
```

**Edge Case — Device deleted after notification stored**:
```
GIVEN: A notification exists with device_id = 42 (Android push)
WHEN:  Device 42 is deleted from the devices table
THEN:  PaymentNotification.device_id IS SET TO NULL
THEN:  The notification row still exists (no cascade)
```

**Error Case — Null device_id with empty source_type**:
```
GIVEN: An insert attempts to create a notification with device_id = NULL
AND:   source_type is omitted (default applies)
WHEN:  The INSERT runs
THEN:  It succeeds with source_type = 'android_push' (default)
THEN:  No integrity error — but semantically this is wrong for a null-device notif.
NOTE:  Future endpoints MUST explicitly set source_type. The default protects
       the current DeviceController path only.
```

---

## Deliverable 2: `SourceType` Backed Enum

### What

A new backed string PHP enum in `app/Enums/SourceType.php`, following the same convention as `BankCode`.

### Definition

```php
<?php

namespace App\Enums;

enum SourceType: string
{
    case AndroidPush = 'android_push';

    /**
     * Human-readable label for display in admin UI / logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::AndroidPush => 'Android Push',
        };
    }

    /**
     * All known source types (not just currently active ones).
     * Use for validation rules, migration seeds, etc.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
```

### Acceptance Criteria

- [ ] **AC2.1**: `SourceType::AndroidPush->value === 'android_push'`
- [ ] **AC2.2**: `SourceType::AndroidPush->label() === 'Android Push'`
- [ ] **AC2.3**: `SourceType::values()` returns `['android_push']`
- [ ] **AC2.4**: Model cast on `PaymentNotification.source_type` accepts the enum (or stores the string value)
- [ ] **AC2.5**: Future additions can add cases without breaking existing code

### Scenarios

**Happy Path — Enum resolution**:
```
GIVEN: A SourceType::AndroidPush case
WHEN:  Its value is accessed
THEN:  It returns 'android_push'
WHEN:  Its label is accessed
THEN:  It returns 'Android Push'
```

**Edge Case — Unknown string → null**:
```
GIVEN: A string 'sms' is passed to SourceType::tryFrom()
THEN:  It returns null (no case for SMS yet)
```

**Edge Case — Validation**:
```
GIVEN: A request sends source_type = 'alien_format'
WHEN:  Validated against SourceType::values()
THEN:  Validation fails with "The selected source_type is invalid."
```

---

## Deliverable 3: Per-Channel Regex Parser

### What

Modify `PaymentNotificationParser::parse()` and `PaymentNotificationParser::normalizeForDedup()` to accept an optional `$sourceType` parameter. When `$sourceType` is provided, the parser looks up `regex_{bankCode}_{sourceType}` in `SystemConfig`. If the key is not found, the parser returns `null` (unparseable) **without falling back** to the generic `regex_{bankCode}`. If `$sourceType` is `null`, the parser also returns `null` (fails closed).

The existing `regex_{bankCode}` keys remain in the database for backward compatibility with the SMS code path — but the channel-aware path does NOT use them.

### Method Signatures

```php
// Current (unchanged): still used by SMS-based code paths
public function parse(string $bankCode, string $text): ?ParsedPayment

// New overload with source type — primary for channel-aware paths
public function parse(string $bankCode, string $text, ?string $sourceType = null): ?ParsedPayment
```

Behavior table for the channel-aware overload:

| `$sourceType` | `regex_{bankCode}_{sourceType}` exists | Regex matches | Result      |
|--------------|----------------------------------------|---------------|-------------|
| `null`       | —                                      | —             | `null` (fail) |
| `'android_push'` | Yes                                | Yes           | `ParsedPayment` |
| `'android_push'` | Yes                                | No            | `null` (fail) |
| `'android_push'` | No (key not found)                 | —             | `null` (fail) |
| `'sms'`      | Yes                                    | Yes           | `ParsedPayment` |
| `'sms'`      | No                                     | —             | `null` (fail) |

### Implementation Logic

```php
public function parse(string $bankCode, string $text, ?string $sourceType = null): ?ParsedPayment
{
    // Select regex key based on source type
    $regexKey = $sourceType !== null
        ? "regex_{$bankCode}_{$sourceType}"
        : null;

    // No source type → no regex to try → fail
    if ($regexKey === null) {
        return null;
    }

    // Get regex from SystemConfig (cached 1h)
    $regex = SystemConfig::get($regexKey);

    // No regex configured for this bank+channel → fail
    if (! $regex) {
        return null;
    }

    // Apply regex
    if (preg_match($regex, $text, $matches) !== 1) {
        return null;
    }

    // Validate required groups (unchanged)
    if (empty($matches['amount']) || empty($matches['reference'])) {
        return null;
    }

    // Normalize and return (unchanged)
    $namedGroups = array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);

    return new ParsedPayment(
        amountCents: $this->normalizeAmount($matches['amount']),
        reference: normalizeRef($matches['reference']),
        senderPhoneLast4: $this->extractLast4($matches['phone'] ?? null),
        parsedAt: $this->parseDate(
            $matches['date'] ?? null,
            $matches['time'] ?? null,
            $this->getDateFormat($bankCode)
        ),
        rawGroups: $namedGroups,
    );
}
```

Similarly for `normalizeForDedup`:

```php
public function normalizeForDedup(string $bankCode, string $rawBody, ?string $sourceType = null): string
{
    $regexKey = $sourceType !== null
        ? "regex_{$bankCode}_{$sourceType}"
        : null;

    if ($regexKey === null) {
        return $rawBody;
    }

    $regex = SystemConfig::get($regexKey);

    if (! $regex || preg_match($regex, $rawBody, $matches) !== 1) {
        return $rawBody;
    }

    // ... rest unchanged (normalization logic)
}
```

### Acceptance Criteria

- [ ] **AC3.1**: `parse('bdv', $text, 'android_push')` finds `regex_bdv_android_push` and parses successfully when the push format matches.
- [ ] **AC3.2**: `parse('bdv', $text, 'android_push')` returns `null` when `regex_bdv_android_push` does not exist in SystemConfig.
- [ ] **AC3.3**: `parse('bdv', $text, null)` returns `null` (source type required — no fallback).
- [ ] **AC3.4**: `parse('bdv', $text)` (no 3rd arg) still works for the non-source-type-aware path — it uses the old `regex_bdv` key (backward compat).
- [ ] **AC3.5**: `normalizeForDedup('bdv', $body, 'android_push')` uses `regex_bdv_android_push` for normalization.
- [ ] **AC3.6**: `ParsedPayment` still requires both `amount` and `reference` (unchanged validation).
- [ ] **AC3.7**: Existing `regex_bdv` key remains in SystemConfig and still works via the non-source-type-aware overload.

### Scenarios

**Happy Path — Android push parses correctly**:
```
GIVEN: SystemConfig has regex_bdv_android_push configured
AND:   A BDV push notification body arrives
WHEN:  parser->parse('bdv', $pushBody, 'android_push')
THEN:  Returns a ParsedPayment with amount, reference, phone, date
```

**Error Case — Missing per-channel regex**:
```
GIVEN: SystemConfig has regex_bdv but NOT regex_bdv_android_push
AND:   A BDV push notification arrives
WHEN:  parser->parse('bdv', $pushBody, 'android_push')
THEN:  Returns null (no matching regex for android_push channel)
```

**Error Case — Source type null**:
```
GIVEN: A notification arrives with source_type = null or unspecified
WHEN:  parser->parse('bdv', $text, null)
THEN:  Returns null (no channel-aware regex resolution without source type)
```

**Edge Case — Backward compat path unchanged**:
```
GIVEN: SystemConfig has regex_bdv (the old generic key)
AND:   An SMS notification arrives (legacy path, no source_type)
WHEN:  parser->parse('bdv', $smsBody)   [no 3rd arg]
THEN:  Still uses regex_bdv key
THEN:  ParsedPayment returned as before (no behavior change)
```

---

## Deliverable 4: Server-Side Dedup Hash

### What

Remove `dedup_hash` from the request validation rules in `DeviceController::storeNotification()`. The server computes its own hash via `PaymentNotification::computeDedupHash()` and stores it. The `dedup_hash_mismatch` SystemAlert emission is removed — the server's hash is authoritative.

The `dedup_hash` field stays on the `payment_notifications` table (it is useful for the dedup UNIQUE constraint on the server-computed value). Its UNIQUE constraint remains active.

### Controller Changes

```php
// DeviceController::storeNotification()

public function storeNotification(Request $request): JsonResponse
{
    /** @var Device $device */
    $device = $request->get('device');

    $validated = $request->validate([
        'bank_code' => ['required', 'string', 'max:20'],
        'raw_body' => ['required', 'string'],
        // 'dedup_hash' REMOVED from validation — old clients still send it, ignored
    ]);

    try {
        // Compute hash SERVER-SIDE before insert
        $dedupHash = PaymentNotification::computeDedupHash(
            $validated['bank_code'],
            $validated['raw_body'],
        );

        $notification = PaymentNotification::forceCreate([
            'device_id' => $device->id,
            'bank_code' => $validated['bank_code'],
            'raw_text' => $validated['raw_body'],
            'dedup_hash' => $dedupHash,
            'source_type' => 'android_push',       // <-- NEW: set source type
            'parse_status' => 'pending',
        ]);

        // SystemAlert for dedup_hash_mismatch REMOVED — server hash is authoritative

        IngestPaymentNotification::dispatch($notification);

        return response()->json(['status' => 'created'], 201);
    } catch (QueryException $e) {
        if ($e->getCode() === '23505') {
            // Unique violation on dedup_hash: same notification already stored
            return response()->json(['status' => 'duplicate_ignored'], 200);
        }

        throw $e;
    }
}
```

### Key Points

- Old Android clients still send `dedup_hash` in the request body → the field is accepted but IGNORED (not validated, not used).
- The server computes the hash using the **existing** `computeDedupHash()` method, which delegates to `PaymentNotificationParser::normalizeForDedup()`.
- The UNIQUE constraint on `dedup_hash` still catches duplicates — same behavior, same HTTP 200 `duplicate_ignored` response.
- The `dedup_hash_mismatch` SystemAlert and its associated `use` statement for `SystemAlert` and `Notification` are removed.
- Add `source_type = 'android_push'` when the notification is created via DeviceController.

### Acceptance Criteria

- [ ] **AC4.1**: Device sends notification with `dedup_hash: 'anything'` (or omits it entirely) → notification created with server-computed hash.
- [ ] **AC4.2**: Duplicate notification (same server-computed hash) returns HTTP 200 `duplicate_ignored` — UNIQUE constraint still catches it.
- [ ] **AC4.3**: `dedup_hash_mismatch` SystemAlert is no longer sent to admins.
- [ ] **AC4.4**: `Log::warning('Dedup hash mismatch', ...)` call is removed.
- [ ] **AC4.5**: `source_type` is set to `'android_push'` in the stored notification.

### Scenarios

**Happy Path — New notification**:
```
GIVEN: Android device sends POST /api/device/notifications
AND:   Body includes bank_code, raw_body (dedup_hash optionally present)
WHEN:  storeNotification() processes the request
THEN:  Server computes dedup_hash = sha256(bank_code + normalized)
THEN:  Notification stored with server-computed dedup_hash
THEN:  source_type = 'android_push'
THEN:  Response 201 created
```

**Error Case — Duplicate detected by UNIQUE constraint**:
```
GIVEN: Same notification (same bank_code + raw_body) sent twice
WHEN:  Second request arrives
THEN:  Server computes same hash as first
THEN:  PostgreSQL UNIQUE constraint on dedup_hash fires
THEN:  QueryException with code 23505 caught
THEN:  Response 200 duplicate_ignored
THEN:  No SystemAlert sent
```

**Backward Compat — Old client sends dedup_hash**:
```
GIVEN: Old Android app sends dedup_hash in request
WHEN:  Request arrives
THEN:  dedup_hash in body is accepted but IGNORED (no validation error)
THEN:  Server computes its own hash (may differ from client hash)
THEN:  Notification stored with server hash
THEN:  No SystemAlert about mismatch
```

---

## Deliverable 5: By-Reference PaymentMatch Dedup

### What

Modify `PaymentMatch::createFromParsed()` to deduplicate by `parsed_reference` rather than only by `payment_notification_id`. When a new notification arrives with a `parsed_reference` that already has a match record:

- If the existing match has `match_status = 'unmatched'`: **return the existing record** (no new match created — the existing unmatched match will be updated by the orchestrator when reconciliation runs).
- If the existing match has `match_status = 'matched'`: **create a new match** with `match_status = 'duplicate'` (audit trail — same payment notified twice via different channels).
- If no match exists with that `parsed_reference`: create a new match with `match_status = 'unmatched'` (current behavior).

### Implementation

```php
public static function createFromParsed(PaymentNotification $notification, ParsedPayment $parsed): static
{
    // First, check for existing match by payment_notification_id (idempotency
    // for retries/job replays — exact same notification arriving again)
    $existingByNotification = static::where('payment_notification_id', $notification->id)->first();

    if ($existingByNotification !== null) {
        return $existingByNotification;
    }

    // Now check by parsed_reference for cross-channel dedup
    $existingByReference = static::where('parsed_reference', $parsed->reference)->first();

    if ($existingByReference !== null) {
        // There's already a match with this reference.
        if ($existingByReference->match_status === 'unmatched') {
            // Reuse the existing unmatched match — orchestrator will reconcile it
            return $existingByReference;
        }

        // Existing match is already matched → this is a duplicate notification
        // from a different channel. Create a new record for audit trail.
        return static::create([
            'payment_notification_id' => $notification->id,
            'parsed_reference' => $parsed->reference,
            'parsed_amount_cents' => $parsed->amountCents,
            'parsed_sender_phone_last4' => $parsed->senderPhoneLast4,
            'match_status' => 'duplicate',
        ]);
    }

    // No existing match found — create a new unmatched match
    return static::create([
        'payment_notification_id' => $notification->id,
        'parsed_reference' => $parsed->reference,
        'parsed_amount_cents' => $parsed->amountCents,
        'parsed_sender_phone_last4' => $parsed->senderPhoneLast4,
        'match_status' => 'unmatched',
    ]);
}
```

### Race Condition Handling

The `createFromParsed()` call runs inside a `DB::transaction()` in `IngestPaymentNotification::handle()`. The by-reference lookup uses a `SELECT ... WHERE parsed_reference = ?` query. Two concurrent job workers processing the same reference could both see "no existing match" and attempt to create an unmatched match.

Mitigation: Add a database-level UNIQUE constraint on `parsed_reference` for the `unmatched` state — or use a partial unique index:

```sql
-- Partial unique index: at most one unmatched match per reference
CREATE UNIQUE INDEX payment_matches_unmatched_ref_unique
    ON payment_matches (parsed_reference)
    WHERE match_status = 'unmatched';
```

This ensures that even in a race condition, only one unmatched match per reference survives. The second INSERT would fail with a unique violation, and `createFromParsed` should catch and retry (or fall back to fetching the existing unmatched match).

**NOTE**: Evaluate whether this partial index is feasible for the existing data volume. If existing data has many unmatched matches with different references (expected), it is safe.

### Acceptance Criteria

- [ ] **AC5.1**: First notification with reference `R1` → new unmatched PaymentMatch created.
- [ ] **AC5.2**: Second notification with same reference `R1` while first is still `unmatched` → returns existing match (no new row).
- [ ] **AC5.3**: After first match becomes `matched`, second notification with same reference `R1` → new match created with `match_status = 'duplicate'`.
- [ ] **AC5.4**: Job retry (same notification dispatched twice) → returns existing match by `payment_notification_id` regardless of reference.
- [ ] **AC5.5**: Race condition (two workers, same reference, both see no existing match) → only one unmatched match created; second attempt either returns the first or creates a duplicate.

### Scenarios

**Happy Path — SMS arrives, push arrives later (before match)**:
```
GIVEN: SMS notification for reference "1234567890" arrives first
WHEN:  createFromParsed() runs
THEN:  New PaymentMatch created: payment_notification_id = SMS, match_status = 'unmatched'
WHEN:  Push notification for same reference "1234567890" arrives
WHEN:  createFromParsed() runs again
THEN:  Unmatched match exists for reference "1234567890"
THEN:  Returns existing match (no new row)
```

**Happy Path — SMS arrives, push arrives later (after match)**:
```
GIVEN: SMS notification for reference "1234567890" arrives
WHEN:  Orchestrator reconciles it → match_status = 'matched'
WHEN:  Push notification for same reference arrives later
WHEN:  createFromParsed() runs
THEN:  Matched match exists for reference "1234567890"
THEN:  New PaymentMatch created: match_status = 'duplicate'
```

**Error Case — Race condition**:
```
GIVEN: Two IngestPaymentNotification jobs dispatched simultaneously
AND:   Both process the same reference "1234567890"
WHEN:  Worker 1 checks: no match exists → prepares INSERT
WHEN:  Worker 2 checks: no match exists → prepares INSERT concurrently
WHEN:  Worker 1 INSERT succeeds (unmatched)
WHEN:  Worker 2 INSERT fails unique constraint
THEN:  Worker 2 retries the transaction
THEN:  Worker 2 now SELECTs and finds Worker 1's match
THEN:  Worker 2 returns the existing match
```

**Edge Case — Same notification re-dispatched**:
```
GIVEN: Job fails after creating PaymentMatch but before marking parsed
WHEN:  Job retries with same PaymentNotification
WHEN:  createFromParsed() runs again
THEN:  Finds existing match by payment_notification_id
THEN:  Returns existing match (idempotent)
```

---

## Data Migration: Per-Channel Regex Entries

### What

To avoid breaking existing SMS parsing when switching to channel-aware regex resolution, this migration creates the per-channel regex entries for both SMS and Android push for each existing bank.

Only bank codes with existing `regex_{bankCode}` entries are seeded. The existing keys are preserved (not deleted).

### Seed Data

```php
$banks = ['bdv', 'bnc'];
$channels = ['sms', 'android_push'];

foreach ($banks as $bank) {
    $existingRegex = SystemConfig::get("regex_{$bank}");

    if ($existingRegex === null) {
        continue; // Skip banks without a base regex
    }

    foreach ($channels as $channel) {
        $key = "regex_{$bank}_{$channel}";

        $existing = SystemConfig::where('key', $key)->first();

        if ($existing !== null) {
            continue; // Already exists, don't overwrite
        }

        SystemConfig::create([
            'group' => 'reconciliation',
            'key' => $key,
            'value' => $existingRegex,
            'type' => 'string',
        ]);
    }
}
```

**Important**: This copies the existing regex to both `_{sms}` and `_{android_push}` keys. In practice, the SMS and push formats differ per bank. The operators will need to update the `_android_push` entries with the actual push notification regex patterns. This migration merely ensures the channel-aware path has _something_ to resolve — it does not guess the correct push regex.

### Acceptance Criteria

- [ ] **DMC.1**: After migration, for each bank with an existing `regex_{b}`, both `regex_{b}_sms` and `regex_{b}_android_push` exist in SystemConfig.
- [ ] **DMC.2**: Existing `regex_{b}` keys are preserved (not modified or deleted).
- [ ] **DMC.3**: Migration is idempotent — running it again does not duplicate entries.
- [ ] **DMC.4**: Banks without any regex are skipped.

---

## Affected Files Summary

| File | Action | Description |
|------|--------|-------------|
| `app/Enums/SourceType.php` | **New** | Backed string enum with `AndroidPush`, `label()`, `values()` |
| `database/migrations/landlord/YYYY_MM_DD_HHMMSS_add_source_type_to_payment_notifications.php` | **New** | Alter migration: drop/re-add FK, add `source_type` column + index, data migration |
| `database/migrations/landlord/YYYY_MM_DD_HHMMSS_seed_channel_regex_entries.php` | **New** | Seed per-channel `regex_{b}_{s}` entries from existing `regex_{b}` |
| `app/Models/PaymentNotification.php` | **Modify** | Add `source_type` to `$fillable` and `casts()` |
| `app/Models/PaymentMatch.php` | **Modify** | `createFromParsed()` implements by-reference dedup logic |
| `app/Services/Payment/PaymentNotificationParser.php` | **Modify** | `parse()` and `normalizeForDedup()` accept `?string $sourceType`; channel-key resolution |
| `app/Http/Controllers/Api/DeviceController.php` | **Modify** | Remove `dedup_hash` validation + alert; compute server hash; set `source_type` |
| `app/Jobs/IngestPaymentNotification.php` | **Modify** | Pass notification's `source_type` to `parser->parse()` |
| `database/factories/PaymentNotificationFactory.php` | **Modify** | Add `source_type` to factory definition |
| `database/factories/PaymentMatchFactory.php` | **Modify** | Add `duplicate` state support |
| `tests/Feature/Api/DeviceNotificationTest.php` | **Modify** | Remove `dedup_hash_mismatch` tests; add `source_type` assertions |
| `tests/Unit/Services/Payment/IngestPaymentNotificationTest.php` | **Modify** | Test channel-aware parsing; test by-reference dedup |
| `tests/Unit/Services/Payment/PaymentNotificationParserTest.php` | **New/Modify** | Test per-channel key resolution |

---

## Rollback Verification

Each deliverable's rollback must be independently verifiable:

1. **DB schema**: Down migration drops `source_type`, restores FK (it was not removed — just re-asserted). Nothing breaks.
2. **SourceType enum**: Remove file. No other code references it after full revert.
3. **Parser**: Restore `regex_{b}` single-lookup. No channel awareness.
4. **Dedup hash**: Restore `dedup_hash` validation + SystemAlert. Revert to client-supplied hash.
5. **PaymentMatch dedup**: Restore `firstOrCreate` by `payment_notification_id`. No by-reference logic.
6. **Regex entries**: Delete `regex_{b}_{s}` entries. Keep `regex_{b}`.

---

*End of spec document.*
