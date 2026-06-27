# Documentación Viva del Sistema de Conciliación Automática

## Changelog de Decisiones

| Fecha | Decisión | Razón |
|-------|----------|-------|
| 2026-06-23 | **S8f completado** — Reconciliation Dashboard: KPIs, match rate, orphan payments/notifications, shadow mode toggle. Route GET /admin/reconciliation + PATCH /admin/reconciliation/shadow-mode. Card en admin-panel. | Oversight UI del sistema de conciliación completo. Todas las fases 0-7 y S8a-S8f están implementadas. Pendiente solo Fase 8 (App Android) y endpoints API (S3). |
| 2026-06-22 | **S8e completado + mejoras post-facto** — PaymentNotification viewer: controller con index (filtros por parse_status, bank_code como Select dinámico, reference en raw_text+parsed_data, fecha) + reprocess, página Inertia con tabla expandible, card renombrada a "Notificaciones Bancarias", factory con datos realistas de SMS bancarios. Cards de admin renombradas: "Notifications" → "Anuncios", "Notificaciones" → "Notificaciones Bancarias". 12 feature tests (101 assertions) + 10 browser tests, 120 landlord tests sin regresiones. | Interfaz para monitorear notificaciones bancarias entrantes y reprocesar fallidas. |
| 2026-06-22 | **S8d completado** — PaymentMethodConfig CRUD: Controller RESTful, 3 páginas Inertia (index agrupado por tipo, create con selector de tipo, edit con type read-only), Form Requests, 21 tests (78 assertions). Seeder actualizado a bancos reales BDV/BNC. Banner informativo al borrar última cuenta activa de un tipo. ~617 líneas, 8 archivos. | UI faltante para gestionar cuentas bancarias. El modelo `PaymentMethodConfig` ya existía y se usaba en producción, pero no tenía interfaz de administración — dependencia de tinker/seeder para cualquier cambio. |
| 2026-06-22 | **S8c completado** — Alert Dashboard: AlertController (index/read), alerts.tsx con filtros por severidad/leída/fecha, sidebar badge con conteo no leídas, fix HandleInertiaRequests para Landlord. 13 tests feature (76 assertions), 100 landlord tests sin regresiones. | UI faltante para que admins vean y gestionen alertas de infraestructura (SystemAlert). |
| 2026-06-21 | **S8b completado** — SystemConfig UI: tabla agrupada con modal type-aware, validación regex, cache invalidation. 12 tests. | UI completa para gestionar configs dinámicas sin depender de tinker/seeders. |
| 2026-06-21 | **S8a completado** — PaymentMatch UI: OrderController con eager-load, payment-details-card.tsx con verifier, cancellationTypeBadge, PaymentMatch section. 17 tests (8 feature + 9 browser). | UI extension del backend existente. No se crearon nuevas columnas. |
| 2026-06-21 | **S8 reorganizado en sub-slices atómicos S8a–S8f** — Se excluyen endpoints Android (IC-8) y se agregan UIs faltantes: PaymentMatch en vistas, SystemConfig, Alertas, PaymentMethodConfig, PaymentNotification viewer, y Dashboard de Conciliación. | En code review se identificó que el backend tiene mucha más capacidad de la que la UI expone. Se subdivide para mantener slices atómicos, testeables y verificables manualmente. Los endpoints Android (IC-8) se postergan hasta S3. |
| 2026-06-21 | **Fix: tests borraban DB de desarrollo** — Creado `.env.testing` con `DB_DATABASE=spatie-laravel-multitenancy_testing` y base separada en PostgreSQL. | `phpunit.xml` no configurable DB_DATABASE, tests corrían contra la misma DB de desarrollo. `BrowserTestCase::refreshDatabase()` ejecutaba `migrate:fresh` y borraba todos los datos. |
| 2026-06-20 | **S5b completado** — IngestPaymentNotification job + ExpirePendingPayments + ReprocessFailedNotifications. Fix: NotTenantAware en el job. 10 tests Pest, 4 bloques de pruebas manuales. | Conexión del motor de matching con el mundo real. S5b contiene el job IngestPaymentNotification (parse → match → eventos IC-4), el comando de expiración (payments:expire-pending), y el comando de backfill (reconciliation:reprocess). Se descubrió que Spatie Multitenancy v4 tiene queues_are_tenant_aware_by_default=true, por lo que jobs landlord deben implementar NotTenantAware, de lo contrario los cambios no persisten. |
| 2026-06-20 | **S5a completado** — PaymentMatch + ReconciliationOrchestrator implementado y verificado manualmente. 13 tests Pest, 5 escenarios manuales en tinker. | Slice progresivo del motor de matching. S5a contiene el modelo PaymentMatch (con createFromParsed idempotente), el DTO ReconciliationResult, y el orquestador con 5 pasos (duplicate → select → single/multiple/none). |
| 2026-06-20 | **Fix: SystemConfig::set()** — Corregido: ahora deriva `group` del prefijo de la key, auto-detecta `type` del valor PHP, y maneja arrays/booleanos correctamente. | El método `set()` existente no asignaba `group` (nullable en DB pero necesario para consistencia) ni infería `type`, causando que valores booleanos se guardaran como string "Array" al castear `(string) $value` sobre un array. |
| 2026-06-20 | **Fix: PagoMovilGateway::resolveReceivingAccount()** — Cambiado de retornar arreglo de error a usar `abort_if(422)`. | La versión anterior retornaba `['error' => '...']` que no era manejado por `PaymentService::recordPayment()`, causando SQL error por datos inválidos. |
| 2026-06-19 | **S3 (Devices + API) postpone** — Se reordenan los slices. S3 se ejecuta cuando el Android esté más avanzado. | S3 crea endpoints que nadie usa aún. |

> **Nuevo orden de slices**: S1 ✅ → S2 ✅ → **S3 deferred** → S4 ✅ → S5 ✅ → S6 ✅ → S7 ✅ → S8a ✅ → S8b ✅ → S8c ✅ → S8d ✅ → S8e ✅ → **S8f ✅**
>
> **Cuándo retomar S3**: Cuando el proyecto Android tenga el `NotificationListenerService` implementado y necesite enviar notificaciones al backend. Hasta entonces, las notificaciones se prueban con `SimulatePaymentNotification` (S2).

---

## Sinopsis para Revisión

### Qué queremos construir

Sistema de reconciliación bancaria automática para pagos por PagoMóvil en Venezuela. Un teléfono Android dedicado captura notificaciones push de bancos, Laravel las parsea con regex almacenados en base de datos, un motor de matching las vincula con pagos reportados por clientes, y si hay match exacto (referencia + monto + ventana temporal), el sistema auto-verifica el pago sin intervención humana. El sistema opera en "shadow mode" inicialmente (sugiere pero no auto-aprueba) y luego se activa gradualmente.

### Stack del proyecto

- **Backend**: Laravel 13, PHP 8.5, PostgreSQL
- **Frontend**: Inertia.js v3 + React 19, Tailwind CSS v4
- **Testing**: Pest v4, PHPUnit v12, 502 tests, ~1876 assertions
- **Multitenancy**: Spatie Laravel Multitenancy v4 (multi-database: landlord DB central para billing/suscripciones, tenant DB independiente para datos de cada tenant)
- **Auth**: Laravel Fortify v1
- **Code quality**: Laravel Pint v1
- **Build**: Vite + Wayfinder
- **Deployment**: Laravel Herd (local), configurable a Cloud/Heroku
- **Plataforma objetivo**: SaaS genérico multi-tenant (barebone, no atado a dominio específico)

### Funcionalidad actual — Landlord (panel de administración)

| Módulo | Estado | Descripción |
|--------|--------|-------------|
| **Tenant Management** | ✅ Completo | CRUD de tenants, listado, detalle, suspensión |
| **Plan Management** | ✅ Completo | CRUD de planes con tiers, precios, features |
| **Resource Management** | ✅ Completo | Catálogo de recursos vendibles |
| **Subscription Management** | ✅ Completo | Historial, cambios de plan, expiración |
| **Order Management** | ✅ Completo | Listado de órdenes de todos los tenants, detalle |
| **Payment Verification** | ✅ Completo | Verificación manual de pagos (PagoMóvil + Transferencia), cancelación con razón, datos de conciliación en detalle |
| **Payment Matching UI** | ✅ Completo | S8a: verifier, cancellation type badge, PaymentMatch info en vista de orden |
| **PaymentMethodConfig** | ✅ Completo | Cuentas receptoras configurables por método de pago |
| **PaymentMethodConfig UI** | ✅ Completo | S8d: CRUD completo con listado agrupado por tipo, creación/edición con campos condicionales, borrado con banner informativo |
| **Alert Dashboard** | ✅ Completo | S8c: AlertDashboard con filtros por severidad/leída/fecha, badge sidebar, mark-as-read. Rutas: index + read. |
| **SystemConfig** | ✅ Completo | UI de gestión de configuraciones dinámicas: tabla agrupada, edición type-aware, validación regex, cache |
| **Payment Notifications** | ✅ Completo | S8e: monitoreo de notificaciones SMS bancarias con tabla expandible (raw_text, parsed_data, match info), filtros (parse_status, bank_code como Select dinámico, reference, rango de fecha), reprocesar fallidas, factory con datos realistas BDV/BNC. 12 feature tests (101 assertions) + 10 browser tests. Card renombrada de "Notificaciones" a "Notificaciones Bancarias". |
| **Admin Panel** | ✅ Completo | Dashboard principal del administrador |

### Funcionalidad actual — Tenant (cliente)

| Módulo | Estado | Descripción |
|--------|--------|-------------|
| **Billing/Orders** | ✅ Completo | Crear órdenes, ver estado, reportar pago con sender fields |
| **Billing History** | ✅ Completo | Historial de facturación del tenant |
| **Payment Flow** | ✅ Completo | Selección de método de pago, instrucciones por tipo, formulario de reporte |
| **Shop** | ✅ Completo | Catálogo de recursos disponibles para compra |
| **Roles/Permissions** | ✅ Completo | Spatie Permission con roles por tenant |
| **Settings** | ✅ Completo | Perfil, avatar, seguridad |
| **Notifications** | ✅ Completo | Notificaciones del tenant |

### Funcionalidad actual — Pagos (core financiero)

| Componente | Estado | Descripción |
|-----------|--------|-------------|
| **Order** | ✅ Completo | Exclusive Arcs (plan_id OR resource_id), expiración automática, paid_cents calculado |
| **Payment (Supertipo)** | ✅ Completo | Tabla central con status, amount_cents, transaction_id, verificación |
| **PagoMovilDetail (Subtipo)** | ✅ Completo | Snapshot receiver + sender report (phone, bank, rif, sender fields) |
| **BankTransferDetail (Subtipo)** | ✅ Completo | Snapshot receiver + sender report (account, holder, sender fields) |
| **PaymentGatewayInterface** | ✅ Completo | Strategy pattern con PagoMovilGateway + BankTransferGateway |
| **PaymentService** | ✅ Completo | Orquestador con transacciones, verifyPayment(), cancelPayment() |
| **PaymentMethodConfig** | ✅ Completo | Cuentas receptoras configurables por tipo |
| **Validation** | ✅ Completo | Referencia 6-10 dígitos, duplicada cross-tenant, estado de orden, sender fields |
| **Events/Listeners** | ✅ Completo | PaymentVerified → ActivateSubscription, OrderExpired, PendingPaymentCreated, PaymentCancelled → NotifyPaymentRejected |
| **Reconciliation Dashboard** | ✅ Completo | S8f: KPIs (match rate, auto-verificados hoy, alertas activas), orphans (payments huérfanos, notificaciones huérfanas), shadow mode indicator + toggle |
| **Shadow Mode** | ✅ Completo | S7+S8f: toggle via SystemConfig `reconciliation.shadow_mode_enabled`, sugerencias sin auto-aprobación, indicador en dashboard |
| **Frontend Landlord** | ✅ Completo | Order list/detail, verificación/cancelación de pagos |
| **Frontend Tenant** | ✅ Completo | Order list/detail, formulario de reporte de pago |

### Funcionalidad actual — Infraestructura

| Componente | Estado | Descripción |
|-----------|--------|-------------|
| **Multitenancy** | ✅ Completo | Spatie v4, multi-database (landlord DB para billing, tenant DB independiente para datos por tenant), tenant resolution por dominio |
| **Auth** | ✅ Completo | Fortify, registro, login, 2FA, passkeys |
| **Browser Tests** | ✅ Completo | 6 archivos, 30 tests, 177 assertions |
| **Feature/Unit Tests** | ✅ Completo | 514 tests, 1915 assertions |
| **ManualNotificationLog** | ✅ Completo | Tabla custom para logs de notificaciones manuales |
| **Order Expiration** | ✅ Completo | Comando programado `orders:expire` |
| **Subscription Expiration** | ✅ Completo | Comando programado `subscriptions:expire` |

### Qué falta (no implementado aún)

> **Todo el backend (Fases 0-7) y frontend operacional (S8a-S8f) están COMPLETOS e implementados.**
> Lo que falta se limita a los componentes del ecosistema Android y endpoints API.

- **Dispositivo Android** como capturador de notificaciones (app nativa con NotificationListenerService)
- Tabla `devices` + modelo `Device` (autenticación de teléfonos)
- Comando `CheckDeviceHeartbeats` (detección de dispositivos offline)
- Endpoints API REST:
  - `POST /api/device/notifications` — ingesta de notificaciones desde el teléfono
  - `POST /api/device/heartbeat` — heartbeat del dispositivo
  - Middleware `device.auth` — validación de `X-Device-Token`
- Dashboard de dispositivos / heartbeat status en UI landlord

> **Nota**: Hasta que el proyecto Android implemente el `NotificationListenerService`, las notificaciones se prueban con `php artisan simulate:payment-notification` (Fase 0).

### Qué queda deferred (no parte de este plan)

- PayPal, Stripe u otros métodos internacionales
- Reembolsos
- Reconciliación de Transferencia Bancaria (requiere CSV/extracto, enfoque distinto)
- Proration
- Reintentos automáticos
- Webhooks de confirmación bancaria
- Soporte visual (captura/PDF del comprobante)

---

## Visión General

Sistema que permite conciliar automáticamente pagos por PagoMóvil usando un teléfono Android dedicado que captura las notificaciones push del banco destino, las envía a Laravel, y un motor de matching las vincula con pagos reportados por clientes para activar el servicio sin intervención humana.

> **Alcance de este plan**: Solo conciliación de **PagoMóvil**. La conciliación de Transferencia Bancaria queda deferred. PagoMóvil tiene un formato de notificación **estandarizado por banco** — si el sistema recibe pagos de Banco X, necesita un parser para ese banco. Si luego recibe de Banco Y, necesita otro parser. Cada banco tiene su propio formato push.

### Por qué solo PagoMóvil (y no Transferencia Bancaria)

PagoMóvil es el método de pago **principal** en Venezuela — tiene volumen alto y verificación inmediata (el dinero se debita al instante). Transferencia Bancaria es secundaria y tiene un proceso de verificación diferente (extracto bancario, horarios bancarios, etc.). Conciliar transferencias requiere un enfoque distinto (CSV/extracto) que no aplica a notificaciones push. Por eso este plan se enfoca solo en PagoMóvil.

---

## Principios de Diseño

- **Pipeline único de aprobación**: tanto el flujo manual como el automático terminan llamando al mismo `PaymentService::verifyPayment()` que dispara `PaymentVerified` → `ActivateSubscription`. No hay caminos paralelos.
- **Datos inmutables**: los raw notifications nunca se modifican, solo se referencian.
- **Idempotencia desde el día 1**: hash determinista evita duplicados por reintentos de red.
- **Matching determinista**: o hay coincidencia exacta (referencia + monto + ventana temporal) → auto-verifica, o no hay → va a cola de revisión manual para que el admin contacte al tenant. Sin scores de confianza, sin probabilidades. El teléfono NO se usa en matching porque algunos bancos lo enmascaran.

> **Por qué matching determinista (sin confidence scores)**: En sistemas financieros, los falsos positivos son peores que los falsos negativos. Un pago auto-verificado incorrectamente puede causar pérdida de dinero o activar un servicio sin pago real. Un falso negativo solo requiere revisión manual — inconveniente, pero no destructivo. Por eso decidimos: o es match exacto (referencia + monto), o va a cola manual. Sin punto medio probabilístico.

> **Por qué el teléfono NO entra en matching**: Los bancos venezolanos enmascaran el teléfono del pagador en las notificaciones push. Ejemplo real: BNC muestra `0416***9503` en vez del número completo. BDV muestra el número completo. Si usáramos teléfono como criterio, BNC nunca matchearía. La referencia (6-12 dígitos, única por transacción) + monto son suficientes y confiables en todos los bancos.

> **Por qué ventana temporal**: Sin ventana, un pago muy viejo (meses) podría tener la misma referencia + monto que uno nuevo por coincidencia. La ventana (default 72h) rechaza pagos antiguos y reduce falsos positivos.
- **Rechazo con semántica explícita**: `CancellationType` enum distingue duplicado de fraude de otras cancelaciones, con routing distinto para alertas internas vs. notificación al cliente. `cancellation_reason` es texto libre para humanos.

> **Por qué CancellationType enum + cancellation_reason texto libre**: El enum resuelve un problema técnico: cada tipo de cancelación tiene un "routing" diferente (duplicate → notificar admin + tenant; expired → solo notificar tenant; manual → notificar solo tenant). El texto libre resuelve un problema humano: el admin o el sistema necesitan escribir "Pago duplicado por error del cliente" o "Referencia 006236568762 ya verificada en pago #45". Son dos necesidades distintas que coexisten sin conflicto.
- **FK directas, sin polimorfismo**: `payment_matches` usa `payment_id` FK directa a `payments`, no `morphs`. Integridad referencial real, agnóstico a framework.

> **Por qué FK directa en vez de morphs**: El sistema ya usa Supertipo/Subtipo (FK directas) para Payment → PagoMovilDetail/BankTransferDetail. `payment_matches` solo matchea contra `Payment` — nunca contra otros tipos. Usar morphs aquí sería inconsistente con la arquitectura existente y perdería integridad referencial a nivel DB. Si otro stack (Go, Node) necesita leer estos datos, una FK estándar es universal; un morph de Laravel es framework lock-in.
- **Configuración centralizada**: toda la configuración vive en `system_configs` (tabla en DB). No hay `config/payment.php`. Una sola fuente de verdad, cacheada en application boot.

> **Por qué system_configs en vez de config file**: `config/payment.php` requiere deploy para cambiar cualquier valor. En un SaaS multi-tenant, el landlord necesita cambiar expiración de órdenes, activar/desactivar shadow mode, o actualizar regex de parsers SIN deployear. `system_configs` con cache de 1h da esa flexibilidad. También simplifica la migración: un soloSeeder crea todos los registros iniciales.
- **PaymentMethodConfig como única fuente**: `PagoMovilGateway` y `BankTransferGateway` siempre requieren un `PaymentMethodConfig` activo. Sin fallback a config file.

---

## Fase 0 — Simulador (✅ COMPLETADA)

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

## Fase 1 — Backend: Migraciones y Modelos (✅ COMPLETADA)

### 1.0 Tabla `system_configs` (configuración centralizada — ÚNICA fuente de verdad)

