# Proposal: Multifield Payment Validation

## Intent

Payments auto-verify using only reference + amount, ignoring `sender_bank` and `sender_phone` that users report. This lets mismatched payments (wrong bank, different phone) slip through as verified. We must validate bank code and phone against parsed notification data before auto-verifying, and flag mismatches for admin review.

## Scope

### In Scope
- **Migration**: Add `parsed_sender_phone_number`, `parsed_sender_phone_first4`, `parsed_bank_code` to `payment_matches`
- **ParsedPayment DTO**: Add `senderPhoneNumber` + `senderPhoneFirst4` (?string)
- **PaymentNotificationParser**: Extract `senderPhoneFirst4` alongside existing `senderPhoneLast4`
- **ReconciliationOrchestrator**: After reference+monto match (monto comparison is per-payment, not per-order — orders can be paid in partial payments of different amounts), validate bank code (`BankCode::tryFrom()`) and phone (canonical for BNC, full digits for BDV); mismatch → `match_status = 'pending'` + SystemAlert
- **PaymentService::attemptReverseMatch**: Same multifield guard before `runReverse()`
- **Frontend phone input**: Replace free-text `sender_phone` with operadora select (0412/0414/0416/0424/0426) + 7-digit input; send concatenated 11 digits
- **Backend validation**: `sender_phone` MUST be exactly 11 digits in `Tenant\PaymentController@store`
- **SystemAlert**: Emit alert on bank/phone mismatch for admin review

### Out of Scope
- Matching by `tenant_rif`, cédula, or other non-notification fields
- Refactoring the match confidence scoring system
- BDV phone canonicalization (BDV sends complete numbers — no masking)

## Capabilities

### New Capabilities
None — this extends existing reconciliation behavior.

### Modified Capabilities
- `payment-reconciliation`: Matching engine SHALL validate bank code + phone in addition to reference + amount. Auto-verify requires all fields to match. Mismatch → `match_status = 'pending'` + SystemAlert emitted.

## Approach

| Layer | Change |
|-------|--------|
| Migration | Add 3 nullable columns to `payment_matches` (landlord DB) |
| Parsing | `PaymentNotificationParser` extracts `senderPhoneFirst4` from phone regex output; stores raw phone for BDV |
| Matching | `ReconciliationOrchestrator` adds guard after candidate found: bank code match (`BankCode::tryFrom` → name comparison), then phone match (BNC: first4+last4; BDV: full digits). Fail = pending + alert |
| Reverse match | Same guard in `PaymentService::attemptReverseMatch` |
| Frontend | Operadora select + 7-digit input replaces free-text |


## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | Add 3 columns to `payment_matches` |
| `app/DTOs/ParsedPayment.php` | Modified | Add phone fields |
| `app/Services/PaymentNotificationParser.php` | Modified | Extract first4 digits |
| `app/Services/ReconciliationOrchestrator.php` | Modified | Multifield validation guard |
| `app/Services/Payment/PaymentService.php` | Modified | Multifield guard in `attemptReverseMatch` |
| `app/Http/Controllers/Tenant/PaymentController.php` | Modified | `sender_phone` → 11-digit rule |
| `resources/js/sections/billing/orders/show.tsx` | Modified | Phone input → operadora + 7 digits |
| `resources/js/types/payment.ts` | Modified | Update PaymentMatch TS type |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| False mismatch due to format differences in sender_phone | Med | Compare normalize (strip non-digits); log raw values for audit |
| Existing payments with null pago_movil_details | Low | Graceful: skip validation when detail is missing |
| BDV phone format changes | Low | Parser is regex-driven, updateable via SystemConfig |

## Rollback Plan

1. Revert migration: `php artisan migrate:rollback`
2. Remove multifield guards from orchestrator + service
3. Revert phone input to free-text field
4. Revert controller validation to current rules
5. No data loss — mismatches only blocked from auto-verify, never lost

## Dependencies

- `BankCode` enum with `appliesCanonicalPhone()` (already spec'd in `bank-code-enum`)
- `PaymentNotificationParser` must output `senderPhoneFirst4` (modification in this change)
- `SystemAlert` notification infrastructure (already spec'd in `alert-dashboard`)

## Success Criteria

- [ ] Migration adds 3 columns to `payment_matches` without data loss
- [ ] Parser extracts `senderPhoneFirst4` for BNC (enmascarado), full phone for others
- [ ] Orchestrator rejects match on bank mismatch → pending + alert
- [ ] Orchestrator rejects match on phone mismatch → pending + alert
- [ ] Orchestrator auto-verifies when all fields match (backward compat)
- [ ] Frontend phone input sends 11 digits; backend rejects non‑11‑digit
- [ ] All existing tests pass
