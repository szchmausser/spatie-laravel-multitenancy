# Phase 2A: Payment Architecture — Documento de Arquitectura

## 1. Problema

### 1.1 Situación Actual
El sistema SaaS multi-tenant tiene:
- **Plan**: Define tier de servicio (price, features)
- **Subscription**: Vincula tenant con plan (status, dates)
- **Simulación de compra**: `BuyResourceDialog` crea entitlements directamente sin pago real

**No existe**: Flujo de pago real. Los tenants obtienen suscripciones sin pagar.

### 1.2 Necesidad de Negocio
- Pago Móvil es el método de pago principal en Venezuela
- Requiere verificación manual (admin verifica comprobante bancario)
- El dinero se debita del cliente INMEDIATAMENTE (no es "intento de pago")
- Se deben acumular múltiples pagos para cubrir el total de una orden
- Arquitectura debe ser extensible para futuros métodos de pago (Transferencias, PayPal, Stripe)

### 1.3 Restricciones
- Stack: Laravel 13.12 + PostgreSQL + Spatie Multitenancy
- Dual-database: landlord (central) + tenant (per-tenant)
- Pago Móvil es manual, no automatizado
- MVP: solo Pago Móvil, extensible a futuro

---

## 2. Propuesta

### 2.1 Enfoque: Strategy Pattern + Patrón Supertipo/Subtipo
Separar **Orden** (intención de compra) de **Pago** (transacción financiera real).
Para los métodos de pago, se usará el patrón arquitectónico **Supertipo/Subtipo** (también conocido como Table-per-Type o Reverse Polymorphism). La tabla `payments` guardará la información financiera core, y los detalles específicos de cada método vivirán en tablas separadas (ej. `pago_movil_details`) vinculadas mediante llaves foráneas estrictas (`payment_id`).

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
│  payment_method (discriminador: 'pago_movil', 'paypal')     │
│  status, transaction_id                                     │
│  verified_by, verified_at                                   │
│  cancellation_reason, cancelled_by, cancelled_at            │
│  metadata, created_at                                       │
│                                                             │
│  Methods:                                                   │
│  - order() → BelongsTo Order                                │
│  - verifier() → BelongsTo User (via verified_by)            │
│  - pagoMovilDetail() → HasOne PagoMovilDetail               │
│  - getDetailsAttribute() → Retorna el detalle correcto      │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ 1:1
                              ▼
┌─────────────────────────────────────────────────────────────┐
│             PagoMovilDetail (Subtipo)                       │
│  "Detalles específicos con constraints fuertes"             │
│  ─────────────────────────────────────────────────────────  │
│  payment_id (PK, FK a payments.id)                          │
│  phone (NOT NULL)                                           │
│  bank (NOT NULL)                                            │
│  rif (NOT NULL)                                             │
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
       │ 3. Crea Payment +  │                    │
       │    PagoMovilDetail │                    │
       │    (status: pending)                    │
       │                    │                    │
       │ 4. Muestra instrucciones Pago Móvil    │
       │<───────────────────│                    │
       │                    │                    │
       │ 5. Envía $80 Pago Móvil (banco real)   │
       │──── (off-system) ──│───────────────────>│
       │                    │                    │
       │ 6. Envía referencia │                   │
       │───────────────────>│                    │
       │                    │ 7. Lista pagos     │
       │                    │    pendientes      │
       │                    │<───────────────────│
       │                    │                    │
       │                    │ 8. Verifica pago   │
       │                    │───────────────────>│
       │                    │                    │
       │                    │ 9. Actualiza Order │
       │                    │    paid_cents += 80│
       │                    │                    │
       │ 10. Notificación   │                   │
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
| `status` | enum | NO | pending/paid/cancelled/expired | Estado de la order |
| `expires_at` | timestamp | NO | Cuándo expira | Orders sin pago deben expirar |
| `metadata` | json | SÍ | Datos adicionales flexibles | Información contextual no crítica |
| `created_at` | timestamp | NO | Creación | Audit trail |

