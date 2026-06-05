# Spec: 1.5F — Buy flow (simulated purchase)

## R1: Free tenants see premium resources in the catalog and show pages

**Given** a tenant on the `free` plan (no `premium-content` feature) is authenticated
**When** they `GET /resources`
**Then** the response includes ALL active resources (free + premium)
**And** each premium resource has `can_download: false` and `has_explicit_entitlement: false`
**And** the React catalog card renders a "Buy" button for those resources

## R2: Free tenants can view the show page of a premium resource

**Given** a free tenant is authenticated
**When** they `GET /resources/{premium-slug}`
**Then** the response is 200 with the resource data
**And** `can_download: false`
**And** the show page renders the "Buy" button (not 404)

## R3: Free tenants can trigger a "purchase" via the Buy dialog

**Given** a free tenant is on the show or catalog page of a premium resource
**When** they click "Buy" → dialog opens → they click "Confirm Purchase"
**Then** `POST /resources/{slug}/request` is called
**And** the response is 302 redirect back to the originating page
**And** a flash message "Access granted to {resource.name}." is shown
**And** a row is inserted into `entitlements` with `tenant_id`, `user_id`, `resource_id`, `granted_via='purchase'`, `granted_at=now()`, `expires_at=null`
**And** the page re-fetches, `can_download` becomes `true`, the button becomes "Download"

## R4: Free tenants cannot download without an entitlement

**Given** a free tenant has NOT requested access to a premium resource
**When** they hit `GET /resources/{slug}/download` directly
**Then** the response is 403 (not 404, because the slug is no longer secret)

## R5: Paid tenants still work (no regression)

**Given** a tenant on `basic` or `premium` plan (has `premium-content: true`)
**When** they `GET /resources` or `GET /resources/{slug}`
**Then** the catalog and show page render "Download" for premium resources (rule 2 of `userCanAccess`)

## R6: Admin-granted entitlements still work (no regression)

**Given** an `Entitlement` row exists for a (tenant, user, resource) tuple, even if the tenant's plan is `free`
**When** that user hits `GET /resources/{slug}/download`
**Then** the response is 200 with the file (rule 3 of `userCanAccess`)

## R7: Dialog UX is honest about the simulation

**Given** the Buy dialog is open
**Then** the body shows: resource name + description, file size, mime type, price (if `price_cents > 0`), and an info note saying "This is a simulated purchase. Phase 2 will add payment method selection and real charge flow here."
**And** two buttons: "Cancel" (closes dialog, no action) and "Confirm Purchase" (triggers the POST)

## R8: `canSeePremium()` private method is removed

**Why**: it becomes unused after the filter removals. Code cleanliness — no dead code.

## Implementation notes

- `request()` is open to any authenticated tenant. The `updateOrCreate` keeps double-clicks idempotent; the `UNIQUE(tenant_id, user_id, resource_id)` constraint is the second layer of defence.
- `download()` keeps the `userCanAccess()` check as the single gate. The 404 from `firstOrFail()` still protects against unknown slugs.
- `BuyResourceDialog` props: `{ resource, trigger?, open?, onOpenChange?, onSuccess? }`. Designed for both controlled and uncontrolled use. data-testid naming is frozen for Phase 2: `buy-dialog-{slug}`, `buy-confirm-btn-{slug}`, `buy-cancel-btn-{slug}`, `buy-dialog-price-{slug}`.
- The dialog's `handleSubmit` carries a Phase 2 marker comment: `// Phase 2: replace this simulated purchase with PaymentGateway::charge(...)`.
