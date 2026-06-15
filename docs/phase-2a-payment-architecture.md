# Phase 2A: Payment Architecture — Documento de Arquitectura

## 1. Problema

### 1.1 Situación Actual
El sistema SaaS multi-tenant tiene implementado:
- **Plan**: Define tier de servicio (price, features)
- **Subscription**: Vincula tenant con plan (status, dates)
- **Flujo de pago completo**: Order → Payment → PagoMovilDetail con verificación manual
- **Múltiples métodos de pago**: Pago Móvil (implementado) + Transferencia Bancaria (nuevo)

**Implementado**: Flujo de pago real con Pago Móvil. Los tenants crean órdenes, reciben instrucciones de pago, envían referencia, y un admin verifica el comprobante bancario.

**En desarrollo**: Transferencia Bancaria como segundo método de pago, con tabla `payment_method_configs` para configuración dinámica de cuentas receptoras.

### 1.2 Necesidad de Negocio
- Pago Móvil es el método de pago principal en Venezuela
- Transferencia Bancaria es el segundo método más utilizado
- Requiere verificación manual (admin verifica comprobante bancario)
- El dinero se debita del cliente INMEDIATAMENTE (no es "intento de pago")
- Se deben acumular múltiples pagos para cubrir el total de una orden
- Arquitectura debe ser extensible para futuros métodos de pago (PayPal, Stripe)

### 1.3 Restricciones
- Stack: Laravel 13 + PostgreSQL + Spatie Multitenancy
- Dual-database: landlord (central) + tenant (per-tenant)
- Pago Móvil y Transferencia son manuales, no automatizados
- MVP: Pago Móvil + Transferencia Bancaria, extensible a futuro

---

## 2. Propuesta

### 2.1 Enfoque: Strategy Pattern + Patrón Supertipo/Subtipo
Separar **Orden** (intención de compra) de **Pago** (transacción financiera real).
Para los métodos de pago, se usará el patrón arquitectónico **Supertipo/Subtipo** (también conocido como Table-per-Type o Reverse Polymorphism). La tabla `payments` guardará la información financiera core, y los detalles específicos de cada método vivirán en tablas separadas (ej. `pago_movil_details`, `bank_transfer_details`) vinculadas mediante llaves foráneas estrictas (`payment_id`).

La tabla `payment_method_configs` almacena las cuentas receptoras configurables por el landlord, permitiendo agregar nuevos métodos sin cambios en código.

### 2.2 Por Qué No Otras Alternativas

| Alternativa | Por Qué Se Rechaza |
|-------------|-------------------|
| **God Table (Columnas Nullables)** | Anti-patrón. Genera tablas llenas de valores `NULL`, impide el uso de constraints `NOT NULL` reales en DB para detalles de pagos. |
| **JSONB + DTOs** | Aunque es flexible, delega la validación de integridad referencial y de tipos a la capa de PHP en lugar de la Base de Datos. |
| **Polimorfismo nativo de Laravel** | Guarda namespaces de PHP en Base de Datos (ej. `App\Models\...`), creando *framework lock-in*, y es imposible aplicar Foreign Keys estrictas a la columna `details_id`. |
| **Polimorfismo en Orders (buyable_id + buyable_type)** | Mismo problema de lock-in. No hay FK real, no hay integridad referencial a nivel DB. |

---

## 3. Arquitectura

### 3.1 Diagrama de Entidades

```text
┌─────────────────────────────────────────────────────────────┐
│                         Order                               │
│  "El cliente quiere comprar algo"                           │
│  ─────────────────────────────────────────────────────────  │
│  id, tenant_id                                              │
│  plan_id (nullable FK) | resource_id (nullable FK)          │
│  total_cents, status, expires_at                            │
│  metadata, created_at                                       │
│                                                             │
│  Constraint: Exactamente uno de los dos tiene valor         │
│                                                             │
│  Methods:                                                   │
│  - paid_cents (accessor, calculado)                         │
│  - remaining_cents (accessor, calculado)                    │
│  - isFullyPaid()                                            │
│  - payments() → HasMany Payment                             │
│  - buyable() → Retorna Plan o Resource (accessor)           │
│  - tenant() → BelongsTo Tenant                              │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ 1:N
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                   Payment (Supertipo)                       │
│  "Transacción financiera real recibida"                     │
│  ─────────────────────────────────────────────────────────  │
│  id, tenant_id, order_id                                    │
│  amount_cents, currency                                     │
│  payment_method (discriminador: 'pago_movil',               │
│                  'bank_transfer')                            │
│  payment_method_config_id (nullable FK → payment_method_    │
│                           configs)                          │
│  status, transaction_id                                     │
│  verified_by, verified_at                                   │
│  cancellation_reason, cancelled_by, cancelled_at            │
│  metadata, created_at                                       │
│                                                             │
│  Methods:                                                   │
│  - order() → BelongsTo Order                                │
│  - verifier() → BelongsTo User (via verified_by)            │
│  - paymentMethodConfig() → BelongsTo PaymentMethodConfig    │
│  - pagoMovilDetail() → HasOne PagoMovilDetail               │
│  - bankTransferDetail() → HasOne BankTransferDetail         │
│  - getDetailsAttribute() → Retorna el detalle correcto      │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ 1:1
                              ▼
┌─────────────────────────────────────────────────────────────┐
│             PagoMovilDetail (Subtipo)                       │
│  "Snapshot receiver + Reporte del tenant"                   │
│  ─────────────────────────────────────────────────────────  │
│  payment_id (PK, FK a payments.id CASCADE)                  │
│                                                             │
│  📸 SNAPSHOT RECEIVER (al momento del pago):                │
│  phone (NOT NULL)      ← Teléfono destino                   │
│  bank (NOT NULL)       ← Banco destino                      │
│  rif (NOT NULL)        ← RIF destino                        │
│                                                             │
│  👤 SENDER REPORT (datos del tenant que pagó):              │
│  sender_bank (NOT NULL) ← Banco desde donde pagó            │
│  sender_phone (NOT NULL)← Teléfono del tenant               │
│  sender_id (NOT NULL)   ← Cédula/RIF del que pagó           │
│  payment_date (NOT NULL)← Fecha en que pagó                 │
│  concept (nullable)     ← Concepto (opcional)               │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│           BankTransferDetail (Subtipo)                      │
│  "Snapshot receiver + Reporte del tenant"                   │
│  ─────────────────────────────────────────────────────────  │
│  payment_id (PK, FK a payments.id CASCADE)                  │
│                                                             │
│  📸 SNAPSHOT RECEIVER (al momento del pago):                │
│  account_number (NOT NULL) ← Cuenta destino                 │
│  bank_name (NOT NULL)      ← Banco destino                  │
│  account_holder (NOT NULL) ← Titular destino                │
│  holder_id (NOT NULL)      ← RIF destino                    │
│                                                             │
│  👤 SENDER REPORT (datos del tenant que transfirió):        │
│  sender_bank (NOT NULL) ← Banco de origen                   │
│  sender_name (NOT NULL) ← Nombre del titular emisor         │
│  sender_id (NOT NULL)   ← Cédula/RIF del emisor             │
│  sender_account_number (nullable) ← N° cuenta origen        │
│  tenant_rif (nullable)  ← RIF del cliente del servicio      │
│  payment_date (NOT NULL)← Fecha de la operación             │
│  concept (nullable)     ← Concepto/motivo                   │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│            PaymentMethodConfig                              │
│  "Cuentas receptoras configurables (Landlord)"              │
│  ─────────────────────────────────────────────────────────  │
│  id, type, label, bank_name                                │
│  account_number, account_holder, holder_id                  │
│  is_active, sort_order, metadata                           │
│  created_at, updated_at                                    │
│                                                             │
│  Scopes:                                                   │
│  - active() → where is_active = true                       │
│  - ofType(string $type) → where type = $type               │
│                                                             │
│  NOTA: Para pago_movil, account_number = teléfono,         │
│  bank_name = banco, holder_id = RIF del beneficiario.      │
│  Para bank_transfer, account_number = cuenta,              │
│  bank_name = banco, account_holder = titular,              │
│  holder_id = RIF del titular.                              │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Flujo de Datos

```text
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    Tenant    │     │  Landlord    │     │    Admin     │
│  (Comprador) │     │   (Sistema)  │     │ (Verificador)│
└──────┬───────┘     └──────┬───────┘     └──────┬───────┘
       │                    │                    │
       │ 1. Selecciona plan │                    │
       │───────────────────>│                    │
       │                    │                    │
       │ 2. Crea Order      │                    │
       │    (status: pending)                    │
       │                    │                    │
       │ 3. Selecciona método de pago           │
       │    (pago_movil / bank_transfer)         │
       │    + Selecciona cuenta específica       │
       │    (PaymentMethodConfig)                │
       │───────────────────>│                    │
       │                    │                    │
       │ 4. Resuelve gateway según método       │
       │    (PaymentService)                     │
       │                    │                    │
       │ 5. Crea Payment +  │                    │
       │    Detail (pending) │                    │
       │    - payment_method_config_id guardado  │
       │    - Snapshot receiver en detail table  │
       │                    │                    │
       │ 6. Muestra instrucciones de pago       │
       │    (desde PaymentMethodConfig)          │
       │<───────────────────│                    │
       │                    │                    │
       │ 7. Envía dinero (banco real)           │
       │──── (off-system) ──│───────────────────>│
       │                    │                    │
       │ 8. Reporta pago con sus datos          │
       │    (sender_bank, sender_phone, etc.)    │
       │───────────────────>│                    │
       │                    │ 9. Lista pagos     │
       │                    │    pendientes      │
       │                    │<───────────────────│
       │                    │                    │
       │                    │ 10. Verifica pago  │
       │                    │    - Compara       │
       │                    │      snapshot      │
       │                    │      receiver vs   │
       │                    │      config actual │
       │                    │    - Verifica      │
       │                    │      referencia    │
       │                    │      bancaria      │
       │                    │───────────────────>│
       │                    │                    │
       │                    │ 11. Actualiza Order│
       │                    │    paid_cents += X │
       │                    │                    │
       │ 12. Notificación   │                   │
       │<───────────────────│                    │
       │                    │                    │
