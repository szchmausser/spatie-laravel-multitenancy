## S8e — Archive Report

### Change Summary
S8e — PaymentNotification viewer + reprocess failed

### What was built
- PaymentNotificationController with index (paginated, filterable by parse_status/bank_code/date) and reprocess actions
- Routes: GET /admin/payment-notifications, POST /admin/payment-notifications/{notification}/reprocess
- payment-notifications/index.tsx Inertia page with filter card, table with expandable rows (raw_text, parsed_data, match info), reprocess button, pagination, empty state
- Admin panel "Notificaciones" card in admin-panel.tsx
- PaymentNotification model: added match() hasOne relationship
- TypeScript PaymentNotification types in types/models.ts
- PaymentNotificationFactory: realistic bank SMS data (BDV + BNC formats)
- BrowserTestCase: added payment_notifications table cleanup

### Files created/modified
| File | Action |
|------|--------|
| app/Models/PaymentNotification.php | Modified (+match relationship) |
| database/factories/PaymentNotificationFactory.php | Modified (realistic data) |
| resources/js/types/models.ts | Modified (+PaymentNotification types) |
| app/Http/Controllers/Landlord/PaymentNotificationController.php | Created |
| routes/landlord.php | Modified |
| resources/js/pages/landlord/payment-notifications/index.tsx | Created |
| resources/js/pages/landlord/admin-panel.tsx | Modified (+card) |
| tests/Browser/BrowserTestCase.php | Modified (+payment_notifications cleanup) |
| tests/Feature/Landlord/PaymentNotificationControllerTest.php | Created |
| tests/Browser/Landlord/PaymentNotificationBrowserTest.php | Created |

### Test Results
- 11 feature tests (85 assertions)
- 9 browser tests
- 126 landlord tests (no regressions)
- Pint clean

### Plan document
- docs/plan-conciliacion-automatica.md updated
- S8e status: ✅ Completed

### Lessons Learned
- PaymentNotificationParser returns null (does not throw) — markFailed stores the reason
- Dedup_hash prevents duplicate PaymentMatch creation downstream
- parsed_data JSON structure varies by bank (BDV vs BNC raw_groups differ)
- Select Radix + Dusk: use click on trigger + [role="option"] to change values
- Factory should generate realistic raw_text to be useful for browser tests and manual verification

### Engram Observation IDs
- verify-report: #668
- apply-progress: #667