```php
Schema::create('system_configs', function (Blueprint $table) {
    $table->id();
    $table->string('group');              // 'payment', 'reconciliation', 'devices'
    $table->string('key')->unique();      // 'order_expiry_hours', 'match_window_hours', etc.
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
| `reconciliation` | `match_window_hours` | `72` | integer | Ventana de tiempo para matching y expiración de pagos pending | Nuevo |
| `reconciliation` | `shadow_mode_enabled` | `true` | boolean | Modo sombra: solo sugiere matches, no auto-aprueba | Nuevo |
| `reconciliation` | `regex_bdv` | `(?i)Recibiste\s+un\s+PagomovilBDV...` | string | Regex para parsear notificaciones BDV | ✅ Verificado |
| `reconciliation` | `regex_bnc` | `(?i)BNC\s+Pago\s+Movil\s+Recibido...` | string | Regex para parsear notificaciones BNC | ✅ Verificado |
| `reconciliation` | `regex_banesco` | `(?i)Banesco\s+Pago\s+Movil...` | string | Regex para parsear notificaciones Banesco | ⏳ No verificado |
| `reconciliation` | `regex_mercantil` | `(?i)Mercantil\s+Tpago...` | string | Regex para parsear notificaciones Mercantil | ⏳ No verificado |
| `reconciliation` | `regex_provincial` | `(?i)Provincial:\s+Dinero\s+Rapido...` | string | Regex para parsear notificaciones Provincial | ⏳ No verificado |
| `devices` | `heartbeat_interval_minutes` | `5` | integer | Intervalo esperado de heartbeat del dispositivo | Nuevo |

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
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Sentinel pattern atómico: evita la condición de carrera has/get.
        // Cache solo valores que existen en DB. El default se aplica fuera del cache.
        $cacheKey = "system_config.{$key}";
        $sentinel = '__CACHE_MISS__';

        $cached = Cache::get($cacheKey, $sentinel);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $config = static::where('key', $key)->first();

        if ($config) {
            $value = $config->getValue();
            Cache::put($cacheKey, $value, 3600);
            return $value;
        }

        return $default;
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

> **Restricción de cache**: `SystemConfig::set()` y `save()` invalidan la caché correctamente.
> Pero `SystemConfig::where(...)->update([...])` NO pasa por `save()` y la caché queda
> inconsistente. Siempre usar `SystemConfig::set()` o `->save()` para actualizar configuración.

**Eliminar `config/payment.php`**: Ya no existe. Toda la configuración se lee de `SystemConfig::get()`.

### 1.1 Gateways sin fallback a config file

**`PagoMovilGateway`**: Siempre requiere un `PaymentMethodConfig` activo. No hay fallback a config file.

```php
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
    $table->string('bank_code');              // banco destino asociado — SIEMPRE lowercase
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
    $table->string('bank_code');            // ej. 'banesco', 'mercantil' — SIEMPRE lowercase, snake_case
    $table->text('raw_title');              // título original de la notificación
    $table->text('raw_body');               // cuerpo original de la notificación
    $table->string('dedup_hash')->unique(); // SHA256(bank_code_lowercase + raw_body)
    $table->timestamp('received_at');       // timestamp del teléfono
    $table->string('parse_status')->default('pending'); // pending | parsed | failed
    $table->timestamps();

    $table->index('bank_code');
    $table->index('parse_status');
});
```

**Nota**: Se usa `foreignId('device_id')->constrained('devices')` en vez de `string('device_id')` para mantener integridad referencial. El `device_token` se valida en el controller antes de resolver el `device_id`.

> **Por qué FK a devices en vez de derivar device_id del token**: El token es un string de autenticación que puede cambiar (rotación de seguridad). El `device_id` es la FK real que vincula la notificación con el dispositivo. El controller valida el token contra la tabla `devices`, obtiene el `device_id`, y usa ese como FK. Si el token cambia, la FK no se rompe.

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
    $table->timestamp('matched_at')->nullable();
    $table->timestamps();

    $table->index('match_status');
    $table->index('payment_id');
});
```

**Migración separada** (inmediatamente posterior): Partial unique index como safety net contra condición de carrera.

```php
// database/migrations/landlord/2026_06_XX_0000XX_add_partial_unique_index_to_payment_matches.php
DB::statement('CREATE UNIQUE INDEX idx_payment_matches_matched ON payment_matches (payment_id) WHERE match_status = \'matched\'');
```

> **Nota**: `payments`, `payment_notifications`, y `payment_matches` viven en la misma base de datos
> (landlord DB). No hay problema de FK cross-database en este flujo, ya que el core financiero
> y de conciliación reside centralizado.

**Modelo `PaymentMatch`**:

```php
class PaymentMatch extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'payment_notification_id', 'payment_id', 'parsed_reference',
        'parsed_amount_cents', 'parsed_sender_phone_last4',
        'match_status', 'matched_at',
    ];

    protected function casts(): array
    {
        return ['matched_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(PaymentNotification::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Crear match desde datos parseados. Idempotente: si ya existe un match
     * para esta notificación, devuelve el existente (evita duplicados por re-procesamiento).
     */
    public static function createFromParsed(PaymentNotification $notification, ParsedPayment $parsed): static
    {
        return static::firstOrCreate(
            ['payment_notification_id' => $notification->id],
            [
                'parsed_reference' => $parsed->reference,
                'parsed_amount_cents' => $parsed->amount_cents,
                'parsed_sender_phone_last4' => $parsed->sender_phone_last4,
                'match_status' => 'unmatched',
            ]
        );
    }
}
```

> **Por qué FK directa en payment_matches en vez de morphs:**
> - `matchable` solo puede ser `Payment` — no hay otros tipos
> - FK real = integridad referencial a nivel DB
> - Si otro stack necesita leer estos datos, entiende una FK, no un morph de Laravel
- Consistente con la arquitectura Supertipo/Subtipo ya establecida

### 1.5b Notificaciones de infraestructura (reutiliza `notifications` de Laravel)

> **Por qué reutilizar `notifications` en vez de tabla separada**: La tabla `notifications` ya
> existe en Landlord DB, tiene `notifiable_type`/`notifiable_id`, `data` (JSON), y `read_at`.
> Las alertas de infraestructura se envían como notificaciones a users con rol admin del landlord.
> Se distinguen de las notificaciones de negocio por el campo `category = 'system'` en el JSON `data`.

```php
class SystemAlert extends Notification
{
    public function __construct(
        public string $type,      // heartbeat_offline | parser_failed | no_match_accumulated
        public string $severity, // critical | warning | info
        public string $title,    // "Dispositivo Banesco offline"
        public string $message,  // "El teléfono no hace heartbeat desde hace 25 minutos"
        public ?array $metadata = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'category' => 'system',
            'type' => $this->type,
            'severity' => $this->severity,
            'metadata' => $this->metadata,
        ];
    }
}
```

**Envío**: Se envía a todos los users con rol `landlord-admin` usando `Notification::send()`.

**Ciclo de vida**:
1. Job de parsing falla → `Notification::send($admins, new SystemAlert('parser_failed', 'critical', ...))`
2. Heartbeat timeout → `Notification::send($admins, new SystemAlert('heartbeat_offline', 'critical', ...))`
3. **Resolución**: El admin ve la alerta en el dashboard, actualiza el regex, corre el backfill si es necesario, y al verificar que el problema se resolvió, marca la notificación como leída (`read_at = now()`).
4. Dashboard solo muestra notificaciones sin leer (`read_at IS NULL`) y cuyo `data->>'category' = 'system'`

**Ventajas sobre tabla separada**:
- No se crea tabla nueva ni modelo nuevo
- Se reutiliza infraestructura existente (database storage, badge en navbar, etc.)
- Las notificaciones de negocio e infraestructura conviven en la misma tabla
- Se distinguen por el campo `category = 'system'` en el JSON `data`
- El admin puede resolver alertas desde el mismo panel de notificaciones

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
    return $this->hasOne(PaymentMatch::class)->latestOfMany();
}
```

### 1.8 Migrar `PaymentService::createOrder()` para leer expiración de `system_configs`

```php
// ANTES:
'expires_at' => now()->addHours(48),  // hardcodeado

// DESPUÉS:
'expires_at' => now()->addHours((int) SystemConfig::get('payment.order_expiry_hours', 48)),  // lee de DB cacheada
```

### 1.9 Comando `ExpireOrders` — sin cambios necesarios

`ExpireOrders` ya lee `expires_at` de la columna en la tabla `orders`. No usa `config()` ni hardcodea horas. La expiración se setea una sola vez en `createOrder()` (sección 1.8). Este comando no necesita cambios.

### 1.10 Helper global `normalizeRef()`

La referencia se normaliza en dos lugares: el parser (al parsear la notificación) y el reverse match (al buscar coincidencias). Para evitar duplicar lógica, se crea un helper global:

```php
// app/Helpers/normalizeRef.php
if (! function_exists('normalizeRef')) {
    function normalizeRef(string $raw): string
    {
        return trim(strtoupper($raw));
    }
}
```

Se agrega `require_once __DIR__.'/Helpers/normalizeRef.php';` en `composer.json` → `autoload.files` (o se crea como función estática en una clase de utilidad si el proyecto no usa `helpers.php`).

**Nota**: `PaymentNotificationParser::normalizeReference()` se reemplaza por llamada a `normalizeRef()` para mantener una sola fuente de lógica de normalización.

**Precondición en `PaymentService::recordPayment()`**: El `transaction_id` se normaliza al guardar para garantizar que el matching funcione. Sin esto, el match falla silenciosamente por diferencias de capitalización o espacios:

```php
// En PaymentService::recordPayment(), al guardar el pago:
'transaction_id' => normalizeRef($request->transaction_id),
```

---

---

### Manual: S4 (CancellationType + PaymentService)

> **Prerequisito**: Migración corrida (`php artisan migrate --path=database/migrations/landlord --database=landlord`), datos de S1/S2 existentes.

## Fase 2 — Backend: Parser Único + Regex en DB (✅ COMPLETADA)

> **Importante**: PagoMóvil tiene un formato de notificación push **estandarizado por banco**. Cada banco envía la notificación con un formato diferente (orden de campos, separadores, texto). En vez de crear una clase por banco (que obligaría a compilar y deployear por cada cambio), usamos un **parser único guiado por datos** — un solo componente que aplica el regex correcto según el banco.

> **Por qué parser único + regex en DB (en vez de una clase por banco)**: Los bancos venezolanos cambian el formato de sus notificaciones constantemente (añaden un espacio, cambian guiones por barras, quitan palabras). Si tuviéramos una clase `BNCParser.php` hardcodeada, cada cambio de formato requeriría: modificar código → compilar → subir a Play Store → usuario actualiza. Con regex en DB: actualizar una fila en `system_configs` → todos los dispositivos reciben el nuevo patrón sin actualización de la app. Esto fue confirmado por investigación externa: "ningún banco publica la documentación de sus plantillas de texto, y esos formatos cambian mediante actualizaciones silenciosas".

### Ejemplos reales de formatos

| Campo | BDV (SMS) | BNC (App Android) |
|-------|-----------|-----------|
| Texto ejemplo | `Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40` | `BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion` |
| Teléfono | Completo: `0424-3153557` | **Enmascarado**: `0416***9503` |
| Referencia | `006236568762` | `603185603` |
| Monto | `Bs. 3.000,00` (con separador de miles) | `Bs.10455,00` (sin separador) |
| Fecha | `02-06-26` (DD-MM-YY) | `31/05/26` (DD/MM/YY) |
| Hora | `09:40` | `20:25` |

**Conclusión**: El teléfono NO es confiable para matching — algunos bancos lo enmascaran. El matching se basa en **referencia + monto + ventana temporal**.

### Arquitectura: Parser Único + Regex en DB

```
Notificación llega al endpoint
        │
1. Endpoint resuelve: packageName → bank_code (strtolower)
        │
2. Buscar en system_configs: regex para este bank_code
        │
3. Parser único aplica regex al texto crudo
        │
4. Normaliza datos (monto, referencia, fecha)
        │
5. Devuelve ParsedPayment
```

**Ventaja clave**: Si un banco cambia su formato → se actualiza el regex en `system_configs` → todos los dispositivos reciben el nuevo patrón sin actualizar la app Android.

### Package IDs de bancos (Android)

| Banco | Package ID | Estado |
|-------|-----------|--------|
| BDV | `com.bancodevenezuela.bdvapp` | ✅ Verificado |
| BNC | `com.bnc.bncmovil` | ✅ Verificado |
| Banesco | `com.banesco.bancamovil` | ⏳ Pendiente verificación |
| Mercantil | `com.synergygb.mercantil.tpago` | ⏳ Pendiente verificación |
| Provincial | `com.dinerorapido.bancamovil` | ⏳ Pendiente verificación |

> **Nota**: Solo BDV y BNC tienen formatos de notificación verificados con dispositivos reales.
> Banesco, Mercantil y Provincial están pendientes — sus regex son estimaciones basadas en
> reportes no verificados. Ver sección 2.7 para instrucciones de agregar bancos nuevos.

### 2.1 Regex por banco (almacenados en `system_configs`)

| Banco | Key en DB | Regex | Estado |
|-------|-----------|-------|--------|
| BDV | `regex_bdv` | `(?i)Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)` | ✅ Verificado |
| BNC | `regex_bnc` | `(?i)BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)` | ✅ Verificado |
| Banesco | `regex_banesco` | `(?i)Banesco\s+Pago\s+Movil:\s+Recibio\s+Bs\.\s+(?<amount>[\d.,]+)\s+de\s+(?<phone>\d+),\s+Ref:\s+(?<reference>\d+)` | ⏳ No verificado |
| Mercantil | `regex_mercantil` | `(?i)Mercantil\s+Tpago:\s+Recibiste\s+un\s+pago\s+de\s+(?<id>[VEJG]\d+)\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\.\s+Ref:\s+(?<reference>\d+)` | ⏳ No verificado |
| Provincial | `regex_provincial` | `(?i)Provincial:\s+Dinero\s+Rapido\s+recibido\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+de\s+(?<phone>\d+)\.\s+Referencia:\s+(?<reference>\d+)` | ⏳ No verificado |

> **Nota**: Solo BDV y BNC tienen regex verificados con dispositivos reales. Los demás son
> estimaciones basadas en reportes no verificados. Ver sección 2.7 para instrucciones de
> agregar o corregir regex de bancos.

> **Nota**: Estos regex son punto de partida basados en ejemplos reales. Deben validarse con dispositivos físicos y actualizarse en `system_configs` cuando los bancos cambien sus formatos.

### 2.2 Formato de fecha por banco (hardcodeado en parser)

Los bancos usan formatos de fecha distintos. Los formatos están hardcodeados en el parser:

| Banco | Formato de fecha | Ejemplo | Estado |
|-------|------------------|---------|--------|
| BDV | `d-m-y H:i` | `02-06-26 09:40` | ✅ Verificado |
| BNC | `d/m/y H:i` | `31/05/26 20:25` | ✅ Verificado |
| Banesco | `d-m-Y` | `18-06-2026` | ⏳ No verificado |
| Mercantil | `d-m-Y` | `18-06-2026` | ⏳ No verificado |
| Provincial | `d-m-Y` | `18-06-2026` | ⏳ No verificado |

> **Por qué hardcodeado**: Los formatos de fecha no cambian frecuentemente. Si un banco cambia
> su formato, se actualiza una línea en el método `getDateFormat()` del parser. No justifica
> una configuración en base de datos.

### 2.3 `PaymentNotificationParser` (parser único)

> **FC-2 resuelto**: Parser sin estado mutable. Lee el regex del cache en cada llamada a `parse()`.
> El singleton no mantiene `$packageToBankCode` como propiedad — `Cache::remember()` se ejecuta
> en cada invocación, pero cachea por 1h. Si `SystemConfig::set()` invalida el cache, la
> siguiente llamada al parser lee el valor actualizado.

> **FC-3 resuelto**: El parser acepta `bank_code` directamente, no `packageName`.
> La resolución `packageName → bank_code` ocurre en el endpoint de ingesta (landlord),
> antes de crear el `payment_notification`. El parser no necesita conocer package IDs.

> **Precondición del parser**: `normalizeAmount()` asume formato venezolano: separador de miles
> con punto (`.`), separador decimal con coma (`,`). Ej: `"3.000,45"` → 300045 centavos.
> Si algún banco usa formato anglosajón (`"3,000.45"`), el resultado será incorrecto.
> Esto se documenta como limitación conocida — los bancos venezolanos usan formato europeo.

```php
class PaymentNotificationParser
{
    public function parse(string $bankCode, string $text): ?ParsedPayment
    {
        // 1. Buscar regex por bank_code (cacheado por 1h)
        $regex = SystemConfig::get("regex_{$bankCode}");

        if (!$regex) {
            return null;
        }

        // 2. Aplicar regex
        if (!preg_match($regex, $text, $matches)) {
            return null;
        }

        // 3. Normalizar y devolver
        return new ParsedPayment(
            amount_cents: $this->normalizeAmount($matches['amount']),
            reference: normalizeRef($matches['reference']),
            sender_phone_last4: $this->extractLast4($matches['phone'] ?? null),
            parsed_at: $this->parseDate($matches['date'] ?? null, $matches['time'] ?? null, $this->getDateFormat($bankCode)),
        );
    }

    private function getDateFormat(string $bankCode): string
    {
        return match ($bankCode) {
            'bdv' => 'd-m-y H:i',
            'bnc' => 'd/m/y-H:i',
            'banesco' => 'd-m-Y',
            'mercantil' => 'd-m-Y',
            'provincial' => 'd-m-Y',
            default => 'd-m-Y',
        };
    }

    private function normalizeAmount(string $raw): int
    {
        // "3.000,45" → 300045 (cents)
        // "10455,00" → 1045500 (cents)
        $clean = str_replace('.', '', $raw);
        $clean = str_replace(',', '.', $clean);
        return (int) round((float) $clean * 100);
    }

    private function extractLast4(?string $phone): ?string
    {
        if (!$phone) return null;
        $clean = preg_replace('/[^0-9]/', '', $phone); // quita guiones, asteriscos
        return substr($clean, -4);
    }

    private function parseDate(?string $date, ?string $time, string $format): ?Carbon
    {
        if (!$date) return null;
        $full = $time ? "{$date} {$time}" : $date;
        $parsed = Carbon::createFromFormat($format, $full);
        return $parsed !== false ? $parsed : null;
    }
}
```

### 2.5 Validación de regex antes de guardar

> **Riesgo operativo**: Si un admin guarda un regex malformado o que no captura los grupos requeridos, el parser devuelve `null` silenciosamente para todas las notificaciones de ese banco hasta que alguien lo note.

**Mecanismo de validación** (en el controller o servicio que actualiza `system_configs`):

```php
// Antes de guardar un regex de reconciliación:
if (Str::startsWith($key, 'regex_')) {
    // 1. Validar que el regex compila
    @preg_match($value, 'test', $matches);
    if (preg_last_error() !== PREG_NO_ERROR) {
        abort(422, 'El regex no es válido.');
    }

    // 2. Validar que tiene los grupos nombrados requeridos
    $requiredGroups = ['amount', 'reference'];
    preg_match_all('/\(\?<(\w+)>/', $value, $namedGroups);
    $foundGroups = $namedGroups[1] ?? [];
    $missing = array_diff($requiredGroups, $foundGroups);
    if (!empty($missing)) {
        abort(422, 'El regex falta grupos requeridos: ' . implode(', ', $missing));
    }
}
```

**Endpoint de prueba** (recomendado):

```
POST /admin/system-configs/test-regex
{
    "regex": "(?i)BNC\\s+Pago\\s+Movil...",
    "test_samples": [
        "BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603"
    ]
}
```

Response: muestra qué campos extrae de cada sample, o errores si el regex falla.

### 2.6 Fallback: notificaciones sin parsear

Cuando el parser no puede extraer datos (banco desconocido o formato cambiado), el flujo es **interno** — no hay endpoint externo:

```
Job IngestPaymentNotification
    │
    ├── Parser falla (regex no matchea o banco desconocido)
    │
    ├── Marca payment_notification.parse_status = 'failed'
    │
    ├── Envía SystemAlert a landlord admins:
    │   type: parser_failed
    │   severity: warning
    │   title: "Banco {bank_code} tiene formato no reconocido"
    │   message: "Texto: {raw_text}"
    │   metadata: { device_id, bank_code, package_name }
    │
    └── Fin (no continúa a matching)
