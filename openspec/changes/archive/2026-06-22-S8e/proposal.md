# Proposal: S8e — PaymentNotification viewer + reprocess failed

## Intent

`PaymentNotification` records exist (incoming bank notifications via S2) but admins have no interface to monitor parse status, inspect raw text/parsed data, or reprocess failures. This builds a dedicated viewer so admins can detect parse issues and recover without CLI access.

## Scope

### In Scope
- `PaymentNotificationController` with `index()` (paginated, filterable) and `reprocess($id)` (POST, dispatch job)
- Route `GET /admin/payment-notifications` and `POST /admin/payment-notifications/{id}/reprocess`
- `landlord/payment-notifications/index.tsx` — filterable table with expandable detail (React toggle)
- Expandable row shows raw_text, parsed_data, match info (eager load `match.payment`)
- "Reprocesar" button on failed notifications — Inertia POST + redirect back with flash
- Card entry on `admin-panel.tsx` + `match()` hasOne relationship on PaymentNotification
- Feature tests (index filters/pagination, reprocess success/failure, authorization)

### Out of Scope
- Notification creation (S2) or editing/deletion
- Reconciliation dashboard (S8f) or bulk reprocess
- Matched-payment CRUD (S8a)

## Capabilities

### New Capabilities
- `payment-notification-viewer`: Browse, filter (parse_status, bank_code, date range), and reprocess individual payment notifications. Queries the landlord `payment_notifications` table.

### Modified Capabilities
None — no existing capability changes at spec level.

## Approach

1. **Controller**: `index()` mirrors AlertController — query `PaymentNotification::query()` with `parse_status`/`bank_code`/date filters, paginate 20, eager load `match.payment`. `reprocess($id)` finds the record, dispatches `IngestPaymentNotification` job (same as existing command), redirects back with flash.
2. **Model**: Add `match()` hasOne to `PaymentNotification` (mirrors existing `PaymentMatch::notification()` belongsTo).
3. **Route**: Inside existing `prefix('admin')->middleware([auth, verified, EnsureUserIsAdmin])` group.
4. **Page**: Inertia page at `landlord/payment-notifications/index.tsx` — table with query-string filters, React-toggle expandable row per each record's `raw_text`, `parsed_data`, match info (related order/amount/status).
5. **Admin panel**: Card entry on admin-panel.tsx linking to `/admin/payment-notifications`.
6. **Tests**: Feature tests covering index (default, filtered by parse_status/bank_code, date range, empty), reprocess (success enqueues job, 404 for missing/not-failed, authorization).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/PaymentNotificationController.php` | New | index + reprocess |
| `app/Models/PaymentNotification.php` | Modified | + `match()` hasOne relationship |
| `routes/landlord.php` | Modified | + payment-notifications routes |
| `resources/js/pages/landlord/payment-notifications/index.tsx` | New | Filterable table + expandable detail |
| `resources/js/pages/landlord/admin-panel.tsx` | Modified | + PaymentNotifications card |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `IngestPaymentNotification` calls external bank API | Low | Job already has retry/backoff; reprocess is per-notification, safe |
| User reprocesses same notification multiple times | Low | Dedup_hash prevents duplicate match creation downstream |

## Rollback Plan

Remove routes from `landlord.php`. Delete controller and page file. Revert `match()` relationship addition. Remove admin-panel card. No schema rollback needed.

## Dependencies

- `PaymentNotification` model + `IngestPaymentNotification` job already exist (no changes needed)
- `payment_notifications` table already seeded via `NotificationSampleSeeder`

## Success Criteria

- [ ] `/admin/payment-notifications` renders paginated notifications filtered by parse_status/bank_code/date
- [ ] Expandable detail shows raw_text, parsed_data, and match info (order, amount, status)
- [ ] "Reprocesar" button only visible for `failed` notifications, dispatches `IngestPaymentNotification`
- [ ] Admin panel card links to the new page
- [ ] All tests pass: `php artisan test --compact --filter=PaymentNotificationController`
