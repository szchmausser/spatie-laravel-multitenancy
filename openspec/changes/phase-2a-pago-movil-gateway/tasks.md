# Tasks: Phase 2A — Manual Pago Móvil Payment Gateway

> **Fuente de verdad**: `docs/phase-2a-payment-architecture.md`
> Este archivo es un plan de implementación. Para detalles de arquitectura, ver el documento de arquitectura.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 1200–1500 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (migrations+models+enums) → PR 2 (services+gateway) → PR 3 (controllers+routes+frontend) → PR 4 (events+commands+tests) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Migrations + Models + Enums + Factories | PR 1 | Foundation layer — all DB + model contracts |
| 2 | Services: Gateway interface, PagoMovilGateway, PaymentService | PR 2 | Core business logic, depends on PR 1 models |
| 3 | Controllers + Routes + Frontend pages | PR 3 | UI wiring, depends on PR 2 services |
| 4 | Events, Command, Tests | PR 4 | Integration + verification, depends on PR 1-3 |

## Phase 1: Database — Migrations

- [x] 1.1 Create `database/migrations/landlord/2026_06_12_000001_create_orders_table.php` — id, tenant_id FK, plan_id nullable FK, resource_id nullable FK, total_cents integer, status varchar(20) default 'pending', expires_at timestamp nullable, metadata json nullable, timestamps. Indexes: tenant_id, status, [tenant_id+status] (M)
- [x] 1.2 Add CHECK constraint in orders migration: `(plan_id IS NOT NULL AND resource_id IS NULL) OR (plan_id IS NULL AND resource_id IS NOT NULL)` via raw SQL in `afterCreated` or DB statement (S)
- [x] 1.3 Create `database/migrations/landlord/2026_06_12_000002_create_payments_table.php` — id, tenant_id FK, order_id FK, amount_cents integer, currency varchar(3), payment_method varchar(50), transaction_id varchar(255) nullable, status varchar(20) default 'pending', verified_by bigint FK nullable, verified_at timestamp nullable, cancellation_reason text nullable, cancelled_by bigint FK nullable, cancelled_at timestamp nullable, metadata json nullable, timestamps. Indexes: order_id, status, tenant_id (M)
- [x] 1.4 Create `database/migrations/landlord/2026_06_12_000003_create_pago_movil_details_table.php` — payment_id PK + FK → payments.id, phone varchar(20), bank varchar(100), rif varchar(20). Cascade delete on payment (S)
- [x] 1.5 Run `php artisan migrate` on landlord connection — verify all 3 tables created, CHECK constraint enforced (S)

## Phase 2: Models + Enums + Factories

- [x] 2.1 Create `app/Enums/OrderStatus.php` — enum: Pending, Paid, Cancelled, Expired with label() method. Follow SubscriptionStatus pattern (S)
- [x] 2.2 Create `app/Enums/PaymentStatus.php` — enum: Pending, Verified, Cancelled with label() method (S)
- [x] 2.3 Create `app/Models/Order.php` — UsesLandlordConnection, fillable fields, casts status→OrderStatus, expires_at→datetime, metadata→array. Relationships: tenant(), plan(), resource(), payments(). Accessor paid_cents (sum of verified payments), remaining_cents (total_cents - paid_cents). Method: isFullyPaid() (M)
- [x] 2.4 Create `app/Models/Payment.php` — UsesLandlordConnection, fillable fields, casts status→PaymentStatus, verified_at/cancelled_at→datetime, metadata→array. Relationships: tenant(), order(), verifiedBy()→Landlord, cancelledBy()→Landlord, pagoMovilDetail(). (M)
- [x] 2.5 Create `app/Models/PagoMovilDetail.php` — UsesLandlordConnection, fillable, $primaryKey='payment_id', $incrementing=false. Relationship: payment() BelongsTo. (S)
- [x] 2.6 Create `database/factories/OrderFactory.php` — default state: pending, expires_at now()+48h, total_cents 1000. States: forPlan(), forResource(), paid(), cancelled(), expired(). (M)
- [x] 2.7 Create `database/factories/PaymentFactory.php` — default state: pending pago_movil, amount_cents 1000, currency 'VES'. States: verified(), cancelled(). (S)
- [x] 2.8 Create `database/factories/PagoMovilDetailFactory.php` — default phone, bank, rif with faker data. (S)
- [x] 2.9 Write unit tests: `tests/Unit/Models/OrderTest.php` — test paid_cents accessor, remaining_cents, isFullyPaid(), CHECK constraint enforcement. `tests/Unit/Models/PaymentTest.php` — test status casts, relationships. `tests/Unit/Models/PagoMovilDetailTest.php` — test primaryKey config. Run and verify RED→GREEN. (M)