```

**Por qué no hay endpoint externo**: El parser vive en el backend, no en el teléfono. El teléfono solo captura y envía el texto raw. Cuando el parser falla, el job lo sabe y genera la alerta. No necesita un endpoint que el teléfono llame — el teléfono no tiene esa información.

**Flujo de resolución**:
1. Admin ve la alerta en el dashboard de notificaciones (filtrada por `category = 'system'`)
2. Admin actualiza el regex en `system_configs`
3. Admin corre: `php artisan reconciliation:reprocess --parse-status=failed`
4. El backfill re-procesa las notificaciones fallidas con el nuevo regex
5. Admin verifica que las notificaciones se procesaron correctamente y resuelve la alerta manualmente desde el dashboard

### 2.7 Agregar un banco nuevo

Guía paso a paso para agregar soporte de un banco nuevo al sistema de conciliación.

#### Escenario A: Banco con formato similar a los existentes

Si el nuevo banco usa un formato de notificación parecido a BDV o BNC (mismos campos, mismo orden, solo cambia texto/sepadores):

1. Obtener el texto crudo de una notificación real del banco (capturar del dispositivo Android)
2. Insertar regex en `system_configs`:
   ```php
   SystemConfig::set('regex_{bank_code}', '/<regex_pattern>/i', 'string');
   ```
3. Los dispositivos Android reciben el nuevo patrón automáticamente (regex viene del backend)
4. Agregar tests en `PaymentNotificationParserTest.php` con el formato real del banco
5. Agregar samples en `NotificationSampleSeeder` si se desea datos de prueba

#### Escenario B: Banco con formato completamente distinto

Si el banco tiene un formato diferente (orden de campos, separadores, texto diferente):

**Paso 1 — Obtener formato real**
- Capturar al menos 3 notificaciones reales del banco (montos diferentes, con y sin decimales)
- Documentar el formato exacto en esta sección del plan (tabla de formatos)

**Paso 2 — Crear regex**
- Construir regex con grupos nombrados requeridos: `(?<amount>...)`, `(?<reference>...)`
- Grupo opcional: `(?<phone>...)` para sender phone last4
- Grupo opcional: `(?<date>...)`, `(?<time>...)` para parsed_at
- Validar que el regex compila: `@preg_match($regex, 'test'); echo preg_last_error();`
- Validar que captura los grupos requeridos con textos de prueba reales

**Paso 3 — Guardar en system_configs**
```php
SystemConfig::set('regex_{bank_code}', '/<regex_pattern>/i', 'string');
```
El parser único lo detecta automáticamente por la key `regex_{bank_code}`.

**Paso 4 — Agregar formato de fecha en el parser**
En `app/Services/Payment/PaymentNotificationParser.php`, método `getDateFormat()`:
```php
private function getDateFormat(string $bankCode): string
{
    return match ($bankCode) {
        'bdv' => 'd-m-y H:i',
        'bnc' => 'd/m/y H:i',
        'nuevo_banco' => 'd/m/Y H:i',  // ← agregar aquí
        default => 'd-m-y H:i',
    };
}
```

**Paso 5 — Agregar package ID en Android**
En el dispositivo Android, en `NotificationListenerService`:
- Agregar el `packageName` del banco en el array de paquetes soportados
- Mapear `packageName → bank_code` (strtolower)

**Paso 6 — Tests**
- Agregar regex en `beforeEach` del test file correspondiente
- Agregar tests de parseo con formato real del banco (mínimo 3 casos)
- Agregar test de error (formato no reconocido → retorna null)
- Agregar round-trip test: raw text → parser → markParsed → verificar parsed_data

**Paso 7 — Seeder (opcional)**
- Agregar método `make{Bank}()` en `NotificationSampleSeeder`
- Agregar 2-4 samples representativos con formatos variados

#### Checklist completo al agregar un banco

| # | Archivo | Cambio |
|---|---------|--------|
| 1 | `system_configs` (DB) | Insertar `regex_{bank_code}` |
| 2 | `app/Services/Payment/PaymentNotificationParser.php` | Agregar caso en `getDateFormat()` |
| 3 | `database/seeders/SystemConfigSeeder.php` | Agregar regex en el seeder |
| 4 | `tests/Unit/Services/Payment/PaymentNotificationParserTest.php` | Agregar tests con formato real |
| 5 | `tests/Unit/Services/P PaymentNotificationParserIntegrationTest.php` | Agregar integration tests |
| 6 | `database/seeders/NotificationSampleSeeder.php` | Agregar samples (opcional) |
| 7 | `app/Console/Commands/SimulatePaymentNotification.php` | Agregar caso en `VALID_BANKS` y `formatNotification()` |
| 8 | Android `NotificationListenerService` | Agregar `packageName → bank_code` mapping |

#### Notas importantes

- **El regex es la única parte que cambia frecuentemente**. Los bancos venezolanos cambian sus formatos de notificación constantemente (añaden espacios, cambian guiones por barras). Con regex en DB, se actualiza una fila sin redeploy.
- **El formato de fecha está hardcodeado** en el parser porque no cambia frecuentemente. Si un banco cambia su formato de fecha, se actualiza una línea en `getDateFormat()`.
- **Siempre validar con dispositivos reales** antes de agregar un regex en producción. Los formatos varían entre SMS y app Android del mismo banco.
- **El parser retorna null** cuando falla — no hay excepciones. El flujo de error va a `SystemAlert` para que el admin actualice el regex.

`ParsedPayment` = DTO con:
- `amount_cents: int`
- `reference: ?string` (normalizado: trim, uppercase, sin separadores)
- `sender_phone_last4: ?string`

> `parse()` retorna `null` cuando falla (banco desconocido, regex no matchea, datos insuficientes).
> No hay campo `success` — `null` es el signal de fallo.

### 2.8 Test Suite

- PHPUnit con ejemplos reales por banco (anonimizados)
- Al menos 10 casos por banco incluyendo edge cases

### 2.9 Pruebas Manuales (Tinker)

Proceso de verificación manual de S1 (SystemConfig + Parser) y S2 (Almacenamiento de notificaciones). Se ejecutan con `php artisan tinker` para confirmar que la base funciona correctamente antes de construir sobre ella.

> **Por qué pruebas manuales además de Pest**: Las pruebas Pest verifican el código en aislamiento (transactions rollback). Las pruebas manuales verifican que la data persiste correctamente en PostgreSQL, que los tipos se castean bien, y que el flujo completo funciona con la DB real. Son complementarias, no redundantes.

#### Preparación de DB

```bash
# 1. Fresh migrate (base + landlord)
php artisan migrate:fresh --database=landlord --no-interaction
php artisan migrate --path=database/migrations/landlord --database=landlord --no-interaction

# 2. Seed SystemConfig
php artisan db:seed --class=Database\Seeders\SystemConfigSeeder --database=landlord --no-interaction

# 3. Entrar a tinker
php artisan tinker
```

#### S1 — SystemConfig + Parser + normalizeRef

**Test 1: SystemConfig seeded (configs por banco)**

```php
\App\Models\SystemConfig::count()
```

```php
\App\Models\SystemConfig::all()->each(function($c) { echo "{$c->key} ({$c->type}): " . substr($c->value, 0, 60) . PHP_EOL; })
```

> Esperado: count = 8 (payment.* + reconciliation.* + device.* + regex_bdv + regex_bnc). Los regex deben tener delimitadores `/pattern/i`.

**Test 2: SystemConfig get/set/cache + typed returns**

```php
\App\Models\SystemConfig::get("payment.default_gateway")
```

```php
\App\Models\SystemConfig::set("payment.default_gateway", "test_value")
```

```php
\App\Models\SystemConfig::get("payment.default_gateway")
```

```php
\App\Models\SystemConfig::set("payment.default_gateway", "pago_movil")
```

```php
\App\Models\SystemConfig::get("reconciliation.match_window_hours")
```

```php
\App\Models\SystemConfig::get("reconciliation.shadow_mode_enabled")
```

> Esperado: get retorna string, set persiste, get("match_window_hours") retorna integer (72), get("shadow_mode_enabled") retorna boolean (true).

**Test 3: Parser con formatos reales del plan**

```php
$parser = new \App\Services\Payment\PaymentNotificationParser();
```

```php
$bdv = $parser->parse("bdv", "Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40")
```

```php
$bdv->amountCents    // 300000
```

```php
$bdv->reference      // "006236568762"
```

```php
$bdv->senderPhoneLast4  // "3557"
```

```php
$bdv->rawGroups      // ["amount" => "3.000,00", "phone" => "0424-3153557", "reference" => "006236568762", "date" => "02-06-26", "time" => "09:40"]
```

```php
$bnc = $parser->parse("bnc", "BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion")
```

```php
$bnc->amountCents    // 1045500
```

```php
$bnc->reference      // "603185603"
```

```php
$bnc->senderPhoneLast4  // "9503"
```

```php
$parser->parse("unknown", "some text")   // null
```

> Esperado: BDV parsea monto con separador de miles (`3.000,00` → 300000), referencia normalizada, phone last4 extraído. BNC parsea teléfono enmascarado (`0416***9503` → 9503). Unknown bank retorna null.

**Test 4: normalizeRef trim + uppercase**

```php
normalizeRef("  123456  ")   // "123456"
normalizeRef("abc123")       // "ABC123"
normalizeRef("  AbC123  ")   // "ABC123"
normalizeRef("")             // ""
normalizeRef("123456")       // "123456"
```

#### S2 — Almacenamiento de Notificaciones

**Test 1: Seeder crea notificaciones (4 por banco × 2 bancos = 8)**

```php
(new \Database\Seeders\NotificationSampleSeeder)->run()
```

```php
\App\Models\PaymentNotification::count()
```

```php
\App\Models\PaymentNotification::select('bank_code', \DB::raw('count(*) as total'))->groupBy('bank_code')->get()
```

> Esperado: 8 notificaciones, 4 BDV + 4 BNC.

**Test 2: Formato real BDV en raw_text**

```php
\App\Models\PaymentNotification::where('bank_code', 'bdv')->first()->raw_text
```

> Esperado: `"Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40"`

**Test 3: Formato real BNC en raw_text**

```php
\App\Models\PaymentNotification::where('bank_code', 'bnc')->first()->raw_text
```

> Esperado: `"BNC Pago Movil Recibido Bs.3.000,00 Telf.0424***3557 Dia:02/06/26-09:40 Ref:006236568762 Llamar al 0500-2625000 si no realizo esta Operacion"`

**Test 4: Seeder es idempotente (correr 2 veces no duplica)**

```php
(new \Database\Seeders\NotificationSampleSeeder)->run()
```

```php
\App\Models\PaymentNotification::count()   // sigue en 8
```

**Test 5: Todas las notificaciones en pending**

```php
\App\Models\PaymentNotification::where('parse_status', 'pending')->count()
```

> Esperado: 8 (todas pendientes de parseo).

#### Checklist de pruebas manuales

| # | Fase | Test | Qué verifica |
|---|------|------|--------------|
| 1 | S1 | SystemConfig count | Seeder inserta todas las configs |
| 2 | S1 | get/set/cache + typed | Sentinel cache, cast por tipo (int, bool, string) |
| 3 | S1 | Parser BDV + BNC | Regex reales parsean formatos del plan |
| 4 | S1 | normalizeRef | trim + uppercase funciona |
| 5 | S2 | Seeder 8 notificaciones | 4 BDV + 4 BNC, formato real |
| 6 | S2 | raw_text contiene formato real | No es texto fake |
| 7 | S2 | Idempotente | Segunda corrida no duplica |
| 8 | S2 | Todas pending | parse_status = 'pending' |
| 9 | S4 | verifyPayment null adminId | verified_by = null (auto-verificación) |
| 10 | S4 | cancelPayment con CancellationType | cancellation_type cast, cancelled_by null para 'system' |

---

## Fase 3 — Backend: Job de Parsing (✅ COMPLETADA)

### 3.1 Orquestador `IngestPaymentNotification` (job único, no eventos Eloquent)

En vez de escuchar `eloquent.created` (frágil para backfill), el endpoint de ingesta
hace dispatch explícito de un solo job orquestador:

```php
// En el controller/endpoint de ingesta:
// Normalizar bank_code ANTES de crear el registro (previene rotas de dedup y matching)
try {
    $notification = PaymentNotification::create([
        'device_id' => $device->id,
        'bank_code' => strtolower($request->bank_code),  // ← enforcement real
        'raw_title' => $request->title,
        'raw_body' => $request->body,
        'dedup_hash' => $request->dedup_hash, // calculado por la app Android
        'parse_status' => 'pending',
        // ...
    ]);
} catch (\Illuminate\Database\QueryException $e) {
    // IC-7: dedup_hash tiene constraint unique — reintento legítimo no causa 500
    if ($e->getCode() === '23505') { // unique_violation en PostgreSQL
        return response()->json(['status' => 'duplicate_ignored'], 200);
    }
    throw $e;
}

IngestPaymentNotification::dispatch($notification);
```

> **Por qué dispatch explícito en vez de eventos Eloquent**: Los eventos `eloquent.created` son frágiles para backfill — si queremos reprocesar notificaciones viejas, tendríamos que crear registros fake para disparar el evento. Con dispatch explícito, tanto el flujo normal (endpoint) como el backfill (comando) usan el mismo job. Cero lógica duplicada, más fácil de testear.

```php
class IngestPaymentNotification implements ShouldQueue {
    public function handle(): void
    {
        // 1. Parsear notificación
        $parsed = app(PaymentNotificationParser::class)->parse(
            $this->notification->bank_code,
            $this->notification->raw_body
        );

        if (!$parsed) {
            $this->notification->update(['parse_status' => 'failed']);
            // SystemAlert se crea internamente, no endpoint externo
            return;
        }

        // 2. Matching y verificación en transacción
        $match = null;
        $result = new ReconciliationResult();
        DB::transaction(function () use ($parsed, &$match, &$result) {
            $match = PaymentMatch::createFromParsed($this->notification, $parsed);
            $result = app(ReconciliationOrchestrator::class)->run($match);
        });

        // 3. Marcar notificación como parseada después de transacción exitosa
        $this->notification->update(['parse_status' => 'parsed']);

        // 4. Despachar eventos DESPUÉS del commit (IC-4)
        if ($result->verifiedPayment
            && !SystemConfig::get('reconciliation.shadow_mode_enabled')) {
            event(new PaymentVerified($result->verifiedPayment));
        }

        if ($result->cancelledPayment) {
            event(new PaymentCancelled($result->cancelledPayment, CancellationType::SystemDuplicate, $result->cancelledReason));
        }
    }
}
```

> **IC-4 resuelto**: Los eventos se despachan después del `DB::transaction()`.
> Si la transacción falla, los listeners no se ejecutan. No hay efectos parciales.

> **IC-5 resuelto**: `parse_status` se actualiza a `parsed` después de procesamiento exitoso,
> y a `failed` cuando el parser no puede extraer datos.

**Nota**: la creación del `PaymentMatch` + la llamada al orquestador están envueltas en
una transacción. Si el job falla a mitad de camino, Laravel reintenta el job completo.

### 3.2 Comando de backfill

```bash
php artisan reconciliation:reprocess --parse-status=failed
```

Itera sobre `PaymentNotification` con `parse_status = failed`, y dispacha `IngestPaymentNotification`
para cada una. Usa exactamente el mismo job que el flujo normal — cero lógica duplicada.

---

## Fase 4 — Backend: Motor de Matching (✅ COMPLETADA)

### 4.1 Job `ReconciliationOrchestrator` (invocado por `IngestPaymentNotification`, nunca por eventos Eloquent)

> **Transacción**: El orchestrator opera DENTRO de la transacción del job (`DB::transaction()` en `IngestPaymentNotification`). NO abre su propia transacción. En PostgreSQL, si abriera una crearía un savepoint, no una transacción nueva, y el `SELECT FOR UPDATE` podría no propagarse correctamente al padre.

Lógica de matching en ORDEN ESTRICTO:

> **Retorno**: `run()` devuelve un `ReconciliationResult` que expone los pagos verificados
> y cancelados para que el Job despache los eventos después del commit (IC-4).

```php
// DTO simple para comunicación entre orquestador y Job
class ReconciliationResult {
    public ?Payment $verifiedPayment = null;
    public ?Payment $cancelledPayment = null;
    public ?string $cancelledReason = null;
}
```

**Guard de estado**: Si el match ya fue procesado (status != 'unmatched'), retorna resultado vacío. Esto previene re-procesamiento en retry.

**Paso 0 — Validación de duplicado (CORRE PRIMERO, SIEMPRE)**:
Si `parsed_reference` no es null:
  - Buscar `Payment` con:
    - `status = Verified`
    - `transaction_id` normalizado = `parsed_reference` (ya existe en payments, NO en PagoMovilDetail)
  - **Precondición**: `transaction_id` en `payments` se guarda normalizado (trim + uppercase) cuando el cliente reporta el pago. Si no se normaliza al guardar, el match falla silenciosamente por diferencias de capitalización o espacios.
  - Si existe → **es duplicado**:
    - Marcar `PaymentMatch.match_status = duplicate_attempt`
    - Buscar el `Payment` pendiente que intentó reusar el código
      (el que tiene `status = Pending` y el mismo `transaction_id`)
    - Si existe `$attemptingPayment` → llamar `PaymentService::cancelPayment($attemptingPayment, CancellationType::SystemDuplicate, 'system', 'Referencia ya verificada')` y setear `$result->cancelledPayment = $attemptingPayment`
    - Si NO existe `$attemptingPayment` → solo enviar SystemAlert de duplicado (no hay pago que cancelar, la notificación es redundante pero no dañina)
    - Retornar resultado (no continuar a matching normal)

**Paso 1 — Matching normal** (solo si no hubo duplicado):
Buscar `Payment` con:
  - `status = Pending`
  - `amount_cents = parsed_amount_cents` (monto exacto)
  - `transaction_id` normalizado = `parsed_reference` (referencia exacta)
  - `created_at` dentro de la ventana configurable (`SystemConfig::get('reconciliation.match_window_hours', 72)`)
  - No tiene un `PaymentMatch` con `match_status = 'matched'` (usando `whereDoesntHave('paymentMatch', fn($q) => $q->where('match_status', 'matched'))` en Eloquent). **Alternativa**: confiar primariamente en `status = Pending` + el `SELECT FOR UPDATE` + Guard de estado, lo cual ya garantiza que un pago verificado no sea matcheado dos veces.
  - **`SELECT FOR UPDATE`** dentro de la transacción para bloquear la fila

> **OM-6: Ventana temporal basada en parsed_at, no created_at**: La fecha de la transacción
> bancaria (`parsed_at` del parser) es más precisa que `created_at` del pago (cuando el cliente
> reportó). El matching debe verificar que `payment.created_at` esté dentro de la ventana
> calculada desde `notification.parsed_at`, no desde `payment.created_at`.

> **Por qué reference + amount + ventana temporal (sin teléfono)**:
> El teléfono NO es confiable — algunos bancos enmascaran el número (ej. BNC: `0416***9503`).
> La referencia es única por transacción (6-10 dígitos). El monto confirma que no es una
> referencia reutilizada con monto distinto. La ventana temporal rechaza pagos viejos.

> **Protección contra condición de carrera**: Si dos notificaciones distintas llegan al mismo tiempo y matchean el mismo pago, el `SELECT FOR UPDATE` en el Paso 1 bloquea la fila del Payment. El segundo job espera a que el primero termine la transacción. Al llegar, el guard de estado (Paso 2) detecta que el pago ya no es `Pending` y descarta el match graciosamente. Sin `SELECT FOR UPDATE`, ambos jobs pasarían el guard de estado en paralelo y duplicarían la verificación.

**Paso 2**: Si hay UN solo candidato → **Match exacto** (determinista):
  - Set `PaymentMatch.payment_id = Payment.id`, `match_status = matched`, `matched_at = now()`
  - **Guard de estado**: Verificar `$payment->status === PaymentStatus::Pending` ANTES de continuar
    - Si NO es Pending (fue verificado manualmente mientras tanto): `match_status = unmatched`, salir
  - Lee `SystemConfig::get('reconciliation.shadow_mode_enabled', true)`
  - Si shadow mode OFF:
    - Llama `PaymentService::verifyPayment($payment, null)` (adminId null = automático)
    - Setea `$result->verifiedPayment = $payment`
  - Si shadow mode ON:
    - `match_status = pending` (solo sugerencia, espera confirmación manual)

**Paso 3**: Si hay MÚLTIPLES candidatos → **Match manual**:
  - `match_status = pending` (cola de revisión)
  - Crea notificación tipo `info` para el admin con los candidatos sugeridos
  - Dashboard distingue por `payment_id`: si es null → múltiples candidatos, si tiene valor → shadow mode

**Paso 4**: Si NO hay candidatos → **No match**:
  - `match_status = unmatched`
  - Crea notificación tipo `info` para el admin con "Pago recibido sin identificar"

### 4.2 Commando programado: expiración de pagos pendientes viejos

```bash
php artisan payments:expire-pending
```

(Scheduled en `routes/console.php` cada hora)

- Calcula expiración: `match_window_hours + 24h` (buffer para evitar race conditions)
- Busca `Payment` con `status = Pending` y `created_at` mayor al valor calculado
- Para cada pago:
  - Envuelve `cancelPayment()` en `DB::transaction()`
  - Después del commit, despacha `event(new PaymentCancelled($payment, CancellationType::SystemExpired, 'Pago expirado sin conciliación'))` (IC-4)
- Esto evita acumulación de registros huérfanos y cierra el ciclo de vida del `CancellationType::SystemExpired`

### 4.3 IC-1: Matching reverso (pago reportado → buscar notificación existente)

> **Por qué es crítico**: El PagoMóvil es casi instantáneo (1-3 segundos), pero el cliente
> tarda 1-5 minutos en reportar. En el ~80% de los casos, la notificación llega ANTES de que
> el cliente termine de llenar el formulario. Sin reverse match, el sistema falla en automatizar
> la mayoría de las transacciones.

> **Por qué síncrono y no Job**: El matching toma microsegundos (SELECT simple con índices).
> Un Job agrega latencia innecesaria (Redis/DB queue), complejidad (DTO, IC-4 post-commit),
> y peor UX (el usuario espera una respuesta HTTP, no un job async). El reverse match se ejecuta
> **síncronamente** dentro de `recordPayment()` y devuelve al frontend el resultado inmediato.

**Flujo**:
1. Llega notificación → `ReconciliationOrchestrator` no encuentra pago pendiente → crea `payment_matches` con `match_status = 'unmatched'`
2. Minutos después, el cliente reporta el pago → `PaymentService::recordPayment()` → `payment.status = 'pending'`
3. `recordPayment()` busca `payment_matches.match_status = 'unmatched'` con misma referencia + monto
4. Si encuentra → crea match, verifica automáticamente (respetando Shadow Mode), devuelve resultado al frontend

**En `PaymentService`, nuevo método `attemptReverseMatch()`**:

```php
/**
 * Buscar notificaciones unmatched que coincidan con este pago.
 * Ejecuta síncronamente — el matching toma microsegundos.
 *
 * Retorna eventos pendientes para que el controller los despache después del commit (IC-4).
 *
 * @return array{matched: bool, payment: Payment, events: array}
 */
