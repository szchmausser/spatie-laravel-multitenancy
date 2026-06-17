# Plan: Sistema de Conciliación Automática de PagoMóvil

## Visión General

Sistema que permite conciliar automáticamente pagos por PagoMóvil usando un teléfono Android dedicado que captura las notificaciones push del banco destino, las envía a Laravel, y un motor de matching las vincula con pagos reportados por clientes para activar el servicio sin intervención humana.

---

## Principios de Diseño

- **Pipeline único de aprobación**: tanto el flujo manual como el automático terminan llamando al mismo `PaymentService::verifyPayment()` que dispara `PaymentVerified` → `ActivateSubscription`. No hay caminos paralelos.
- **Datos inmutables**: los raw notifications nunca se modifican, solo se referencian.
- **Idempotencia desde el día 1**: hash determinista evita duplicados por reintentos de red.
- **Matching de dos factores**: reference_code normalizado + monto, nunca uno solo.
- **Rechazo con semántica explícita**: `CancellationType` enum distingue duplicado de fraude de otras cancelaciones, con routing distinto para alertas internas vs. notificación al cliente. `cancellation_reason` es texto libre para humanos.
- **FK directas, sin polimorfismo**: `payment_matches` usa `payment_id` FK directa a `payments`, no `morphs`. Integridad referencial real, agnóstico a framework.
- **Configuración centralizada**: toda la configuración vive en `system_configs` (tabla en DB). No hay `config/payment.php`. Una sola fuente de verdad, cacheada en application boot.
- **PaymentMethodConfig como única fuente**: `PagoMovilGateway` y `BankTransferGateway` siempre requieren un `PaymentMethodConfig` activo. Sin fallback a config file.

---

## Fase 0 — Simulador

### 0.1 Artisan Command: `php artisan simulate:payment-notification`

Comando que inserta filas directamente en `payment_notifications` raw (sin Android),
usando textos reales de cada banco para iterar el parser rápido.

```bash
php artisan simulate:payment-notification \
  --bank=banesco \
  --amount=15000 \
  --reference=001234567 \
  --sender-phone=04141234567
```

### 0.2 Database Seeder opcional

10-20 ejemplos representativos por banco destino, incluyendo edge cases
(montos con cero decimales, referencias cortas, espacios irregulares).

**Output**: filas en `payment_notifications` con `parse_status = pending`.

---

## Fase 1 — Backend: Migraciones y Modelos

### 1.0 Tabla `system_configs` (configuración centralizada — ÚNICA fuente de verdad)

```php
Schema::create('system_configs', function (Blueprint $table) {
    $table->id();
    $table->string('group');              // 'payment', 'reconciliation', 'devices'
    $table->string('key')->unique();      // 'order_expiry_hours', 'payment_expiry_hours', etc.
    $table->text('value');
    $table->string('type')->default('string'); // 'string', 'integer', 'boolean', 'json'
    $table->text('description')->nullable();
    $table->timestamps();

    $table->index('group');
});
```

**Registros iniciales (migración de `config/payment.php` + nuevos):**

| group | key | value | type | description | Origen |
|-------|-----|-------|------|-------------|--------|
| `payment` | `default_gateway` | `pago_movil` | string | Gateway por defecto | `config('payment.default')` |
| `payment` | `order_expiry_hours` | `48` | integer | Horas antes de expirar una order pending | `config('payment.order_expiry_hours')` |
| `payment` | `pago_movil_phone` | `04141234567` | string | Teléfono receptor PagoMóvil (fallback global) | `config('payment.pago_movil.phone')` |
| `payment` | `pago_movil_bank` | `Banco de Venezuela` | string | Banco receptor PagoMóvil (fallback global) | `config('payment.pago_movil.bank')` |
| `payment` | `pago_movil_rif` | `J-12345678-9` | string | RIF receptor PagoMóvil (fallback global) | `config('payment.pago_movil.rif')` |
| `reconciliation` | `payment_expiry_hours` | `72` | integer | Horas antes de cancelar un pago pending sin match | Nuevo |
| `reconciliation` | `shadow_mode_enabled` | `true` | boolean | Modo sombra: solo sugiere matches, no auto-aprueba | Nuevo |
| `reconciliation` | `auto_match_confidence` | `high` | string | Confianza mínima para auto-match: `high`, `medium`, `none` | Nuevo |
| `devices` | `heartbeat_interval_minutes` | `5` | integer | Intervalo esperado de heartbeat del dispositivo | Nuevo |
| `devices` | `heartbeat_timeout_minutes` | `10` | integer | Minutos sin heartbeat antes de alerta critical | Nuevo |

