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