private array $pendingEvents = [];

/**
 * Buscar notificaciones unmatched que coincidan con este pago.
 * Ejecuta síncronamente — el matching toma microsegundos.
 * Acumula eventos pendientes en $this->pendingEvents para que el controller
 * los despache después del commit (IC-4).
 */
public function attemptReverseMatch(Payment $payment): void
{
    // Guard: solo aplica a pagos pendientes de PagoMóvil
    if ($payment->status !== PaymentStatus::Pending) {
        return;
    }
    if ($payment->payment_method !== 'pago_movil') {
        return;
    }

    // Paso 0: Validación de duplicado
    $alreadyVerified = Payment::where('status', 'Verified')
        ->where('transaction_id', normalizeRef($payment->transaction_id))
        ->exists();

    if ($alreadyVerified) {
        $this->cancelPayment(
            $payment,
            CancellationType::SystemDuplicate,
            'system',
            'Referencia ya verificada en otro pago'
        );
        $this->pendingEvents[] = new PaymentCancelled($payment, CancellationType::SystemDuplicate, 'Referencia ya verificada en otro pago');
        return;
    }

    // Buscar notificaciones unmatched
    $match = PaymentMatch::where('match_status', 'unmatched')
        ->where('parsed_reference', normalizeRef($payment->transaction_id))
        ->where('parsed_amount_cents', $payment->amount_cents)
        ->where('created_at', '>=', now()->subHours(
            SystemConfig::get('reconciliation.match_window_hours', 72)
        ))
        ->first();

    if ($match) {
        app(ReconciliationOrchestrator::class)->runReverse($match, $payment);
        $payment->refresh();

        if ($payment->status === PaymentStatus::Verified) {
            $this->pendingEvents[] = new PaymentVerified($payment);
        }
    }
}

/**
 * Retorna y limpia eventos pendientes acumulados por attemptReverseMatch().
 * Llamar después de que la transacción haya commiteado (IC-4).
 */
public function getPendingEvents(): array
{
    $events = $this->pendingEvents;
    $this->pendingEvents = [];
    return $events;
}
```

**En `PaymentService::recordPayment()`, al final** (retorna `Payment`, NO array):

```php
// ... código existente que guarda el pago ...

// NUEVO: Intentar matching reverso (síncrono, microsegundos)
$this->attemptReverseMatch($payment);

return $payment;  // ✅ interfaz intacta
```

> **Atomicidad**: `attemptReverseMatch()` corre dentro de la misma transacción que `recordPayment()`. Si el matching falla o no encuentra coincidencia, el pago se guarda normalmente sin rollback.

**En el Controller** (después de llamar a `recordPayment()`):

```php
$payment = $paymentService->recordPayment($order, ...);

// Despachar eventos pendientes DESPUÉS del commit (IC-4)
foreach ($paymentService->getPendingEvents() as $event) {
    event($event);
}

// Devolver resultado al frontend
if ($payment->status === PaymentStatus::Verified) {
    return back()->with('success', 'Pago verificado automáticamente al instante.');
}

return back()->with('success', 'Pago reportado correctamente. Esperando verificación.');
```

**En `ReconciliationOrchestrator`, nuevo método `runReverse()`**:

**En `ReconciliationOrchestrator`, nuevo método `runReverse()`**:

```php
public function runReverse(PaymentMatch $match, Payment $payment): void
{
    // Guard de estado (mismo que flujo forward)
    if ($payment->status !== PaymentStatus::Pending) {
        $match->update(['match_status' => 'unmatched']);
        return;
    }

    // Evaluar Shadow Mode ANTES de decidir el status (evita doble escritura)
    $status = SystemConfig::get('reconciliation.shadow_mode_enabled', true)
        ? 'pending'
        : 'matched';

    // Vincular el pago al match
    $match->update([
        'payment_id' => $payment->id,
        'match_status' => $status,
        'matched_at' => now(),
    ]);

    // Auto-verificar solo si no es shadow mode
    if ($status === 'matched') {
        app(PaymentService::class)->verifyPayment($payment, null);
    }
}
```

> **IC-4 en reverse match**: `attemptReverseMatch()` retorna eventos pendientes en el array
> `events`. El controller es responsable de despacharlos después del commit. Esto mantiene
> la garantía de IC-4: los listeners solo corren tras commits exitosos.

> **Por qué síncrono y no Job**: Matching toma microsegundos (SELECT con índices). Job agrega
> latencia (queue Redis/DB), complejidad (DTO, IC-4 post-commit), y peor UX (respuesta HTTP
> diferida). Si en el futuro se agregan pasos pesados (notificación push, email), se puede
> extraer a un Job sin cambiar la interfaz pública de `recordPayment()`.

> **Por qué query `payment_matches` y no `payment_notifications`**: `payment_matches` es la
> tabla de resultados del matching — ya tiene `match_status`, `parsed_reference` y
> `parsed_amount_cents`. `payment_notifications` NO tiene `match_status` (tiene `parse_status`
> que es diferente). La query directa sobre `payment_matches` es más simple y eficiente.

---

---

### Manual: S5a (PaymentMatch + ReconciliationOrchestrator)

> **Prerequisito**: Migración corrida, SystemConfigSeeder ejecutado, NotificationSampleSeeder ejecutado, un Tenant existente en DB.

> **Precaución — DB compartida**: Todos los tests del proyecto usan la misma PostgreSQL (`spatie-laravel-multitenancy-testing`). Si se ejecutan tests en paralelo (varios archivos a la vez), las migraciones colisionan y producen errores de "ya existe" / "no existe" en las tablas. Para las pruebas manuales en tinker esto no aplica, pero al correr Pest recordar usar `--filter` por archivo individual.

> **Precaución — PaymentNotification $fillable**: El modelo `PaymentNotification` tiene `$fillable` restringido — solo permite asignar `parse_status`, `parsed_data`, `parse_error`, `parsed_at`. Para crear notificaciones en tinker usar `forceCreate()` en vez de `create()`.

> **Precaución — ParsedPayment constructor**: Requiere `parsedAt` como 4to parámetro (acepta `null`). `rawGroups` es opcional (5to param).

> **Precaución — Shadow mode**: Por defecto `reconciliation.shadow_mode_enabled = true`. En shadow mode, el orchestrator encuentra el match pero NO verifica el payment (match_status = "pending"). Para verificar automáticamente hay que desactivar shadow mode primero.

#### Preparación de DB

```bash
# 1. Fresh migrate
php artisan migrate:fresh --database=landlord --no-interaction
php artisan migrate --path=database/migrations/landlord --database=landlord --no-interaction

# 2. Seed SystemConfig (sin esto el parser no funciona)
php artisan db:seed --class=Database\Seeders\SystemConfigSeeder --database=landlord --no-interaction

# 3. Seed notifications de prueba
php artisan db:seed --class=Database\Seeders\NotificationSampleSeeder --database=landlord --no-interaction

# 4. Crear tenant manual (necesario para crear orders y payments)
php artisan tinker
```

```php
$plan = \App\Models\Plan::factory()->createQuietly(['slug' => 'free'])
$tenant = \App\Models\Tenant::create(['name' => 'Test Tenant', 'domain' => 'tenant1.spatie-laravel-multitenancy.test', 'database' => 'tenant_test'])
```

> **Nota**: El Tenant dispara un observer que busca un plan con slug 'free'. Por eso se crea primero ese plan.

```bash
# Listo, cerrar tinker y volver a abrir para empezar las pruebas
# (o re-usar la misma sesión si sigue abierta)
```

#### Test 1: createFromParsed básico

```php
$noti = \App\Models\PaymentNotification::where('bank_code', 'bdv')->inRandomOrder()->first()
```

```php
$parser = new \App\Services\Payment\PaymentNotificationParser
```

```php
$parsed = $parser->parse($noti->bank_code, $noti->raw_text)
```

> Esperado: `$parsed` NO es null. Tiene `amountCents`, `reference`, `senderPhoneLast4`.

```php
$match = \App\Models\PaymentMatch::createFromParsed($noti, $parsed)
```

```php
$match->parsed_reference       // ref normalizada ej. "123456789012"
$match->parsed_amount_cents    // ej. 150000
$match->match_status           // "unmatched"
$match->id                     // > 0
```

#### Test 2: Idempotencia de createFromParsed

```php
$m2 = \App\Models\PaymentMatch::createFromParsed($noti, $parsed)
$m2->id === $match->id         // true (mismo ID)
```

#### Test 3: Single match (shadow mode)

```php
$plan = \App\Models\Plan::factory()->createQuietly()
$order = \App\Models\Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id])
$payment = \App\Models\Payment::factory()->createQuietly(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'status' => \App\Enums\PaymentStatus::Pending, 'amount_cents' => $match->parsed_amount_cents, 'transaction_id' => $match->parsed_reference])
$service = app(\App\Services\Payment\PaymentService::class)
$orchestrator = new \App\Services\Payment\ReconciliationOrchestrator($service)
$result = \Illuminate\Support\Facades\DB::transaction(function() use ($match, $orchestrator) { return $orchestrator->run($match); })
$match->refresh()
```

```php
$match->match_status           // "pending" (shadow mode activo por defecto)
$result->verifiedPayment       // null (shadow mode — no auto-verifica)
$match->payment_id             // ID del payment (se vinculó)
```

#### Test 4: Sin candidatos

> **Precaución**: `PaymentNotification::create()` NO permite asignar `bank_code`/`raw_text` por `$fillable`. Usar `forceCreate()`.

```php
$nuevoParsed = new \App\Services\Payment\ParsedPayment(reference: "NOEXISTE999", amountCents: 1, senderPhoneLast4: "0000", parsedAt: null, rawGroups: [])
$noti2 = \App\Models\PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'Test sin candidato '.uniqid(), 'dedup_hash' => 'test-'.uniqid(), 'parse_status' => 'pending'])
$match2 = \App\Models\PaymentMatch::createFromParsed($noti2, $nuevoParsed)
$result2 = \Illuminate\Support\Facades\DB::transaction(function() use ($match2, $orchestrator) { return $orchestrator->run($match2); })
$match2->refresh()
```

```php
$match2->match_status          // "unmatched"
$match2->payment_id            // null
```

#### Test 5: Duplicate detection

```php
// Primero verificar el payment del Test 3
$service->verifyPayment($payment)
$payment->refresh()
$payment->status->value        // "verified"
```

```php
// Crear otra notificación con misma referencia
$noti3 = \App\Models\PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'Otra noti misma ref '.uniqid(), 'dedup_hash' => 'test-'.uniqid(), 'parse_status' => 'pending'])
$parsedDup = $parser->parse('bdv', 'Recibiste un PagomovilBDV por Bs. 1.500,00 del 0424-1112222 Ref: 123456789012 en fecha: 15-06-26 hora: 14:30')
$match3 = \App\Models\PaymentMatch::createFromParsed($noti3, $parsedDup)
$result3 = \Illuminate\Support\Facades\DB::transaction(function() use ($match3, $orchestrator) { return $orchestrator->run($match3); })
$match3->refresh()
```

```php
$match3->match_status          // "duplicate_attempt"
$result3->cancelledPayment     // null (no hay pending con misma ref, ya está verified)
```

#### Checklist S5a

| # | Test | Qué verifica |
|---|------|--------------|
| 1 | createFromParsed | Match creado desde notificación parseada |
| 2 | Idempotencia | Misma noti → mismo match (no duplica) |
| 3 | Single match (shadow) | Match_status = "pending", payment vinculado |
| 4 | Sin candidatos | Match_status = "unmatched", payment_id = null |
| 5 | Duplicate detection | Match_status = "duplicate_attempt" |

---

#### verifyPayment con null adminId (verificación automática)

```php
$tenant = App\Models\Tenant::first()
$plan = App\Models\Plan::factory()->createQuietly()
$order = App\Models\Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id])
$payment = App\Models\Payment::factory()->createQuietly(['order_id' => $order->id, 'tenant_id' => $tenant->id, 'status' => App\Enums\PaymentStatus::Pending])
$service = app(App\Services\Payment\PaymentService::class)
$service->verifyPayment($payment)
$payment->refresh()
$payment->verified_by     // null
$payment->verified_at     // Carbon instance
$payment->status->value   // "verified"
```

#### cancelPayment con CancellationType::SystemDuplicate

```php
$p2 = App\Models\Payment::factory()->createQuietly(['order_id' => $order->id, 'tenant_id' => $tenant->id, 'status' => App\Enums\PaymentStatus::Pending])
$service->cancelPayment($p2, App\Enums\CancellationType::SystemDuplicate, 'system', 'Referencia ya verificada')
$p2->refresh()
$p2->cancellation_type             // CancellationType::SystemDuplicate
$p2->cancellation_type->value     // "system_duplicate"
$p2->cancellation_reason          // "Referencia ya verificada"
$p2->cancelled_by                 // null (actorId era 'system')
```

---

### Manual: S5b (IngestPaymentNotification + Comandos)

> **Prerequisito**: Migraciones landlord corridas, SystemConfigSeeder ejecutado, un Tenant existente con plan 'free' (creado en S5a). DB fresca para empezar limpio.

> **Precaución — DB compartida**: Todos los tests usan la misma PostgreSQL (`spatie-laravel-multitenancy-testing`). Para pruebas manuales en tinker esto no aplica, pero al correr Pest recordar usar `--filter` por archivo individual.

> **Precaución — PaymentNotification $fillable**: Usar `forceCreate()` para crear notificaciones en tinker (los campos raw son inmutables).

> **Precaución — Order requiere plan_id OR resource_id**: La tabla `orders` tiene una constraint `chk_exclusive_buyable` que exige al menos uno de los dos. Siempre pasar `plan_id` al crear la orden.

> **Precaución — PaymentStatus es enum con backing value**: El cast de Eloquent usa `PaymentStatus::class`. Al pasar el valor directamente, usar minúscula: `'pending'`, no `'Pending'`.

> **Precaución — created_at no es fillable en Payment**: Al crear pagos en tinker, Eloquent auto-asigna `created_at = now()`. Para simular pagos viejos (test de expire), usar `DB::table('payments')->update(['created_at' => ...])` después del create.

> **Precaución — NotTenantAware**: El job `IngestPaymentNotification` implementa `NotTenantAware` porque opera exclusivamente en DB landlord. Sin esto, el paquete Spatie Multitenancy intenta restaurar el tenant activo (que no existe en contexto de tinker o reprocess command) y los cambios no persisten.

> **Precaución — PendingDispatch en tinker**: `dispatch()` retorna un `PendingDispatch` que se encola en su destructor PHP. En tinker, el destructor se ejecuta al salir del script. Si se necesita probar el encolado y procesamiento, mejor correr el comando de reprocess y luego `queue:work --stop-when-empty` desde bash.

#### Preparación de DB

```bash
# 1. Fresh migrate
php artisan migrate:fresh --database=landlord --no-interaction
php artisan migrate --path=database/migrations/landlord --database=landlord --no-interaction

# 2. Seed configuraciones
php artisan db:seed --class=Database\Seeders\SystemConfigSeeder --database=landlord --no-interaction
php artisan db:seed --class=Database\Seeders\PlansSeeder --database=landlord --no-interaction
php artisan db:seed --class=Database\Seeders\PaymentMethodConfigSeeder --database=landlord --no-interaction

# 3. Crear tenant + plan free
php artisan tinker
```

```php
$plan = \App\Models\Plan::where('slug', 'free')->first() ?? \App\Models\Plan::factory()->createQuietly(['slug' => 'free'])
$tenant = \App\Models\Tenant::first() ?? \App\Models\Tenant::create(['name' => 'Test Tenant', 'domain' => 'tenant1.spatie-laravel-multitenancy.test', 'database' => 'tenant_test'])
```

> **Nota**: El Tenant dispara un observer que busca subscription con plan 'free'. Si es la primera vez, se crea automáticamente.

```bash
# Cerrar tinker y empezar las pruebas (o re-usar la misma sesión)
```

---

#### Bloque 1: Pipeline completo (parse → match → verify, shadow mode OFF)

**¿Qué prueba?**: El flujo completo del job `IngestPaymentNotification`: recibe una notificación cruda con formato BDV real, el parser la entiende, se crea un PaymentMatch, el orchestrator encuentra un pago Pending con la misma referencia + monto, y con shadow mode OFF el pago queda automáticamente `Verified`.

**Datos**: Notificación BDV con ref `006236568762`, monto Bs. 300,00. Pago Pending con misma ref y monto.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
$order = \App\Models\Order::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 30000, 'status' => 'pending'])
$payment = \App\Models\Payment::create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'amount_cents' => 30000, 'transaction_id' => '006236568762', 'status' => 'pending', 'payment_method' => 'pago_movil', 'currency' => 'VES'])
```

