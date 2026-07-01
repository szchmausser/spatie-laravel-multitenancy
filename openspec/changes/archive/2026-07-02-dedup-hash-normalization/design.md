# Design: Dedup Hash Normalization

## Technical Approach

Replace `SHA256(bank_code + "|" + raw_body)` with field-level normalization before hashing. Add `normalizeForDedup()` to `PaymentNotificationParser` (shares the same bank regex as `parse()`), produce a 4-field pipe string per the Android↔Laravel contract, then `SHA256(bank_code + normalized)`. This makes semantically identical payments from different sources (masked vs. full phone, 2 vs. 4 digit years) produce the same dedup_hash.

## Architecture Decisions

| Option | Tradeoff / Rationale | Decision |
|--------|----------------------|----------|
| Normalization in Parser vs. new service | Reuses regex in one place; normalizeForDedup and parse() share extraction logic. A new service would duplicate regex access or require refactoring SystemConfig reads. | **Parser** |
| computeDedupHash signature | Static method called before insertion — needs no model instance. Same signature as current, keeps callers unchanged. | **`static (bankCode, rawText): string`** |
| hash_version column | Adds migration + query complexity for zero value (no collision risk). Old hash = `SHA256(code."\|".raw)`, new = `SHA256(code.normalized)` — mathematically impossible to collide. | **No hash_version** |
| Reject on hash mismatch | Notification is valid data; hash is a best-effort cross-check. Rejecting loses payment data. SystemAlert warns admins without data loss. | **Alert, don't reject** |
| Bank-specific formatting in BankCode enum | Keeps `canonicalPhone` (BNC-only), `dateFormats` per bank, and `androidPackage` in one place. Append case = add bank. | **Enum centralizes metadata** |

## Data Flow

```
Android App
  │ POST /api/device/notifications { bank_code, raw_body, dedup_hash }
  ▼
DeviceController::storeNotification
  │
  ├── 1. forceCreate notification (raw dedup_hash stored)
  │
  ├── 2. Recompute: PaymentNotification::computeDedupHash($bank, $raw)
  │       └── PaymentNotificationParser::normalizeForDedup($bank, $raw)
  │             ├── Fetch regex from SystemConfig
  │             ├── Extract: amount, phone, date, time, reference
  │             ├── canonicalPhone() if BNC
  │             ├── parseDateMultiFormat() → ISO 8601
  │             └── Return "amount|phone|date|ref"
  │       └── hash('sha256', $bank.$normalized)
  │
  ├── 3. Compare computed vs. received hash
  │     ├── Match  → silent
  │     └── Mismatch → Log::warning + SystemAlert to admins
  │
  └── 4. Dispatch IngestPaymentNotification job (always)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Enums/BankCode.php` | **Create** | String-backed enum with Bdv, Bnc cases + metadata methods |
| `app/Services/Payment/PaymentNotificationParser.php` | **Modify** | Add `normalizeForDedup()`, `canonicalPhone()`, `parseDateMultiFormat()` |
| `app/Models/PaymentNotification.php` | **Modify** | Rewrite `computeDedupHash()` to delegate to parser normalization |
| `app/Http/Controllers/Api/DeviceController.php` | **Modify** | Add hash recomputation + comparison + SystemAlert on mismatch |
| `app/Console/Commands/SimulatePaymentNotification.php` | **Modify** | Replace `VALID_BANKS` with `BankCode::cases()` |

## Interfaces / Contracts

### normalizeForDedup

```php
public function normalizeForDedup(string $bankCode, string $rawBody): string
```

Returns `"$amount|$phone|$date|$ref"`. Missing regex groups → `""` (empty string, pipe preserved). All 4 fields always present.

### canonicalPhone

```php
public function canonicalPhone(string $phone): string
```

Digits only → `first4 . last4` (8 chars). No digits → `""`.

### parseDateMultiFormat

```php
public function parseDateMultiFormat(string $date, ?string $time, array $formats): string
```

Tries each format via `DateTime::createFromFormat`. First match → `Y-m-d\TH:i:s`. All fail → raw `"$date $time"`. Never null, never throws.

### computeDedupHash (rewritten)

```php
public static function computeDedupHash(string $bankCode, string $rawText): string
{
    $normalized = app(PaymentNotificationParser::class)
        ->normalizeForDedup($bankCode, $rawText);
    return hash('sha256', $bankCode . $normalized);
}
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `canonicalPhone()` | full digits, masked, non-digits only, empty |
| Unit | `parseDateMultiFormat()` | BDV format, BNC 2-digit, BNC 4-digit, unparseable fallback |
| Unit | `normalizeForDedup()` | BDV full match, BNC with masked phone, missing field |
| Unit | `BankCode` enum | each case, `appliesCanonicalPhone()`, `dateFormats()`, `code()` |
| Unit | `computeDedupHash()` | same payment different sources → same hash |
| Feature | `storeNotification` hash mismatch | log warning + SystemAlert created, notification stored |
| Feature | `SimulatePaymentNotification` | works with `BankCode::cases()`, no hardcoded array |

## Migration / Rollout

No schema migration. Old records have hash = `SHA256(code."|".raw)`; new records = `SHA256(code.normalized)`. These algorithms produce different outputs — no UNIQUE collision. New notifications from any source (Android or simulate) use the new hash. Old records are untouched and remain deduplicable among themselves.

## Open Questions

- None. The hash contract is immutable and agreed with Android.