**Modelo `SystemConfig` (con cache):**

```php
class SystemConfig extends Model
{
    use UsesLandlordConnection;

    protected $fillable = ['group', 'key', 'value', 'type', 'description'];

    public function getValue(): string|int|bool|array
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => (bool) $this->value,
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            "system_config.{$key}",
            3600,
            function () use ($key, $default) {
                $config = static::where('key', $key)->first();
                return $config?->getValue() ?? $default;
            }
        );
    }

    public static function set(string $key, mixed $value, string $type = 'string'): static
    {
        $record = static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'type' => $type]
        );
        Cache::forget("system_config.{$key}");
        return $record;
    }

    public function save(array $options = []): bool
    {
        $result = parent::save($options);
        Cache::forget("system_config.{$this->key}");
        return $result;
    }
}
```

**Eliminar `config/payment.php`**: Ya no existe. Toda la configuración se lee de `SystemConfig::get()`.

### 1.1 Eliminar fallback de config en gateways

**`PagoMovilGateway` — Cambio:**

```php
// ANTES (lee de config file como fallback):
private function resolveReceivingAccount(?int $configId): array
{
    if ($configId) {
        $config = PaymentMethodConfig::findOrFail($configId);
        return ['phone' => $config->account_number, 'bank' => $config->bank_name, 'rif' => $config->holder_id];
    }
    return config('payment.pago_movil'); // ← fallback a config file
}

// DESPUÉS (siempre requiere PaymentMethodConfig):
private function resolveReceivingAccount(int $configId): array
{
    $config = PaymentMethodConfig::where('id', $configId)
        ->where('type', 'pago_movil')
        ->where('is_active', true)
        ->first();

    abort_if(!$config, 422, 'No hay cuenta PagoMóvil activa configurada.');

    return ['phone' => $config->account_number, 'bank' => $config->bank_name, 'rif' => $config->holder_id];
}
```

**`BankTransferGateway`**: Sin cambios — ya requiere `PaymentMethodConfig` explícito.

**Seeder obligatorio**: Al menos 1 cuenta `PaymentMethodConfig` por tipo (`pago_movil`, `bank_transfer`) al iniciar el sistema.

### 1.2 Tabla `devices` (autenticación de teléfonos)

