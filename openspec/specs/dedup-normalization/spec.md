# Dedup Normalization Specification

## Purpose

Normalize payment notification fields so semantically identical payments produce the same dedup hash regardless of source formatting (masked phone, 2 vs 4 digit dates, etc). Follows the Android ↔ Laravel hash contract: `normalized = "$amount|$phone|$date|$ref"` then `SHA256(bank_code + normalized)`.

## Requirements

### Requirement: `normalizeForDedup()`

The system MUST provide `normalizeForDedup(string $bankCode, string $rawBody): string` that extracts amount, phone, date, time, and reference using the bank's regex, normalizes each field, and returns a pipe-delimited string.

#### Scenario: Happy path produces 4-field pipe output

- GIVEN a BDV raw body matching its regex
- WHEN `normalizeForDedup('bdv', $rawBody)` is called
- THEN the result is `"<amount>|<phone>|<iso_date>|<reference>"`
- AND the string contains exactly 3 pipe separators

#### Scenario: Missing field produces empty string

- GIVEN a raw body without a phone number
- WHEN `normalizeForDedup()` is called
- THEN the phone segment is `""` (empty string)
- AND the 3-pipe structure is preserved

### Requirement: `canonicalPhone()`

The system MUST provide `canonicalPhone(string $phone): string` that extracts all digits, returns `first4 + last4` (8 chars). If no digits exist, returns `""`.

#### Scenario: Normal phone returns first4+last4

- GIVEN `$phone = "0416-123.45.67"`
- WHEN `canonicalPhone($phone)` is called
- THEN it returns `"04164567"`

#### Scenario: Masked phone returns first4+last4

- GIVEN `$phone = "0416***9503"`
- WHEN `canonicalPhone($phone)` is called
- THEN it returns `"04169503"`

#### Scenario: No digits returns empty string

- GIVEN `$phone = "***-***"`
- WHEN `canonicalPhone($phone)` is called
- THEN it returns `""`

### Requirement: `parseDateMultiFormat()`

The system MUST provide `parseDateMultiFormat(string $date, string $time, array $formats): string` that tries each format and returns ISO 8601 (`Y-m-d\TH:i:s`) on success. If none match, returns the raw concatenated `"$date $time"` string — never null, never throws.

#### Scenario: BDV date format parses to ISO

- GIVEN `$date = "15/1/2026"`, `$time = "09:40"`, and formats `['n/j/Y G:i']`
- WHEN `parseDateMultiFormat($date, $time, $formats)` is called
- THEN it returns `"2026-01-15T09:40:00"`

#### Scenario: BNC 2-digit year parses to ISO

- GIVEN `$date = "15/01/26"`, `$time = "09:40"`, and formats `['d/m/y H:i', 'd/m/Y H:i']`
- WHEN `parseDateMultiFormat($date, $time, $formats)` is called
- THEN it returns `"2026-01-15T09:40:00"`

#### Scenario: BNC 4-digit year parses to ISO

- GIVEN `$date = "15/01/2026"`, `$time = "09:40"`, and formats `['d/m/y H:i', 'd/m/Y H:i']`
- WHEN `parseDateMultiFormat($date, $time, $formats)` is called
- THEN it returns `"2026-01-15T09:40:00"`

#### Scenario: Unparseable date returns raw string

- GIVEN an unparseable date format
- WHEN `parseDateMultiFormat()` is called
- THEN it returns the raw `"$date $time"` concatenation
- AND no exception is thrown

### Requirement: Regex Case-Sensitivity

All bank regex patterns used by normalization MUST be case-sensitive (no `i` flag). PHP `\d` SHALL NOT use the `u` (unicode) flag — use explicit `[0-9*]+` groups instead.

#### Scenario: Case-sensitive matching

- GIVEN a regex expecting uppercase bank name
- WHEN the raw body contains a lowercase variant
- THEN the regex does NOT match
