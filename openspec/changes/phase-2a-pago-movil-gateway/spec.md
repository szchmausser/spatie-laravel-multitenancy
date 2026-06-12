# Spec: Phase 2A — Payment Gateway

> **Fuente de verdad**: `docs/phase-2a-payment-architecture.md`
> Este archivo es un resumen ejecutivo. Para detalles completos, ver el documento de arquitectura.

## Functional Requirements

| ID | Requisito | Referencia Docs |
|----|-----------|-----------------|
| FR-1 | Tenant-initiated payment flow | §5.1, §5.2, §5.3 |
| FR-2 | Admin verification flow | §5.4, §5.5 |
| FR-3 | Payment accumulation | §5.2 |
| FR-4 | Order expiration (48h) | §5.3 |
| FR-5 | Business rules (1 plan, N resources) | §5.5, §5.6 |

## Non-Functional Requirements

| ID | Requisito | Referencia Docs |
|----|-----------|-----------------|
| NFR-1 | Data integrity (CHECK constraints) | §4.1, §4.2, §4.3 |
| NFR-2 | Performance (Eager Loading) | §6.1 |
| NFR-3 | Security (authorization) | §3.2 |
| NFR-4 | Audit trail | §4.2 |

## Database Schema

**Ver**: `docs/phase-2a-payment-architecture.md` §4.1 (Order), §4.2 (Payment), §4.3 (PagoMovilDetail)

### Orders Table
- Exclusive Arcs: `plan_id` (nullable FK) + `resource_id` (nullable FK)
- CHECK constraint: exactly one non-null
- Accessor: `paid_cents` calculated from verified payments

### Payments Table
- Supertipo: core financial fields
- FK to orders, tenants, users

### PagoMovilDetails Table
- Subtipo: `phone`, `bank`, `rif` (all NOT NULL)
- PK = FK to payments.id

## Validation Rules

| Rule | Value | Reference |
|------|-------|-----------|
| Payment reference | 6-10 digits | §4.4 |
| Amount | Must match order total | §4.5 |
| Status transitions | pending→verified, pending→cancelled | §4.2 |

## Test Scenarios

**Ver**: `docs/phase-2a-payment-architecture.md` §5 (Escenarios de Uso)

1. Pago simple (§5.1)
2. Acumulación de pagos (§5.2)
3. Expiración de order (§5.3)
4. Cancelación de pago (§5.4)
5. Cambio de plan (§5.5)
6. Múltiples resources (§5.6)

---

**Para detalles completos**: Ver `docs/phase-2a-payment-architecture.md`
