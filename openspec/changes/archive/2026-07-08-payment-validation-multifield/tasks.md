# Tasks: Multifield Payment Validation

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~480-550 |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: Backend (migration → service); PR 2: Frontend + validation + types; PR 3: Tests |
| Delivery strategy | ask-on-risk |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Migration, DTO, Parser, Model, Guard, Orchestrator, Service | PR 1 | All backend business logic, independent |
| 2 | Frontend phone input, controller validation, TS types | PR 2 | Depends on PR 1 model changes |
| 3 | Tests for all new behavior | PR 3 | Depends on PR 1 + PR 2 |

## Phase 1: Database & Data Layer

- [x] 1.1 Migration — `database/migrations/landlord/2026_07_08_000001_add_phone_and_bank_to_payment_matches.php`: add 3 nullable columns (`parsed_sender_phone_number`, `parsed_sender_phone_first4`, `parsed_bank_code`); down drops them
- [x] 1.2 ParsedPayment DTO — `app/Services/Payment/ParsedPayment.php`: add `?string $senderPhoneNumber`, `?string $senderPhoneFirst4` to constructor (default null)
- [x] 1.3 PaymentNotificationParser — `app/Services/Payment/PaymentNotificationParser.php`: add `extractFirst4()`; in `parse()` extract phone fields and pass to ParsedPayment
- [x] 1.4 PaymentMatch::createFromParsed — `app/Models/PaymentMatch.php`: add 3 fields to `$fillable`; pass in Steps 3 and 4 `create()` calls

## Phase 2: Core Business Logic

- [x] 2.1 PaymentMatchGuard — `app/Services/Payment/PaymentMatchGuard.php` (NEW): static `validate()` — bank match via `BankCode::tryFrom()`, phone match (BNC canonical first4+last4, BDV full digits), skip on null guard conditions
- [x] 2.2 ReconciliationOrchestrator — `app/Services/Payment/ReconciliationOrchestrator.php`: guard in `run()` (after single candidate) and `runReverse()` (after payment_id link); mismatch → pending + alert; add `sendMismatchAlert()` with SystemAlert to Landlord admins
- [x] 2.3 PaymentService::attemptReverseMatch — `app/Services/Payment/PaymentService.php`: guard after candidate found before `runReverse()`; mismatch → early return (no-op, no alert)

## Phase 3: Frontend & Validation

- [x] 3.1 Frontend phone input — `resources/js/pages/billing/orders/show.tsx`: replace free-text with operadora `<select>` (0412/14/16/24/26) + 7-digit `<Input>` pattern `[0-9]{7}`; concatenate on submit; update button validation states
- [x] 3.2 Backend validation — `app/Http/Controllers/Tenant/PaymentController.php`: sender_phone rule → `size:11|regex:/^[0-9]+$/` for pago_movil
- [x] 3.3 TS types — `resources/js/components/payment-details-card.tsx`, `resources/js/pages/admin/orders/show.tsx`: add `parsed_sender_phone_number`, `parsed_sender_phone_first4`, `parsed_bank_code` to PaymentMatch type

## Phase 4: Tests

- [x] 4.1 PaymentMatchGuardTest — 9 tests: bank match/mismatch, phone BNC/BDV match/mismatch, skip scenarios (null pagoMovilDetail, null first4, invalid bank code)
- [x] 4.2 PaymentNotificationParserTest — 4 tests: extractFirst4 BNC masked, BDV full, null, ParsedPayment has new fields
- [x] 4.3 ReconciliationOrchestratorTest — 5 tests: forward/reverse match + mismatch with pending/alert
- [x] 4.4 PaymentControllerTest — 3 tests: 11 digits ok, 10 digits error, non-digit chars error
