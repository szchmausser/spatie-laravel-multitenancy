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

A new backed string PHP enum `App\Enums\SourceType` tracks the origin channel of each payment notification. Currently includes `BankApp = 'bank-app'`. The enum provides `label()` for display and `values()` for validation rules.

#### Scenario: BankApp case value and label

- GIVEN the `SourceType::BankApp` case
- WHEN its `value` is accessed
- THEN it returns `'bank-app'`
- WHEN its `label()` is called
- THEN it returns `'Bank App'`

#### Scenario: Unknown string returns null via tryFrom

- GIVEN a string `'sms'` passed to `SourceType::tryFrom()`
- THEN it returns `null` (no case for SMS yet)

#### Scenario: Validation against SourceType values

- GIVEN a request sends `source_type = 'alien_format'`
- WHEN validated against `SourceType::values()`
- THEN validation fails

### Requirement: Nullable `device_id` + `source_type` column

The `payment_notifications` table SHALL accept null `device_id` (to support non-Android sources) and SHALL have a `source_type` varchar(20) column with default `'bank-app'`. The FK on `device_id` uses `ON DELETE SET NULL`. An index on `source_type` supports filtering.

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
- THEN all existing `payment_notifications` rows have `source_type = 'bank-app'`

### Requirement: Server-side dedup hash (no client-supplied hash)

The `IngestController::__invoke()` endpoint SHALL compute `dedup_hash` server-side via `PaymentNotification::computeDedupHash()`. The `dedup_hash` field is removed from request validation. The `dedup_hash_mismatch` SystemAlert emission is removed. Old Android clients that still send `dedup_hash` in the body have that field accepted but ignored.

#### Scenario: New notification with server-computed hash

- GIVEN device sends `POST /api/ingest/bank-app`
- AND body includes `bank_code`, `raw_body` (`dedup_hash` optionally present)
- WHEN `IngestNotificationAction` processes the request
- THEN server computes `dedup_hash` via `computeDedupHash()`
- THEN notification stored with server-computed hash
- THEN `source_type = 'bank-app'`
- THEN response 201 created

#### Scenario: Duplicate detected by UNIQUE constraint

- GIVEN same notification sent twice
- WHEN second request arrives
- THEN server computes same hash
- THEN PostgreSQL UNIQUE constraint fires
- THEN response 200 `duplicate_ignored`
- THEN no SystemAlert sent

#### Scenario: Old client sends dedup_hash (backward compat)

- GIVEN old app sends `dedup_hash` in request
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

### Requirement: Migration — phone + bank columns on payment_matches

The `payment_matches` table (landlord DB) SHALL add `parsed_sender_phone_number` (varchar(30), nullable), `parsed_sender_phone_first4` (varchar(4), nullable), and `parsed_bank_code` (varchar(10), nullable) via migration `2026_07_08_000001_add_phone_and_bank_to_payment_matches`.

#### Scenario: Columns exist after migration

- GIVEN migration has run
- WHEN inspecting `payment_matches` schema
- THEN all three new columns exist and are nullable

#### Scenario: Rollback preserves other columns

- WHEN migration is rolled back
- THEN the three columns are removed
- AND no other columns are affected

### Requirement: ParsedPayment DTO — phone fields

The DTO SHALL carry `senderPhoneNumber` (?string) — raw phone from regex — and `senderPhoneFirst4` (?string) — first 4 digits.

#### Scenario: Phone fields populated

- GIVEN a parsed notification with phone `0426***6568`
- WHEN `ParsedPayment` is constructed
- THEN `senderPhoneNumber` = `0426***6568`
- AND `senderPhoneFirst4` = `0426`

### Requirement: PaymentNotificationParser — extract phone first4

After regex execution, the parser SHALL store the raw phone match in `senderPhoneNumber` and compute `senderPhoneFirst4` as the first 4 digits (strip non-digits, substr 0, 4). `senderPhoneLast4` behavior is unchanged.

#### Scenario: BNC masked phone → canonical first4+last4

- GIVEN a BNC notification with phone `0416***9503`
- WHEN `parse()` executes
- THEN `senderPhoneNumber` = `0416***9503`
- AND `senderPhoneFirst4` = `0416`
- AND `senderPhoneLast4` = `9503` (unchanged)

#### Scenario: BDV full phone → first4 extracted

- GIVEN a BDV notification with phone `0424-3153557`
- WHEN `parse()` executes
- THEN `senderPhoneFirst4` = `0424`

### Requirement: PaymentMatch::createFromParsed — store new fields

`createFromParsed()` SHALL persist `parsed_sender_phone_number`, `parsed_sender_phone_first4`, and `parsed_bank_code` (from `$notification->bank_code`) on the PaymentMatch.

#### Scenario: Match stores phone and bank from parsed data

- GIVEN a ParsedPayment with `senderPhoneNumber`, `senderPhoneFirst4`, and a notification with `bank_code = 'bnc'`
- WHEN `createFromParsed()` runs
- THEN the PaymentMatch has `parsed_sender_phone_number`, `parsed_sender_phone_first4`, and `parsed_bank_code = 'bnc'` populated

### Requirement: Multifield guard — ReconciliationOrchestrator::run()

After reference+monto finds a single candidate (monto comparison is per-payment, not per-order — orders can be paid in partial payments of different amounts), BEFORE `verifyPayment`, the system SHALL validate:

| Step | Rule |
|------|------|
| 1. Bank | `BankCode::tryFrom(notification->bank_code)->name()` MUST match `payment->pagoMovilDetail->sender_bank` |
| 2. Phone (BNC) | Strip non-digits from payment phone, compare `first4+last4` vs match `parsed_sender_phone_first4+parsed_sender_phone_last4` |
| 3. Phone (BDV) | Strip non-digits from both sides, compare full digits |
| 4. Mismatch | `match_status = 'pending'`, SystemAlert emitted, `verifyPayment` NOT called |

#### Scenario: All match → auto-verify (backward compat)

- GIVEN bank matches AND phone validates
- WHEN multifield guard passes
- THEN `verifyPayment` is called
- AND `match_status` = `verified`

#### Scenario: Bank mismatch → pending + alert

- GIVEN `payment->pagoMovilDetail->sender_bank = 'BNC'` and `notification->bank_code = 'bdv'`
- WHEN guard validates bank
- THEN `match_status` = `pending`
- AND SystemAlert emitted describing mismatch
- AND `verifyPayment` NOT called

#### Scenario: Phone mismatch (BNC canonical) → pending

- GIVEN BNC notification, `parsed_sender_phone_first4 = '0416'`, payment phone starts with `0424`
- WHEN guard validates phone
- THEN `match_status` = `pending`
- AND SystemAlert includes both phone values

#### Scenario: Phone mismatch (BDV full digits) → pending

- GIVEN BDV notification, parsed phone `04243153557`, payment phone (normalized) `04121234567`
- WHEN guard validates phone
- THEN `match_status` = `pending`

#### Scenario: PagoMovilDetail is null → skip phone, validate bank if possible

- GIVEN `payment->pagoMovilDetail` is null (bank_transfer payment method)
- WHEN guard runs
- THEN phone validation is skipped
- AND bank validation applies if sender_bank info is available

#### Scenario: parsed_sender_phone_first4 is null → skip phone

- GIVEN `match->parsed_sender_phone_first4` is null
- WHEN guard runs
- THEN phone validation is skipped
- AND bank validation still applies

### Requirement: runReverse() — same multifield guard

`ReconciliationOrchestrator::runReverse()` SHALL apply identical bank + phone validation before calling `verifyPayment`.

#### Scenario: Reverse match mismatches → pending + no verify

- GIVEN a candidate in the reverse flow
- WHEN bank or phone mismatch detected
- THEN `match_status` = `pending`
- AND SystemAlert emitted
- AND `verifyPayment` NOT called

### Requirement: PaymentService::attemptReverseMatch — guard before runReverse

`attemptReverseMatch()` SHALL validate bank and phone against the candidate match before calling `runReverse()`. If mismatch, return early without modifying state.

#### Scenario: Mismatch → no-op

- GIVEN `attemptReverseMatch` has a candidate with phone mismatch
- WHEN guard validates
- THEN returns early
- AND `runReverse()` is NOT called
- AND no record state changes

### Requirement: Frontend — operadora select + 7-digit phone input

The billing payment form (`billing/orders/show.tsx`) SHALL replace the free-text `sender_phone` input with an operadora select (options: 0412, 0414, 0416, 0424, 0426) and a 7-digit numeric input (`pattern="[0-9]{7}"`, `maxLength={7}`). Both values SHALL be concatenated into the existing `senderPhone` state (11 digits) on submit.

#### Scenario: Valid phone composition

- GIVEN user selects operadora `0424` and enters `3153557` in the 7-digit field
- WHEN form submits
- THEN `senderPhone` = `04243153557`

#### Scenario: Browser validation blocks incomplete input

- GIVEN user enters `12345` in the 7-digit field
- WHEN form attempts submit
- THEN browser `pattern` validation prevents submission

### Requirement: Backend validation — sender_phone exactly 11 digits

`Tenant\PaymentController@store` validation for `sender_phone` SHALL be `required|string|size:11|regex:/^[0-9]+$/`.

#### Scenario: Valid phone passes

- GIVEN a request with `sender_phone = '04243153557'`
- WHEN validated
- THEN passes

#### Scenario: Non-digit chars rejected

- GIVEN `sender_phone = '0424-3153557'`
- WHEN validated
- THEN fails with validation error

#### Scenario: Wrong length rejected

- GIVEN a 10-digit `sender_phone`
- WHEN validated
- THEN fails with validation error

### Requirement: SystemAlert on multifield mismatch

When bank or phone mismatch prevents auto-verify, the system SHALL emit a SystemAlert (category `system`, severity `warning`) to all landlord admins. The alert SHALL include: field name, value from payment, value from notification, and match ID.

#### Scenario: Alert payload contains mismatch details

- GIVEN a bank mismatch
- WHEN SystemAlert is emitted
- THEN `data->'field'` = `sender_bank`
- AND `data->'payment_value'` = `'BNC'`
- AND `data->'notification_value'` = `'bdv'`
- AND `data->'match_id'` references the PaymentMatch

### Requirement: PaymentMatch TypeScript types — new fields

Frontend TS types for PaymentMatch SHALL include `parsed_sender_phone_number`, `parsed_sender_phone_first4`, and `parsed_bank_code`.

#### Scenario: Typed properties available

- GIVEN a PaymentMatch object from the API
- WHEN accessed in TypeScript
- THEN `parsed_sender_phone_number`, `parsed_sender_phone_first4`, `parsed_bank_code` are valid typed properties
