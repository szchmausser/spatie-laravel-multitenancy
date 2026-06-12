# Design: Phase 2A — Payment Gateway

> **Fuente de verdad**: `docs/phase-2a-payment-architecture.md`
> Este archivo es un resumen ejecutivo. Para detalles completos, ver el documento de arquitectura.

## Class Diagram

**Ver**: `docs/phase-2a-payment-architecture.md` §3.1 (Diagrama de Entidades)

```
Order (1) ──→ (N) Payment (Supertipo)
Payment (1) ──→ (1) PagoMovilDetail (Subtipo)
```

## Service Layer

**Ver**: `docs/phase-2a-payment-architecture.md` §4.4, §4.5, §4.6

| Componente | Responsabilidad | Reference |
|------------|-----------------|-----------|
| `PaymentGatewayInterface` | Contrato para gateways | §4.4 |
| `PagoMovilGateway` | Implementación Pago Móvil | §4.5 |
| `PaymentService` | Orquestador | §4.6 |

## Controller Design

**Ver**: `docs/phase-2a-payment-architecture.md` §3.2 (Flujo de Datos)

| Controller | Endpoints | Reference |
|------------|-----------|-----------|
| Tenant PaymentController | create, store, show | §3.2 |
| Admin PaymentController | index, verify, cancel | §3.2 |

## Frontend Design

**Ver**: `docs/phase-2a-payment-architecture.md` §5 (Escenarios de Uso)

| Page | Componentes | Reference |
|------|-------------|-----------|
| billing/payment.tsx | PaymentInstructions, PaymentStatus | §5.1 |
| landlord/payments/index.tsx | DataTable, PaymentActions | §5.4 |

## Event/Listener Design

**Ver**: `docs/phase-2a-payment-architecture.md` §4.6 (PaymentService)

| Event | Listener | Trigger |
|-------|----------|---------|
| PaymentVerified | ActivateSubscription | verifyPayment() success |

## Command Design

**Ver**: `docs/phase-2a-payment-architecture.md` §5.3 (Expiración)

| Command | Signature | Logic |
|---------|-----------|-------|
| OrdersExpireCommand | `orders:expire` | Expire orders past expires_at |

## Configuration

**Ver**: `docs/phase-2a-payment-architecture.md` §8 (Scope)

```php
// config/payment.php
return [
    'default' => env('PAYMENT_GATEWAY', 'pago_movil'),
    'order_expiry_hours' => env('ORDER_EXPIRY_HOURS', 48),
];
```

## File Structure

**Ver**: `docs/phase-2a-payment-architecture.md` §8 (Scope)

### New Files (~25)
- Models: Order, Payment, PagoMovilDetail
- Services: PaymentGatewayInterface, PagoMovilGateway, PaymentService
- Controllers: Tenant, Admin
- Events: PaymentVerified
- Listeners: ActivateSubscription
- Commands: OrdersExpireCommand
- Config: payment.php
- Migrations: 3 (orders, payments, pago_movil_details)
- Frontend: 3 pages, 3 components

### Modified Files (~5)
- AppServiceProvider.php
- ChangePlanService.php
- SubscriptionStatus.php
- SubscriptionEventType.php
- Routes (web.php, landlord.php)

---

**Para detalles completos**: Ver `docs/phase-2a-payment-architecture.md`