```

---

## 4. Entidades Detalladas

### 4.1 Order (Tabla: `orders` en landlord DB)

**Propósito**: Representa la intención de compra de un tenant. Es "qué quiere comprar".

#### Campos

| Campo | Tipo | Nullable | Descripción | Justificación |
|-------|------|----------|-------------|---------------|
| `id` | bigint | NO | PK autoincremental | Identificador único |
| `tenant_id` | bigint | NO | FK a tenants | Tenant que creó la order |
| `plan_id` | bigint | SÍ | FK a plans | Si es compra de plan (nullable) |
| `resource_id` | bigint | SÍ | FK a resources | Si es compra de resource (nullable) |
| `total_cents` | integer | NO | Monto total en centavos | Siempre en centavos para evitar decimales |
| `status` | varchar(20) | NO | pending/paid/cancelled/expired | Estado de la order |
| `expires_at` | timestamp | NO | Cuándo expira | Orders sin pago deben expirar |
| `metadata` | json | SÍ | Datos adicionales flexibles | Información contextual no crítica |
| `created_at` | timestamp | NO | Creación | Audit trail |
| `updated_at` | timestamp | NO | Última actualización | Audit trail |

#### Constraint de Integridad (Exclusive Arcs)

```sql
-- Exactamente uno de los dos debe tener valor
ALTER TABLE orders ADD CONSTRAINT chk_exclusive_buyable 
CHECK (
    (plan_id IS NOT NULL AND resource_id IS NULL) OR 
    (plan_id IS NULL AND resource_id IS NOT NULL)
);
```

Este constraint **permanece sin cambios** con la adición de Transferencia Bancaria. El Exclusive Arcs en Orders define qué se compra (Plan vs Resource), no cómo se paga.

#### Campos Calculados (Accessors)

| Accessor | Lógica | Justificación |
|----------|--------|---------------|
| `paid_cents` | `SUM(payments.where(status=verified).amount_cents)` | Single source of truth en pagos, no hardcodeado |
| `remaining_cents` | `MAX(0, total_cents - paid_cents)` | Cuánto falta para completar |
| `isFullyPaid()` | `paid_cents >= total_cents` | Booleano para verificar completitud |

#### Estados

```
pending ──────────> paid
  │                   │
  │                   │
  v                   v
expired           cancelled
```

| Estado | Descripción | Transiciones |
|--------|-------------|--------------|
| `pending` | Esperando pagos | → paid (pagos cubren total) |
| `paid` | Pagos cubren total | → cancelled (reembolso) |
| `cancelled` | Cancelada por admin/tenant | Terminal |
| `expired` | Tiempo de expiración alcanzado | Terminal |

#### Relaciones

```php
public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}

public function plan(): BelongsTo
{
    return $this->belongsTo(Plan::class);
}

public function resource(): BelongsTo
{
    return $this->belongsTo(Resource::class);
}

public function tenant(): BelongsTo
{
    return $this->belongsTo(Tenant::class);
}

// Accessor para obtener el buyable (Plan o Resource)
public function getBuyableAttribute()
{
    return $this->plan ?? $this->resource;
}

// Helper para saber el tipo
public function getBuyableTypeAttribute(): string
{
    return $this->plan_id !== null ? 'plan' : 'resource';
}
```

#### Accessors

```php
public function getPaidCentsAttribute(): int
{
    return $this->payments()
        ->where('status', PaymentStatus::Verified)
        ->sum('amount_cents');
}

public function getRemainingCentsAttribute(): int
{
    return max(0, $this->total_cents - $this->paid_cents);
}

public function isFullyPaid(): bool
{
    return $this->paid_cents >= $this->total_cents;
}
```

#### Por Qué Accessors en Vez de Campo Hardcodeado

**Problema**: Si `paid_cents` estuviera en la tabla, al verificar un Payment, deberíamos:
1. Actualizar Payment.status
2. Recalcular Order.paid_cents
3. Mantener consistencia entre ambas tablas

**Riesgo**: Si falla el paso 2, hay inconsistencia financiera.

**Solución**: Accessor calcula al vuelo. Siempre es correcto. No hay sincronización que fallar.

**Trade-off**: Performance. Pero cada Order tendrá 1-5 pagos máximo. La agregación es trivial.

**Mitigación**: Para listings, usar `Order::withSum('payments', 'amount_cents')` o `loadSum()` para evitar N+1.

---

### 4.2 Payment (Tabla: `payments` en landlord DB)

**Propósito**: Entidad financiera base (Supertipo). Representa una transacción financiera real.

#### Campos Core

| Campo | Tipo | Nullable | Descripción | Justificación |
|-------|------|----------|-------------|---------------|
| `id` | bigint | NO | PK autoincremental | Identificador único |
| `tenant_id` | bigint | NO | FK a tenants | Denormalizado para queries sin JOIN |
| `order_id` | bigint | NO | FK a orders | Order que este pago aplica |
| `amount_cents` | integer | NO | Monto exacto en centavos | Siempre monto exacto |
| `currency` | varchar(3) | NO | Código ISO moneda | 'VES' por defecto, extensible |
| `payment_method` | varchar(50) | NO | Tipo de método de pago | Discriminador: 'pago_movil', 'bank_transfer' |
| `payment_method_config_id` | bigint | SÍ | FK a payment_method_configs | **NUEVO:** Qué cuenta receptora se usó. Nullable para backward compatibility. |
| `transaction_id` | varchar(255) | SÍ | Referencia/ID externo | Referencia bancaria del tenant |
| `status` | varchar(20) | NO | pending/verified/cancelled | Estado del pago |
| `verified_by` | bigint | SÍ | FK a users | Admin que verificó |
| `verified_at` | timestamp | SÍ | Cuándo se verificó | Audit trail |
| `cancellation_reason` | text | SÍ | Razón de cancelación | Si fue cancelado, por qué |
| `cancelled_by` | bigint | SÍ | FK a users | Admin que canceló |
| `cancelled_at` | timestamp | SÍ | Cuándo se canceló | Audit trail |
| `metadata` | json | SÍ | Datos contextuales | Información no crítica |
| `created_at` | timestamp | NO | Creación | Audit trail |
| `updated_at` | timestamp | NO | Última actualización | Audit trail |

#### Estados

```
pending ──────────> verified
  │                   │
  │                   │
  v                   v