```php
$noti = \App\Models\PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'Recibiste un PagomovilBDV por Bs. 300,00 del 041295039503 Ref: 006236568762 en fecha: 20-06-26 hora: 10:30', 'dedup_hash' => 'test-pipe-' . uniqid(), 'parse_status' => 'pending'])
```

```php
\App\Models\SystemConfig::set('reconciliation.shadow_mode_enabled', false)
```

```php
$job = new \App\Jobs\IngestPaymentNotification($noti)
$job->handle()
```

```php
$noti->refresh()
$noti->parse_status    // "parsed"
```

```php
$match = \App\Models\PaymentMatch::first()
$match->match_status   // "matched"
```

```php
$payment->refresh()
$payment->status->value      // "verified"
$payment->verified_at        // Carbon instance (no null)
```

```php
\App\Models\SystemConfig::set('reconciliation.shadow_mode_enabled', true)   // restaurar default
```

> **Resultado esperado**: `parse_status = parsed`, `match_status = matched`, `payment.status = verified`.

---

#### Bloque 2: Parse failure

**¿Qué prueba?**: El job maneja correctamente una notificación cuyo texto no matchea ningún regex. Debe quedar como `parse_status = failed` sin crear PaymentMatch ni crashear.

**Datos**: Texto inválido que no coincide con ningún patrón BDV/BNC.

```php
$notiFail = \App\Models\PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'Este texto no coincide con ningun regex de BDV', 'dedup_hash' => 'test-fail-' . uniqid(), 'parse_status' => 'pending'])
```

```php
$job = new \App\Jobs\IngestPaymentNotification($notiFail)
$job->handle()
```

```php
$notiFail->refresh()
$notiFail->parse_status    // "failed"
$notiFail->parse_error     // "Regex did not match"
```

```php
\App\Models\PaymentMatch::where('payment_notification_id', $notiFail->id)->count()   // 0
```

> **Resultado esperado**: `parse_status = failed`, sin PaymentMatch creado.

---

#### Bloque 3: ExpirePendingPayments

**¿Qué prueba?**: El comando `payments:expire-pending` cancela pagos Pending más viejos que `match_window_hours + 24h` (default 72+24=96h). Respeta los pagos jóvenes que aún están dentro de la ventana.

**Datos**: Pago viejo (120h atrás → debe expirar). Pago reciente (24h atrás → debe seguir Pending). La ventana default es 72h + 24h buffer = 96h.

**Precaución — Variables perdidas al salir de tinker**: Este bloque requiere salir de tinker para ejecutar el comando artisan. Al volver a entrar, todas las variables PHP de la sesión anterior se pierden. Usar `where('transaction_id', ...)->first()` para recuperar los modelos.

**Precaución — created_at**: `created_at` no es fillable en Payment. Se debe setear vía query builder después del create.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
```

```php
// Pago VIEJO (120h atrás → debe expirar)
$orderV = \App\Models\Order::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 10000, 'status' => 'pending'])
$pagoV = \App\Models\Payment::create(['tenant_id' => $tenant->id, 'order_id' => $orderV->id, 'amount_cents' => 10000, 'transaction_id' => 'VIEJO001', 'status' => 'pending', 'payment_method' => 'pago_movil', 'currency' => 'VES'])
\Illuminate\Support\Facades\DB::table('payments')->where('id', $pagoV->id)->update(['created_at' => now()->subHours(120)])
$pagoV->refresh()
$pagoV->created_at->diffInHours(now())   // >= 120
```

```php
// Pago RECIENTE (24h atrás → NO debe expirar)
$orderR = \App\Models\Order::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 20000, 'status' => 'pending'])
$pagoR = \App\Models\Payment::create(['tenant_id' => $tenant->id, 'order_id' => $orderR->id, 'amount_cents' => 20000, 'transaction_id' => 'RECIENTE001', 'status' => 'pending', 'payment_method' => 'pago_movil', 'currency' => 'VES'])
\Illuminate\Support\Facades\DB::table('payments')->where('id', $pagoR->id)->update(['created_at' => now()->subHours(24)])
$pagoR->refresh()
$pagoR->created_at->diffInHours(now())   // >= 24
```

```bash
# Salir de tinker y ejecutar el comando:
php artisan payments:expire-pending
```

> **Resultado esperado en la salida del comando**: `Cancelled 1 expired pending payment(s).`

```bash
# Volver a tinker para verificar:
# NOTA: las variables $pagoV/$pagoR de la sesión anterior YA NO EXISTEN.
# Buscar los pagos por transaction_id en vez de reusar variables.
php artisan tinker
```

```php
$pagoV = \App\Models\Payment::where('transaction_id', 'VIEJO001')->first()
$pagoV->status->value              // "cancelled"
$pagoV->cancellation_type->value   // "system_expired"
$pagoV->cancelled_at               // Carbon instance
```

```php
$pagoR = \App\Models\Payment::where('transaction_id', 'RECIENTE001')->first()
$pagoR->status->value              // "pending" (no se tocó)
```

> **Resultado esperado**: Pago viejo → `cancelled` con `SystemExpired`. Pago reciente → sigue `pending`.

---

#### Bloque 4: ReprocessFailedNotifications

**¿Qué prueba?**: El comando `reconciliation:reprocess --parse-status=failed` redispara jobs para notificaciones con parse fallido. Si el texto ahora es válido (por un cambio de regex, por ejemplo), el job parsea correctamente y la notificación queda `parsed`. Incluye verificación de que el queue worker procesa los jobs exitosamente.

**Datos**: 2 notificaciones con `parse_status = failed` pero con texto BDV válido (simulan notificaciones que fallaron por regex viejo, luego se actualizó el regex, ahora deben reprocesarse).

```php
$notiR1 = \App\Models\PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'Recibiste un PagomovilBDV por Bs. 500,00 del 041295039503 Ref: 005555555555 en fecha: 20-06-26 hora: 10:30', 'dedup_hash' => 'test-repro-' . uniqid(), 'parse_status' => 'failed'])
$notiR2 = \App\Models\PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'Recibiste un PagomovilBDV por Bs. 600,00 del 041295039503 Ref: 006666666666 en fecha: 20-06-26 hora: 11:00', 'dedup_hash' => 'test-repro-' . uniqid(), 'parse_status' => 'failed'])
```

```bash
# Salir de tinker. Ejecutar reprocess + queue worker:
php artisan reconciliation:reprocess --parse-status=failed
```

> **Resultado esperado**: `Dispatched 2 IngestPaymentNotification job(s) for parse_status [failed].`

```bash
php artisan queue:work --stop-when-empty
```

> **Resultado esperado**: Debe mostrar los jobs ejecutándose (Laravel 13 muestra `RUNNING` y `DONE`).

```bash
# Volver a tinker para verificar:
# NOTA: las variables $notiR1/$notiR2 de la sesión anterior YA NO EXISTEN.
# Buscar por dedup_hash o simplemente listar las parsed.
php artisan tinker
```

```php
\App\Models\PaymentNotification::where('parse_status', 'parsed')->where('dedup_hash', 'like', '%test-repro%')->get()->each(function($n) {
    echo "#{$n->id} status={$n->parse_status}" . PHP_EOL;
})
```

> **Resultado esperado**: Ambas notificaciones ahora están `parsed`.

---

#### Checklist S5b

| # | Bloque | Qué verifica | Resultado esperado |
|---|--------|-------------|-------------------|
| 1 | Pipeline completo (shadow OFF) | Job parsea BDV, crea match, auto-verifica pago | `parse_status = parsed`, `match_status = matched`, `payment.status = verified` |
| 2 | Parse failure | Texto inválido → no crashea, no crea match | `parse_status = failed`, `parse_error = "Regex did not match"`, 0 PaymentMatch |
| 3 | ExpirePendingPayments | Pago viejo expira, pago reciente respeta ventana | Viejo → `cancelled` con `SystemExpired`. Reciente → sigue `pending` |
| 4 | ReprocessFailedNotifications + queue | Failed con texto válido → reprocess → queue:work → parsed | Ambas notis quedan `parsed` |

### Manual: S6 (Reverse Matching)

> **Prerequisito**: Migraciones landlord corridas, SystemConfigSeeder ejecutado, un Tenant existente con plan 'free', PaymentMethodConfigSeeder ejecutado. DB fresca para empezar limpio.

> **Precaución — DB compartida**: Todos los tests usan la misma PostgreSQL (`spatie-laravel-multitenancy-testing`). Para pruebas manuales en tinker esto no aplica, pero al correr Pest recordar usar `--filter` por archivo individual.

> **Precaución — PaymentNotification $fillable**: Usar `forceCreate()` para crear notificaciones en tinker (los campos raw son inmutables).

> **Precaución — Order requiere plan_id**: La tabla `orders` tiene constraint `chk_exclusive_buyable` que exige al menos `plan_id` o `resource_id`. Siempre pasar `plan_id`.

> **Shadow mode — qué es y por qué existe**: El sistema tiene un interruptor `reconciliation.shadow_mode_enabled` que controla si los matches encontrados se verifican automáticamente o solo se sugieren. Cuando está **ON** (default), el motor de matching encuentra el match, vincula los registros, pero NO cambia el estado del pago — queda como `match_status = pending` para que el admin revise y confirme manualmente. Cuando está **OFF**, el matching auto-verifica el pago y lo deja como `Verified` sin intervención humana.
>
> **¿Por qué tenerlo OFF alguna vez si ON es más seguro?**: Porque el propósito del sistema es automatizar pagos. Una vez que se validó que el parser + matching funcionan correctamente (observando 1-2 semanas en shadow mode), se desactiva para que los pagos se automaticen. El riesgo de tenerlo OFF es un falso positivo — un pago que se verifica por error y activa un servicio sin haber recibido el dinero real. Por eso se recomienda empezar siempre con shadow mode ON y solo desactivarlo cuando el admin confíe en el sistema.
>
> **Resumen para las pruebas manuales**: Los Bloques 1 y 2 prueban el escenario con shadow mode OFF (auto-verificación). El Bloque 3 prueba que con shadow mode ON el pago NO se auto-verifica. El Bloque 4 no depende del shadow mode porque el guard de método (`pago_movil`) corta antes. Siempre verificar el estado actual del shadow mode al inicio de cada bloque porque el cache de `SystemConfig` puede quedar desactualizado entre comandos tinker.

> **Precaución — Cache de SystemConfig**: `SystemConfig::set()` invalida el cache, pero el siguiente comando tinker (proceso separado) podría tener un valor cacheado desactualizado. Antes de cada bloque, verificar el estado actual con `SystemConfig::get('reconciliation.shadow_mode_enabled')`. Para bloques que requieren shadow mode OFF, desactivar explícitamente al inicio del bloque, NO asumir que quedó restaurado de un bloque anterior.

> **Precaución — PaymentMethodConfig usa columna `type` no `method`**: La columna en `payment_method_configs` se llama `type`, no `method`. Usar `where('type', 'pago_movil')` o `where('type', 'bank_transfer')` según corresponda.

> **Precaución — payment.transaction_id unique**: La columna `transaction_id` tiene unique constraint. Usar referencias distintas para cada bloque (ej. prefijar con `S6B1-`, `S6B2-`, etc.) o correr los bloques con DB fresca.

> **Precaución — recordPayment() ahora acepta transactionId**: La firma de `recordPayment()` ahora incluye `?string $transactionId = null`. Al pasar una referencia, se normaliza automáticamente con `normalizeRef()`. Los callers existentes (sin transactionId) no se ven afectados.

> **Precaución — $pendingEvents buffer**: Cuando el reverse match encuentra una notificación y auto-verifica el pago, `PaymentVerified` se almacena en `$pendingEvents` en vez de dispararse inmediatamente. El controller los despacha después del commit con `getPendingEvents()`. Para pruebas en tinker, llamar manualmente a `dispatch()` si se necesita verificar el event dispatch.

> **Precaución — PendingDispatch en tinker**: `dispatch()` retorna un `PendingDispatch` que se encola en su destructor PHP. En tinker, el destructor se ejecuta al salir del script. Si se necesita verificar el event dispatch, mejor envolver en `DB::transaction()` o verificar el estado final del payment directamente.

#### Preparación de DB

```bash
# 1. Fresh migrate
php artisan migrate:fresh --database=landlord --no-interaction
php artisan migrate --path=database/migrations/landlord --database=landlord --no-interaction

# 2. Seed configuraciones
php artisan db:seed --class=Database\Seeders\SystemConfigSeeder --database=landlord --no-interaction
php artisan db:seed --class=Database\Seeders\PlansSeeder --database=landlord --no-interaction
php artisan db:seed --class=Database\Seeders\PaymentMethodConfigSeeder --database=landlord --no-interaction

# 3. Crear tenant + plan free
php artisan tinker
```

```php
$plan = \App\Models\Plan::where('slug', 'free')->first() ?? \App\Models\Plan::factory()->createQuietly(['slug' => 'free'])
$tenant = \App\Models\Tenant::first() ?? \App\Models\Tenant::create(['name' => 'Test Tenant', 'domain' => 'tenant1.spatie-laravel-multitenancy.test', 'database' => 'tenant_test'])
```

> **Nota**: El Tenant dispara un observer que busca subscription con plan 'free'. Si es la primera vez, se crea automáticamente.

```bash
# Cerrar tinker y empezar las pruebas (o re-usar la misma sesión)
```

---

#### Bloque 1: Reverse match — happy path (shadow mode OFF)

**¿Qué prueba?**: El flujo completo de reverse matching. Primero llega una notificación PagoMóvil que se parsea y queda como `PaymentMatch` unmatched. Luego el cliente reporta manualmente un pago con la misma referencia + monto → `recordPayment()` llama a `attemptReverseMatch()`, encuentra la notificación, y con shadow mode OFF auto-verifica el pago. El pago queda `Verified` sin intervención del admin.

**Datos**: Notificación BDV con ref `006236568762`, monto Bs. 300,00. Mismo ref + monto en `recordPayment()`.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
```

```php
// Crear la notificación (simula que llegó primero el aviso del banco)
$noti = \App\Models\PaymentNotification::forceCreate([
    'bank_code' => 'bdv',
    'raw_text' => 'Recibiste un PagomovilBDV por Bs. 300,00 del 041295039503 Ref: 006236568762 en fecha: 20-06-26 hora: 10:30',
    'dedup_hash' => 'test-s6-b1-' . uniqid(),
    'parse_status' => 'pending',
])
```

```php
// Parsear la notificación y crear el PaymentMatch (simula lo que hace IngestPaymentNotification)
$parser = new \App\Services\Payment\PaymentNotificationParser
$parsed = $parser->parse($noti->bank_code, $noti->raw_text)
$match = \App\Models\PaymentMatch::createFromParsed($noti, $parsed)
```

```php
// Verificar que el match quedó unmatched (esperando un pago)

$match->match_status        // "unmatched"
$match->parsed_reference    // "006236568762"
$match->parsed_amount_cents // 30000
```

```php
// Verificar estado actual de shadow mode (debe ser ON por defecto)
\App\Models\SystemConfig::get('reconciliation.shadow_mode_enabled')  // true
```

```php
// Desactivar shadow mode para que el reverse match auto-verifique
\App\Models\SystemConfig::set('reconciliation.shadow_mode_enabled', false)
\App\Models\SystemConfig::get('reconciliation.shadow_mode_enabled')  // false (confirmar)
```

```php
// Crear orden y pago (simula que el cliente reporta manualmente el pago)
$order = \App\Models\Order::create([
    'tenant_id' => $tenant->id,
    'plan_id' => $plan->id,
    'total_cents' => 30000,
    'status' => 'pending',
    'expires_at' => now()->addHours(48),
])
```

```php
// recordPayment() con la MISMA referencia + monto que la notificación
// Pasar transaction_id para que se normalice y matchee con el PaymentMatch
$service = app(\App\Services\Payment\PaymentService::class)
$payment = $service->recordPayment(
    $order,
    30000,
    'pago_movil',
    \App\Models\PaymentMethodConfig::where('type', 'pago_movil')->where('is_active', true)->first()?->id,
    [
        'sender_bank' => 'Banco de Prueba',
        'sender_phone' => '041295039503',
        'sender_id' => 'V12345678',
        'payment_date' => now()->format('Y-m-d'),
        'concept' => 'Pago de prueba S6',
    ],
    '006236568762',  // transaction_id — se normaliza internamente
)
```

```php
// Verificar que el pago se auto-verificó por reverse match
$payment->refresh()
$payment->status->value               // "verified"
$payment->verified_at                 // Carbon instance (no null)
$payment->verified_by                 // null (auto-verificado, no admin)
```

```php
// Verificar que el PaymentMatch se vinculó correctamente
$match->refresh()
$match->match_status                  // "matched"
$match->payment_id                    // ID del payment ($payment->id)
```

```php
// Restaurar shadow mode a su default
\App\Models\SystemConfig::set('reconciliation.shadow_mode_enabled', true)
```

> **Resultado esperado**: `match_status = matched`, `payment.status = verified`, `verified_by = null`.

---

#### Bloque 2: Sin notificación previa (no reverse match)

**¿Qué prueba?**: Cuando NO existe una notificación unmatched con la misma referencia, `attemptReverseMatch()` termina sin efecto. El pago se crea normalmente como `Pending`, y el forward flow (S5a/S5b) lo matcheará cuando llegue la notificación más tarde. Verifica que el reverse match no crashea ni interfiere.

**Datos**: Pago con ref `REFNOEXISTE999`, monto Bs. 500,00. Sin notificación previa.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
$order = \App\Models\Order::create([
    'tenant_id' => $tenant->id,
    'plan_id' => $plan->id,
    'total_cents' => 50000,
    'status' => 'pending',
    'expires_at' => now()->addHours(48),
])
```

```php
$service = app(\App\Services\Payment\PaymentService::class)
$payment = $service->recordPayment(
    $order,
    50000,
    'pago_movil',
    \App\Models\PaymentMethodConfig::where('type', 'pago_movil')->where('is_active', true)->first()?->id,
    [
        'sender_bank' => 'Banco de Prueba',
        'sender_phone' => '04161234567',
        'sender_id' => 'V87654321',
        'payment_date' => now()->format('Y-m-d'),
    ],
    'REFNOEXISTE999',
)
```

```php
// Verificar que el pago quedó Pending (sin reverse match)
$payment->refresh()
$payment->status->value               // "pending"
$payment->verified_at                 // null
```

> **Resultado esperado**: Pago queda `Pending`. No se vinculó ningún PaymentMatch.

---

#### Bloque 3: Shadow mode ON — sugiere pero no auto-verifica

**¿Qué prueba?**: Con shadow mode activo (default), el reverse match encuentra la notificación, vincula el match, pero NO verifica el pago. El admin debe revisar y confirmar manualmente.

**Datos**: Notificación BDV con ref `006666666666`, monto Bs. 150,00. Shadow mode activo por defecto.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
```

```php
// Asegurar que shadow mode está ON (setear explicitamente por si un bloque anterior lo modificó)
\App\Models\SystemConfig::set('reconciliation.shadow_mode_enabled', true)
\App\Models\SystemConfig::get('reconciliation.shadow_mode_enabled')   // true
```

