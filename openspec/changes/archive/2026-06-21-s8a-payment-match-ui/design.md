# Design: S8a — Payment Match UI

## Technical Approach

Pure UI extension surfacing existing payment reconciliation data (verification, cancellation type, payment match) in the order detail view. The controller's `load()` adds two relationship paths, TypeScript types extend to match, and `payment-details-card.tsx` gains three conditional sections: verifier info, cancellation badge, and match details. Zero new routes, business logic, or DB columns.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|--------------|-----------|
| Cancellation badge | `CancellationTypeBadge` sub‑component (extracted inside `payment-details-card.tsx`) | Inline switch | Follows `PaymentStatusBadge` pattern; keeps card readable; 4 color variants warrant extraction |
| Verifier display | Inline `DetailRow` with null‑coalescing fallback | Separate component | Single conditional label ("Automático"), no extraction needed |
| PaymentMatch section | Inline conditional `<div>` behind `payment.payment_match ?? null` guard | Collapsible accordion, new component | Data is compact (3–5 rows); accordion adds complexity with no benefit |

## Data Flow

```
OrderController::show(order)
  │
  ├── load('payments.verifier')         → Landlord → { id, name, email }
  ├── load('payments.paymentMatch')     → PaymentMatch → { match_status, matched_at, parsed_* }
  │
  └── Inertia::render('admin/orders/show')
        │
        └──  OrderShowPage (show.tsx)
               │
               └──  PaymentDetailsCard (payment-details-card.tsx)
                      │
                      ├── verified_by / verified_at → "Verificado por {name}" or "Automático"
                      ├── cancellation_type         → colored Badge + secondary reason (ES)
                      └── payment_match             → match_status, matched_at, parsed data
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/OrderController.php` | Modify | Add `'payments.verifier', 'payments.paymentMatch'` to `load()` call |
| `resources/js/pages/admin/orders/show.tsx` | Modify | Add `PaymentMatch` type; add `verified_by`, `cancellation_type`, `payment_match` fields to `Payment` type |
| `resources/js/components/payment-details-card.tsx` | Modify | Add verifier section, `CancellationTypeBadge`, payment match section; update `Payment` type |
| `tests/Feature/Landlord/OrderControllerTest.php` | Modify | Assert `verifier` and `paymentMatch` loaded via Inertia props |
| `tests/Browser/Landlord/OrdersBrowserTest.php` | Modify | Assert new fields render for each scenario |

## Interfaces / Contracts

```typescript
// Payment in show.tsx & payment-details-card.tsx — additions only
type Payment = {
  // …existing fields…
  verified_by: { id: number; name: string; email: string } | null;
  cancellation_type: 'manual' | 'system_duplicate' | 'system_expired' | 'method_changed' | null;
  payment_match: PaymentMatch | null;
};

type PaymentMatch = {
  id: number;
  match_status: string;
  matched_at: string;
  parsed_reference: string;
  parsed_amount_cents: number;
  parsed_sender_phone_last4: string | null;
  payment_notification_id: number;
};
```

## Cancellation Badge Map

| `cancellation_type`  | Color | Tailwind Classes | Secondary Reason (ES) |
|----------------------|-------|-------------------|-----------------------|
| `manual`             | rojo  | `bg-destructive/10 text-destructive` | Cancelado manualmente |
| `system_duplicate`   | amarillo | `bg-amber-50 text-amber-700 border-amber-300` | Cancelado: duplicado |
| `system_expired`     | gris  | `bg-muted text-muted-foreground` | Cancelado: expirado |
| `method_changed`     | azul  | `bg-blue-50 text-blue-700 border-blue-300` | Cambio de método |

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Controller loads `verifier` and `paymentMatch` | `assertInertia` → `has('order.payments.0.verifier')` and `has('order.payments.0.payment_match')` |
| Browser | Verified payment shows verifier name | Create payment with `verified_by` → `assertSee(verifier.name)` |
| Browser | Auto-verified shows "Automático" | Payment with `verified_by=null`, `verified_at` set → `assertSee('Automático')` |
| Browser | Each cancellation type renders correct badge | 4 payments, one per type → assert badge class & secondary text |
| Browser | Matched payment shows match data | Payment with related `PaymentMatch` → assert `match_status`, `parsed_reference` render |
| Browser | Unmatched hides match section | Payment without `paymentMatch` → `assertDontSee` for match fields |

## Migration / Rollout

No migration required. All data already exists in DB (S1–S7 backend).

## Open Questions

None — all data shapes confirmed by reading models, relationships, and enums.