cancelled         cancelled
```

| Estado | Descripción | Transiciones |
|--------|-------------|--------------|
| `pending` | Esperando verificación | → verified (admin verifica) |
| `verified` | Pago verificado y aplicado | → cancelled (reembolso/error) |
| `cancelled` | Cancelado por error/fraude | Terminal |

**Por Qué No `rejected`**: En Pago Móvil y Transferencia Bancaria, el dinero YA se debita. No se "rechaza", se "cancela" (reembolsa manualmente).

#### Relaciones

```php
public function order(): BelongsTo
{
    return $this->belongsTo(Order::class);
}

public function verifier(): BelongsTo
{
    return $this->belongsTo(User::class, 'verified_by');
}

public function tenant(): BelongsTo
{
    return $this->belongsTo(Tenant::class);
}

public function paymentMethodConfig(): BelongsTo
{
    return $this->belongsTo(PaymentMethodConfig::class);
}

public function pagoMovilDetail(): HasOne
{
    return $this->hasOne(PagoMovilDetail::class);
}

public function bankTransferDetail(): HasOne
{
    return $this->hasOne(BankTransferDetail::class);
}

public function getDetailsAttribute()
{
    return match($this->payment_method) {
        'pago_movil' => $this->pagoMovilDetail,
        'bank_transfer' => $this->bankTransferDetail,
        default => null,
    };
}
```

---

### 4.3 PagoMovilDetail (Tabla: `pago_movil_details`)

**Propósito**: Entidad de detalle estricta (Subtipo). Almacena un **snapshot** de la cuenta receptora al momento del pago, más el **reporte del tenant** con sus datos de emisión.

#### Arquitectura de Datos: Snapshot + Sender Report

La tabla cumple dos responsabilidades:
1. **Snapshot Receiver**: Preserva los datos de la cuenta receptora al momento del pago (inmutables). Si el landlord cambia su configuración después, el snapshot queda como evidencia histórica.
2. **Sender Report**: Los datos que el tenant proporciona al reportar su pago (desde dónde pagó).

#### Campos

| Campo | Tipo | Nullable | Descripción | Justificación |
|-------|------|----------|-------------|---------------|
| `payment_id` | bigint | NO | PK y FK a `payments.id` CASCADE | Garantiza relación 1:1 estricta con borrado en cascada |
| **Snapshot Receiver** | | | | |
| `phone` | varchar(20) | NO | Teléfono destino | Snapshot de la cuenta al momento del pago |
| `bank` | varchar(100) | NO | Banco destino | Snapshot de la cuenta al momento del pago |
| `rif` | varchar(20) | NO | RIF destino | Snapshot de la cuenta al momento del pago |
| **Sender Report** | | | | |
| `sender_bank` | varchar(100) | NO | Banco desde donde pagó | Dato del tenant |
| `sender_phone` | varchar(20) | NO | Teléfono del tenant | Dato del tenant |
| `sender_id` | varchar(20) | NO | Cédula/RIF del que pagó | Dato del tenant |
| `payment_date` | date | NO | Fecha en que pagó | Dato del tenant |
| `concept` | varchar(255) | SÍ | Concepto (opcional) | Dato del tenant |

**Sin timestamps**: La tabla no tiene `created_at` ni `updated_at`. La información es estática (configuración de cuenta receptor + reporte del tenant).

#### Por Qué Snapshot en Vez de Solo FK

**Problema**: Si solo tuviéramos la FK a `payment_method_configs` y el landlord desactiva/modifica esa cuenta, perderíamos los datos originales que se le mostraron al tenant.

**Solución**: Snapshot al momento del pago. Los campos `phone`, `bank`, `rif` son una "foto" de lo que el tenant vio cuando realizó el pago. Esto es estándar en sistemas financieros — los registros deben ser auto-contenidos e inmutables.

**Trade-off**: Algo de duplicación de datos, pero valioso para:
- Resolución de disputas (meses después)
- Auditoría financiera
- Reconstrucción de estado histórico

#### Por Qué Tabla Separada (No JSON ni Columnas Nullable)

1. **Validación Estricta**: `NOT NULL` real en DB. Imposible insertar detalle sin teléfono.
2. **Cero Basura**: No hay columnas NULL para métodos que no usan esos campos.
3. **Escalabilidad**: Agregar Transferencia = nueva tabla `bank_transfer_details`, no tocar `payments`.
4. **Universal**: Cualquier ORM/lenguaje lo entiende. Sin lock-in.
5. **Integridad**: FK real con cascada. Si se borra el payment, se borra el detalle.

---

### 4.4 BankTransferDetail (Tabla: `bank_transfer_details`)

**Propósito**: Entidad de detalle estricta (Subtipo) para transferencias bancarias. Misma filosofía que PagoMovilDetail: snapshot receiver + sender report.

#### Campos

| Campo | Tipo | Nullable | Descripción | Justificación |
|-------|------|----------|-------------|---------------|
| `payment_id` | bigint | NO | PK y FK a `payments.id` CASCADE | Garantiza relación 1:1 estricta con borrado en cascada |
| **Snapshot Receiver** | | | | |
| `account_number` | varchar(20) | NO | Número de cuenta destino | Snapshot de la cuenta al momento del pago |
| `bank_name` | varchar(100) | NO | Nombre del banco destino | Snapshot de la cuenta al momento del pago |
| `account_holder` | varchar(100) | NO | Titular de la cuenta destino | Snapshot de la cuenta al momento del pago |
| `holder_id` | varchar(20) | NO | RIF/Cédula del titular destino | Snapshot de la cuenta al momento del pago |
| **Sender Report** | | | | |
| `sender_bank` | varchar(100) | NO | Banco de origen | Dato del tenant |
| `sender_name` | varchar(100) | NO | Nombre del titular que transfirió | Dato del tenant |
| `sender_id` | varchar(20) | NO | Cédula/RIF del titular | Dato del tenant |
| `sender_account_number` | varchar(20) | SÍ | Número de cuenta de origen | Dato del tenant — comprobante de transferencia |
| `tenant_rif` | varchar(20) | SÍ | RIF del cliente del servicio | Nullable — si paga un tercero |
| `payment_date` | date | NO | Fecha de la operación | Dato del tenant |
| `concept` | varchar(255) | SÍ | Concepto/motivo | Dato del tenant |

**Sin timestamps**: Misma razón que PagoMovilDetail.

#### Migración

```sql
CREATE TABLE bank_transfer_details (
    payment_id BIGINT PRIMARY KEY REFERENCES payments(id) ON DELETE CASCADE,
    -- Snapshot receiver
    account_number VARCHAR(20) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_holder VARCHAR(100) NOT NULL,
    holder_id VARCHAR(20) NOT NULL,
    -- Sender report
    sender_bank VARCHAR(100) NOT NULL,
    sender_name VARCHAR(100) NOT NULL,
    sender_id VARCHAR(20) NOT NULL,
    sender_account_number VARCHAR(20) NULL,
    tenant_rif VARCHAR(20) NULL,
    payment_date DATE NOT NULL,
    concept VARCHAR(255) NULL
);
```

---

### 4.5 PaymentMethodConfig (Tabla: `payment_method_configs`)

**Propósito**: Almacena las **cuentas receptoras** configurables por el landlord. Permite agregar nuevos métodos de pago y cuentas sin cambios en código. Es la fuente de verdad para las instrucciones de pago que se muestran al tenant.

#### Relación con Detail Tables

Las tablas `pago_movil_details` y `bank_transfer_details` almacenan un **snapshot** de la config al momento del pago. La config es "dónde recibo dinero", el snapshot es "qué le mostré al tenant cuando pagó".

```
PaymentMethodConfig          Detail Table (snapshot)
"Cuenta actual"              "Cuenta al momento del pago"
       │                              │
       │  Se lee al crear             │  Se crea al registrar pago
       │  instrucciones               │  (inmutable después)
       │                              │
       ▼                              ▼
