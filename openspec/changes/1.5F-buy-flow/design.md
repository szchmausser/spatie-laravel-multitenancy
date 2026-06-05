# Design: 1.5F — Buy flow (simulated purchase)

## Technical approach

**Backend.** Surgical removal of the 1.5D `canSeePremium` filter. The `userCanAccess()` method stays the single source of truth for download authorization. The `request()` method drops its gating and the dead `canSeePremium()` helper disappears.

**Frontend.** New `BuyResourceDialog` component (shadcn `Dialog`) encapsulates the simulated purchase: a controlled/uncontrolled dialog with resource details, a clearly-labeled simulation note, and a `useForm` POST. The pages own the open state (`useState<Resource | null>` in the index for selecting among many resources; `useState<boolean>` in the show for a single resource). After a successful POST, Inertia auto-refreshes the page props and the button flips from "Buy" to "Download" because `can_download` is now `true`.

## Data flow

```
catalog page (free tenant, premium resource)
  can_download = false  →  <Button onClick={() => setSelectedResource(r)}>Buy</Button>
  click "Buy"  →  setSelectedResource(r)  →  dialog opens
  click "Confirm Purchase"
    → POST /resources/{slug}/request
    → Entitlement::updateOrCreate(... granted_via: 'purchase' ...)
    → 302 back() with flash "Access granted to {name}."
    → Inertia reloads page props
    → can_download = true  →  button is now "Download"
```

## Why this design

- **Idempotent buy.** `updateOrCreate` + `UNIQUE(tenant_id, user_id, resource_id)` makes double-clicks safe.
- **No 404 information leak.** The 1.5D "404 because the slug is secret" was a UX anti-feature in a storefront model. The user must be able to discover and click "Buy" on a premium resource, which means the slug is not secret. 403 (not 404) is the correct response when the gate fails.
- **Phase 2 swap point is tiny.** The dialog `handleSubmit` carries a one-line comment marker. Replacing the `post()` with a payment gateway call is the entire Phase 2 frontend work; the controller's `request()` method just needs a payment-validation pass before the `updateOrCreate`.
- **State ownership.** One dialog at the page level (not N dialogs per card) keeps the component tree small. The `selectedResource` state in the catalog is the only state needed.

## What we did NOT change

- `Resource` model and `is_premium` flag — intact.
- `userCanAccess()` and `userHasExplicitEntitlement()` — intact.
- Routes in `routes/web.php` — intact.
- Sidebar link condition — intact (still `!isFreeTier || hasFreeResources`).
- Landlord admin panel for resources — intact.
- The `EntitlementGrantVia::Purchase` enum value — still used, Phase 2 keeps it.

## Phase 2 hook

The literal comment in `resources/js/components/resources/buy-resource-dialog.tsx` (line ~155 in `handleSubmit`):

```ts
// Phase 2: replace this simulated purchase with PaymentGateway::charge(...)
```

The controller docblock in `app/Http/Controllers/Resource/ResourceController.php` also references this marker as the integration contract.