```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->string('name');                   // ej. 'Telefono Banesco Principal'
    $table->string('bank_code');              // banco destino asociado
    $table->string('token', 64)->unique();    // token revocable (random, 64 chars)
    $table->timestamp('last_heartbeat_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

Cada request de la app Android (notificaciones y heartbeat) debe incluir:
- `X-Device-Token` header o `device_token` en el body
- El endpoint valida el token contra la tabla `devices` y rechaza con 401 si es inválido o está inactivo

### 1.3 Tabla `payment_notifications` (inmutable)

```php
Schema::create('payment_notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete(); // FK directa a devices
    $table->string('bank_code');            // ej. 'banesco', 'mercantil'
    $table->text('raw_title');              // título original de la notificación
    $table->text('raw_body');               // cuerpo original de la notificación
    $table->string('dedup_hash')->unique(); // SHA256(title + body + truncated_at)
    $table->timestamp('received_at');       // timestamp del teléfono
    $table->string('parse_status')->default('pending'); // pending | parsed | failed
    $table->timestamps();

    $table->index('bank_code');
    $table->index('parse_status');
});
```

**Nota**: Se usa `foreignId('device_id')->constrained('devices')` en vez de `string('device_id')` para mantener integridad referencial. El `device_token` se valida en el controller antes de resolver el `device_id`.

### 1.4 Model `PaymentNotification` (read-only, sin `protected $fillable` pública)

Muta solo `parse_status` interno. Los `raw_*` no se tocan jamás.

### 1.5 Tabla `payment_matches` (resultado del parseo + matching)

```php
Schema::create('payment_matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_notification_id')->constrained()->cascadeOnDelete();
    $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete(); // FK directa, sin morphs
    $table->string('parsed_reference')->nullable();      // reference_code extraído (normalizado)
    $table->integer('parsed_amount_cents');
    $table->string('parsed_sender_phone_last4')->nullable();
    $table->string('match_status'); // pending | matched | unmatched | duplicate_attempt
    $table->string('match_confidence'); // high | medium | manual
    $table->timestamp('matched_at')->nullable();
    $table->timestamps();

    $table->index('match_status');
    $table->index('payment_id');
});
```

**Por qué FK directa en vez de morphs:**
- `matchable` solo puede ser `Payment` — no hay otros tipos
- FK real = integridad referencial a nivel DB
- Si otro stack (Go, Node, Python) necesita leer estos datos, entiende una FK, no un morph de Laravel
- Consistente con la arquitectura Supertipo/Subtipo ya establecida

### 1.6 Enum `CancellationType`

```php
enum CancellationType: string {
    case Manual          = 'manual';           // Admin cancela desde UI
    case SystemDuplicate = 'system_duplicate'; // Sistema detecta referencia duplicada
    case SystemExpired   = 'system_expired';   // Sistema expira pago viejo
    case MethodChanged   = 'method_changed';   // Tenant cambia de método de pago
}
```

**Nota**: `cancellation_reason` se queda como `TEXT` libre — el admin o el sistema escriben lo que sea. `CancellationType` es para routing de notificaciones y lógica de negocio, no para reemplazar la razón.

### 1.7 Extender `Payment` model

**Nueva columna** `cancellation_type` — migración separada:

```php
Schema::table('payments', function (Blueprint $table) {
    $table->string('cancellation_type')->nullable()->after('cancellation_reason');
});
```

`cancellation_reason` se queda como `TEXT` nullable sin cast — es texto libre para humanos.

**Cast y relaciones a agregar:**

```php
// En Payment model — agregar a $casts:
'cancellation_type' => CancellationType::class,

// Agregar relación:
public function paymentMatch(): HasOne
{
    return $this->hasOne(PaymentMatch::class);
}
```

### 1.8 Migrar `PaymentService::createOrder()` para leer expiración de `system_configs`

```php
// ANTES:
'order expiry_hours' => config('payment.order_expiry_hours', 48),  // hardcodeado en config file

// DESPUÉS:
'order expiry_hours' => SystemConfig::get('payment.order_expiry_hours', 48),  // lee de DB cacheada
```

### 1.9 Migrar comando `ExpireOrders` para leer de `system_configs`

```php
// ANTES:
// Lee de config() o hardcodeado