```php
// Crear notificación + PaymentMatch
$noti = \App\Models\PaymentNotification::forceCreate([
    'bank_code' => 'bdv',
    'raw_text' => 'Recibiste un PagomovilBDV por Bs. 150,00 del 041295039503 Ref: 006666666666 en fecha: 20-06-26 hora: 11:00',
    'dedup_hash' => 'test-s6-b3-' . uniqid(),
    'parse_status' => 'pending',
])
$parser = new \App\Services\Payment\PaymentNotificationParser
$parsed = $parser->parse($noti->bank_code, $noti->raw_text)
$match = \App\Models\PaymentMatch::createFromParsed($noti, $parsed)
```

```php
$order = \App\Models\Order::create([
    'tenant_id' => $tenant->id,
    'plan_id' => $plan->id,
    'total_cents' => 15000,
    'status' => 'pending',
    'expires_at' => now()->addHours(48),
])
```

```php
$service = app(\App\Services\Payment\PaymentService::class)
$payment = $service->recordPayment(
    $order,
    15000,
    'pago_movil',
    \App\Models\PaymentMethodConfig::where('type', 'pago_movil')->where('is_active', true)->first()?->id,
    [
        'sender_bank' => 'Banco de Prueba',
        'sender_phone' => '041295039503',
        'sender_id' => 'V12345678',
        'payment_date' => now()->format('Y-m-d'),
    ],
    '006666666666',
)
```

```php
// Verificar: pago sigue Pending (shadow mode evitó auto-verificación)
$payment->refresh()
$payment->status->value               // "pending"
```

```php
// Verificar: match se vinculó pero quedó como "pending" (sugerido, no verificado)
$match->refresh()
$match->match_status                  // "pending"
$match->payment_id                    // ID del payment ($payment->id)
```

> **Resultado esperado**: `payment.status = pending`, `match_status = pending`, `payment_id` vinculado — el admin debe revisar y confirmar manualmente.

---

#### Bloque 4: Método diferente a pago_movil (no intenta reverse match)

**¿Qué prueba?**: `attemptReverseMatch()` solo se ejecuta para pagos con método `pago_movil`. Si el cliente reporta un pago por transferencia bancaria (`bank_transfer`), el reverse match se salta completamente. Verifica que el guard de método funciona correctamente.

**Datos**: Notificación BDV con ref `007777777777`. Pago reportado como `bank_transfer`.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
```

```php
// Crear notificación BDV + PaymentMatch (como si hubiera llegado antes)
$noti = \App\Models\PaymentNotification::forceCreate([
    'bank_code' => 'bdv',
    'raw_text' => 'Recibiste un PagomovilBDV por Bs. 200,00 del 041295039503 Ref: 007777777777 en fecha: 20-06-26 hora: 12:00',
    'dedup_hash' => 'test-s6-b4-' . uniqid(),
    'parse_status' => 'pending',
])
$parser = new \App\Services\Payment\PaymentNotificationParser
$parsed = $parser->parse($noti->bank_code, $noti->raw_text)
$match = \App\Models\PaymentMatch::createFromParsed($noti, $parsed)
```

```php
$order = \App\Models\Order::create([
    'tenant_id' => $tenant->id,
    'plan_id' => $plan->id,
    'total_cents' => 20000,
    'status' => 'pending',
    'expires_at' => now()->addHours(48),
])
```

```php
$service = app(\App\Services\Payment\PaymentService::class)
$payment = $service->recordPayment(
    $order,
    20000,
    'bank_transfer',       // ← NO es pago_movil, el reverse match se salta
    \App\Models\PaymentMethodConfig::where('type', 'bank_transfer')->where('is_active', true)->first()?->id,
    [
        'sender_bank' => 'Banco de Prueba',
        'sender_name' => 'Juan Perez',
        'sender_id' => 'V12345678',
        'payment_date' => now()->format('Y-m-d'),
        'tenant_rif' => 'J-12345678-9',
    ],
    '007777777777',
)
```

```php
// Verificar: pago queda Pending (no hubo reverse match)
$payment->refresh()
$payment->status->value               // "pending"
$payment->payment_method              // "bank_transfer"
$payment->verified_at                 // null
```

```php
// Verificar: el PaymentMatch sigue unmatched (no se vinculó)
$match->refresh()
$match->match_status                  // "unmatched"
$match->payment_id                    // null
```

> **Resultado esperado**: Pago `Pending` con método `bank_transfer`. PaymentMatch sigue `unmatched` y sin `payment_id`. El forward flow (S5b) lo matcheará cuando el job procese la notificación.

---

#### Checklist S6

| # | Bloque | Qué verifica | Resultado esperado |
|---|--------|-------------|-------------------|
| 1 | Reverse match — happy path (shadow OFF) | Notificación unmatched existe → cliente reporta mismo ref+monto → auto-verifica | `match_status = matched`, `payment.status = verified`, `verified_by = null` |
| 2 | Sin notificación previa | No hay notificación unmatched → pago se crea normalmente | `payment.status = pending`, sin PaymentMatch vinculado |
| 3 | Shadow mode ON | Notificación existe pero shadow mode evita auto-verificación | `payment.status = pending`, `match_status = pending`, `payment_id` vinculado |
| 4 | Método diferente a pago_movil | Pago con bank_transfer → no intenta reverse match | `payment.status = pending`, `match_status = unmatched`, `payment_id = null` |

---

#### Procedimiento simplificado con comandos Artisan (S6 Reverse)

Como alternativa a los bloques de tinker, existe un flujo más rápido usando comandos Artisan. Este procedimiento asume que ya tenés datos seedeados (ver §18 de `Arquitectura multitenencia aplicada.md`) y shadow mode configurado.

**Preparación única (si no está hecho):**

```powershell
# Configurar regex del banco BDV (solo la primera vez)
php artisan tinker --execute='SystemConfig::set("regex_bdv", "/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i", "string");'

# Desactivar shadow mode si se quiere auto-verificación
php artisan tinker --execute='SystemConfig::set("reconciliation.shadow_mode_enabled", false, "boolean");'

# Verificar estado actual
php artisan tinker --execute='echo SystemConfig::get("reconciliation.shadow_mode_enabled") ? "ON" : "OFF";'
```

**Escenario A — Pago existe primero, notificación llega después**

El cliente ya reportó un pago desde el tenant. Simulamos la notificación del banco que llega después. El `IngestPaymentNotification` job parsea la notificación, crea el `PaymentMatch`, y `ReconciliationOrchestrator::run()` encuentra el payment pending con misma ref+monto.

```powershell
# 1. Simular notificación con los mismos datos del pago existente
php artisan simulate:payment-notification --bank=bdv --amount=49,00 --reference=12345678

# 2. Reprocesar notificaciones pendientes + procesar cola
php artisan reconciliation:reprocess --parse-status=pending; php artisan queue:work --stop-when-empty
```

Resultado: Payment `verified`, orden `paid`, match `matched`.

**Escenario B — Notificación llega primero, pago se reporta después**

Es el mismo escenario que **Bloque 1** (pág. 1937) pero con comandos Artisan en vez de tinker. La notificación llega y se parsea, el match queda `unmatched` porque no hay payment todavía; después `recordPayment()` ejecuta `attemptReverseMatch()` y lo resuelve.

```powershell
# 1. Simular notificación con ref NUEVA (que NO exista en payments aún)
php artisan simulate:payment-notification --bank=bdv --amount=149,00 --reference=99999904

# 2. Reprocesar y procesar cola → el match queda 'unmatched'
php artisan reconciliation:reprocess --parse-status=pending; php artisan queue:work --stop-when-empty

# 3. Verificar que el match quedó esperando
php artisan tinker --execute='$m = App\Models\PaymentMatch::where("parsed_reference","99999904")->first(); echo "status={$m->match_status} payment_id=".($m->payment_id ?? "null")."\n";'
```

**4. Reportar el pago desde el tenant** con ref `99999904`, monto $149.00.
`attemptReverseMatch()` encuentra el match pendiente y auto-verifica.

**5. Verificar**
```powershell
php artisan tinker --execute='$m = App\Models\PaymentMatch::where("parsed_reference","99999904")->first(); $m->load("payment.order"); echo "match={$m->match_status} payment={$m->payment->status->value} order={$m->payment->order->status->value}`n";'
```

---

#### Scheduler: tareas programadas existentes

El matching es event-driven, no por polling. Cuando llega una notificación, el `IngestPaymentNotification` job la procesa y `ReconciliationOrchestrator::run()` busca payments pendientes con misma ref+monto. No hay que "revisar cada 5 minutos" porque:

1. **Notif después del pago**: el job se ejecuta cuando la notificación llega → encuentra el payment pending → match
2. **Pago después de la notif**: `attemptReverseMatch()` en `recordPayment()` lo resuelve síncrono
3. **Queue worker caído**: el job queda persistido en la tabla `jobs` → cuando el worker sube, lo procesa

Las únicas tareas programadas son de limpieza:

| Comando | Frecuencia | Qué hace |
|---------|-----------|----------|
| `orders:expire` | Cada hora | Cancela órdenes pending vencidas (expires_at) |
| `payments:expire-pending` | Cada hora | Cancela payments pending más viejos que `match_window_hours + 24h` (~96h) |
| `subscriptions:expire` | Diario | Desactiva suscripciones vencidas |

Definidas en `routes/console.php`:
```php
Schedule::command('orders:expire')->hourly();
Schedule::command('subscriptions:expire')->daily();
Schedule::command('payments:expire-pending')->hourly();
```

**Gap real: pagos reportados sin notificación que nunca llega**

Si el cliente reporta un pago y la notificación del banco **nunca llega** (teléfono apagado, banco no envió, error de red permanente), el payment se queda `pending` hasta que `payments:expire-pending` lo cancele (~96h).

El **Dashboard de Conciliación** (S8f) expone estos casos en dos tablas:
- **Payments huérfanos**: payments pending sin PaymentMatch vinculado, con antigüedad > N horas
- **Notificaciones huérfanas**: PaymentMatches unmatched sin payment vinculado, con antigüedad > N horas

El admin puede monitorear estos casos desde `/admin/reconciliation` y actuar manualmente antes del expiry automático. Si en el futuro se necesita una alerta proactiva (SystemAlert cuando un payment supera X horas sin match), se puede agregar un comando programado adicional.

**¿Qué pasa si el job falla?** La notificación queda con `parse_status = 'failed'`. Ahí entra el comando manual de backfill:

```powershell
php artisan reconciliation:reprocess --parse-status=failed; php artisan queue:work --stop-when-empty
```

No está en el scheduler porque reintentar ciegamente notificaciones que fallaron por datos inválidos (spam, texto no parseable) no tiene sentido.

---

### Manual: S7 (IC-4 + Eventos + Notificaciones)

> **Prerequisito**: Migraciones landlord corridas, SystemConfigSeeder ejecutado, PlansSeeder ejecutado, PaymentMethodConfigSeeder ejecutado, LandlordUserSeeder ejecutado. Un Tenant existente con plan 'free' (creado en S5a/S5b). DB fresca para empezar limpio.

> **Precaución — DB compartida**: Todos los tests usan la misma PostgreSQL (`spatie-laravel-multitenancy-testing`). Para pruebas manuales en tinker esto no aplica, pero al correr Pest recordar usar `--filter` por archivo individual.

> **Precaución — Conexión tenant vs landlord**: El listener `NotifyPaymentRejected` switchea a la conexión tenant para enviar `PaymentRejected`. Las notificaciones de tenant (`PaymentRejected`) se guardan en la tabla `notifications` de la DB **tenant**. Los `SystemAlert` se guardan en la tabla `notifications` de la DB **landlord**. Para verificar, usar `DB::connection('tenant')->table('notifications')` para las de tenant, y `DB::connection('pgsql')->table('notifications')` para las de landlord.

> **Precaución — DB::connection('tenant') solo funciona después del evento**: La conexión `tenant` es dinámica (apunta a la DB del tenant actual via `$tenant->makeCurrent()`). El listener `NotifyPaymentRejected` llama a `makeCurrent()` internamente, así que `DB::connection('tenant')` está disponible SOLO después de disparar `event(new PaymentCancelled(...))`. Si intentas leer notificaciones de tenant antes del evento, fallará porque no hay DB configurada. El orden correcto es: (1) crear orden + pago, (2) disparar evento, (3) leer `DB::connection('tenant')` para verificar.

> **Precaución — Leer notificaciones por payment_id (NO por latest)**: Como los bloques se corren secuencialmente en la misma sesión tinker, hay múltiples notificaciones en DB. NO usar `->latest()->first()` porque traerá la notificación del bloque anterior. En su lugar, filtrar usando `->get()->first(fn($n) => (json_decode($n->data, true)['payment_id'] ?? null) == $payment->id)`.

> **Precaución — Se necesita un usuario con rol owner o tenant-admin en el tenant**: El listener busca usuarios con roles `owner` o `tenant-admin` en la DB del tenant. Si no hay ninguno, no se envía notificación al tenant (el listener retorna silenciosamente). Usar `TenantUsersSeeder` o crear manualmente un user con `syncRoles(['owner'])`.

> **Precaución — Se necesita al menos un Landlord admin para SystemAlert**: El listener envía `SystemAlert` a todos los registros de `Landlord::all()`. El seeder `LandlordUserSeeder` crea uno. Sin landlord admins, `SystemAlert` no se envía (retorna silenciosamente).

> **Precaución — Order requiere plan_id**: La tabla `orders` tiene constraint `chk_exclusive_buyable` que exige al menos `plan_id` o `resource_id`. Siempre pasar `plan_id`.

> **Precaución — payment.transaction_id unique**: La columna `transaction_id` tiene unique constraint. Usar transacciones distintas para cada bloque.

> **Precaución — Sesión tinker continua**: Todos los bloques se ejecutan en una sola sesión de `php artisan tinker` (sin el flag `--execute`). Cada bloque asume que viene después del anterior, así que las variables se mantienen. Si se cierra tinker entre bloques, todo el estado PHP se pierde — hay que recrear órdenes y pagos desde cero.

> **Precaución — DB tenant persiste entre migrate:fresh landlord**: `php artisan migrate:fresh --database=landlord` SOLO dropea tablas de la DB landlord. La DB tenant (`tenant_test`) no se toca. Si hay datos de tandas anteriores, las verificaciones fallarán (notificaciones viejas interfieren con el filtro por payment_id). Para empezar limpio, dropear la DB tenant manualmente antes del migrate:fresh (Paso 0).

#### Preparación de DB

```bash
# 0. Dropear DB tenant si existe (para empezar limpio — migrate:fresh no toca tenant databases)
php artisan tinker --execute 'DB::statement("DROP DATABASE IF EXISTS tenant_test WITH (FORCE)");'

# 1. Fresh migrate landlord
php artisan migrate:fresh --database=landlord --no-interaction
php artisan migrate --path=database/migrations/landlord --database=landlord --no-interaction

# 2. Seed configuraciones
php artisan db:seed --class=Database\Seeders\SystemConfigSeeder --database=landlord --no-interaction
php artisan db:seed --class=Database\Seeders\PlansSeeder --database=landlord --no-interaction
php artisan db:seed --class=Database\Seeders\PaymentMethodConfigSeeder --database=landlord --no-interaction

# 3. Crear landlord admin (necesario para SystemAlert)
php artisan db:seed --class=Database\Seeders\LandlordUserSeeder --database=landlord --no-interaction

# 4. Crear tenant + plan free
php artisan tinker
```

```php
$plan = \App\Models\Plan::where('slug', 'free')->first() ?? \App\Models\Plan::factory()->createQuietly(['slug' => 'free'])
$tenant = \App\Models\Tenant::first() ?? \App\Models\Tenant::create(['name' => 'Test Tenant', 'domain' => 'tenant1.spatie-laravel-multitenancy.test', 'database' => 'tenant_test'])
```

> **Nota**: El Tenant::create() crea la DB física y le asigna el plan 'free' via subscription, pero NO corre migraciones tenant ni seeders (sigue el estándar Spatie multitenancy). Hay que migrar y seedear la DB tenant manualmente:

```bash
# 5. Migrar DB tenant (crea tablas users, notifications, permissions, etc.)
php artisan tenants:artisan "migrate --database=tenant --force"

# 6. Seedear permisos + roles en la DB tenant
php artisan tenants:artisan "db:seed --class=Database\Seeders\TenantPermissionsSeeder --database=tenant --force"

# 7. Seedear usuario admin del tenant (email: tenant1@..., password: password, rol: owner)
php artisan tenants:artisan "db:seed --class=Database\Seeders\TenantUsersSeeder --database=tenant --force"
```

```bash
# Cerrar tinker después de la preparación y abrir una sesión nueva para los bloques
```

> **Importante**: Los 3 bloques se ejecutan dentro de la **misma sesión** `php artisan tinker` (sin `--execute`). Abrir tinker y correr las líneas una por una.

```bash
php artisan tinker
```

---

#### Bloque 1: Cancelación manual → PaymentRejected al tenant

**¿Qué prueba?**: Cuando un administrador cancela manualmente un pago (tipo `Manual`), el listener `NotifyPaymentRejected` detecta el evento, switchea a la DB tenant, busca usuarios con rol owner/tenant-admin, y les envía una notificación `PaymentRejected` con el mensaje "Su pago ha sido cancelado por un administrador."

**Datos**: Pago Pending con ref `S7-B1-001`, monto Bs. 300,00.

```php
$plan = \App\Models\Plan::where('slug', 'free')->first()
$tenant = \App\Models\Tenant::first()
$order = \App\Models\Order::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 30000, 'status' => 'pending'])
$payment = \App\Models\Payment::create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'amount_cents' => 30000, 'transaction_id' => 'S7-B1-001', 'status' => 'pending', 'payment_method' => 'pago_movil', 'currency' => 'VES'])
$payment->status->value           // "pending"
```

```php
use App\Enums\CancellationType
use App\Events\PaymentCancelled

event(new PaymentCancelled($payment, CancellationType::Manual, 'Pago rechazado por el administrador'))
```

```php
// Verificar: notificación PaymentRejected en DB tenant
$noti = \Illuminate\Support\Facades\DB::connection('tenant')->table('notifications')
    ->where('type', 'App\Notifications\PaymentRejected')
    ->get()
    ->first(fn($n) => (json_decode($n->data, true)['payment_id'] ?? null) == $payment->id)
$data = json_decode($noti->data, true)
$data['cancellation_type']        // "manual"
$data['message']                  // "Su pago ha sido cancelado por un administrador."
```

```php
// Verificar: NO hay SystemAlert en landlord DB
\Illuminate\Support\Facades\DB::connection('pgsql')->table('notifications')
    ->where('type', 'App\Notifications\SystemAlert')
    ->count()                      // 0 (sin alerta a administradores)
```

> **Resultado esperado**: User del tenant recibe `PaymentRejected` con `cancellation_type = manual` y mensaje "cancelado por un administrador". Landlord NO recibe SystemAlert.

---

#### Bloque 2: Duplicado de referencia → PaymentRejected + SystemAlert

**¿Qué prueba?**: Cuando el sistema detecta un posible fraude por referencia duplicada (tipo `SystemDuplicate`), el listener envía `PaymentRejected` al tenant **y además** un `SystemAlert` a los landlord admins con severity `warning`. El mensaje de alerta incluye el `transaction_id` y `payment_id` del pago cancelado.

**Datos**: Pago Pending con ref `S7-B2-001`, monto Bs. 500,00.

```php
$order2 = \App\Models\Order::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 50000, 'status' => 'pending'])
$payment2 = \App\Models\Payment::create(['tenant_id' => $tenant->id, 'order_id' => $order2->id, 'amount_cents' => 50000, 'transaction_id' => 'S7-B2-001', 'status' => 'pending', 'payment_method' => 'pago_movil', 'currency' => 'VES'])
```

```php
// Verificar: existe un landlord admin
\App\Models\Landlord::count()     // >= 1
```

```php
event(new PaymentCancelled($payment2, CancellationType::SystemDuplicate))
```

```php
// Verificar: PaymentRejected en DB tenant
$noti2 = \Illuminate\Support\Facades\DB::connection('tenant')->table('notifications')
    ->where('type', 'App\Notifications\PaymentRejected')
    ->get()
    ->first(fn($n) => (json_decode($n->data, true)['payment_id'] ?? null) == $payment2->id)