┌──────────────┐              ┌──────────────────┐
│ phone=0412.. │  ──snapshot──>│ phone=0412..     │
│ bank=BDV     │              │ bank=BDV         │
│ rif=J-123..  │              │ rif=J-123..      │
└──────────────┘              └──────────────────┘
        │
        │ Si landlord cambia a phone=0424..
        │ el snapshot viejo NO cambia
        ▼
┌──────────────┐
│ phone=0424.. │  ← Nueva config
│ bank=BDV     │
│ rif=J-123..  │
└──────────────┘
```

#### Campos

| Campo | Tipo | Nullable | Descripción | Justificación |
|-------|------|----------|-------------|---------------|
| `id` | bigint | NO | PK autoincremental | Identificador único |
| `type` | varchar(50) | NO | Tipo de método | 'pago_movil' o 'bank_transfer' |
| `label` | varchar(100) | NO | Nombre visible | 'Bancaracas', 'Mercantil' |
| `bank_name` | varchar(100) | NO | Nombre del banco | Para mostrar en UI |
| `account_number` | varchar(20) | NO | Teléfono (pago móvil) o cuenta (transferencia) | Unificado para ambos métodos |
| `account_holder` | varchar(100) | NO | Titular de la cuenta | Nombre del beneficiario |
| `holder_id` | varchar(20) | NO | RIF/Cédula del titular | Identificación fiscal |
| `is_active` | boolean | NO | Si está habilitado | Permite deshabilitar sin borrar |
| `sort_order` | integer | NO | Orden de presentación | Para UI, default 0 |
| `metadata` | json | SÍ | Datos adicionales | Extensible |
| `created_at` | timestamp | NO | Creación | Audit trail |
| `updated_at` | timestamp | NO | Última actualización | Audit trail |

#### Modelo

```php
class PaymentMethodConfig extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'type',
        'label',
        'bank_name',
        'account_number',
        'account_holder',
        'holder_id',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