// DESPUÉS:
$expiryHours = SystemConfig::get('payment.order_expiry_hours', 48);
```

---

## Fase 2 — Backend: Parser por Banco

### 2.1 Interface `BankNotificationParser`

```php
interface BankNotificationParser {
    public function canParse(string $bankCode): bool;
    public function parse(string $title, string $body): ParsedPayment;
}
```

### 2.2 Implementación por banco destino

- `BanescoParser`
- `MercantilParser`
- etc.

`ParsedPayment` = DTO con:
- `amount_cents: int`
- `reference: ?string` (normalizado: trim, uppercase, sin separadores)
- `sender_phone_last4: ?string`
- `confidence: float` (qué tan seguro está el parser de los datos extraídos)

### 2.3 Parser Service

```php
class PaymentNotificationParser {
    public function __construct(private array $parsers) {}
    public function parse(PaymentNotification $notification): ParsedPayment;
}
```

### 2.4 Test Suite

- PHPUnit con ejemplos reales por banco (anonimizados)
- Al menos 10 casos por banco incluyendo edge cases

---

## Fase 3 — Backend: Job de Parsing

### 3.1 Orquestador `IngestPaymentNotification` (job único, no eventos Eloquent)

En vez de escuchar `eloquent.created` (frágil para backfill), el endpoint de ingesta
hace dispatch explícito de un solo job orquestador:

```php
// En el controller/endpoint de ingesta:
IngestPaymentNotification::dispatch($notification);
```

```php
class IngestPaymentNotification implements ShouldQueue {
    public function handle(): void {
        DB::transaction(function () {
            ParsedPayment $parsed = app(PaymentNotificationParser::class)->parse($this->notification);

            $match = PaymentMatch::createFromParsed($this->notification, $parsed);

            app(ReconciliationOrchestrator::class)->run($match);
        });
    }
}
```

**Nota**: la creación del `PaymentMatch` + la llamada al orquestador están envueltas en
una transacción. Si el job falla a mitad de camino, Laravel reintenta el job completo.

### 3.2 Comando de backfill

```bash
php artisan reconciliation:reprocess --parse-status=failed
```

Itera sobre `PaymentNotification` con `parse_status = failed`, y dispacha `IngestPaymentNotification`
para cada una. Usa exactamente el mismo job que el flujo normal — cero lógica duplicada.

---

## Fase 4 — Backend: Motor de Matching

### 4.1 Job `ReconciliationOrchestrator` (invocado por `IngestPaymentNotification`, nunca por eventos Eloquent)

Lógica de matching en ORDEN ESTRICTO:

**Paso 0 — Validación de duplicado (CORRE PRIMERO, SIEMPRE)**:
Si `parsed_reference` no es null:
  - Buscar `Payment` con:
    - `status = Verified`
    - `transaction_id` normalizado = `parsed_reference` (ya existe en payments, NO en PagoMovilDetail)
  - Si existe → **es duplicado**:
    - Marcar `PaymentMatch.match_status = duplicate_attempt`
    - Buscar el `Payment` pendiente que intentó reusar el código
      (el que tiene `status = Pending` y el mismo `transaction_id`)
    - Llamar `PaymentService::cancelPayment($attemptingPayment, CancellationType::SystemDuplicate, 'system', 'Referencia ya verificada')`
      — esto cancela el **nuevo** (el pendiente), no el ya verificado
    - Salir del job (no continuar a matching normal)

**Paso 1 — Matching normal** (solo si no hubo duplicado):
Buscar `Payment` con:
  - `status = Pending`
  - `amount_cents = parsed_amount_cents`
  - `transaction_id` normalizado = `parsed_reference` (exacto o por sufijo según banco)
  - Sin otro `PaymentMatch` ya vinculado (`payment_id` IS NULL)

**Paso 2**: Si hay UN solo candidato → **Match de alta confianza**:
  - Set `PaymentMatch.payment_id = Payment.id`, `match_status = matched`, `matched_at = now()`
  - **Guard de estado**: Verificar `$payment->status === PaymentStatus::Pending` ANTES de continuar
    - Si NO es Pending (fue verificado manualmente mientras tanto): `match_status = unmatched`, salir
  - Lee `SystemConfig::get('reconciliation.shadow_mode_enabled', true)`
  - Si shadow mode OFF y `match_confidence = high`:
    - Llama `PaymentService::verifyPayment($payment, null)` (adminId null = automático)
  - Si shadow mode ON:
    - `match_status = pending` (solo sugerencia, espera confirmación manual)

**Paso 3**: Si hay MÚLTIPLES candidatos → **Match manual**:
  - `match_status = pending` (cola de revisión)
  - Crea notificación tipo `info` para el admin con los candidatos sugeridos

**Paso 4**: Si NO hay candidatos → **No match**:
  - `match_status = unmatched`
  - Crea notificación tipo `info` para el admin con "Pago recibido sin identificar"

### 4.2 Commando programado: expiración de pagos pendientes viejos

```bash
php artisan payments:expire-pending
```

(Scheduled en `routes/console.php` cada hora)

- Lee `SystemConfig::get('reconciliation.payment_expiry_hours', 72)` como fuente de verdad
- Busca `Payment` con `status = Pending` y `created_at` mayor al valor configurado
- Los marca como `Cancelled` con `cancellation_type = CancellationType::SystemExpired`
- Dispara `PaymentCancelled` con `type = CancellationType::SystemExpired`
- Esto evita acumulación de registros huérfanos y cierra el ciclo de vida del `CancellationType::SystemExpired`

---

## Fase 5 — Backend: Eventos y Notificaciones

### 5.1 Modificar `PaymentService::verifyPayment()`

**Estado actual**: `verifyPayment(Payment $payment, int $adminId)` — requiere adminId, valida status=Pending.

**Cambio**: Cambiar firma a:
```php
public function verifyPayment(Payment $payment, ?int $adminId = null): void
```

Cuando `adminId` es null, el pago fue verificado automáticamente (por el reconciliador).

**En la UI**: Cuando `verified_by` es null y el payment tiene un `PaymentMatch` vinculado,
mostrar "Verificado automáticamente" en lugar de un nombre.

### 5.2 Crear evento `PaymentCancelled` (NUEVO — no existe hoy)

```php
class PaymentCancelled {
    public function __construct(
        public readonly Payment $payment,
        public readonly CancellationType $type,
        public readonly ?string $reason = null,
    ) {}
}
```

**Nota**: Este evento no existe actualmente. Cuando se cancela un pago hoy, solo se actualiza el registro en BD sin disparar nada.

**Quién lo dispara**: Solo `PaymentService::cancelPayment()`. El update directo en `recordPayment()` (cambio de método) NO lo dispara — es una acción legítima del usuario que no necesita alertar a nadie.

### 5.3 Modificar `PaymentService::cancelPayment()`

**Estado actual**: `cancelPayment(Payment $payment, string $reason, int $adminId)` — toma string, no enum.

**Cambio**: Migrar a:
```php
public function cancelPayment(
    Payment $payment,
    CancellationType $type,
    int|string $actorId, // int para admin, string 'system' para automático
    ?string $reason = null,
): void
```

**Cambios internos**:
- `$reason` de `string` a `CancellationType $type` (enum para routing)
- Nuevo parámetro `?string $reason = null` (texto libre para humanos)
- `$adminId` pasa de `int` a `int|string` (acepta 'system' para automatizados)
- Agregar `event(new PaymentCancelled($payment, $type, $reason))` al final

**Importante**: en el escenario de duplicado, quien invoca esto debe pasar
el `$payment` que es el **intento nuevo** (el `status = Pending` que intentó reusar
un código ya verificado), **NO** el pago legítimo ya verificado.
La variable debe nombrarse explícitamente `$attemptingPayment` en el código de matching
para evitar ambigüedad.

**Callers existentes que se actualizan:**

| Caller | Cambio |
|--------|--------|
| `Landlord\PaymentController::cancel()` | Recibe `cancellation_type = 'manual'` del form (hidden input) + `reason` como texto libre |
| `PaymentService::recordPayment()` | **NO pasa por `cancelPayment()`** — sigue haciendo update directo. No se dispara evento porque es una acción legítima del usuario (cambio de método) que no necesita alertar a nadie |

### 5.4 Crear listener `NotifyPaymentRejected` (NUEVO)

Reutiliza el sistema de notificaciones existente (no crea tabla `system_alerts`):

```php
class NotifyPaymentRejected {
    public function handle(PaymentCancelled $event): void
    {
        match ($event->reason) {
            CancellationType::SystemDuplicate => $this->handleDuplicateFraud($event),
            default => $this->handleNormalRejection($event),
        };
    }

