# 1.5F — Buy flow (simulated purchase)

## Why

Phase 1.5D shipped "treat premium as 404 for free tenants" (no information leak). That decision coupled the existence of a slug to the plan feature, which made rule 3 of `userCanAccess()` (explicit entitlement) unreachable from the frontend: the `request()` endpoint required `premium-content` in the plan, but if the plan had it, rule 2 already granted access without needing a request. Free tenants could not buy individual resources.

## What changes

1. **Catalog & show pages** show every active resource to every authenticated tenant. No more `where('is_premium', false)` filter. The `can_download` flag in the Inertia payload drives the button state.
2. **`request()` endpoint** is open to any authenticated tenant. The Buy dialog posts there; the controller creates an `Entitlement` with `granted_via='purchase'`.
3. **`download()` endpoint** keeps `userCanAccess()` as the gate. 403 (not 404) when the user has neither a plan with `premium-content` nor an explicit entitlement. 404 only on truly unknown slugs.
4. **`canSeePremium()` helper** removed.
5. **New `BuyResourceDialog`** component (`resources/js/components/resources/buy-resource-dialog.tsx`) wraps a shadcn `Dialog` with resource details, file size, mime type, price, an explicit "simulated purchase" note, and Cancel/Confirm Purchase buttons. Marker `// Phase 2: replace this simulated purchase with PaymentGateway::charge(...)` on the `handleSubmit`.
6. **`index.tsx` & `show.tsx`** replace the inline `<Form>` "Request Access" with a "Buy" `<Button>` that opens the dialog.

## How it preserves correctness

- **R5 (paid tenants)**: rule 2 of `userCanAccess` still returns `true` for plans with `premium-content`. No regression.
- **R6 (admin-granted entitlements)**: rule 3 returns `true` if a non-expired `Entitlement` row exists. No regression.
- **R4 (free tenant, no entitlement, hits download)**: 403, not 404. The slug is no longer secret.
- **R7 (honest UX)**: dialog body says "This is a simulated purchase. Phase 2 will add payment method selection and real charge flow here."

## Phase 2 hook

`BuyResourceDialog::handleSubmit` carries the comment marker `// Phase 2: replace this simulated purchase with PaymentGateway::charge(...)`. The data-testid naming is frozen (`buy-dialog-{slug}`, `buy-confirm-btn-{slug}`, `buy-cancel-btn-{slug}`, `buy-dialog-price-{slug}`) so feature/browser tests do not break when the swap happens.

## Test counts

- Pre-change: 187/184 passing, 3 skipped
- Post-change: 188/185 passing, 3 skipped
- Delta: +1 test, +1 passing, 0 regressions
