# Tasks: Payment Notification Multichannel

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~400 |
| 800-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

---

## Phase 1: Foundation

- [x] **T1** Create `SourceType` backed string enum
  - Files: `app/Enums/SourceType.php`
  - Deps: None
  - Desc: Backed enum with `AndroidPush` case, `label()` + `values()` methods (follows `BankCode` pattern)
  - AC: value === 'android_push', label === 'Android Push', values() returns ['android_push'], tryFrom('unknown') → null
  - Effort: Low | Lines: ~20

- [x] **T2** Schema migration: nullable `device_id` FK + `source_type` column with backfill
  - Files: `database/migrations/landlord/2026_07_03_000001_add_source_type_to_payment_notifications.php`
  - Deps: None
  - Desc: Drop FK, re-add with `nullOnDelete`, add `varchar(20) source_type` default 'android_push' + index, backfill existing rows. Down drops column + index.
  - AC: New notification without device_id succeeds; existing rows get source_type='android_push'; device delete sets FK null; migration reversible
  - Effort: Low | Lines: ~40

- [x] **T3** Update `PaymentNotification` model
  - Files: `app/Models/PaymentNotification.php`
  - Deps: T1
  - Desc: Add `'source_type'` to `$fillable` and `casts()` as `SourceType` enum
  - AC: Model accepts source_type on create; cast to SourceType works; existing rows cast correctly
  - Effort: Low | Lines: ~5

## Phase 2: Data Migration

- [x] **T4** Seed per-channel regex entries from existing `regex_{bank}`
  - Files: `database/seeders/PaymentNotificationChannelSeeder.php` (implemented as Seeder, not Migration)
  - Deps: None
  - Desc: For each bank with `regex_{b}`, create `regex_{b}_sms` and `regex_{b}_android_push` if not exist. Down deletes created entries.
  - AC: After migration, both per-channel keys exist per bank; existing `regex_{b}` preserved; idempotent
  - Effort: Low | Lines: ~45

## Phase 3: Core Logic

- [x] **T5** Make `PaymentNotificationParser` channel-aware
  - Files: `app/Services/Payment/PaymentNotificationParser.php`
  - Deps: None
  - Desc: Add `?string $sourceType` param to `parse()` and `normalizeForDedup()`. When provided, resolve `regex_{bank}_{sourceType}` — fail (return null) if key missing or sourceType null. Existing 2-arg overload unchanged (backward compat).
  - AC: parse('bdv', $text, 'android_push') uses regex_bdv_android_push; null when key missing; null when sourceType=null; 2-arg still uses regex_bdv
  - Effort: Medium | Lines: ~60

- [x] **T6** Rewrite `PaymentMatch::createFromParsed()` with 4-step by-reference dedup
  - Files: `app/Models/PaymentMatch.php`
  - Deps: None
  - Desc: 4-step algorithm: (1) by notification_id idempotency, (2) by parsed_reference unmatched → reuse, (3) by parsed_reference matched → create 'duplicate_attempt', (4) fresh → create 'unmatched'
  - AC: Same notification returns existing; same ref unmatched returns existing; same ref matched creates duplicate_attempt; fresh creates unmatched
  - Effort: Medium | Lines: ~35

- [x] **T7** Remove `dedup_hash` validation + SystemAlert from `DeviceController::storeNotification()`
  - Files: `app/Http/Controllers/Api/DeviceController.php`
  - Deps: T2
  - Desc: Remove dedup_hash from validate(), set source_type='android_push', compute server hash via `computeDedupHash()`, remove `Log::warning` + `SystemAlert` emission + unused imports
  - AC: Request without dedup_hash succeeds; server hash stored; no SystemAlert sent; source_type='android_push' in notification; duplicate UNIQUE catch still works
  - Effort: Medium | Lines: ~30

## Phase 4: Wiring

- [x] **T8** Pass `source_type` to parser in `IngestPaymentNotification`
  - Files: `app/Jobs/IngestPaymentNotification.php`
  - Deps: T5, T6
  - Desc: Pass `$this->notification->source_type?->value` as 3rd arg to `$parser->parse()`; `PaymentMatch::createFromParsed()` already handles result
  - AC: Parser receives source_type from notification; existing SMS path handles null
  - Effort: Low | Lines: ~5

- [x] **T9** Update factories for new fields
  - Files: `database/factories/PaymentNotificationFactory.php`, `database/factories/PaymentMatchFactory.php`
  - Deps: T1, T6
  - Desc: Add `source_type => 'android_push'` to `PaymentNotificationFactory::definition()`; add `duplicate()` state to `PaymentMatchFactory`
  - AC: Factory creates valid records with source_type; duplicate() state sets match_status='duplicate'
  - Effort: Low | Lines: ~15

## Phase 5: Tests

- [x] **T10** Update `DeviceNotificationTest` — server hash + source_type assertions
  - Files: `tests/Feature/Api/DeviceNotificationTest.php`
  - Deps: T7
  - Desc: Replace dedup_hash_mismatch test with server-hash and source_type assertions; remove SystemAlert checks; add test for old dedup_hash ignored
  - AC: Test passes with new controller behavior; no reference to dedup_hash_mismatch alert
  - Effort: Medium | Lines: ~50

- [x] **T11** Add channel-aware tests to `PaymentNotificationParserTest` + by-reference dedup to `IngestPaymentNotificationTest`
  - Files: `tests/Unit/Services/Payment/PaymentNotificationParserTest.php`, `tests/Unit/Services/Payment/IngestPaymentNotificationTest.php`
  - Deps: T5, T6, T8
  - Desc: ParserTest: add channel-aware parse tests (AndroidPush success, missing key → null, null sourceType → null, backward compat). IngestionTest: add push notification → by-reference dedup scenario, duplicate status test.
  - AC: 4 new parser test cases pass; 2 new ingestion test cases pass
  - Effort: Medium | Lines: ~80

## Tests Needing Updates

| Test File | Change |
|-----------|--------|
| `tests/Feature/Api/DeviceNotificationTest.php` | Remove `dedup_hash_mismatch` assertion + SystemAlert checks; add server-hash + source_type assertions |
| `tests/Unit/Services/Payment/PaymentNotificationParserTest.php` | Add 4 channel-aware scenarios (success, missing key, null sourceType, backward compat) |
| `tests/Unit/Services/Payment/IngestPaymentNotificationTest.php` | Add by-reference dedup scenario (SMS + push same ref); add duplicate match_status test |