    private function handleDuplicateFraud(PaymentCancelled $event): void
    {
        // 1. Notificar al tenant (pago rechazado con motivo) via notificación existente
        // 2. Notificar a landlord admins via notificación existente (canal database + mail)
    }

    private function handleNormalRejection(PaymentCancelled $event): void
    {
        // Solo notificar al tenant
    }
}
```

**Canales**: Se usan los mismos canales `database` + `mail` que ya usa el sistema.
Las notificaciones de tenant se guardan en la tabla `notifications` del tenant.
Las notificaciones de landlord se guardan en la tabla `notifications` de landlord.
No se crea `system_alerts`.

---

## Fase 6 — Backend: Dashboard de Alertas (Inertia)

### 6.1 Ruta y Controller

```
GET /landlord/alerts → Landlord\AlertController::index()
POST /landlord/alerts/{notification}/read
```

### 6.2 Página Inertia

- Lista de notificaciones no leídas del admin logueado (canal `database`)
- Badge en el navbar del panel landlord con conteo de no leídas
- Botón para marcar como leída
- Filtro por tipo (critical, warning, info)
- Reusa el sistema de notificaciones de Laravel — no tabla paralela

---

## Fase 7 — App Android

### 7.1 NotificationListenerService

- Filtro por `packageName` exacto de apps bancarias destino
- Extrae título + body
- Genera hash: `SHA256(bank_code + raw_body + received_at_truncado_a_minuto)`
- Guarda en SQLite local inmediatamente (buffer de seguridad)
- Intenta POST a endpoint Laravel con reintentos exponenciales
- Si éxito (HTTP 200): marca `delivered = true` en SQLite
- Si falla: queda en cola local para reintento

### 7.2 Almacenamiento local SQLite (en el teléfono)

```sql
CREATE TABLE notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dedup_hash TEXT UNIQUE,
    bank_code TEXT,
    device_token TEXT,       -- copia local del token para autenticación
    raw_title TEXT,
    raw_body TEXT,
    received_at TEXT,
    delivered INTEGER DEFAULT 0,
    retry_count INTEGER DEFAULT 0,
    created_at TEXT
);
```

### 7.3 Heartbeat

- Cada N minutos (configurado en `SystemConfig::get('devices.heartbeat_interval_minutes')`): `POST /api/device/heartbeat` con `device_token`, `battery_level`, `notifications_pending_count`
- Backend busca el `device` por token, actualiza `last_heartbeat_at`
- Si un dispositivo no hace heartbeat en > M minutos (configurado en `SystemConfig::get('devices.heartbeat_timeout_minutes')`) → notificación `critical` al admin

### 7.4 Modo Simulación (end-to-end)

- Botón en la app que genera una notificación local fake con textos reales configurados
- Prueba el pipeline completo: NotificationManager → NotificationListenerService → SQLite → POST → Laravel

---

## Fase 8 — Transición

### 8.1 Modo Sombra (Shadow Mode)

Controlado por `SystemConfig::get('reconciliation.shadow_mode_enabled')`.

Durante la primera semana:
- El sistema corre completo pero **no auto-aprueba** pagos
- Cada match potencial se registra en `payment_matches` con `match_status = pending`
- Dashboard de admin muestra sugerencias de match: "Este pago de Bs. 150 del cliente X coincide con una notificación recibida. ¿Confirmar?"
- Se compara la tasa de acierto del motor contra las decisiones manuales

### 8.2 Activación Gradual

1. Cambiar `reconciliation.shadow_mode_enabled` a `false`
2. Cambiar `reconciliation.auto_match_confidence` a `high` (monto exacto + reference_code exacto)
3. Monitorear tasa de falsos positivos durante 1-2 semanas
4. Si tasa < 1%: cambiar `auto_match_confidence` a `medium` (monto exacto + sufijo de referencia)
5. Revisión manual para todo lo que no sea `high` o `medium`

---

## Tabla Resumen: Cambios a Código Existente

| Archivo | Cambio | ¿Existe hoy? |
|---------|--------|---------------|
| `app/Services/Payment/PaymentService.php` | `verifyPayment()`: `int $adminId` → `?int $adminId = null` | ✅ Método existe |
| `app/Services/Payment/PaymentService.php` | `cancelPayment()`: migrar de `string $reason` a `CancellationType $type` + `int $adminId` a `int|string $actorId` + nuevo param `?string $reason` + disparar `PaymentCancelled` event. `recordPayment()` se queda con update directo (sin evento) para cambio de método | ✅ Método existe, cambio de firma |
| `app/Services/Payment/PaymentService.php` | `createOrder()`: leer `order_expiry_hours` de `SystemConfig::get()` en vez de `config()` | ✅ Método existe |
| `app/Services/Payment/PagoMovilGateway.php` | Eliminar fallback a `config('payment.pago_movil')` — siempre requerir `PaymentMethodConfig` | ✅ Gateway existe |
| `app/Models/Payment.php` | Agregar cast `cancellation_type => CancellationType::class` + nueva columna `cancellation_type` + relación `paymentMatch()` | ✅ Modelo existe, nueva migración |
| `app/Console/Commands/ExpireOrders.php` | Leer expiración de `SystemConfig::get()` en vez de config file | ✅ Comando existe |
| `config/payment.php` | **ELIMINAR COMPLETAMENTE** — toda config migra a `system_configs` | ✅ Archivo existe, se borra |
| `app/Events/PaymentCancelled.php` | **CREAR** — nuevo evento | ❌ No existe |
| `app/Listeners/NotifyPaymentRejected.php` | **CREAR** — nuevo listener | ❌ No existe |

---

## Dependencias entre Fases

| Fase | Depende de | Esfuerzo | Riesgo |
|------|-----------|----------|--------|
| 0. Simulador | nada | Bajo | Bajo |
| 1. Migraciones/Modelos (incl. `devices`, `system_configs`, eliminar `config/payment.php`) | 0 | Medio-Alto | Medio (migrar config existente) |
| 2. Parser por banco | 0, 1 | Medio | Medio (cambios de formato) |
| 3. Job de parsing + orquestador | 1, 2 | Medio | Bajo |
| 4. Motor de matching | 3, **5.1** | Medio | Alto (lógica de negocio) |
| 5.1 verifyPayment nullable | 1 | Bajo | Bajo | ← prerequisito de fase 4 |
| 5.2-5.4 PaymentCancelled + listener + cancelPayment migration | 1 | Medio | Bajo (puede ir en paralelo a 4) |
| 6. Dashboard de alertas | 1, 5.2-5.4 | Medio | Bajo |
| 7. App Android | 1 | Alto | Medio (hardware físico) |
| 8. Transición | 4, 5.2-5.4, 6, 7 | Bajo | Bajo |

**Nota**: 5.1 (firma nullable) es prerequisito de fase 4. El resto de fase 5 (evento, listener) puede
implementarse en paralelo o después — no bloquean el matching.

---

## Excluido (mejoras futuras)

- ❌ Integración a Slack/Telegram para alertas (queda como mejora, post-fase 1)
- ❌ OCR de comprobantes subidos por clientes (red de seguridad pasiva, no bloqueante)
- ❌ Conciliación contra extracto descargado/CSV (fase futura si se necesita)
- ❌ Webhook de API bancaria formal (si en el futuro el banco ofrece API, se agrega como otro parser)

---

## Puntos de Riesgo y Mitigación

1. **Orden de matching**: la validación de duplicado (Paso 0) corre SIEMPRE primero en
   `ReconciliationOrchestrator`, antes que cualquier matching normal. Esto evita que un
   reference_code ya verificado sea reusado inadvertidamente.
2. **Ambigüedad de `cancelPayment()`**: en el código de matching, la variable del pago a
   cancelar se nombra `$attemptingPayment` para distinguirla del `$alreadyVerifiedPayment`.
3. **`recordPayment()` no dispara evento**: cuando el tenant cambia de método de pago, el
   update directo NO pasa por `cancelPayment()` y NO dispara `PaymentCancelled`. Es una
   acción legítima del usuario que no necesita alertar a nadie.
3. **Jobs vía dispatch explícito**: no se usan eventos `eloquent.created` como trigger.
   `IngestPaymentNotification` se dispacha desde el controller de ingesta (y desde el
   comando de backfill). Esto permite reprocesar sin duplicar lógica.
4. **Autenticación de dispositivos**: cada request incluye un `device_token` validado contra
   la tabla `devices`. El `device_id` en `payment_notifications` es una FK a `devices.id`,
   no un campo derivado del token.
5. **Configuración centralizada**: `config/payment.php` desaparece. Toda configuración vive
   en `system_configs` con cache de 1 hora. Una sola fuente de verdad.
6. **FK directa en payment_matches**: sin `morphs`, sin polimorfismo. Si otro stack necesita leer
   estos datos, entiende una FK estándar, no un patrón de Laravel.
7. **Guard de estado en matching**: antes de llamar `verifyPayment()`, el orchestrator verifica
   que el payment sigue en `Pending`. Si fue verificado manualmente mientras tanto, el match
   se descarta graciosamente.
8. **Migración de config**: `config/payment.php` se elimina completamente. Se crea un Seeder
   que inserta los registros iniciales en `system_configs`. El código que leía `config('payment.*')`
   se cambia a `SystemConfig::get('payment.*')`.
