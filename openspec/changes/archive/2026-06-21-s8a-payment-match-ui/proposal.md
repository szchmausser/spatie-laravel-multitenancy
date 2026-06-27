# Proposal: S8a — Payment Match UI

## Intent

Payment reconciliation backend (S1–S7) stores `verified_by`, `verified_at`, `cancellation_type`, and `paymentMatch` data, but landlord admins cannot see them. This surfaces that data in the existing order detail UI — no new logic, no new DB columns.

## Scope

### In Scope
- Extend `OrderController::show()` to eager-load `payments.verifier`, `payments.paymentMatch`
- Extend TS types in `show.tsx` to include `verified_by`, `cancellation_type`, `payment_match`
- Update `payment-details-card.tsx` to render verified_by, verified_at, cancellation badge, paymentMatch section
- Tests for controller eager-loading and component rendering changes

### Out of Scope
- Pages or routes (existing ones suffice)
- New business logic or mutations
- New DB columns (match_type, confidence_score, shadow_mode absent from DB)
- Dashboard, SystemConfig, Alerts, PaymentMethodConfig, PaymentNotification UI (S8b–S8f)

## Capabilities

### New Capabilities
None — pure UI extension, no new isolated capability

### Modified Capabilities
None — no spec-level behavior changes

## Approach

1. **Controller**: Add `'payments.verifier', 'payments.paymentMatch'` to the `load()` call in `show()` — keeps existing eager-loads (`tenant`, `plan`, `resource`, `payments.pagoMovilDetail`, `payments.bankTransferDetail`) intact
2. **TS types**: Add `verified_by: { id, name, email } | null`, `cancellation_type: string | null`, `payment_match: PaymentMatch | null` to the `Payment` type in `show.tsx`; define `PaymentMatch` type with existing DB columns
3. **Component**: In `payment-details-card.tsx`, add: verifier section (name/"Automático" + verified_at), cancellation badge (rojo/amarillo/gris/azul per type + secondary reason), payment match section (status, matched_at, parsed data)
4. **Tests**: Update `OrderControllerTest` to assert new relationships are loaded; update `OrdersBrowserTest` to assert new fields render

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/OrderController.php:43` | Modified | + `'payments.verifier', 'payments.paymentMatch'` |
| `resources/js/pages/admin/orders/show.tsx:46–57` | Modified | + `verified_by`, `cancellation_type`, `payment_match` to Payment type |
| `resources/js/components/payment-details-card.tsx:31–41` | Modified | + `verified_by`, `verified_at`, `cancellation_type`, `payment_match` in Payment type |
| `resources/js/components/payment-details-card.tsx:170–174` | Modified | Replace cancellation text with type badge + secondary reason |
| `tests/Feature/Landlord/OrderControllerTest.php` | Modified | Assert new `verifier` and `paymentMatch` relationships loaded |
| `tests/Browser/Landlord/OrdersBrowserTest.php` | Modified | Assert new fields rendered in UI |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `verified_by` null for auto-verifications | High | Graceful fallback — "Automático" label |
| `paymentMatch` null for unmatched payments | High | Conditional render, guard null |
| Missing DB columns (match_type etc.) | Low | Pre-verified absent, scoped out of S8a |

## Rollback Plan

Revert `OrderController::show()` to original `load()` call and revert frontend changes. No schema rollback needed. Test revert confirms green suite.

## Dependencies

None — all data already exists in DB

## Success Criteria

- [ ] Verified payments display verifier name (or "Automático") and `verified_at` in card
- [ ] Cancelled payments show colored badge per type + secondary reason text
- [ ] Matched payments display `match_status`, `matched_at`, `parsed_reference`, `parsed_amount_cents`
- [ ] All tests pass: `php artisan test --compact --filter=OrderController`
