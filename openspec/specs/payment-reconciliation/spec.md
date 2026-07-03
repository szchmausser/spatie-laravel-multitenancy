# Payment Reconciliation Specification

## Purpose

Reconcile payment notifications from bank devices with tenant rent obligations. Handles deduplication, normalization, and matching of payments to tenants.

## Requirements

### Requirement: `computeDedupHash()` uses normalized input

The system MUST provide `PaymentNotification::computeDedupHash(string $bankCode, string $rawBody): string` that delegates to `PaymentNotificationParser::normalizeForDedup()` and computes `hash('sha256', $bankCode . $normalized)`. This replaces the previous raw `SHA256(bank_code + "|" + raw_body)` approach.

#### Scenario: Same payment from different BNC sources produces same hash

- GIVEN two raw bodies representing the same payment — one with masked phone `"0416***9503"` and 2-digit year `"15/01/26"`, the other with full phone `"04161234567"` and 4-digit year `"15/01/2026"`
- WHEN `computeDedupHash('bnc', $body1)` and `computeDedupHash('bnc', $body2)` are called
- THEN both return the same hash

#### Scenario: BDV payment uses full phone without canonicalization

- GIVEN a BDV raw body with phone `"04121234567"`
- WHEN `computeDedupHash('bdv', $body)` is called
- THEN the hash is computed using the full phone digits, not first4+last4

### Requirement: `dedup_hash` unique constraint

The `payment_notifications` table SHALL enforce a UNIQUE constraint on `dedup_hash`. The hash is now computed over normalized fields rather than raw body text.
(Previously: `dedup_hash` was `SHA256(bank_code + "|" + raw_body)` — direct hash over raw text)

#### Scenario: Normalized dedup prevents cross-source duplicates

- GIVEN an existing notification with hash computed from raw body
- WHEN a semantically identical payment arrives with different formatting
- THEN the system returns HTTP 200 `duplicate_ignored`
- AND the UNIQUE constraint prevents insertion

#### Scenario: Old vs new hash algorithms do not collide

- GIVEN a raw body stored with the old raw-text hash
- WHEN the same payment arrives from a well-normalized source
- THEN the new normalized hash differs from the old raw hash
- AND both records may coexist (no false duplicate rejection)

### Requirement: `SourceType` backed enum

A new backed string PHP enum `App\Enums\SourceType` tracks the origin channel of each payment notification. Currently includes `AndroidPush = 'android_push'`. The enum provides `label()` for display and `values()` for validation rules.

#### Scenario: AndroidPush case value and label

- GIVEN the `SourceType::AndroidPush` case
- WHEN its `value` is accessed
- THEN it returns `'android_push'`
- WHEN its `label()` is called
- THEN it returns `'Android Push'`

#### Scenario: Unknown string returns null via tryFrom

- GIVEN a string `'sms'` passed to `SourceType::tryFrom()`
- THEN it returns `null` (no case for SMS yet)

#### Scenario: Validation against SourceType values

- GIVEN a request sends `source_type = 'alien_format'`
- WHEN validated against `SourceType::values()`
- THEN validation fails

### Requirement: Nullable `device_id` + `source_type` column

The `payment_notifications` table SHALL accept null `device_id` (to support non-Android sources) and SHALL have a `source_type` varchar(20) column with default `'android_push'`. The FK on `device_id` uses `ON DELETE SET NULL`. An index on `source_type` supports filtering.

#### Scenario: Non-Android notification without device_id succeeds

- GIVEN a notification arrives via future webhook
- WHEN it is stored without a `device_id` (`source_type = 'webhook'`)
- THEN `PaymentNotification.device_id` IS NULL
- THEN `PaymentNotification.source_type = 'webhook'`
- THEN no FK constraint violation occurs

#### Scenario: Device deleted after notification stored

- GIVEN a notification exists with `device_id = 42` (Android push)
- WHEN Device 42 is deleted from the devices table
- THEN `PaymentNotification.device_id` IS SET TO NULL
- THEN the notification row still exists (no cascade)

#### Scenario: Existing rows backfilled

- AFTER migration runs
- THEN all existing `payment_notifications` rows have `source_type = 'android_push'`

### Requirement: Server-side dedup hash (no client-supplied hash)

The `DeviceController::storeNotification()` endpoint SHALL compute `dedup_hash` server-side via `PaymentNotification::computeDedupHash()`. The `dedup_hash` field is removed from request validation. The `dedup_hash_mismatch` SystemAlert emission is removed. Old Android clients that still send `dedup_hash` in the body have that field accepted but ignored.

#### Scenario: New notification with server-computed hash

- GIVEN Android device sends `POST /api/device/notifications`
- AND body includes `bank_code`, `raw_body` (`dedup_hash` optionally present)
- WHEN `storeNotification()` processes the request
- THEN server computes `dedup_hash` via `computeDedupHash()`
- THEN notification stored with server-computed hash
- THEN `source_type = 'android_push'`
- THEN response 201 created