## Phase 3: Services — Strategy Pattern

- [x] 3.1 Create `app/Services/Payment/PaymentGatewayInterface.php` — interface with method: recordPayment(Order $order, array $data): Payment. Follows SOLID, payment-method agnostic. (S)
- [x] 3.2 Create `app/Services/Payment/PagoMovilGateway.php` — implements PaymentGatewayInterface. recordPayment(): validates exact amount, creates Payment + PagoMovilDetail in DB::transaction, sets payment_method='pago_movil'. Throws InvalidPaymentAmountException on mismatch. (M)
- [x] 3.3 Create `app/Exceptions/InvalidPaymentAmountException.php` — extends RuntimeException, carries expected/received amounts. (S)
- [x] 3.4 Create `app/Services/Payment/PaymentService.php` — orchestrator. Methods: createOrder(tenantId, type, planOrResourceId, amount): Order (cancels pending plan orders if applicable), recordPayment(orderId, method, data): Payment (delegates to gateway), verifyPayment(paymentId, adminId): void (sets verified_by/at, fires PaymentVerified), cancelPayment(paymentId, reason, adminId): void (sets cancellation fields). (L)
- [x] 3.5 Register gateway in `app/Providers/AppServiceProvider.php` — bind PaymentGatewayInterface to PagoMovilGateway in register(). (S)
- [x] 3.6 Write unit tests: `tests/Unit/Services/Payment/PagoMovilGatewayTest.php` — test recordPayment success, exact amount enforcement, transaction atomicity. `tests/Unit/Services/Payment/PaymentServiceTest.php` — test createOrder with plan (cancels previous pending), createOrder with resource (allows multiples), verifyPayment, cancelPayment. Run and verify RED→GREEN. (L)

## Phase 4: Controllers + Routes

- [x] 4.1 Create `app/Http/Controllers/Tenant/PaymentController.php` — index(): list tenant's orders with payments, create(): show payment form for order, store(): submit pago_movil payment details, show(order): view order + payment status. Uses Inertia, Gate::authorize for billing permissions. (M)
- [x] 4.2 Create `app/Http/Controllers/Landlord/PaymentController.php` — index(): list all payments with filters (status, tenant, date), show(payment): payment detail with pago_movil info, verify(payment): mark verified + fire event, cancel(payment): mark cancelled with reason. EnsureUserIsAdmin middleware. (M)
- [x] 4.3 Add tenant routes in `routes/web.php` under billing prefix: `GET /billing/orders`, `POST /billing/orders`, `GET /billing/orders/{order}`, `POST /billing/orders/{order}/payments`. (S)
- [x] 4.4 Add landlord routes in `routes/landlord.php` under admin prefix: `GET /payments`, `GET /payments/{payment}`, `POST /payments/{payment}/verify`, `POST /payments/{payment}/cancel`. (S)
- [x] 4.5 Write feature tests: `tests/Feature/Tenant/PaymentControllerTest.php` — test index shows orders, store creates payment + detail, exact amount validation, authorization. `tests/Feature/Landlord/PaymentControllerTest.php` — test index with filters, verify sets fields + fires event, cancel with reason, admin-only access. Run and verify RED→GREEN. (L)

