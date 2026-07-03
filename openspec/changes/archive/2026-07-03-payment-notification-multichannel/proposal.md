# Proposal: Payment Notification Multichannel

## Intent

Enable payment notifications from non-SMS sources (Android push, future webhook/scraper/API) alongside existing SMS parsing, without collisions or orphaned PaymentMatch records. Fixes 4 concrete issues that create noise, false positives, and unreliable dedup in the PagoMóvil reconciliation pipeline.

## Scope

### In Scope

1. **DB schema** — make `device_id` nullable, add `source_type` varchar(20) column with backed enum (`android_push` primary, open for future values)
2. **Per-channel regex** — `PaymentNotificationParser` resolves `regex_{bankCode}_{sourceType}`, fails if not found (no fallback to bank-generic). Existing `regex_{bankCode}` keys remain for backward compat.
3. **Server-side dedup_hash** — `storeNotification()` computes hash server-side, stores it, removes `dedup_hash` from request validation + SystemAlert emission. Android stops sending it.
4. **By-reference dedup in PaymentMatch** — `createFromParsed()` checks for existing unmatched match by `parsed_reference` before creating a new one. Existing matched records get new match with `match_status = 'duplicate'`.
5. **SourceType enum** — backed enum in `app/Enums/SourceType.php` with at least `AndroidPush` case.

### Out of Scope

- ❌ Other payment methods (BankTransfer)
- ❌ Non-PagoMóvil notification sources (webhook, scraper, API — enum is ready but no handling code)
- ❌ Verifier abstraction or bus refactor
- ❌ UI changes to device/notification pages

## Capabilities

### New Capabilities
- `android-push-reconciliation`: End-to-end handling of push notifications from bank Android apps — ingest, parse with channel-specific regex, dedup by reference, reconcile

### Modified Capabilities
- `payment-reconciliation`: Parsing now channel-aware, `PaymentMatch::createFromParsed()` deduplicates by reference (not just notification ID), `dedup_hash` stored server-computed

## Approach

| # | Problem | Fix |
|---|---------|-----|
| 1 | `device_id` NOT NULL blocks non-Android sources | Migration: nullable FK + `source_type` varchar(20). `SourceType` backed enum. DeviceController sets `source_type = 'android_push'`. Future endpoints omit device_id. |
| 2 | One regex per bank competes SMS vs push | `Parser::parse()` tries `regex_{bankCode}_{sourceType}` first. Not found → null (fails). Existing `regex_{bankCode}` unused for channel-aware calls. |
| 3 | `dedup_hash_mismatch` always fires (Android vs server diff) | Remove `dedup_hash` from `storeNotification` validation. Server computes hash via existing `computeDedupHash()` and stores it. Delete SystemAlert emission. |
| 4 | SMS + push of same payment create 2 PaymentMatch records | `createFromParsed()` checks: any unmatched match with same `parsed_reference` exists? If yes, skip. If matched match exists, create new with `match_status = 'duplicate'`. |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Enums/SourceType.php` | **New** | Backed string enum: `AndroidPush = 'android_push'` |
| `database/migrations/landlord/` | **New** | Alter `payment_notifications`: device_id nullable, add source_type, drop dedup_hash unique if needed |
| `app/Models/PaymentNotification.php` | **Modify** | Add `source_type` to casts, update `$fillable` |
| `app/Models/PaymentMatch.php` | **Modify** | `createFromParsed()` dedup by reference, support duplicate status |
| `app/Services/Payment/PaymentNotificationParser.php` | **Modify** | `parse()` accepts `sourceType`, resolves `regex_{b}_{s}` |
| `app/Http/Controllers/Api/DeviceController.php` | **Modify** | `storeNotification()`: pass source_type, remove dedup_hash validation+alert, store server hash |
| `app/Jobs/IngestPaymentNotification.php` | **Modify** | Pass source_type to parser |
| `app/Notifications/SystemAlert.php` | **Remove** | Delete `dedup_hash_mismatch` type usage |
| `database/factories/PaymentNotificationFactory.php` | **Modify** | Add source_type defaults |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing devices still send `dedup_hash` in body | High (transition period) | Body field accepted but ignored; server computes own hash. Remove from validation — old clients won't error. |
| Missing `regex_{b}_{s}` for current channels breaks existing flow | Medium | Migration creates both SMS and push regex entries per bank during deploy. Seed script in migration. |
| `device_id` null breaks queries assuming non-null FK | Low | Audit queries before merge — only DeviceController creates notifications with device_id. |

## Rollback Plan

1. Revert migration (down method restores `device_id` NOT NULL with default, drops `source_type`)
2. Restore `dedup_hash` validation and SystemAlert in DeviceController
3. Restore `PaymentMatch::createFromParsed()` to `firstOrCreate` by notification ID
4. Restore parser to single-regex lookup

## Dependencies

- Existing `regex_bdv`, `regex_bnc` SystemConfig entries must stay (SMS path unchanged)
- New `regex_bdv_sms`, `regex_bnc_sms`, `regex_bdv_android_push`, `regex_bnc_android_push` entries created via data migration

## Success Criteria

- [ ] Non-Android notification (no device_id) creates PaymentNotification with `source_type` set, no FK error
- [ ] BDV push notification matches `regex_bdv_android_push`; without it, notification fails with parse_error
- [ ] Same BDV payment arriving via SMS + push produces one PaymentMatch (first wins), second marked `duplicate`
- [ ] `dedup_hash` stored in DB is server-computed; `dedup_hash_mismatch` SystemAlert no longer fires
- [ ] All existing SMS-only flows unchanged