#### Scenario: Duplicate detected by UNIQUE constraint

- GIVEN same notification sent twice
- WHEN second request arrives
- THEN server computes same hash
- THEN PostgreSQL UNIQUE constraint fires
- THEN response 200 `duplicate_ignored`
- THEN no SystemAlert sent

#### Scenario: Old client sends dedup_hash (backward compat)

- GIVEN old Android app sends `dedup_hash` in request
- WHEN request arrives
- THEN `dedup_hash` in body is accepted but IGNORED
- THEN server computes its own hash
- THEN notification stored with server hash
- THEN no SystemAlert about mismatch

### Requirement: Per-channel regex parser

`PaymentNotificationParser::parse()` and `normalizeForDedup()` accept an optional `?string $sourceType` parameter. When `$sourceType` is provided, the parser looks up `regex_{bankCode}_{sourceType}` in `SystemConfig`. If the key is not found, the parser returns `null` (unparseable) **without falling back** to the generic `regex_{bankCode}`. If `$sourceType` is `null`, the parser also returns `null` (fails closed). The existing `regex_{bankCode}` keys remain for backward compatibility with the SMS code path.

#### Scenario: Android push parses with channel-specific regex

- GIVEN `SystemConfig` has `regex_bdv_android_push` configured
- AND a BDV push notification body arrives
- WHEN `parser->parse('bdv', $pushBody, 'android_push')`
- THEN returns a `ParsedPayment` with amount, reference, phone, date

#### Scenario: Missing per-channel regex returns null

- GIVEN `SystemConfig` has `regex_bdv` but NOT `regex_bdv_android_push`
- WHEN `parser->parse('bdv', $pushBody, 'android_push')`
- THEN returns `null` (no matching regex for android_push channel)

#### Scenario: Null source type returns null

- WHEN `parser->parse('bdv', $text, null)`
- THEN returns `null` (no channel-aware regex resolution without source type)

#### Scenario: Backward compat path unchanged

- GIVEN `SystemConfig` has `regex_bdv` (the old generic key)
- WHEN `parser->parse('bdv', $smsBody)` (no 3rd arg)
- THEN still uses `regex_bdv` key
- THEN `ParsedPayment` returned as before (no behavior change)

### Requirement: By-reference PaymentMatch dedup

`PaymentMatch::createFromParsed()` deduplicates by `parsed_reference` rather than only by `payment_notification_id`. The 4-step algorithm: (1) idempotency — same notification returns existing; (2) same reference, unmatched exists → reuse; (3) same reference, matched exists → create new with `match_status = 'duplicate_attempt'`; (4) no match → create new unmatched.

**NOTE**: The implementation uses `duplicate_attempt` (not `duplicate` as originally specified). This is a deliberate design deviation to distinguish cross-channel duplicate notifications from other duplicate scenarios.

#### Scenario: First notification creates unmatched match

- GIVEN first notification with reference `R1`
- WHEN `createFromParsed()` runs
- THEN new `PaymentMatch` created: `match_status = 'unmatched'`

#### Scenario: Same reference while unmatched → reuse

- GIVEN existing unmatched match for reference `R1`
- WHEN second notification with same reference arrives
- WHEN `createFromParsed()` runs
- THEN returns existing match (no new row)

#### Scenario: Same reference after matched → duplicate_attempt

- GIVEN existing matched match for reference `R1`
- WHEN second notification with same reference arrives
- WHEN `createFromParsed()` runs
- THEN new match created with `match_status = 'duplicate_attempt'`

#### Scenario: Job retry → idempotent by notification_id

- GIVEN job fails after creating PaymentMatch but before marking parsed
- WHEN job retries with same PaymentNotification
- WHEN `createFromParsed()` runs
- THEN finds existing match by `payment_notification_id`
- THEN returns existing match (idempotent)

### Requirement: Per-channel regex entries (seeded)

For each bank with an existing `regex_{bankCode}` entry in `SystemConfig`, both `regex_{bankCode}_sms` and `regex_{bankCode}_android_push` entries are created (copied from the existing regex). The existing `regex_{bankCode}` keys are preserved. Implemented via `PaymentNotificationChannelSeeder` (not a migration).

#### Scenario: Per-channel keys exist for each bank

- AFTER seeder runs
- THEN for each bank with existing `regex_{b}`, both `regex_{b}_sms` and `regex_{b}_android_push` exist

#### Scenario: Existing keys preserved

- GIVEN `regex_bdv` exists in `SystemConfig`
- WHEN seeder runs
- THEN `regex_bdv` is NOT modified or deleted

#### Scenario: Idempotent

- WHEN seeder runs twice
- THEN no duplicate entries created

#### Scenario: Banks without regex skipped

- GIVEN a bank code with no `regex_{b}` entry
- WHEN seeder runs
- THEN no entries created for that bank
