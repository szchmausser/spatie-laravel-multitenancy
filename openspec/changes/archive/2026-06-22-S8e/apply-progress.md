# Apply Progress: S8e — PaymentNotification viewer + reprocess failed

**Status**: ✅ Complete — all 11 tests passing, all tasks implemented.

## Completed Tasks

### Phase 1: Foundation — Relationship + Types
- [x] 1.1 Add `match(): HasOne` to `PaymentNotification.php` (`$this->hasOne(PaymentMatch::class)`)
- [x] 1.2 Add `PaymentNotificationItem` interface + `PaymentNotificationPageProps` to `resources/js/types/models.ts`

### Phase 2: Backend — Controller + Routes
- [x] 2.1 Create `PaymentNotificationController` — `index()` with inline filter validation (parse_status/bank_code/from/to), eager-load `match.payment`, paginate 20, `withQueryString()`
- [x] 2.2 Add `reprocess()` — route-model binding, guard `parse_status === 'failed'` else redirect with error, dispatch `IngestPaymentNotification`, redirect back with flash
- [x] 2.3 Register GET `/admin/payment-notifications` + POST `/{notification}/reprocess` inside admin middleware group in `routes/landlord.php`

### Phase 3: Frontend — Payment Notifications Page
- [x] 3.1 Created `resources/js/pages/landlord/payment-notifications/index.tsx` with filter card, table, expandable rows, pagination
- [x] 3.2 Table with parse_status badges (pending=gray, parsed=green, failed=red)
- [x] 3.3 Expanded detail: raw_text (pre), parsed_data (JSON pretty), parse_error (if failed), match info or "Sin match"
- [x] 3.4 "Reprocesar" button on failed rows — Inertia POST, disabled during submit
- [x] 3.5 Pagination links, empty state with Banknote icon

### Phase 4: Wiring — Admin Panel Card
- [x] 4.1 Add "Notificaciones" card entry in `admin-panel.tsx` with Banknote icon

### Phase 5: Tests
- [x] 5.1 Feature tests: index default, parse_status filter, bank_code filter, date range, empty state, pagination
- [x] 5.2 Feature tests: reprocess success (Bus::fake), non-failed error, 404, 403 for non-admin
- [x] 5.3 Browser tests: empty state, list display, expand detail, filter by parse_status, filter by date range, filter by bank_code, clear filters, reprocess flash

## Files Changed

| File | Action | Description |
|------|--------|-------------|
| `app/Models/PaymentNotification.php` | Modified | Added `match(): HasOne` relationship |
| `database/factories/PaymentNotificationFactory.php` | Modified | Added `pending()` and `failed()` states |
| `resources/js/types/models.ts` | Modified | Added `PaymentNotificationItem` and `PaymentNotificationPageProps` types |
| `app/Http/Controllers/Landlord/PaymentNotificationController.php` | Created | Controller with `index()` and `reprocess()` methods |
| `routes/landlord.php` | Modified | Added payment-notifications routes in admin group |
| `resources/js/pages/landlord/payment-notifications/index.tsx` | Created | Full Inertia page with filterable table, expandable rows, reprocess action + data-testid attributes |
| `resources/js/pages/landlord/admin-panel.tsx` | Modified | Added "Notificaciones" card entry |
| `tests/Feature/Landlord/PaymentNotificationControllerTest.php` | Created | 11 feature tests covering index, filters, reprocess, auth |
| `tests/Browser/Landlord/PaymentNotificationBrowserTest.php` | Created | 9 browser tests (empty, list, expand, filters, clear, reprocess, non-failed guard, bank_code filter) |
| `tests/Browser/BrowserTestCase.php` | Modified | Added `payment_notifications` table cleanup |

## Deviations from Design

- **parse_status validation**: Used actual model values (`pending`, `parsed`, `failed`) instead of spec's typo (`pending`, `success`, `failed`)
- **Reprocess non-failed guard**: Redirects back with error flash instead of `abort(422)` — better UX for Inertia POST flow
- **Admin card icon**: `Banknote` from lucide-react instead of `Bell`

## Test Results

```
Feature: 11 passed (85 assertions)
Browser: 9 tests created
```

## Next Recommended

`sdd-verify`
