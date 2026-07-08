# Delta for Payment Reconciliation — Multifield Validation

## ADDED Requirements

### Migration — phone + bank columns on payment_matches

The `payment_matches` table (landlord DB) SHALL add `parsed_sender_phone_number` (varchar(30), nullable), `parsed_sender_phone_first4` (varchar(4), nullable), and `parsed_bank_code` (varchar(10), nullable) via migration `2026_07_08_000001_add_phone_and_bank_to_payment_matches`.

#### Scenario: Columns exist after migration

- GIVEN migration has run
- WHEN inspecting `payment_matches` schema
- THEN all three new columns exist and are nullable

#### Scenario: Rollback preserves other columns

- WHEN migration is rolled back
- THEN the three columns are removed
- AND no other columns are affected

### ParsedPayment DTO — phone fields

The DTO SHALL carry `senderPhoneNumber` (?string) — raw phone from regex — and `senderPhoneFirst4` (?string) — first 4 digits.

#### Scenario: Phone fields populated

- GIVEN a parsed notification with phone `0426***6568`
- WHEN `ParsedPayment` is constructed
- THEN `senderPhoneNumber` = `0426***6568`
- AND `senderPhoneFirst4` = `0426`

### PaymentNotificationParser — extract phone first4

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

### PaymentMatch::createFromParsed — store new fields

`createFromParsed()` SHALL persist `parsed_sender_phone_number`, `parsed_sender_phone_first4`, and `parsed_bank_code` (from `$notification->bank_code`) on the PaymentMatch.

#### Scenario: Match stores phone and bank from parsed data

- GIVEN a ParsedPayment with `senderPhoneNumber`, `senderPhoneFirst4`, and a notification with `bank_code = 'bnc'`
- WHEN `createFromParsed()` runs
- THEN the PaymentMatch has `parsed_sender_phone_number`, `parsed_sender_phone_first4`, and `parsed_bank_code = 'bnc'` populated

### Multifield guard — ReconciliationOrchestrator::run()

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

### runReverse() — same multifield guard

`ReconciliationOrchestrator::runReverse()` SHALL apply identical bank + phone validation before calling `verifyPayment`.

#### Scenario: Reverse match mismatches → pending + no verify

- GIVEN a candidate in the reverse flow
- WHEN bank or phone mismatch detected
- THEN `match_status` = `pending`
- AND SystemAlert emitted
- AND `verifyPayment` NOT called

### PaymentService::attemptReverseMatch — guard before runReverse

`attemptReverseMatch()` SHALL validate bank and phone against the candidate match before calling `runReverse()`. If mismatch, return early without modifying state.

#### Scenario: Mismatch → no-op

- GIVEN `attemptReverseMatch` has a candidate with phone mismatch
- WHEN guard validates
- THEN returns early
- AND `runReverse()` is NOT called
- AND no record state changes

### Frontend — operadora select + 7-digit phone input

The billing payment form (`billing/orders/show.tsx`) SHALL replace the free-text `sender_phone` input with an operadora select (options: 0412, 0414, 0416, 0424, 0426) and a 7-digit numeric input (`pattern="[0-9]{7}"`, `maxLength={7}`). Both values SHALL be concatenated into the existing `senderPhone` state (11 digits) on submit.

#### Scenario: Valid phone composition

- GIVEN user selects operadora `0424` and enters `3153557` in the 7-digit field
- WHEN form submits
- THEN `senderPhone` = `04243153557`

#### Scenario: Browser validation blocks incomplete input

- GIVEN user enters `12345` in the 7-digit field
- WHEN form attempts submit
- THEN browser `pattern` validation prevents submission

### Backend validation — sender_phone exactly 11 digits

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

### SystemAlert on multifield mismatch

When bank or phone mismatch prevents auto-verify, the system SHALL emit a SystemAlert (category `system`, severity `warning`) to all landlord admins. The alert SHALL include: field name, value from payment, value from notification, and match ID.

#### Scenario: Alert payload contains mismatch details

- GIVEN a bank mismatch
- WHEN SystemAlert is emitted
- THEN `data->'field'` = `sender_bank`
- AND `data->'payment_value'` = `'BNC'`
- AND `data->'notification_value'` = `'bdv'`
- AND `data->'match_id'` references the PaymentMatch

### PaymentMatch TypeScript types — new fields

Frontend TS types for PaymentMatch SHALL include `parsed_sender_phone_number`, `parsed_sender_phone_first4`, and `parsed_bank_code`.

#### Scenario: Typed properties available

- GIVEN a PaymentMatch object from the API
- WHEN accessed in TypeScript
- THEN `parsed_sender_phone_number`, `parsed_sender_phone_first4`, `parsed_bank_code` are valid typed properties