**Constraint de Integridad (Exclusive Arcs)**:
```sql
-- Exactamente uno de los dos debe tener valor
ALTER TABLE orders ADD CONSTRAINT chk_exclusive_buyable 
CHECK (
    (plan_id IS NOT NULL AND resource_id IS NULL) OR 
    (plan_id IS NULL AND resource_id IS NOT NULL)
);
```

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
| `currency` | varchar(3) | NO | Código ISO moneda | USD por defecto, extensible |
| `payment_method` | varchar(50) | NO | Tipo de método de pago | Discriminador para lógica específica |
| `transaction_id` | varchar(255) | SÍ | Referencia/ID externo | ID del procesador o referencia bancaria |
| `status` | enum | NO | pending/verified/cancelled | Estado del pago |
| `verified_by` | bigint | SÍ | FK a users | Admin que verificó |
| `verified_at` | timestamp | SÍ | Cuándo se verificó | Audit trail |
| `cancellation_reason` | text | SÍ | Razón de cancelación | Si fue cancelado, por qué |
| `cancelled_by` | bigint | SÍ | FK a users | Admin que canceló |
| `cancelled_at` | timestamp | SÍ | Cuándo se canceló | Audit trail |
| `metadata` | json | SÍ | Datos contextuales | Información no crítica |
| `created_at` | timestamp | NO | Creación | Audit trail |

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

**Por Qué No `rejected`**: En Pago Móvil, el dinero YA se debita. No se "rechaza", se "cancela" (reembolsa manualmente).

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

public function pagoMovilDetail(): HasOne
{
    return $this->hasOne(PagoMovilDetail::class);
}

// Futuros: paypalDetail(), stripeDetail()

public function getDetailsAttribute()
{
    return match($this->payment_method) {
        'pago_movil' => $this->pagoMovilDetail,
        // 'paypal' => $this->paypalDetail,
        default => null,
    };
}
```

---

### 4.3 PagoMovilDetail (Tabla: `pago_movil_details`)

**Propósito**: Entidad de detalle estricta (Subtipo). Garantiza integridad relacional y validación estricta de base de datos.

#### Campos

| Campo | Tipo | Nullable | Descripción | Justificación |
|-------|------|----------|-------------|---------------|
| `payment_id` | bigint | NO | PK y FK a `payments.id` | Garantiza relación 1:1 estricta con borrado en cascada |
| `phone` | varchar(20) | NO | Teléfono destino | Constraint NOT NULL real |
| `bank` | varchar(100) | NO | Banco destino | Constraint NOT NULL real |
| `rif` | varchar(20) | NO | RIF destino | Constraint NOT NULL real |

#### Por Qué Tabla Separada (No JSON ni Columnas Nullable)

1. **Validación Estricta**: `NOT NULL` real en DB. Imposible insertar detalle sin teléfono.
2. **Cero Basura**: No hay columnas NULL para métodos que no usan esos campos.
3. **Escalabilidad**: Agregar PayPal = nueva tabla `paypal_details`, no tocar `payments`.
4. **Universal**: Cualquier ORM/lenguaje lo entiende. Sin lock-in.
5. **Integridad**: FK real con cascada. Si se borra el payment, se borra el detalle.

---

### 4.4 PaymentGatewayInterface (Contrato)

Define el contrato para procesar pagos independientemente del método.

```php
interface PaymentGatewayInterface
{
    /**
     * Crea un pago para una orden.
     */
    public function createPayment(CreatePaymentRequest $request): Payment;
    
    /**
     * Retorna instrucciones de pago para el frontend.
     */
    public function getInstructions(Payment $payment): array;
    
    /**
     * Valida si una referencia de pago es válida.
     */
    public function validateReference(string $reference): bool;
    
    /**
     * Verifica si un pago es válido para la orden.
     */
    public function validatePayment(Payment $payment, Order $order): bool;
}
```

**Por Qué Esta Interfaz**:

1. `createPayment()`: Cada método crea el pago de forma diferente
2. `getInstructions()`: Cada método tiene instrucciones diferentes
3. `validateReference()`: Cada método tiene formato de referencia diferente
4. `validatePayment()`: Cada método tiene reglas de validación diferentes

---

### 4.5 PagoMovilGateway (Implementación)

Usa transacciones para garantizar la inserción atómica del supertipo (`Payment`) y su subtipo (`PagoMovilDetail`).

```php
class PagoMovilGateway implements PaymentGatewayInterface
{
    public function createPayment(CreatePaymentRequest $request): Payment
    {
        return DB::transaction(function () use ($request) {
            $payment = Payment::create([
                'tenant_id' => $request->tenant_id,
                'order_id' => $request->order_id,
                'amount_cents' => $request->amount_cents,
                'currency' => 'USD',
                'payment_method' => 'pago_movil',
                'status' => PaymentStatus::Pending,
            ]);
            
            $payment->pagoMovilDetail()->create([
                'phone' => $request->phone,
                'bank' => $request->bank,
                'rif' => $request->rif,
            ]);
            
            return $payment;
        });
    }
    
