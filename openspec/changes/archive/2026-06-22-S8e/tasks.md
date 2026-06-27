# Tasks: S8e — PaymentNotification viewer + reprocess failed

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~400–480 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

## Phase 1: Foundation — Relationship + Types

- [x] 1.1 Add `match(): HasOne` to `PaymentNotification.php` (`$this->hasOne(PaymentMatch::class)`)
- [x] 1.2 Add `PaymentNotificationItem` interface to `resources/js/types/models.ts`

## Phase 2: Backend — Controller + Routes

- [x] 2.1 Create `PaymentNotificationController` — `index()` with inline filter validation (parse_status/bank_code/from/to), eager-load `match.payment`, paginate 20, `withQueryString()`
- [x] 2.2 Add `reprocess()` — find by route-model binding, guard `parse_status === 'failed'` else abort(422), dispatch `IngestPaymentNotification`, redirect back with flash
- [x] 2.3 Register GET `/admin/payment-notifications` + POST `/{notification}/reprocess` inside admin middleware group in `routes/landlord.php`

## Phase 3: Frontend — Payment Notifications Page

- [x] 3.1 Create `resources/js/pages/landlord/payment-notifications/index.tsx` — filter card with parse_status select, bank_code text, date range inputs, "Filtrar" button
- [x] 3.2 Render table with expandable rows (accordion-style `useState<number | null>`), showing bank_code, parse_status badge, created_at
- [x] 3.3 Expanded detail: raw_text (pre), parsed_data (JSON pretty), parse_error (if failed), match info (reference/amount/payment.status) or "Sin match"
- [x] 3.4 "Reprocesar" button on failed rows — Inertia POST, disabled during submit, hidden for non-failed
- [x] 3.5 Pagination links at bottom (same pattern as alerts.tsx), empty state with icon

## Phase 4: Wiring — Admin Panel Card

- [x] 4.1 Add "Notificaciones" card entry in `admin-panel.tsx` with Banknote icon, desc "Monitorear notificaciones bancarias entrantes", href `/admin/payment-notifications`

## Phase 5: Tests

- [x] 5.1 Feature tests: `PaymentNotificationController::index` — default 200, filter by parse_status, bank_code, date range, empty state, pagination links present
- [x] 5.2 Feature tests: `reprocess` — success (job dispatched via `Bus::fake()`), non-failed (redirect with error flash), missing (404), unauthorized non-admin (403)