```

#### Migración

```sql
CREATE TABLE payment_method_configs (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(20) NOT NULL,
    account_holder VARCHAR(100) NOT NULL,
    holder_id VARCHAR(20) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    sort_order INTEGER DEFAULT 0,
    metadata JSON,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

### 4.6 PaymentGatewayInterface (Contrato)

Define el contrato para procesar pagos independientemente del método.

```php
interface PaymentGatewayInterface
{
    /**
     * Registra un pago para una orden.
     * Crea el Payment y su detalle específico (Supertipo + Subtipo).
     */
    public function recordPayment(Order $order, array $data): Payment;

    /**
     * Retorna instrucciones de pago para el frontend.
     */
    public function getInstructions(Payment $payment): array;
}
```

**Por Qué Esta Interfaz (Implementada)**:

1. `recordPayment()`: Cada método crea el pago + su detalle de forma diferente
2. `getInstructions()`: Cada método tiene instrucciones diferentes para el frontend

**Nota**: Los métodos `validateReference()` y `validatePayment()` del diseño original **no se implementaron**. La validación de referencia se maneja en el controller/service layer, y la verificación de pago es responsabilidad del admin humano.

---

### 4.7 PagoMovilGateway (Implementación)

Usa transacciones para garantizar la inserción atómica del supertipo (`Payment`) y su subtipo (`PagoMovilDetail`). El receiver se resuelve desde `PaymentMethodConfig` (con fallback a config global) y se almacena como **snapshot** en el detail table.

```php
class PagoMovilGateway implements PaymentGatewayInterface
{
    public function recordPayment(Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($order, $data) {
            // 1. Crear Payment con FK a config
            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount_cents' => $data['amount_cents'],
                'currency' => 'VES',
                'payment_method' => 'pago_movil',
                'payment_method_config_id' => $data['payment_method_config_id'] ?? null,
                'status' => PaymentStatus::Pending,
            ]);

            // 2. Resolver receiver (snapshot)
            $config = $this->resolveReceivingAccount(
                $data['payment_method_config_id'] ?? null
            );

            // 3. Crear PagoMovilDetail con snapshot receiver + sender fields
            $payment->pagoMovilDetail()->create([
                // Snapshot receiver (inmutable)
                'phone' => $config['phone'],
                'bank' => $config['bank'],
                'rif' => $config['rif'],
                // Sender report (datos del tenant)
                'sender_bank' => $data['sender_bank'],
                'sender_phone' => $data['sender_phone'],
                'sender_id' => $data['sender_id'],
                'payment_date' => $data['payment_date'],
                'concept' => $data['concept'] ?? null,
            ]);

            return $payment;
        });
    }

    public function getInstructions(Payment $payment): array
    {
        $detail = $payment->pagoMovilDetail;

        return [
            'type' => 'pago_movil',
            'title' => 'Pago Móvil',
            'fields' => [
                ['label' => 'Teléfono', 'value' => $detail->phone],
                ['label' => 'Banco', 'value' => $detail->bank],
                ['label' => 'RIF', 'value' => $detail->rif],
            ],
            'amount' => $payment->amount_cents / 100,
        ];
    }
}
```

**Diferencias con el diseño original**:
- `recordPayment()` recibe `Order` + `array $data` (no `CreatePaymentRequest`)
- `currency` es `'VES'` (no `'USD'`)
- Datos de cuenta receptor vienen de `PaymentMethodConfig` (con fallback a config global)
- **Snapshot receiver** se almacena en el detail table (inmutable)
- **Sender fields** se almacenan en el detail table
- `payment_method_config_id` se guarda en `payments` para referencia

---

### 4.8 BankTransferGateway (Implementación)

```php
class BankTransferGateway implements PaymentGatewayInterface
{
    public function recordPayment(Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($order, $data) {
            // 1. Crear Payment con FK a config
            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount_cents' => $data['amount_cents'],
                'currency' => 'VES',
                'payment_method' => 'bank_transfer',
                'payment_method_config_id' => $data['payment_method_config_id'],
                'status' => PaymentStatus::Pending,
            ]);

            // 2. Resolver receiver (snapshot)
            $config = $this->resolveReceivingAccount(
                $data['payment_method_config_id']
            );

            // 3. Crear BankTransferDetail con snapshot receiver + sender fields
            $payment->bankTransferDetail()->create([
                // Snapshot receiver (inmutable)
                'account_number' => $config['account_number'],
                'bank_name' => $config['bank_name'],
                'account_holder' => $config['account_holder'],
                'holder_id' => $config['holder_id'],
                // Sender report (datos del tenant)
                'sender_bank' => $data['sender_bank'],
                'sender_name' => $data['sender_name'],
                'sender_id' => $data['sender_id'],
                'sender_account_number' => $data['sender_account_number'] ?? null,
                'tenant_rif' => $data['tenant_rif'] ?? null,
                'payment_date' => $data['payment_date'],
                'concept' => $data['concept'] ?? null,
            ]);

            return $payment;
        });
    }

    public function getInstructions(Payment $payment): array
    {
        $detail = $payment->bankTransferDetail;

        return [
            'type' => 'bank_transfer',
            'title' => 'Transferencia Bancaria',
            'fields' => [
                ['label' => 'Banco', 'value' => $detail->bank_name],
                ['label' => 'Cuenta', 'value' => $detail->account_number],
                ['label' => 'Titular', 'value' => $detail->account_holder],
                ['label' => 'RIF/Cédula', 'value' => $detail->holder_id],
            ],
            'amount' => $payment->amount_cents / 100,
        ];
    }
}
```

**Diferencias con PagoMovilGateway**:
- Datos de receiver vienen **siempre** de `PaymentMethodConfig` (no hay fallback a config global)
- `payment_method_config_id` es **requerido** (no nullable)
- **Snapshot receiver** se almacena en el detail table (inmutable)
- **Sender fields** se almacenan en el detail table (sender_bank, sender_name, sender_id, sender_account_number, tenant_rif, payment_date, concept)
- `tenant_rif` es nullable — permite que un tercero pague por el tenant

---

### 4.9 PaymentService (Orquestador)

Orquesta el flujo de pago sin conocer detalles de cada método. Resuelve el gateway según el método de pago seleccionado.

```php
class PaymentService
{
    public function __construct(
        private readonly array $gateways,  // Registro de gateways: ['pago_movil' => ..., 'bank_transfer' => ...]
    ) {}

    /**
     * Crea una orden (no registra pago).
     * El pago se registra después con recordPayment().
     */
    public function createOrder(
        int $tenantId,
        string $buyableType,
        int $buyableId,
        int $totalCents,
        array $paymentData,
    ): array {
        // Cancelar orders pendientes previas para Plans
        if ($buyableType === 'plan') {
            Order::where('tenant_id', $tenantId)
                ->where('status', OrderStatus::Pending)
                ->whereNotNull('plan_id')
                ->update(['status' => OrderStatus::Cancelled]);
        }

        $order = Order::create([
            'tenant_id' => $tenantId,
            'plan_id' => $buyableType === 'plan' ? $buyableId : null,
            'resource_id' => $buyableType === 'resource' ? $buyableId : null,
            'total_cents' => $totalCents,
            'status' => OrderStatus::Pending,
            'expires_at' => now()->addHours(48),
        ]);

        return ['order' => $order];
    }

    /**
     * Registra un pago contra una orden existente.
     * Idempotente: retorna pago existente si ya hay uno pending con el mismo método.
     */
    public function recordPayment(
        Order $order,
        int $amountCents,
        string $method = 'pago_movil',
        ?int $paymentMethodConfigId = null,
        array $gatewayData = [],
    ): Payment {
        $existingPayment = $order->payments()
            ->where('status', PaymentStatus::Pending)
            ->first();

        if ($existingPayment) {
            if ($existingPayment->payment_method === $method) {
                return $existingPayment;
            }
            $existingPayment->update([
                'status' => PaymentStatus::Cancelled,
                'cancellation_reason' => 'Payment method changed to '.$method,
            ]);
        }

        $gateway = $this->resolveGateway($method);
        $payment = $gateway->recordPayment($order, array_merge([
            'amount_cents' => $amountCents,
            'payment_method_config_id' => $paymentMethodConfigId,
        ], $gatewayData));

        $this->notifyLandlordAdmins($payment);

        return $payment;
    }

    /**
     * Verifica un pago. Solo admins pueden verificar.
     */
    public function verifyPayment(Payment $payment, int $adminId): void
    {
        DB::transaction(function () use ($payment, $adminId) {
            $payment->update([
                'status' => PaymentStatus::Verified,
                'verified_by' => $adminId,
                'verified_at' => now(),
            ]);
            event(new PaymentVerified($payment));
        });
    }

    /**
     * Cancela un pago. Acepta tanto pending como verified.
     */
    public function cancelPayment(
        Payment $payment,
        string $reason,
        int $adminId,
    ): void {
        DB::transaction(function () use ($payment, $reason, $adminId) {
            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by' => $adminId,
                'cancelled_at' => now(),
            ]);
            $this->recalculateOrder($payment->order);
        });
    }

    private function resolveGateway(string $method): PaymentGatewayInterface
    {
        return $this->gateways[$method]
            ?? throw new \InvalidArgumentException("Unknown payment method: {$method}");
    }

    private function notifyLandlordAdmins(Payment $payment): void
    {
        try {
            $admins = Landlord::all();
            if ($admins->isEmpty()) return;
            Notification::send($admins, new PendingPaymentCreated($payment));
        } catch (\Throwable) {}
    }

    private function recalculateOrder(Order $order): void
    {
        $order->refresh();
        if (! $order->isFullyPaid() && $order->status === OrderStatus::Paid) {
            $order->update(['status' => OrderStatus::Pending]);
        }
    }
}
```

**Diferencias con el diseño original**:
- Constructor recibe un **registro de gateways** (array), no un gateway único
- `createOrder()` no registra pago — solo crea la orden
- `recordPayment()` es idempotente — retorna pago existente si ya hay uno pending
- Acepta `paymentMethodConfigId` (nullable para backward compatibility)
- Acepta `gatewayData` (sender fields específicos del método)
- `verifyPayment()` recibe `adminId` (int), no `User` + `string $reference`
- `cancelPayment()` acepta **ambos estados** (pending y verified), no solo verified

---

### 4.10 Validaciones de Pago

Las validaciones protegen el flujo de pago en múltiples capas:

#### Capa 1: Formato de Referencia (Controller/FormRequest)

```php
// Tenant\PaymentController::store()
'reference' => 'required|string|digits_between:6,10'
```

- Solo dígitos (6-10 caracteres)
- Aplicado en ambos controllers: `Tenant\PaymentController` y `Billing\PaymentController`

#### Capa 2: Referencia Duplicada (Controller — validación custom)

```php
function ($attribute, $value, $fail) {
    $exists = Payment::where('transaction_id', $value)->exists();
    if ($exists) {
        $fail('Esta referencia ya ha sido registrada por otro pago.');
    }
}
```

- **Cross-tenant**: Si Tenant A reporta referencia `123456`, Tenant B no puede usar la misma
- **Mismo tenant**: Un tenant no puede usar la misma referencia en dos órdenes diferentes
- **Razón**: Las referencias PagoMóvil son generadas por banco y pueden duplicarse entre bancos, pero para nuestra cuenta receptora solo una transacción puede tener esa referencia

#### Capa 3: Estado de la Orden (Controller — validación directa)

```php
if ($order->status !== OrderStatus::Pending) {
    return back()->withErrors([
        'order_id' => 'Solo se pueden reportar pagos para órdenes pendientes.',
    ]);
}
```

- Solo se aceptan referencias para órdenes en status `pending`
- Órdenes `paid`, `cancelled`, o `expired` rechazan el pago

#### Capa 4: Validación de Sender Fields (Controller)

**Pago Móvil** — campos requeridos del tenant:
```php
'sender_bank'   => 'required|string|max:100',   // Banco desde donde pagó
'sender_phone'  => 'required|string|max:20',    // Teléfono del tenant
'sender_id'     => 'required|string|max:20',    // Cédula/RIF del que pagó
'payment_date'  => 'required|date|before_or_equal:today',
'concept'       => 'nullable|string|max:255',
```

**Transferencia Bancaria** — campos requeridos del tenant:
```php
'sender_bank'   => 'required|string|max:100',   // Banco de origen
'sender_name'   => 'required|string|max:100',   // Nombre del titular
'sender_id'     => 'required|string|max:20',    // Cédula/RIF del titular
'sender_account_number' => 'nullable|string|max:20', // N° cuenta origen
'tenant_rif'    => 'nullable|string|max:20',    // RIF del cliente del servicio
'payment_date'  => 'required|date|before_or_equal:today',
'concept'       => 'nullable|string|max:255',
```

#### Capa 5: Constraint de Base de Datos (Migration)

```sql
UNIQUE(transaction_id) ON payments
```

- Última línea de defensa contra duplicados
- Captura race conditions que la validación a nivel de aplicación no puede prevenir
- Si se viola, Laravel lanza `UniqueConstraintViolationException`

#### Flujo de Validación

```
Request → Formato (digits_between:6,10)
       → Referencia duplicada (Query Payment::where)
       → Estado de la orden (Order::status === Pending)
       → Sender fields (required/nullable según método)
       → payment_method_config_id (exists en payment_method_configs)
       → Crear Payment + Detail (DB transaction)
       → UNIQUE constraint (DB-level fallback)
```

---

### 4.11 Frontend — Páginas de Pago

Las páginas frontend manejan el flujo completo de pago desde la perspectiva del tenant y del admin.

#### Tenant: `billing/orders/show.tsx` — Formulario de Reporte de Pago

**Propósito**: Permite al tenant reportar un pago existente con sus datos de emisión (sender fields).

**Responsabilidades**:
- Muestra el detalle de la orden (monto total, pagos acumulados, estado)
- Selector de método de pago (pago_movil / bank_transfer)
- Selector de cuenta receptora (PaymentMethodConfig filtrado por tipo)
- **Formulario de sender fields** — varía según método seleccionado:
  - Pago Móvil: `sender_id` (Cédula/RIF)
  - Transferencia Bancaria: `sender_bank`, `sender_name`, `sender_id`, `sender_account_number`, `tenant_rif`, `payment_date`, `concept`
- Envía POST a `POST /billing/orders/{order}/payments`
- Muestra estado de pagos existentes con `PaymentStatusBadge`

**Sender fields — Estado del formulario**:
```typescript
// Pago Móvil
const [senderId, setSenderId] = useState('');

// Transferencia Bancaria
const [senderBank, setSenderBank] = useState('');
const [senderName, setSenderName] = useState('');
const [senderId, setSenderId] = useState('');
const [tenantRif, setTenantRif] = useState('');
const [paymentDate, setPaymentDate] = useState('');
const [concept, setConcept] = useState('');
```

**Condición de deshabilitado**: El botón "Reportar Pago" se deshabilita cuando la orden no está en status `pending`.

#### Tenant: `billing/payment.tsx` — Estado del Pago

**Propósito**: Muestra el estado actual del pago, instrucciones de pago, y detalle de sender para pagos existentes.

**Responsabilidades**:
- Instrucciones de pago (cuenta receptora desde snapshot)
- Estado del pago (pending / verified / cancelled)
- **Detalle de sender** — sección que muestra los datos del tenant que reportó el pago:
  - Pago Móvil: banco emisor, teléfono, cédula/RIF, fecha, concepto
  - Transferencia Bancaria: banco emisor, nombre del titular, cédula/RIF, RIF del cliente, fecha, concepto
- Fecha formateada con `formatDate()` (no ISO raw)

#### Landlord Admin: `admin/payments/show.tsx` — Detalle de Pago

**Propósito**: Vista completa del pago para el admin que verifica. Muestra snapshot receiver + sender report.

**Responsabilidades**:
- Información core del pago (monto, moneda, método, estado, referencia)
- **Snapshot Receiver** — datos de la cuenta receptora al momento del pago:
  - Pago Móvil: teléfono, banco, RIF
  - Transferencia Bancaria: banco, cuenta, titular, RIF
- **Sender Report** — datos del tenant que reportó el pago:
  - Pago Móvil: banco emisor, teléfono, cédula/RIF, fecha, concepto
  - Transferencia Bancaria: banco emisor, nombre del titular, cédula/RIF, RIF del cliente, fecha, concepto
- Acciones: Verificar / Cancelar (con razón)
- Fecha formateada con `formatDate()` (no ISO raw)

**Eager Loading requerido**: El controller `Landlord\PaymentController::show()` debe eager-load `pagoMovilDetail` y `bankTransferDetail` para evitar lazy loading.

---

## 5. Escenarios de Uso

### 5.1 Pago Simple — Pago Móvil (Monto Exacto)

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires=48h
3. Tenant selecciona método: Pago Móvil
4. PaymentService resuelve PagoMovilGateway
5. Payment #1: amount=100, currency=VES, method=pago_movil, status=pending
6. PagoMovilDetail: phone, bank, rif (de config)
7. Tenant envía $100 Pago Móvil
8. Admin verifica Payment #1
9. PaymentVerified → ActivateSubscription
10. Order #1: paid_cents=100, status=paid
11. Subscription: active
```

### 5.2 Pago Simple — Transferencia Bancaria

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires=48h
3. Tenant selecciona método: Transferencia Bancaria
4. PaymentService resuelve BankTransferGateway
5. Payment #1: amount=100, currency=VES, method=bank_transfer, status=pending
6. BankTransferDetail: account_number, bank_name, account_holder, holder_id
   (de PaymentMethodConfig seleccionado)
7. Tenant envía transferencia por $100
8. Admin verifica Payment #1
9. PaymentVerified → ActivateSubscription
10. Order #1: paid_cents=100, status=paid
11. Subscription: active
```

### 5.3 Acumulación de Pagos (Múltiples Métodos)

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires=48h

3. Tenant envía $80 vía Pago Móvil
4. Payment #1: amount=80, method=pago_movil, status=pending
5. Admin verifica Payment #1
6. Order #1: paid_cents=80, status=pending (falta $20)

7. Tenant envía $30 vía Transferencia Bancaria
8. Payment #2: amount=30, method=bank_transfer, status=pending
9. Admin verifica Payment #2
10. Order #1: paid_cents=110, status=paid
11. Excedente: $10 (registrado en metadata)
12. Subscription: active
```

### 5.4 Expiración de Order

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires_at=48h
3. Tenant NO envía pago
4. Command ejecuta: orders:expire (hourly)
5. Order #1: status=expired
6. Tenant notificado (OrderExpired)
```

### 5.5 Cancelación de Pago

```
1. Tenant envía $100 vía Pago Móvil
2. Payment #1: amount=100, status=pending
3. Admin verifica → detecta fraude
4. Payment #1: status=cancelled, cancellation_reason="Fraude detectado"
5. Order #1: paid_cents=0, status=pending
```

### 5.6 Cancelación de Pago Pending

```
1. Tenant envía $100 vía Transferencia Bancaria
2. Payment #1: amount=100, status=pending
3. Admin cancela antes de verificar (error del tenant)
4. Payment #1: status=cancelled, cancellation_reason="Monto incorrecto"
5. Order #1: paid_cents=0, status=pending
6. Tenant puede crear nuevo pago
```

### 5.7 Tenant Cambia de Plan

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, type=plan
3. Tenant envía $80 vía Pago Móvil
4. Payment #1: amount=80, status=pending

5. ANTES de verificar, tenant cambia a Plan Básico ($50)
6. Order #1: status=cancelled (automáticamente por regla de 1 plan)
7. Order #2: total=50, status=pending, type=plan
8. Payment #1 sigue pendiente pero aplica a Order #1 cancelada
9. Admin verifica → Payment #1 se cancela automáticamente
10. Tenant crea nuevo pago para Order #2
```

### 5.8 Tenant Compra Múltiples Resources

```
1. Tenant compra Resource A ($20)
2. Order #1: total=20, status=pending, type=resource

3. Tenant compra Resource B ($30)
4. Order #2: total=30, status=pending, type=resource
5. AMBAS orders coexisten (no se cancelan)

6. Admin verifica Payment #1 (Order #1)
7. Order #1: paid_cents=20, status=paid
8. Resource A: activado

9. Admin verifica Payment #2 (Order #2)
10. Order #2: paid_cents=30, status=paid
11. Resource B: activado
```

### 5.9 Selección de Método de Pago (Nuevo)

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires=48h
3. Frontend muestra selector de métodos de pago
4. Tenant ve cuentas disponibles:
   - Pago Móvil: Bancaracas (0412-1234567)
   - Transferencia: Mercantil (0134-0000-00-0000000000)
   - Transferencia: Banesco (0134-0000-00-0000000000)
5. Tenant selecciona "Transferencia Bancaria — Mercantil"
6. PaymentService resuelve BankTransferGateway con datos de PaymentMethodConfig
7. Payment #1: method=bank_transfer, BankTransferDetail con datos de la cuenta seleccionada
8. Muestra instrucciones de transferencia
```

---

## 6. Trade-offs y Decisiones de Diseño

### 6.1 Supertipo/Subtipo vs Otras Alternativas

| Aspecto | Supertipo/Subtipo | God Table (Columnas Null) | JSONB + DTOs |
|---------|-------------------|---------------------------|--------------|
| **Integridad Referencial** | ✅ Perfecta (FK reales) | ✅ Sí | ❌ No |
| **Restricciones NOT NULL** | ✅ Sí, estrictas en subtipo | ❌ Imposible, dependen de CHECKs | ❌ No, se validan en app |
| **Cero Framework Lock-in** | ✅ 100% agnóstico | ✅ Sí | ✅ Sí |
| **Escalabilidad del Schema** | ✅ Limpio (nueva tabla) | ❌ Caótico (tabla ancha) | ✅ Limpio |
| **Performance de Query** | ⚠️ Requiere JOINs o Eager Loading | ✅ Lectura directa | ✅ Rápido con GIN |

**Decisión Definitiva**: Supertipo/Subtipo (Table-per-Type). Es la arquitectura más robusta para información financiera.

**Mitigación de Contras**: El costo de rendimiento de los JOINs se mitiga mediante Eager Loading estratégico (`Payment::with('pagoMovilDetail')` o `Payment::with('bankTransferDetail')`) en listings.

### 6.2 Accessor vs Campo Hardcodeado

| Aspecto | Accessor | Campo Hardcodeado |
|---------|----------|-------------------|
| Consistencia | ✅ Siempre correcto | ⚠️ Riesgo de desincronización |
| Performance | ⚠️ Agregación en query | ✅ Lectura directa |
| Complejidad | ✅ Simple | ⚠️ Requiere sync |

**Decisión**: Accessor. Para 1-5 pagos por Order, la agregación es trivial. La consistencia financiera es crítica.

### 6.3 Order + Payment Separados vs Unificados

| Aspecto | Separados | Unificados |
|---------|-----------|------------|
| Responsabilidades | ✅ Claras | ❌ Mezcladas |
| Múltiples pagos | ✅ Natural | ⚠️ Complejo |
| Reporting | ✅ Flexibles | ⚠️ Limitado |
| Complejidad | ⚠️ 2 tablas | ✅ 1 tabla |

**Decisión**: Separados. La distinción "qué quieres comprar" vs "cómo pagaste" es fundamental en dominio de pagos.

### 6.4 Múltiples Orders Pendientes vs Una Sola

| Aspecto | Una sola (Plan) | Múltiples (Resources) |
|---------|-----------------|----------------------|
| UX | Simple, sin confusión | Flexible, permite compras simultáneas |
| Complejidad | ✅ Baja | ⚠️ Media |
| Regla de negocio | Correcta para suscripciones | Correcta para add-ons |

**Decisión**: Híbrido. Plans = 1 order pendiente (solo 1 suscripción). Resources = múltiples orders (compras independientes).

**Justificación**: Un tenant solo puede tener 1 suscripción activa, pero puede comprar múltiples resources. La regla se aplica verificando `plan_id IS NOT NULL`.

### 6.5 Exclusive Arcs vs Polimorfismo en Orders

| Aspecto | Exclusive Arcs | Polimorfismo (buyable_id/type) |
|---------|----------------|-------------------------------|
| Foreign Keys | ✅ Reales | ❌ No |
| Framework Lock-in | ✅ Ninguno | ❌ Laravel específico |
| Queryability | ✅ JOINs directos | ⚠️ Necesita saber tipo primero |
| Escalabilidad | ⚠️ Nueva columna por tipo | ✅ Solo agregar al morph map |
| Integridad | ✅ CHECK constraint | ❌ Sin validación en DB |

**Decisión**: Exclusive Arcs. Es consistente con el patrón Supertipo/Subtipo usado en Payments.

**Mitigación**: Al agregar un nuevo tipo de compra, se necesita migración para agregar columna + actualizar CHECK constraint. Esto es aceptable porque los tipos de compra son pocos y conocidos.

### 6.6 Gateway Registry vs Gateway Único

| Aspecto | Gateway Registry | Gateway Único |
|---------|------------------|---------------|
| Extensibilidad | ✅ Agregar método = nuevo gateway + binding | ❌ Modificar gateway existente |
| Separación de concerns | ✅ Cada gateway es independiente | ❌ Condiciones en un solo gateway |
| Testabilidad | ✅ Mockear gateway específico | ⚠️ Mockear lógica condicional |
| Complejidad | ⚠️ Registry + resolución | ✅ Simple |

**Decisión**: Gateway Registry. Con la adición de Transferencia Bancaria, tener un gateway por método es más limpio que un monolito con condiciones.

### 6.7 PaymentMethodConfig (DB) vs Config File

| Aspecto | DB (PaymentMethodConfig) | Config File |
|---------|--------------------------|-------------|
| Modificabilidad | ✅ Sin deploy | ❌ Requiere deploy |
| Multi-tenancy | ✅ Cada tenant puede tener sus cuentas | ❌ Global |
| Auditoría | ✅ timestamps + historial | ❌ Sin historial |
| Complejidad | ⚠️ Tabla + modelo adicional | ✅ Simple |

**Decisión**: DB (PaymentMethodConfig). La flexibilidad de modificar cuentas sin deploy y soportar múltiples cuentas por tipo justifica la complejidad adicional.

---

## 7. Riesgos

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Verificación manual crea delay inherente | Alta | Medio | UX claro de pending state |
| Concurrent verification | Baja | Bajo | Transaction lock en verifyPayment |
| Duplicidad de referencias | Baja | Medio | UNIQUE constraint + app check |
| Excedente no manejado | Media | Bajo | Registrar en metadata, Phase 2B |
| Orders pendientes eternas | Media | Bajo | Expiración automática (48h) |
| Tenant cambia durante pending | Baja | Bajo | Cancelar orders anteriores |
| Cuentas de PaymentMethodConfig desactualizadas | Media | Medio | Flag is_active para deshabilitar sin borrar |
| Gateway registry no encontrado | Baja | Alto | Validación en resolveGateway() + tests |
| Múltiples cuentas del mismo tipo confunden al tenant | Media | Medio | UI con label claro, sort_order para priorizar |

### 7.1 Bug Fixes y Gotchas Descubiertos

Durante la implementación y testing manual se descubrieron los siguientes issues:

| Issue | Causa raíz | Fix |
|-------|------------|-----|
| **5 test failures pre-existentes** | `PagoMovilDetailFactory` no incluía `sender_bank`, `sender_phone`, `payment_date` — campos NOT NULL agregados por migración `2026_06_13_000004` | Actualizar factory con los 3 campos faltantes |
| **sender_id faltante en formulario de reporte** | `billing/orders/show.tsx` no tenía `senderId` state ni input field para pago_móvil | Agregar state, input, POST data, y disabled condition |
| **Admin payments show sin BankTransferDetail** | `Landlord\PaymentController::show()` no eager-loadaba `bankTransferDetail`, y el frontend no tenía tipo ni UI para el detalle | Agregar eager loading + tipo `BankTransferDetail` + card completa con Cuenta Destino + Datos del Emisor + Referencia |
| **Fechas en formato ISO raw** | `admin/payments/show.tsx` y `billing/payment.tsx` mostraban `created_at` como string ISO sin formatear | Reemplazar con `formatDate()` de `@/lib/utils` |
| **Billing/payment.tsx sin secciones de sender** | La página de estado del pago no mostraba los datos del emisor para pagos existentes | Agregar secciones condicionales para ambos métodos (pago_móvil y bank_transfer) |
| **Billing/orders/show.tsx sin sender fields para bank_transfer** | El formulario de reporte no incluía campos de emisor para transferencia bancaria | Agregar `senderBank`, `senderName`, `senderId`, `tenantRif`, `paymentDate`, `concept` + submit + disabled |

**Lección aprendida**: Los factories de tests deben actualizarse cuando se agregan columnas NOT NULL a tablas existentes. Siempre verificar que los factories incluyan todos los campos requeridos después de una migración.

---

## 8. Scope Phase 2A

### Incluido
- Order (con Exclusive Arcs: plan_id nullable + resource_id nullable + CHECK constraint)
- Payment (Supertipo) + PagoMovilDetail (Subtipo) con snapshot receiver + sender report
- Payment (Supertipo) + BankTransferDetail (Subtipo) con snapshot receiver + sender report
- PaymentGatewayInterface + PagoMovilGateway + BankTransferGateway
- Gateway Registry en PaymentService para resolver métodos de pago
- PaymentMethodConfig para cuentas receptoras configurables
- **`payment_method_config_id` en payments** — FK nullable para saber qué config se usó
- **Snapshot receiver** en detail tables — preserva datos al momento del pago (inmutables)
- **Sender fields** — datos del tenant que reporta su pago (sender_bank, sender_phone, sender_id, etc.)
- PaymentService orquestador con transacciones
- Order expiration (expires_at + command `orders:expire`)
- Subscription expiration (command `subscriptions:expire`)
- Rollback de verificación (cancelación con razón)
- Tenant_id denormalizado en Payment
- Regla de negocio: 1 order pendiente para Plans, múltiples para Resources
- Notificaciones: OrderExpired, PaymentVerified, PendingPaymentCreated
- Eventos: PaymentVerified → ActivateSubscription listener
- Validaciones: formato de referencia (6-10 dígitos), referencia duplicada (cross-tenant), estado de orden (pending), sender fields (requeridos según método)
- UNIQUE constraint en transaction_id (defensa a nivel DB)
- **Frontend — Tenant billing:**
  - `billing/orders/index.tsx` — listado de órdenes del tenant
  - `billing/orders/show.tsx` — detalle de orden + formulario de reporte de pago (sender fields para ambos métodos)
  - `billing/payment.tsx` — estado del pago, instrucciones, detalle de sender para ambos métodos
- **Frontend — Landlord admin:**
  - `admin/payments/show.tsx` — detalle de pago con snapshot receiver + sender report + BankTransferDetail completo
- Frontend: selector de método de pago, componentes de instrucciones por tipo
- Config: config/payment.php (gateway, expiry, pago_movil settings)

### Excluido (Phase 2B+)
- PayPal, Stripe u otros métodos de pago internacionales
- Reembolsos
- Reconciliación bancaria automatizada
- Proration
- Reintentos automáticos
- Webhooks de confirmación bancaria
- Pagos parciales con múltiples métodos en una sola transacción
- Soporte visual (captura de pantalla/PDF del comprobante bancario)
- Admin CRUD para gestión de PaymentMethodConfig (pendiente)

---

## 9. Criterios de Aceptación

- [x] Arquitectura relacional 1:1 estricta implementada (supertipo/subtipo en Payments)
- [x] Exclusive Arcs implementado en Orders (plan_id + resource_id + CHECK constraint)
- [x] Transacciones de DB aseguran que no haya pagos huérfanos sin detalles
- [x] Las consultas de listados de pagos incluyen Eager Loading para evitar problemas de N+1
- [x] Tenant puede seleccionar plan, elegir método de pago, ver instrucciones, enviar referencia
- [x] PaymentMethodConfig permite configurar cuentas receptoras sin cambios en código
- [x] PagoMovilGateway crea Payment + PagoMovilDetail atómicamente
- [x] BankTransferGateway crea Payment + BankTransferDetail atómicamente
- [x] PaymentService resuelve gateway según método de pago seleccionado
- [x] Admin puede verificar pago → PaymentVerified → ActivateSubscription
- [x] Admin puede cancelar pago pendiente o verificado con razón
- [x] Orders expiran después de 48h sin pago
- [x] Múltiples pagos acumulan correctamente (incluso mixtos: Pago Móvil + Transferencia)
- [x] Accessor paid_cents siempre retorna valor correcto
- [x] Para Plans: solo 1 order pendiente a la vez (las anteriores se cancelan)
- [x] Para Resources: múltiples orders pendientes permitidas
- [x] CHECK constraint garantiza exactamente un buyable por order
- [x] Agregar nuevo gateway requiere: implementar interfaz + crear tabla detalle + registrar binding
- [x] Agregar nuevo tipo de compra requiere: migración (nueva columna + actualizar CHECK)
- [x] Notificaciones: OrderExpired, PaymentVerified, PendingPaymentCreated funcionan correctamente
- [x] Validación: referencia debe ser 6-10 dígitos numéricos
- [x] Validación: referencia duplicada rechazada (mismo tenant y cross-tenant)
- [x] Validación: solo se aceptan pagos para órdenes en status pending
- [x] UNIQUE constraint en transaction_id previene duplicados a nivel DB
- [x] Frontend: selector de método de pago muestra cuentas activas filtradas por tipo
- [x] Frontend: billing/orders/show.tsx muestra sender fields para ambos métodos
- [x] Frontend: billing/payment.tsx muestra detalle de sender para ambos métodos
- [x] Frontend: admin/payments/show.tsx muestra BankTransferDetail completo con sender fields
- [x] Frontend: fechas formateadas con formatDate() en todas las páginas de pago
- [x] Todos los tests pasan (68/68)
- [x] Pint formatting limpio
- [x] Todo funciona manteniendo el aislamiento del tenant (Base de datos Landlord)