    public function getInstructions(Payment $payment): array
    {
        $details = $payment->details; // Usa el accessor mágico
        
        return [
            'type' => 'pago_movil',
            'title' => 'Pago Móvil',
            'fields' => [
                ['label' => 'Teléfono', 'value' => $details->phone],
                ['label' => 'Banco', 'value' => $details->bank],
                ['label' => 'RIF', 'value' => $details->rif],
            ],
            'reference' => $payment->transaction_id,
            'amount' => $payment->amount_cents / 100,
        ];
    }
    
    public function validateReference(string $reference): bool
    {
        // Referencia Pago Móvil: 6-10 dígitos
        return preg_match('/^\d{6,10}$/', $reference);
    }
    
    public function validatePayment(Payment $payment, Order $order): bool
    {
        // Pago Móvil: monto debe ser exacto
        return $payment->amount_cents === $order->total_cents;
    }
}
```

---

### 4.6 PaymentService (Orquestador)

Orquesta el flujo de pago sin conocer detalles de cada método.

```php
class PaymentService
{
    public function __construct(
        private PaymentGatewayInterface $gateway
    ) {}
    
    public function initiatePayment(
        Tenant $tenant,
        Plan|Resource $buyable,
        string $method
    ): array {
        // 1. Regla de negocio: cancelar orders pendientes previas
        //    - Plans: solo 1 order pendiente a la vez
        //    - Resources: permitir múltiples orders pendientes
        if ($buyable instanceof Plan) {
            Order::where('tenant_id', $tenant->id)
                ->where('status', OrderStatus::Pending)
                ->whereNotNull('plan_id')
                ->update(['status' => OrderStatus::Cancelled]);
        }
        
        // 2. Crear Order con Exclusive Arcs
        $order = Order::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $buyable instanceof Plan ? $buyable->id : null,
            'resource_id' => $buyable instanceof Resource ? $buyable->id : null,
            'total_cents' => $buyable->price_cents,
            'status' => OrderStatus::Pending,
            'expires_at' => now()->addHours(48),
        ]);
        
        // 3. Delegar creación de pago al gateway
        $payment = $this->gateway->createPayment(new CreatePaymentRequest([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'amount_cents' => $buyable->price_cents,
        ]));
        
        // 4. Obtener instrucciones
        $instructions = $this->gateway->getInstructions($payment);
        
        return [
            'order' => $order,
            'payment' => $payment,
            'instructions' => $instructions,
        ];
    }
    
    public function verifyPayment(
        Payment $payment,
        string $reference,
        User $admin
    ): void {
        DB::transaction(function () use ($payment, $reference, $admin) {
            // 1. Verificar referencia
            if (!$this->gateway->validateReference($reference)) {
                throw new InvalidReferenceException();
            }
            
            // 2. Actualizar pago
            $payment->update([
                'status' => PaymentStatus::Verified,
                'transaction_id' => $reference,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);
            
            // 3. Aplicar a orden
            $this->applyPaymentToOrder($payment->order);
        });
    }
    
    public function cancelPayment(
        Payment $payment,
        string $reason,
        User $admin
    ): void {
        DB::transaction(function () use ($payment, $reason, $admin) {
            if ($payment->status !== PaymentStatus::Verified) {
                throw new InvalidPaymentStatusException();
            }
            
            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by' => $admin->id,
                'cancelled_at' => now(),
            ]);
            
            // Recalcular order
            $this->recalculateOrder($payment->order);
        });
    }
    
    private function applyPaymentToOrder(Order $order): void
    {
        // Recargar para obtener paid_cents actualizado
        $order->refresh();
        
        if ($order->isFullyPaid()) {
            $order->update(['status' => OrderStatus::Paid]);
            
            // Activar suscripción
            $this->activateSubscription($order);
        }
    }
    
    private function recalculateOrder(Order $order): void
    {
        $order->refresh();
        
        if (!$order->isFullyPaid() && $order->status === OrderStatus::Paid) {
            $order->update(['status' => OrderStatus::Pending]);
            
            // Desactivar suscripción
            $this->deactivateSubscription($order);
        }
    }
}
```

---

## 5. Escenarios de Uso

### 5.1 Pago Simple (Monto Exacto)

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires=48h
3. Payment #1: amount=100, status=pending
4. PagoMovilDetail: phone, bank, rif
5. Tenant envía $100 Pago Móvil
6. Admin verifica Payment #1
7. Order #1: paid_cents=100, status=paid
8. Subscription: active
```

