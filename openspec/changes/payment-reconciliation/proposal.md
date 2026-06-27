# Proposal: Automatic Payment Reconciliation

## Intent

Admins currently verify payments manually — a bottleneck that delays tenant onboarding and creates operational overhead. We're building an Android NotificationListenerService integration that captures bank push notifications (Pago Móvil, bank transfers), parses them into structured payment data, and matches them against reported payments for automatic verification. The system degrades gracefully: unmatched notifications surface as a landlord dashboard alert for manual resolution.

## Scope

### In Scope
- Device registration + heartbeat API (Android app ↔ landlord)
- Payment notification ingestion and storage (raw + parsed)
- Bank-specific notification parsers (Pago Móvil, Banesco, Mercantil,vincial)
- Matching engine: notification → reported payment (by amount, phone, reference, time window)
- Auto-verify on high-confidence match; alert on low-confidence or no match
- Landlord dashboard: unmatched notifications queue with manual match/ignore actions
- `CancellationType` enum + `PaymentCancelled` event (breaking change cleanup)
- `SystemConfig` model replacing `config/payment.php`
- `verifyPayment()` nullable admin ID; `cancelPayment()` new signature

### Out of Scope
- Real-time push notifications to tenant users (future)
- Multi-bank auto-detection from notification text (future — initial parsers are per-bank)
- Refund processing or payment reversal flows
- Webhook-based bank integrations (Android-only first)
- Mobile app UI beyond notification capture

## First Slice

**Foundation: Device Registration + Notification Ingestion + SystemConfig migration**

This slice is autonomously deployable — it creates the infrastructure (tables, models, API, parsers) without changing any existing payment flow. The existing manual verification continues working unchanged. Value: landlord can see incoming notifications in real-time and the system is ready for matching.

Includes: Device model + migration, PaymentNotification model + migration, PaymentNotificationParser service, SystemConfig model + migration, CancellationType enum, PaymentCancelled event, config/payment.php readers migration.

## Approach

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Device ownership | FK direct (`devices.tenant_id`) | Simpler queries, no polymorphism needed |
| Cancellation reasons | `CancellationType` enum | Type-safe, extensible |
| Config storage | `system_configs` table | Runtime-changeable, landlord-editable |
| Parser architecture | Strategy pattern, per-bank class | Each bank has unique notification format |
| Match confidence | Threshold-based (0.0–1.0) | Auto-verify ≥ 0.9, alert < 0.9 |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/Payment/PaymentService.php` | Modified | `verifyPayment()` nullable admin, `cancelPayment()` new signature |
| `app/Services/Payment/PagoMovilGateway.php` | Modified | Remove config fallback |
| `config/payment.php` | Removed | Readers migrated to SystemConfig |
| `app/Models/Payment.php` | Modified | New cast, relations |
| `app/Models/Order.php` | Modified | Read expiry from SystemConfig |
| New: `app/Models/Device.php` | Created | Android device registration |
| New: `app/Models/PaymentNotification.php` | Created | Raw notification storage |
| New: `app/Models/SystemConfig.php` | Created | Replaces config/payment.php |
| New: `app/Enums/CancellationType.php` | Created | Cancellation reason enum |
| New: `app/Events/PaymentCancelled.php` | Created | Cancellation event |
| New: `app/Services/PaymentNotificationParser.php` | Created | Bank notification parser |
| New: `app/Services/ReconciliationOrchestrator.php` | Created | Match + verify logic |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Parser breaks on bank notification format change | High | Structured parser per bank; easy to update |
| False-positive auto-verification (wrong payment matched) | Med | High threshold (0.9); human review for edge cases |
| Breaking change in `cancelPayment()` signature | Low | Update all callers in same PR |
| `config/payment.php` removal breaks cached config | Low | Clear config cache in migration command |

## Rollback Plan

1. Revert config readers migration: restore `config/payment.php` and revert SystemConfig reads
2. Revert `cancelPayment()` signature: restore old signature + callers
3. Disable auto-verify by setting threshold to 1.0 (all go to manual queue)
4. Drop new tables (devices, payment_notifications) — no existing data depends on them

## Dependencies

- Android app must be built separately (out of scope for this SDD)
- PostgreSQL extension for JSONB (already available)

## Success Criteria

- [ ] Payment notifications ingested and stored with parsed fields
- [ ] Matching engine correctly identifies payments with ≥ 0.9 confidence
- [ ] High-confidence matches auto-verify; low-confidence surface in landlord dashboard
- [ ] `config/payment.php` fully eliminated — all 4 readers migrated
- [ ] `cancelPayment()` and `verifyPayment()` signature changes applied with tests
- [ ] All existing tests pass after migration