$data2 = json_decode($noti2->data, true)
$data2['cancellation_type']       // "system_duplicate"
$data2['message']                 // "Su pago ha sido rechazado porque la referencia ya fue verificada anteriormente."
```

```php
// Verificar: SystemAlert en landlord DB
// NOTA: `id` es UUID, no auto-increment. Usar latest('created_at') para orden cronológico.
$alert = \Illuminate\Support\Facades\DB::connection('pgsql')->table('notifications')
    ->where('type', 'App\Notifications\SystemAlert')
    ->latest('created_at')
    ->first()
$alertData = json_decode($alert->data, true)
$alertData['category']             // "system"
$alertData['type']                 // "duplicate_reference"
$alertData['severity']             // "warning"
$alertData['message']              // contiene "S7-B2-001" y el payment_id
```

> **Resultado esperado**: User del tenant recibe `PaymentRejected` con `cancellation_type = system_duplicate`. Landlord admin recibe `SystemAlert` con `category = system`, `type = duplicate_reference`, `severity = warning`.

---

#### Bloque 3: Pago expirado — solo PaymentRejected, sin SystemAlert

**¿Qué prueba?**: Cuando el sistema expira un pago que no se concilió a tiempo (tipo `SystemExpired`), el listener envía `PaymentRejected` al tenant **pero NO** envía `SystemAlert` a los admins. Es un flujo normal del sistema que no necesita intervención humana.

**Datos**: Pago Pending con ref `S7-B3-001`, monto Bs. 750,00.

```php
$order3 = \App\Models\Order::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 75000, 'status' => 'pending'])
$payment3 = \App\Models\Payment::create(['tenant_id' => $tenant->id, 'order_id' => $order3->id, 'amount_cents' => 75000, 'transaction_id' => 'S7-B3-001', 'status' => 'pending', 'payment_method' => 'pago_movil', 'currency' => 'VES'])
```

```php
event(new PaymentCancelled($payment3, CancellationType::SystemExpired))
```

```php
// Verificar: PaymentRejected en DB tenant
$noti3 = \Illuminate\Support\Facades\DB::connection('tenant')->table('notifications')
    ->where('type', 'App\Notifications\PaymentRejected')
    ->get()
    ->first(fn($n) => (json_decode($n->data, true)['payment_id'] ?? null) == $payment3->id)
$data3 = json_decode($noti3->data, true)
$data3['cancellation_type']       // "system_expired"
$data3['message']                 // "Su pago expiró sin conciliación automática."
```

```php
// Verificar: SystemAlerts NO aumentaron (debe ser 1 del Bloque 2, sigue siendo 1)
\Illuminate\Support\Facades\DB::connection('pgsql')->table('notifications')
    ->where('type', 'App\Notifications\SystemAlert')
    ->count()                      // 1 (solo del Bloque 2, este bloque no creó ninguno)