### 5.2 Acumulación de Pagos

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending
3. Tenant envía $80 Pago Móvil
4. Payment #1: amount=80, status=pending
5. Admin verifica Payment #1
6. Order #1: paid_cents=80, status=pending (falta $20)

7. Tenant envía $30 Pago Móvil
8. Payment #2: amount=30, status=pending
9. Admin verifica Payment #2
10. Order #1: paid_cents=110, status=paid
11. Excedente: $10 (registrado en metadata)
12. Subscription: active
```

### 5.3 Expiración de Order

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, expires_at=48h
3. Tenant NO envía pago
4. Command ejecuta: orders:expire
5. Order #1: status=expired
6. Tenant notificado
```

### 5.4 Cancelación de Pago

```
1. Tenant envía $100 Pago Móvil
2. Payment #1: amount=100, status=pending
3. Admin verifica → detecta fraude
4. Payment #1: status=cancelled, cancellation_reason="Fraude detectado"
5. Order #1: paid_cents=0, status=pending
```

### 5.5 Tenant Cambia de Plan

```
1. Tenant selecciona Plan Premium ($100)
2. Order #1: total=100, status=pending, type=plan
3. Tenant envía $80 Pago Móvil
4. Payment #1: amount=80, status=pending

5. ANTES de verificar, tenant cambia a Plan Básico ($50)
6. Order #1: status=cancelled (automáticamente por regla de 1 plan)
7. Order #2: total=50, status=pending, type=plan
8. Payment #1 sigue pendiente pero aplica a Order #1 cancelada
9. Admin verifica → Payment #1 se cancela automáticamente
10. Tenant crea nuevo pago para Order #2
```

### 5.6 Tenant Compra Múltiples Resources

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

**Mitigación de Contras**: El costo de rendimiento de los JOINs se mitiga mediante Eager Loading estratégico (`Payment::with('pagoMovilDetail')`) al listings.

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

**Mitigación**: Al agregar un nuevo tipo de compra, se necesita migración para agregar columna + actualizar CHECK constraint. Esto es acceptable porque los tipos de compra son pocos y conocidos.

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

---

## 8. Scope Phase 2A

### Incluido
- Order (con Exclusive Arcs: plan_id nullable + resource_id nullable + CHECK constraint)
- Payment (Supertipo) + PagoMovilDetail (Subtipo)
- PaymentGatewayInterface + PagoMovilGateway
- PaymentService orquestador con transacciones
- Order expiration (expires_at + command)
- Rollback de verificación (cancelación con razón)
- Tenant_id denormalizado en Payment
- Regla de negocio: 1 order pendiente para Plans, múltiples para Resources

### Excluido (Phase 2B+)
- Múltiples métodos de pago (se crearán `paypal_details`, etc., en futuras fases)
- Notificaciones y automatizaciones
- Reembolsos
- Reconciliación bancaria
- Proration
- Reintentos automáticos

---

## 9. Criterios de Aceptación

- [ ] Arquitectura relacional 1:1 estricta implementada (supertipo/subtipo en Payments)
- [ ] Exclusive Arcs implementado en Orders (plan_id + resource_id + CHECK constraint)
- [ ] Transacciones de DB aseguran que no haya pagos huérfanos sin detalles
- [ ] Las consultas de listados de pagos incluyen Eager Loading para evitar problemas de N+1
- [ ] Tenant puede seleccionar plan, ver instrucciones Pago Móvil, enviar referencia
- [ ] Payment creado con status Pending + PagoMovilDetail creado atómicamente
- [ ] Admin puede verificar pago → Order acumula paid_cents via accessor
- [ ] Cuando paid_cents >= total_cents → Subscription active
- [ ] Orders expiran después de 48h sin pago
- [ ] Admin puede cancelar pago verificado con razón
- [ ] Múltiples pagos acumulan correctamente
- [ ] Accessor paid_cents siempre retorna valor correcto
- [ ] Para Plans: solo 1 order pendiente a la vez (las anteriores se cancelan)
- [ ] Para Resources: múltiples orders pendientes permitidas
- [ ] CHECK constraint garantiza exactamente un buyable por order
- [ ] Agregar nuevo gateway requiere: implementar interfaz + crear tabla detalle + registrar binding
- [ ] Agregar nuevo tipo de compra requiere: migración (nueva columna + actualizar CHECK)
- [ ] Todos los tests pasan
- [ ] Pint formatting limpio
- [ ] Todo funciona manteniendo el aislamiento del tenant (Base de datos Landlord)
