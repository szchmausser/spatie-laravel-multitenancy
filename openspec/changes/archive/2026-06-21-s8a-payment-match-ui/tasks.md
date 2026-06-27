# Tasks: S8a — Payment Match UI

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 200–300 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr-default |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

## Phase 1: Foundation — TypeScript Types

- [x] 1.1 Add `PaymentMatch` type and `verified_by`, `cancellation_type`, `payment_match` fields to `Payment` type in `resources/js/pages/admin/orders/show.tsx:46–57`
- [x] 1.2 Add matching `PaymentMatch` type and new fields to `Payment` type in `resources/js/components/payment-details-card.tsx:31–41`

## Phase 2: RED — Failing Tests (TDD)

- [x] 2.1 In `tests/Feature/Landlord/OrderControllerTest.php`: add test asserting `order.payments.0.verifier` and `order.payments.0.payment_match` are present via `assertInertia` (create payment with `verified_by` and related `PaymentMatch`)
- [x] 2.2 In `tests/Browser/Landlord/OrdersBrowserTest.php`: add test for verified payment showing verifier name — create payment with `verified_by` set, `assertSee(verifier.name)`
- [x] 2.3 In `tests/Browser/Landlord/OrdersBrowserTest.php`: add test for auto-verified payment showing "Automático" — `verified_by=null`, `verified_at` set, `assertSee('Automático')`
- [x] 2.4 In `tests/Browser/Landlord/OrdersBrowserTest.php`: add tests for each cancellation type badge (4 payments, one per type) — assert badge class and secondary reason text
- [x] 2.5 In `tests/Browser/Landlord/OrdersBrowserTest.php`: add test for matched payment showing match data — create `PaymentMatch`, assert `match_status` and `parsed_reference` render
- [x] 2.6 In `tests/Browser/Landlord/OrdersBrowserTest.php`: add test for unmatched payment hiding match section — `assertDontSee` match fields
- [x] 2.7 Run `php artisan test --compact --filter=OrderController` — confirm new tests FAIL (RED) — Confirmed: feature test failed with `Property [order.payments.0.verifier] does not exist`

## Phase 3: GREEN — Controller Implementation

- [x] 3.1 In `app/Http/Controllers/Landlord/OrderController.php:43–45`: add `'payments.verifier', 'payments.paymentMatch'` to the `load()` array in `show()`
- [x] 3.2 Run `php artisan test --compact --filter=OrderController` — confirm feature test passes (GREEN) — 8/8 passed

## Phase 4: GREEN — Frontend Implementation

- [x] 4.1 In `resources/js/components/payment-details-card.tsx`: add verifier section after "Monto del Pago" — render verifier name or "Automático" + `verified_at` using `DetailRow`
- [x] 4.2 In `resources/js/components/payment-details-card.tsx`: extract `CancellationTypeBadge` sub-component — map `cancellation_type` to colored badge with secondary reason text (manual→red, system_duplicate→amber, system_expired→gray, method_changed→blue)
- [x] 4.3 In `resources/js/components/payment-details-card.tsx`: replace existing cancellation reason block (lines 170–174) with `CancellationTypeBadge`
- [x] 4.4 In `resources/js/components/payment-details-card.tsx`: add payment match section — conditional `<div>` behind `payment.payment_match ?? null` guard, render `match_status`, `matched_at`, `parsed_reference`, `parsed_amount_cents`
- [x] 4.5 Run `php artisan test --compact --filter=OrderController` — confirm all tests pass — 8/8 passed

## Phase 5: Cleanup & Verification

- [x] 5.1 Run `vendor/bin/pint --dirty --format agent` to format modified PHP files — Fixed 2 files
- [x] 5.2 Run `php artisan test --compact --filter=OrderController` — full green suite — 8/8 passed
- [x] 5.3 Run browser tests — 9/9 passed (32 assertions). Fixed: Playwright outdated (npm install playwright@latest + npx playwright install). Fixed: `verified_by` type — Eloquent serializes `verifier` as relationship object, not `verified_by` as object. Updated both TS types and component to use `verifier?.name`
- [x] 5.4 Verify no TypeScript errors: visual check — Component types updated and match backend response