```

> **Resultado esperado**: User del tenant recibe `PaymentRejected` con `cancellation_type = system_expired`. Landlord NO recibe SystemAlert nuevo. El contador de SystemAlerts se mantiene en 1 (del Bloque 2).

---

#### Checklist S7

| # | Bloque | Qué verifica | Resultado esperado |
|---|--------|-------------|-------------------|
| 1 | Cancelación manual → notificación al tenant | Disparar `PaymentCancelled(Manual)` → `NotifyPaymentRejected` envía `PaymentRejected` al tenant | `data.cancellation_type = manual`, `data.message` contiene "cancelado por un administrador", SystemAlerts en landlord = 0 |
| 2 | Referencia duplicada → notificación + alerta | Disparar `PaymentCancelled(SystemDuplicate)` → `PaymentRejected` al tenant + `SystemAlert` a landlord admins | tenant: `cancellation_type = system_duplicate`; landlord: `SystemAlert` con `category = system`, `type = duplicate_reference`, `severity = warning` |
| 3 | Pago expirado → solo notificación | Disparar `PaymentCancelled(SystemExpired)` → solo `PaymentRejected` al tenant, sin SystemAlert | tenant: `cancellation_type = system_expired`; landlord: contador de SystemAlerts se mantiene en 1 (no aumenta) |

---

## Fase 5 — Backend: Eventos y Notificaciones (✅ COMPLETADA)

### 5.1 Modificar `PaymentService::verifyPayment()`

**Estado actual**: `verifyPayment(Payment $payment, int $adminId)` — requiere adminId, valida status=Pending.

**Cambio**: Cambiar firma a:
```php
public function verifyPayment(Payment $payment, ?int $adminId = null): void
```

Cuando `adminId` es null, el pago fue verificado automáticamente (por el reconciliador).

**En la UI**: Cuando `verified_by` es null y el payment tiene un `PaymentMatch` vinculado,
mostrar "Verificado automáticamente" en lugar de un nombre.

> **Responsabilidad de eventos (IC-4)**: `verifyPayment()` NO debe despachar `PaymentVerified`
> internamente. Solo actualiza la DB. La responsabilidad de disparar `event(new PaymentVerified(...))`
> recae en el código que invoca a `verifyPayment()` (Jobs orquestadores, controllers, comandos),
> siempre *después* de que la transacción se haya completado con éxito. Para el caso manual (admin desde UI),
> el controller despacha el evento después de llamar a `verifyPayment()`.

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

**Quién lo dispara**: El código que invoca a `cancelPayment()`, siempre después de que la transacción se haya completado con éxito. Para `attemptReverseMatch()`, el método acumula eventos en `$pendingEvents` y el controller los despacha tras `recordPayment()`. Para el controller manual y `payments:expire-pending`, despachan directamente después del commit. `cancelPayment()` solo actualiza la DB. El update directo en `recordPayment()` (cambio de método) NO lo dispara — es una acción legítima del usuario que no necesita alertar a nadie.

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
- NO despachar eventos internamente — solo actualizar DB

> **Responsabilidad de eventos (IC-4)**: `cancelPayment()` NO debe despachar `PaymentCancelled`
> internamente. Solo actualiza la DB. La responsabilidad de disparar `event(new PaymentCancelled(...))`
> recae en el código que invoca a `cancelPayment()`, siempre *después* de que la transacción se haya
> completado con éxito. Para los Jobs orquestadores, esto significa después del `DB::transaction()`.
> Para el controller manual, después de llamar a `cancelPayment()`.

> **Transacciones**: `cancelPayment()` NO abre su propia transacción. El caller es responsable de
> envolver la llamada en `DB::transaction()` y despachar el evento después del commit.

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

Usa el sistema de notificaciones de Laravel para eventos de negocio (pago rechazado → tenant). Las alertas de infraestructura usan la misma tabla pero se distinguen por el campo `category = 'system'` en el JSON `data`:

```php
class NotifyPaymentRejected {
    public function handle(PaymentCancelled $event): void
    {
        match ($event->type) {
            CancellationType::SystemDuplicate => $this->handleDuplicateFraud($event),
            CancellationType::SystemExpired => $this->handleExpiredPayment($event),
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

    private function handleExpiredPayment(PaymentCancelled $event): void
    {
        // Notificar al tenant: "Tu pago con referencia X expiró sin poder verificarse.
        // Por favor, reporta un nuevo pago para tu orden #Y."
        // NO notificar al landlord — es un flujo normal del sistema
    }
}
```

**Canales**: Se usan los mismos canales `database` + `mail` que ya usa el sistema.
Las notificaciones de tenant se guardan en la tabla `notifications` del tenant.
Las notificaciones de landlord se guardan en la tabla `notifications` de landlord.

> **Separación de concerns**: Las notificaciones de Laravel (`notifications` table) se usan para **todos** los eventos — tanto de negocio (pago verificado → tenant, pago cancelado → tenant) como de infraestructura (dispositivo offline, parser falló). Se distinguen por el campo `category = 'system'` en el JSON `data`. No se mezclan conceptualmente: las de negocio van al tenant afectado, las de infraestructura van a los admins del landlord.

---

## Fase 6 — Backend: Dashboard de Alertas (Inertia) (✅ COMPLETADA)

### 6.1 Ruta y Controller

```
GET /landlord/alerts → Landlord\AlertController::index()
POST /landlord/alerts/{notification}/read → Landlord\AlertController::read()  (marcar como leída)
```

### 6.2 Página Inertia

**Una sola sección en el dashboard:**

**Notificaciones de infraestructura y negocio** (lee de `notifications` de Laravel):
- Filtra por `read_at IS NULL` y `data->>'category' = 'system'` para alertas de infraestructura
- Filtra por `notifiable_id = admin logueado` para notificaciones de negocio
- Filtro por severidad (critical, warning, info) para alertas de infraestructura
- Botón para marcar como leída (`read_at = now()`)
- Badge en el navbar del panel landlord con conteo de no leídas

### IC-8: API Endpoints para App Android

| Método | Ruta | Auth | Request Body | Response |
|--------|------|------|-------------|----------|
| POST | `/api/device/notifications` | `X-Device-Token` header | `{bank_code, title, body, received_at, dedup_hash}` | `{status: "created"}` o `{status: "duplicate_ignored"}` (200) |
| POST | `/api/device/heartbeat` | `X-Device-Token` header | `{battery_level, notifications_pending_count}` | `{status: "ok", heartbeat_interval_minutes: N}` |

**Middleware**: `device.auth` — valida `X-Device-Token` contra `devices.token`. Si es inválido → 401.
**Rate limiting**: 60 requests/minute por device_token (prevenir flooding).

---

## Fase 8 — App Android

### 8.1 NotificationListenerService

- Filtro por `packageName` exacto de apps bancarias destino
- Extrae título + body
- **Normalizar bank_code a lowercase** antes de calcular el hash y antes de enviarlo al backend
- Genera hash: `SHA256(bank_code_lowercase + raw_body)` — determinista, no depende del reloj
- Guarda en SQLite local inmediatamente (buffer de seguridad)
- Intenta POST a endpoint Laravel con reintentos exponenciales
- Si éxito (HTTP 200): marca `delivered = true` en SQLite
- Si falla: queda en cola local para reintento

### 8.2 Almacenamiento local SQLite (en el teléfono)

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

### 8.3 Heartbeat

- Cada N minutos (configurado en `SystemConfig::get('devices.heartbeat_interval_minutes')`): `POST /api/device/heartbeat` con `device_token`, `battery_level`, `notifications_pending_count`
- Backend busca el `device` por token, actualiza `last_heartbeat_at`
- Si un dispositivo no hace heartbeat en > `interval * 2` minutos → enviar SystemAlert a landlord admins con `type = heartbeat_offline`, `severity = critical`

### 8.4 Ciclo de vida del servicio Android

> **Riesgo**: `NotificationListenerService` puede ser matado por el sistema en condiciones de memoria baja, o por ROMs con optimización de batería agresiva (Xiaomi MIUI, Samsung One UI, Huawei EMUI). El servicio NO está garantizado de estar activo permanentemente.

**Mecanismos de recuperación**:

1. **`BOOT_COMPLETED` receiver**: Re-registrar el servicio al boot del teléfono. Cuando el teléfono se reinicia, el `BroadcastReceiver` inicia el `NotificationListenerService` automáticamente.

2. **Auto-reinicio**: Si el servicio es matado, Android puede re-iniciarlo si está registrado correctamente en `AndroidManifest.xml` con `android:enabled="true"` y `android:exported="false"`.

3. **Detección via heartbeat**: El backend ya detecta servicio muerto (heartbeat timeout). Cuando el admin ve la alerta "dispositivo offline", puede:
   - Verificar que el teléfono esté encendido y con internet
   - Reiniciar manualmente la app desde el teléfono
   - Si el problema persiste: reiniciar el teléfono (solución nuclear pero efectiva)

4. **Buffer local SQLite**: Mientras el servicio esté muerto, las notificaciones se acumulan en SQLite local (con `delivered = 0`). Cuando el servicio se reinicia, procesa la cola pendiente y las envía al backend. No se pierden notificaciones.

**Configuración en `AndroidManifest.xml`**:
```xml
<receiver android:name=".BootReceiver" android:enabled="true">
    <intent-filter>
        <action android:name="android.intent.action.BOOT_COMPLETED" />
    </intent-filter>
</receiver>

<service
    android:name=".PagoMovilNotificationListener"
    android:permission="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE"
    android:enabled="true"
    android:exported="false" />
```

### 8.5 Modo Simulación (end-to-end)

- Botón en la app que genera una notificación local fake con textos reales configurados
- Prueba el pipeline completo: NotificationManager → NotificationListenerService → SQLite → POST → Laravel

### 8.6 Heartbeat y Detección de Dispositivos Offline (maduración)

Optimización de infraestructura para cuando los dispositivos ya estén en producción.
Se implementa hacia el final de la Fase 8, una vez que la app esté funcionando.

**Backend** (`CheckDeviceHeartbeats` command):
- Comando programado que se ejecuta cada N minutos (vía `app/Console\Kernel`)
- Consulta dispositivos cuyo `last_heartbeat_at` supere `interval * 2` minutos
- Genera una `SystemAlert` con `type = heartbeat_offline`, `severity = critical`
- La alerta aparece en el dashboard de landlord admins (Fase 6)

**App Android**:
- `POST /api/device/heartbeat` con `device_token`, `battery_level`, `notifications_pending_count`
- Se envía cada N minutos (configurado en `SystemConfig::get('devices.heartbeat_interval_minutes')`)
- Backend busca el `device` por token, actualiza `last_heartbeat_at`

**Por qué va aquí y no antes**: Sin dispositivos Android reales, este comando no tiene nada que monitorear. Es código muerto hasta que la app esté desplegada.

---

## Fase 7 — Transición (✅ COMPLETADA)

### 7.1 Modo Sombra (Shadow Mode)

Controlado por `SystemConfig::get('reconciliation.shadow_mode_enabled')`.

Durante la primera semana:
- El sistema corre completo pero **no auto-aprueba** pagos
- Cada match potencial se registra en `payment_matches` con `match_status = pending`
- Dashboard de admin muestra sugerencias de match: "Este pago de Bs. 150 del cliente X coincide con una notificación recibida. ¿Confirmar?"
- Se compara la tasa de acierto del motor contra las decisiones manuales

> **Por qué shadow mode**: La verificación automática de pagos tiene riesgo real — un falso positivo puede activar un servicio sin pago real. Shadow mode permite validar el sistema completo (parser → matching → verificación) sin consecuencias. Se activa solo después de observar que el sistema toma las mismas decisiones que el admin humano durante 1-2 semanas.

### 7.2 Activación Gradual

1. **Shadow Mode ON** (default): El sistema sugiere matches, admin confirma/rechaza en el dashboard
2. **Observar**: El admin revisa las sugerencias durante 1-2 semanas, verifica que el matching sea correcto
3. **Activar**: Cuando el admin esté seguro de que el sistema funciona correctamente → `SystemConfig::set('reconciliation.shadow_mode_enabled', false)`
4. **Monitorear**: Si después de activar se detectan falsos positivos, volver a `shadow_mode_enabled = true`

**Rollback**: Si después de activar se detectan falsos positivos, volver a `shadow_mode_enabled = true`. Los pagos ya verificados NO se revierten (son transacciones reales).

> **Por qué sin métricas automáticas**: La decisión de activar auto-matching es de negocio, no técnica. Un admin que ve el dashboard tiene mejor contexto que un umbral configurable. Si en el futuro se necesitan métricas automatizadas, se agregan sin problema.

---

## S8 — Frontend Operacional (UI para Landlord)

### Mapa de situación — S8a–S8f COMPLETOS

El backend de conciliación (S1–S7) está completo y funcional. Los sub-slices S8a–S8f agregan toda la interfaz
de usuario faltante para que el admin landlord pueda operar y monitorear el sistema de conciliación:
S8a (PaymentMatch UI), S8b (SystemConfig UI), S8c (Alert Dashboard), S8d (PaymentMethodConfig CRUD),
S8e (PaymentNotification viewer), S8f (Reconciliation Dashboard). Todos implementados.

**Excluido de S8** (postergado a S3 — App Android):
- IC-8: API endpoints `/api/device/notifications` y `/api/device/heartbeat`
- Middleware `device.auth`
- Dashboard de dispositivos / heartbeat status

### Dependencias entre sub-slices

```
S8a ────────────────┐
S8b ────────────┐   │
S8c ────────┐   │   │
S8d ────┐   │   │   │
S8e ┐   │   │   │   │
    │   │   │   │   │
    └───┴───┴───┴───┴──→ S8f (agrega datos de todos)
```

**S8a–S8e fueron independientes entre sí.** Se ejecutaron en orden arbitrario. S8f depende de los datos que
generan los demás y se ejecutó al final.

---

### S8a — PaymentMatch + campos nuevos en vistas existentes

**Objetivo**: Extender las vistas de detalle de pago (ya existentes) para mostrar la información que el backend
guarda y el frontend ignora.

**Backend**:
- `OrderController::show()` — agregar `->load('payments.verifiedBy', 'payments.paymentMatch')`

**Frontend**:
- `payment-details-card.tsx` — agregar secciones:
  - **`verified_by`** — nombre del admin que verificó (o "Automático" si es auto-match)
  - **`verified_at`** — fecha y hora de verificación
  - **`cancellation_type`** — label humano: Manual, Duplicado, Expirado
  - **`PaymentMatch`** — match_status, match_type, matched_at, si fue en shadow mode

**No incluye**: páginas nuevas, routes nuevas, ni lógica de negocio.

**Verificación manual**: Entrar a `/admin/orders/{id}`, verificar que los campos nuevos aparecen en cada
payment card según el estado del pago.

| Archivo | Cambio | Status |
|---------|--------|--------|
| `app/Http/Controllers/Landlord/OrderController.php` | + `load('payments.verifiedBy', 'payments.paymentMatch')` | ✅ Completo |
| `resources/js/components/payment-details-card.tsx` | + verified_by, verified_at, cancellation_type, paymentMatch | ✅ Completo |
| `resources/js/pages/admin/orders/show.tsx` | + verified_by, cancellation_type al type Payment | ✅ Completo |

---

### S8b — Gestión de SystemConfig (configuraciones del sistema)

**Objetivo**: UI para ver y editar las configuraciones dinámicas del sistema (regex, shadow mode, timeouts, etc.).

**Backend**:
- `SystemConfigController` (index, update)
- Route `GET /admin/system-configs`
- Route `PUT /admin/system-configs/{id}`

**Frontend**:
- `landlord/system-configs/index.tsx` — tabla agrupada por `group`, edición inline o por modal
- `admin-panel.tsx` — card de acceso

**Campos mostrados**: group, key (read-only), value (editable, con validación de regex si key empieza con `regex_`), description.

**Consideraciones**:
- Las config con key `regex_*` deben validarse al guardar (la validación existe en el modelo).
- `shadow_mode_enabled` debe mostrar el estado actual y permitir toggle.
- Solo admins pueden editar (middleware `EnsureUserIsAdmin` ya existe).

**Verificación manual**: Editar una config desde la UI, verificar que persiste en DB y que el cache se invalida.

| Archivo | Cambio | Status |
|---------|--------|--------|
| `app/Http/Controllers/Landlord/SystemConfigController.php` | Crear (index, update) | ✅ Completo |
| `routes/landlord.php` | + routes system-configs | ✅ Completo |
| `resources/js/pages/landlord/system-configs/index.tsx` | Crear (+ data-testid) | ✅ Completo |
| `resources/js/pages/landlord/admin-panel.tsx` | + card SystemConfigs | ✅ Completo |
| `tests/Browser/Landlord/SystemConfigBrowserTest.php` | Crear (3 browser tests) | ✅ |

---

### S8c — Dashboard de Alertas (SystemAlert)

**Objetivo**: Interfaz para que el admin landlord vea, filtre y gestione las SystemAlert generadas por el sistema.

**Especificación completa**: Ver [Fase 6 — Dashboard de Alertas](#fase-6--backend-dashboard-de-alertas-inertia).

**Backend**:
- `AlertController` (index, read)
- Route `GET /admin/alerts`
- Route `POST /admin/alerts/{notification}/read`

**Frontend**:
- `landlord/alerts.tsx` — lista con filtros por severidad, leída/no leída, rango de fecha
- Badge en navbar landlord con conteo de no leídas

**Verificación manual**: Disparar `SystemAlert` via tinker, entrar al dashboard, ver la alerta, marcarla como leída, verificar que el badge baja.

| Archivo | Cambio | Status |
|---------|--------|--------|
| `app/Http/Controllers/Landlord/AlertController.php` | Crear (index, read) | ✅ Creado |
| `routes/landlord.php` | + routes alerts | ✅ Modificado (+2 rutas alerts) |
| `resources/js/pages/landlord/alerts.tsx` | Crear | ✅ Creado |
| Layout (navbar landlord) | + badge no leídas | ✅ Modificado (sidebar badge) |

---

### S8d — Gestión de PaymentMethodConfig (cuentas bancarias)

**Objetivo**: CRUD completo para gestionar cuentas receptoras de PagoMóvil y Transferencia Bancaria desde el
panel landlord. La tabla existe y se usa en producción, pero no tiene interfaz de administración.

**Backend**:
- `PaymentMethodConfigController` (index, create, store, edit, update, destroy)
- Routes REST full: GET/POST/PUT/DELETE `/admin/payment-configs`

**Frontend**:
- `landlord/payment-configs/index.tsx` — listado con indicador activo/inactivo
- `landlord/payment-configs/create.tsx` — formulario de creación
- `landlord/payment-configs/edit.tsx` — formulario de edición
- `admin-panel.tsx` — card de acceso

**Verificación manual**: CRUD completo, cambiar orden, activar/desactivar cuentas, verificar que el tenant ve los cambios al reportar un pago.

| Archivo | Cambio | Status |
|---------|--------|--------|
| `app/Http/Requests/Landlord/StorePaymentMethodConfigRequest.php` | Crear (validación) | ✅ |
| `app/Http/Requests/Landlord/UpdatePaymentMethodConfigRequest.php` | Crear (validación sin type) | ✅ |
| `app/Http/Controllers/Landlord/PaymentMethodConfigController.php` | Crear (full CRUD) | ✅ |
| `routes/landlord.php` | + resource payment-configs | ✅ |
| `resources/js/pages/landlord/payment-configs/index.tsx` | Crear (tablas agrupadas x tipo) | ✅ |
| `resources/js/pages/landlord/payment-configs/create.tsx` | Crear (radio type + campos condicionales) | ✅ |
| `resources/js/pages/landlord/payment-configs/edit.tsx` | Crear (type badge read-only) | ✅ |
| `resources/js/pages/landlord/admin-panel.tsx` | + card PaymentConfigs | ✅ |
| `database/seeders/PaymentMethodConfigSeeder.php` | Actualizado (bancos reales BDV/BNC) | ✅ |
| `tests/Feature/Landlord/PaymentMethodConfigTest.php` | Crear (21 tests, 78 assertions) | ✅ |
| `tests/Browser/Landlord/PaymentConfigBrowserTest.php` | Crear (12 browser tests) | ✅ |

---

### S8e — Notificaciones Bancarias (PaymentNotification viewer + reprocesar fallidos)

**Objetivo**: Vista para monitorear las notificaciones que llegan (simuladas por S2), su estado de parseo, y
reprocesar las fallidas.

**Backend**:
- `PaymentNotificationController` (index, reprocess) — filtros: parse_status, bank_code (Select dinámico desde
  distinct values de la DB), reference (busca en raw_text ILIKE + parsed_data->>'reference'), rango de fecha
- Route `GET /admin/payment-notifications`
- Route `POST /admin/payment-notifications/{id}/reprocess`
- `PaymentNotificationFactory` reescrita con datos realistas: raw_text con formato SMS real de BDV y BNC,
  dedup_hash computado, parsed_data con grupos parseados, parse_error con mensajes realistas
- `PaymentNotification::match()` relación HasOne agregada
- `BrowserTestCase.php` — cleanup de `payment_notifications` en setUp

**Frontend**:
- `landlord/payment-notifications/index.tsx` — listado con:
  - Filtros: parse_status (Select), bank_code (Select dinámico), reference (input), rango de fecha
  - Tabla expandible (raw_text, parsed_data con `<pre>`+JSON.stringify, match info con link a orden)
  - Botón "Reprocesar" para notificaciones con parse_status = failed
  - Paginación + estado vacío
- `resources/js/types/models.ts` — tipos `PaymentNotificationItem`, `PaymentNotificationPageProps` (con bank_codes)
- Card sidebar: renombrada de "Notificaciones" a **"Notificaciones Bancarias"** (ícono Banknote)
- Cards de admin renombradas: "Notifications" → **"Anuncios"** (ícono Bell),
  "Notificaciones" → **"Notificaciones Bancarias"** (ícono Banknote)

**Verificación manual**: Seedear notis con `NotificationSampleSeeder`, ver listado, filtrar por banco/referencia/fecha, 
expandir detalle, reprocesar fallida.

**Tests**: 12 feature tests (101 assertions) + 10 browser tests. 120 landlord feature tests sin regresiones.

| Archivo | Cambio | Status |
|---------|--------|--------|
| `app/Http/Controllers/Landlord/PaymentNotificationController.php` | Crear (index con filtros, reprocess) | ✅ |
| `routes/landlord.php` | + routes payment-notifications | ✅ |
| `resources/js/pages/landlord/payment-notifications/index.tsx` | Crear (filtros, tabla expandible, paginación) | ✅ |
| `resources/js/pages/landlord/admin-panel.tsx` | + card Notificaciones Bancarias, renombrar Notifications→Anuncios | ✅ |
| `resources/js/types/models.ts` | + tipos PaymentNotificationItem, PaymentNotificationPageProps | ✅ |
| `resources/js/pages/landlord/notifications/compose.tsx` | breadcrumb renombrado a "Anuncios" | ✅ |
| `resources/js/pages/landlord/notifications/history.tsx` | breadcrumb renombrado a "Anuncios" | ✅ |
| `database/factories/PaymentNotificationFactory.php` | Reescribir con raw_text BDV/BNC realistas | ✅ |
| `tests/Feature/Landlord/PaymentNotificationControllerTest.php` | Crear (12 tests: index, filtros, reprocess, auth, pagination) | ✅ |
| `tests/Browser/Landlord/PaymentNotificationBrowserTest.php` | Crear (10 tests: empty, list, expand, filters×4, reprocess, guard) | ✅ |
| `tests/Browser/BrowserTestCase.php` | + cleanup payment_notifications | ✅ |

---

### S8f — Dashboard de Conciliación (oversight)

**Objetivo cumplido**: Tablero único con métricas de salud del sistema de conciliación. Consolida datos de todos los slices anteriores (S8a–S8e).

**Gap crítico cubierto**: Pagos reportados por el cliente que quedan `pending` porque la notificación del banco nunca llegó. El dashboard expone estos casos como "payments huérfanos" para que el admin pueda actuar antes del expiry automático.

**Backend**:
- `ReconciliationDashboardController` (index + toggleShadowMode)
- Route `GET /admin/reconciliation` + `PATCH /admin/reconciliation/shadow-mode`
- Queries implementadas:
  - Match rate: `PaymentMatch` count × grouped por match_status
  - Autoverificados hoy: `Payment` count donde `verified_by IS NULL` y `verified_at` es hoy
  - Alertas activas: `notifications` donde `type = SystemAlert` y `read_at IS NULL`
  - Notificaciones fallidas: `PaymentNotification` donde `parse_status = failed`
  - Shadow mode status: `SystemConfig::get('reconciliation.shadow_mode_enabled')`
  - **Payments huérfanos**: Payments donde `status = pending` Y `created_at > N horas` Y no tienen un `PaymentMatch` vinculado. Estos son pagos que el cliente reportó pero cuya notificación bancaria nunca llegó.
  - **Notificaciones huérfanas**: PaymentMatches donde `match_status = unmatched` Y `created_at > N horas`. Notificaciones que llegaron pero el cliente nunca reportó el pago.
  - Timeline de últimas actividades

**Frontend**:
- `landlord/reconciliation/index.tsx` — KPIs (match rate, autoverificados hoy, alertas activas), tabs de orphans (payments huérfanos, notificaciones huérfanas), shadow mode toggle, timeline
- `admin-panel.tsx` — card "Dashboard de Conciliación" con acceso directo

**Depende de**: S8a–S8e (los datos que muestra provienen de esas tablas y relaciones).

**Verificación manual**: Verificar que los números del dashboard coinciden con queries directas en DB.

| Archivo | Cambio | Status |
|---------|--------|--------|
| `app/Http/Controllers/Landlord/ReconciliationDashboardController.php` | Crear (index, toggleShadowMode) | ✅ Completo |
| `routes/landlord.php` | + route reconciliation + PATCH shadow-mode | ✅ Completo |
| `resources/js/pages/landlord/reconciliation/index.tsx` | Crear (KPIs, orphan tabs, shadow mode toggle) | ✅ Completo |
| `resources/js/pages/landlord/admin-panel.tsx` | + card Dashboard de Conciliación | ✅ Completo |

---

## Tabla Resumen: Cambios a Código Existente

| Archivo | Cambio | ¿Existe hoy? |
|---------|--------|---------------|
| `app/Services/Payment/PaymentService.php` | `verifyPayment()`: `int $adminId` → `?int $adminId = null` | ✅ Método existe |
| `app/Services/Payment/PaymentService.php` | `cancelPayment()`: migrar de `string $reason` a `CancellationType $type` + `int $adminId` a `int|string $actorId` + nuevo param `?string $reason` + remover `DB::transaction` propio + NO despachar eventos. `recordPayment()` se queda con update directo (sin evento) para cambio de método | ✅ Método existe, cambio de firma |
| `app/Services/Payment/PaymentService.php` | `createOrder()`: cambiar `now()->addHours(48)` hardcodeado por `SystemConfig::get('payment.order_expiry_hours', 48)` | ✅ Método existe |
| `app/Services/Payment/PagoMovilGateway.php` | Eliminar fallback a `config('payment.pago_movil')` — siempre requerir `PaymentMethodConfig` | ✅ Gateway existe |
| `app/Http/Controllers/Tenant/PaymentController.php` | Eliminar `paymentConfig` (phone/bank/rif desde SystemConfig) — el frontend resuelve cuentas desde `PaymentMethodConfig` directamente | ✅ Controller existe |
| `app/Models/Payment.php` | Agregar cast `cancellation_type => CancellationType::class` + nueva columna `cancellation_type` + relación `paymentMatch()` | ✅ Modelo existe, nueva migración |
| `app/Console/Commands/ExpireOrders.php` | Sin cambios — ya lee `expires_at` de la columna en orders | ✅ Comando existe, no necesita cambios |
| `config/payment.php` | **ELIMINADO** — toda config migró a `system_configs` | Eliminado completamente |
| `app/Events/PaymentCancelled.php` | **CREADO** — nuevo evento | ✅ Creado e implementado |
| `app/Listeners/NotifyPaymentRejected.php` | **CREADO** — nuevo listener, rutea por `CancellationType` | ✅ Creado e implementado |

---

## Dependencias entre Fases — Estado Actual

| Fase | Estado | Depende de |
|------|--------|-----------|
| 0. Simulador | ✅ COMPLETA | nada |
| 1. Migraciones y Modelos | ✅ COMPLETA | 0 |
| 2. Parser por banco | ✅ COMPLETA | 0, 1 |
| 3. Job de parsing + orquestador | ✅ COMPLETA | 1, 2 |
| 4. Motor de matching | ✅ COMPLETA | 3, 5.1 |
| 5. Eventos y Notificaciones | ✅ COMPLETA | 1 |
| 6. Dashboard de alertas | ✅ COMPLETA | 1, 5 |
| 7. Transición (Shadow Mode) | ✅ COMPLETA | 4, 5, 6 |
| S8a. PaymentMatch UI | ✅ COMPLETA | 1, 4 |
| S8b. SystemConfig UI | ✅ COMPLETA | 1 |
| S8c. Alert Dashboard UI | ✅ COMPLETA | 6 |
| S8d. PaymentMethodConfig UI | ✅ COMPLETA | 1 |
| S8e. PaymentNotification Viewer | ✅ COMPLETA | 3 |
| S8f. Reconciliation Dashboard | ✅ COMPLETA | S8a–S8e |
| 8. App Android | PENDIENTE (deferred) | 1 + endpoints API |

> **Nota**: Todas las fases 0-7 y sub-slices S8a-S8f están implementadas. La única fase pendiente es la Fase 8 (App Android), que se retomará cuando el proyecto Android tenga el `NotificationListenerService` implementado. El orden de slices cambió durante la implementación: S3 (endpoints API Android) se difirió, y S8a-S8f se agregaron como frontend operacional faltante.

---

## Excluido (mejoras futuras)

- ❌ **Conciliación de Transferencia Bancaria** (este plan solo cubre PagoMóvil)
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
4. **Jobs vía dispatch explícito**: no se usan eventos `eloquent.created` como trigger.
   `IngestPaymentNotification` se dispacha desde el controller de ingesta (y desde el
   comando de backfill). Esto permite reprocesar sin duplicar lógica.
5. **Autenticación de dispositivos**: cada request incluye un `device_token` validado contra
   la tabla `devices`. El `device_id` en `payment_notifications` es una FK a `devices.id`,
   no un campo derivado del token.
6. **Configuración centralizada**: `config/payment.php` desaparece. Toda configuración vive
   en `system_configs` con cache de 1 hora. Una sola fuente de verdad.
7. **FK directa en payment_matches**: sin `morphs`, sin polimorfismo. Si otro stack necesita leer
   estos datos, entiende una FK estándar, no un patrón de Laravel.
8. **Guard de estado en matching**: antes de llamar `verifyPayment()`, el orchestrator verifica
   que el payment sigue en `Pending`. Si fue verificado manualmente mientras tanto, el match
   se descarta graciosamente.
9. **Migración de config**: `config/payment.php` se elimina completamente. Se crea un Seeder
   que inserta los registros iniciales en `system_configs`. El código que leía `config('payment.*')`
   se cambia a `SystemConfig::get('payment.*')`.
10. **Cache de system_configs**: `SystemConfig::get()` usa sentinel pattern atómico con TTL de 1h.
    `SystemConfig::set()` y `save()` hacen `Cache::forget()` inmediatamente para ese key.
    El `save()` tiene la misma lógica de invalidación que `set()` para evitar asimetrías.
    Los regex se cachean por key individual y se invalidan correctamente al hacer `set()`.
11. **bank_code normalization**: El backend enforce `strtolower()` en el endpoint de ingesta.
    La app Android DEBE normalizar a lowercase antes de calcular el `dedup_hash` y antes de
    enviarlo al backend. Si el hash se calcula con mayúsculas y el backend guarda lowercase,
    el hash no es verificable contra el contenido de la fila (inconsistencia de auditoría).
12. **Interacción SELECT FOR UPDATE + queue driver**: Si el queue driver es `database`, Laravel
    maneja una transacción externa para marcar el job como completado. El `SELECT FOR UPDATE`
    dentro de `DB::transaction()` puede comportarse diferente según la configuración. Con
    Redis o Beanstalk (más común en producción) este problema no existe. Verificar el queue
    driver en producción.

---

## Comparativa y Roles en el Flujo de Conciliación

Para comprender el flujo de control, auditoría y resolución de problemas de la conciliación bancaria automatizada en el landlord (panel de administración), es clave definir el rol específico de cada uno de los tres componentes UI principales y cómo interactúan entre sí.

### Matriz de Definición y Roles

| Módulo | Ruta | Rol Operacional | Modelo Subyacente | Destinatario / Dirección |
|--------|------|-----------------|-------------------|-------------------------|
| **Dashboard de Conciliación** | `/admin/reconciliation` | **Torre de Control** (Métricas ejecutivas, KPIs, salud del motor y cola de elementos huérfanos). | Consolidación de `Payment`, `PaymentMatch` y `PaymentNotification`. | Admin (Landlord) / Monitoreo General |
| **Notificaciones Bancarias** | `/admin/payment-notifications` | **Log Técnico / Auditoría** (Historial detallado de ingesta, datos parseados y reprocesamiento de SMS). | `PaymentNotification` | Técnico (Auditoría de Ingesta) / Inbound |
| **Alertas del Sistema** | `/admin/alerts` | **Bandeja de Incidencias** (Notificaciones sobre fallos de infraestructura, errores críticos o sospecha de fraude). | `DatabaseNotification` (categoría `system`) | Admin (Landlord) / Alertas Internas |

> [!NOTE]
> **Sobre la separación de conceptos**: 
> * Las **Alertas** (`alerts`) y las **Notificaciones Bancarias** (`payment-notifications`) no se unifican porque tienen naturalezas diferentes. Las alertas son notificaciones personales leíbles/no leíbles del usuario administrador, mientras que las notificaciones bancarias son registros inmutables del negocio sobre mensajes de pago recibidos.
> * Tampoco se unifican con **Anuncios** (`admin/notifications`), ya que estos últimos son comunicados salientes (outbound) enviados manualmente por el administrador hacia los inquilinos (tenants), mientras que las alertas son internas y de carácter técnico.

---

### Puesta en Escena (Caso de Uso Real)

Para visualizar cómo cooperan estos módulos coordinadamente frente a un flujo operativo, analicemos el ciclo de vida ante un problema:

1. **Fase de Ingesta (El Log)**: Un inquilino realiza un PagoMóvil e ingresa una referencia incorrecta por error al reportarlo. El banco envía la notificación de éxito por SMS. El backend la registra en `/admin/payment-notifications` en estado `parsed`, pero al no coincidir la referencia exacta, el motor no logra matchearla.
2. **Fase de Alarma (La Alerta)**: Al pasar la ventana de tolerancia de 30 minutos sin conciliación, el sistema genera de forma automática una alerta crítica para el administrador. Éste ve subir el contador de alertas y entra a `/admin/alerts` donde lee: *«Posible pago huérfano detectado: Notificación Ref: 9876543 no coincide con ningún reporte de pago»*.
3. **Fase de Diagnóstico (El Dashboard)**: El administrador va a `/admin/reconciliation`, donde observa que el *Match Rate* general disminuyó y encuentra listada la notificación en **Notificaciones Huérfanas** al lado de un pago de inquilino de igual monto en **Payments Huérfanos** (pero con la referencia errónea).
4. **Fase de Resolución (Auditoría y Cierre)**: Desde el feed del dashboard, hace clic en el enlace de la notificación, el cual lo redirige a `/admin/payment-notifications?reference=9876543`. Al confirmar la coincidencia de teléfono emisor y monto, el administrador edita la orden, aprueba el pago manualmente en el sistema y, finalmente, marca la alerta correspondiente como resuelta en su bandeja de alertas.
