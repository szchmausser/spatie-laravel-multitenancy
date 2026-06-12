# Proposal: Phase 2A — Manual Pago Móvil Payment Gateway

> **Fuente de verdad**: `docs/phase-2a-payment-architecture.md`
> Este archivo es un resumen ejecutivo. Para detalles completos, ver el documento de arquitectura.

## Intent

Reemplazar las compras simuladas con una arquitectura de pago extensible usando Pago Móvil como primer gateway. Separar **Orden** (intención de compra) de **Pago** (transacción financiera real).

## Scope

### In Scope
- `PaymentGatewayInterface` + `PagoMovilGateway` (Strategy Pattern)
- `PaymentService` orquestador
- `Order` model — Exclusive Arcs (plan_id + resource_id + CHECK constraint)
- `Payment` model — Supertipo/Subtipo
- `PagoMovilDetail` model — NOT NULL constraints
- UI tenant (instrucciones + envío de referencia)
- Panel admin (verificar/rechazar pagos)
- Reactivación suscripción (Expired → Active)
- Expiración orders (48h + artisan command)

### Out of Scope
- Otros métodos de pago (Phase 2B+)
- Reembolsos (Phase 2C)
- Notificaciones (Phase 2B+)

## Key Decisions

1. **Exclusive Arcs** — plan_id + resource_id + CHECK constraint (no polymorphic lock-in)
2. **Supertipo/Subtipo** — Payment → PagoMovilDetail (NOT NULL real)
3. **Accessor calculated** — paid_cents calculado, no hardcodeado
4. **Business absorbs fees** — cliente paga monto exacto
5. **1 pending order for Plans** — múltiples para Resources

## Risks

- Verificación manual crea delay inherente
- Concurrent verification necesita transaction lock
- Duplicate reference idempotency via UNIQUE constraint

---

**Para detalles completos**: Ver `docs/phase-2a-payment-architecture.md` (818 líneas)