## Phase 5: Frontend — Inertia React Pages

- [x] 5.1 Create `resources/js/pages/billing/orders/index.tsx` — table of tenant orders: plan/resource name, total, status badge, paid/remaining, expiry countdown, actions. Uses Wayfinder for routes. (M)
- [x] 5.2 Create `resources/js/pages/billing/orders/show.tsx` — order detail: line items, payment history table, pago_movil payment form (phone, bank, RIF fields), submit button. (L)
- [x] 5.3 Create `resources/js/pages/admin/payments/index.tsx` — admin payment list: tenant name, amount, method, status, date, actions (verify/cancel). Filters sidebar. (M)
- [x] 5.4 Create `resources/js/pages/admin/payments/show.tsx` — payment detail: pago_movil details (phone, bank, RIF), transaction info, verify/cancel buttons with confirmation. (M)
- [x] 5.5 Create shared component `resources/js/components/payment-status-badge.tsx` — renders OrderStatus/PaymentStatus with color coding. (S)
- [x] 5.6 Create shared component `resources/js/components/pago-movil-form.tsx` — reusable form: phone input (Venezuelan format), bank select, RIF input, amount display. (M)
- [x] 5.7 Verify `npm run build` compiles without errors. Run browser tests if available. (S)

## Phase 6: Events + Listeners

- [x] 6.1 Create `app/Events/PaymentVerified.php` — event class carrying Payment model, dispatched after verification. Uses Dispatchable, SerializesModels. (S)
- [x] 6.2 Create `app/Listeners/ActivateSubscription.php` — handles PaymentVerified: if order fully paid AND order has plan_id, create/update Subscription for tenant. Idempotent (check existing active). (M)
- [x] 6.3 Register listener in `app/Providers/EventServiceProvider.php` or via Event::listen in boot(). (S)
- [x] 6.4 Write integration test: `tests/Feature/Listeners/ActivateSubscriptionTest.php` — test: payment verified + order fully paid → subscription created. Payment verified + not fully paid → no subscription. Already has active subscription → updated not duplicated. Run and verify RED→GREEN. (M)

## Phase 7: Command — Order Expiry

- [x] 7.1 Create `app/Console/Commands/ExpireOrders.php` — signature: `orders:expire`. Finds pending orders where expires_at < now(), sets status=expired, logs action. Follow ExpireSubscriptions pattern. (S)
- [x] 7.2 Register command in `app/Console/Kernel.php` or auto-discovered. Schedule: `->daily()` in schedule(). (S)
- [x] 7.3 Write test: `tests/Feature/Commands/ExpireOrdersTest.php` — test: pending+expired → status=expired. Pending+future → unchanged. Already cancelled → unchanged. Run and verify RED→GREEN. (S)

## Phase 8: Factories + Test Helpers

- [x] 8.1 Add factory states: `OrderFactory::forPlan()`, `OrderFactory::forResource()`, `OrderFactory::pending()`, `OrderFactory::expired()`. `PaymentFactory::verified()`, `PaymentFactory::cancelled()`. (S)
- [x] 8.2 Create test helper: `tests/helpers.php` with `createOrderWithPayment()` that builds Order + Payment + PagoMovilDetail in one call for test setup. Register in `composer.json` autoload-dev. (S)
- [x] 8.3 Run full test suite: `php artisan test --compact` — all tests pass, no regressions. (S)

## Phase 9: Cleanup + Final Verification

- [x] 9.1 Run `vendor/bin/pint --dirty --format agent` — ensure PHP style compliance. (S)
- [x] 9.2 Run `npm run build` — verify frontend compiles. (S)
- [x] 9.3 Manual smoke test: create order, submit pago_movil payment, verify as admin, check subscription activates. (M)
