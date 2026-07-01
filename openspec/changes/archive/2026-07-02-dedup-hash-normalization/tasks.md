# Tasks: Dedup Hash Normalization

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~280-320 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | auto-forecast |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Foundation

- [x] 1.1 Create `app/Enums/BankCode.php` — string-backed enum with `Bdv` and `Bnc` cases, methods `appliesCanonicalPhone()`, `dateFormats()`, `code()`
- [x] 1.2 Write unit tests for `BankCode` — every case, all metadata methods, `cases()` iteration

## Phase 2: Core Implementation

- [x] 2.1 Add `canonicalPhone()` to `PaymentNotificationParser` — strip non-digits, return first4+last4 or `""`
- [x] 2.2 Add `parseDateMultiFormat()` to `PaymentNotificationParser` — try formats in priority order, return ISO 8601 or raw fallback
- [x] 2.3 Add `normalizeForDedup()` to `PaymentNotificationParser` — extract via bank regex, normalize each field, return `"amount|phone|date|ref"`
- [x] 2.4 Rewrite `PaymentNotification::computeDedupHash()` — delegate to `Parser::normalizeForDedup()`, then `hash('sha256', $bankCode . $normalized)`

## Phase 3: Integration

- [x] 3.1 Add hash verification in `DeviceController::storeNotification` — recompute hash after `forceCreate`, log warning + `SystemAlert` on mismatch, never reject
- [x] 3.2 Update `SimulatePaymentNotification` — replace `VALID_BANKS` constant with `BankCode::cases()` for validation

## Phase 4: Testing

- [x] 4.1 Unit tests for `canonicalPhone()` — full digits, masked phone, non-digits only, empty string
- [x] 4.2 Unit tests for `parseDateMultiFormat()` — BDV format, BNC 2-digit, BNC 4-digit, unparseable fallback
- [x] 4.3 Unit tests for `normalizeForDedup()` — BDV happy path, BNC with masked phone, missing field preserves pipes
- [x] 4.4 Integration test for `computeDedupHash()` — same payment from different sources produces identical hash
- [x] 4.5 Feature test for `storeNotification` hash mismatch — log warning + `SystemAlert` created, notification stored normally
- [x] 4.6 Feature test for `SimulatePaymentNotification` — works with `BankCode::cases()`, no VALID_BANKS reference
