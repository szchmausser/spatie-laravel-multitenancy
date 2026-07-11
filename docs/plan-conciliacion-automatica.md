# Documentación Viva del Sistema de Conciliación Automática

## Changelog de Decisiones

| Fecha | Decisión | Razón |
|-------|----------|-------|
| 2026-07-11 | **Sincronización doc vs codebase** — Documentación actualizada contra el código real. Se incorporan: parser con sourceType, regex por canal (`regex_{bank}_{sourceType}`), shadow mode por canales (`shadow_mode_channels` JSON array en vez de booleano), PaymentMatchGuard para validación multifield banco+teléfono, PaymentNotification con `source_type` y `computeDedupHash()` basado en normalización de campos, Device sin `bank_code` y con auto-desactivación en heartbeat timeout, BankCode enum, SourceType enum, PaymentMatch 4-step dedup. Se eliminan referencias a `config/payment.php`, `shadow_mode_enabled` booleano, y se refleja que CheckDeviceHeartbeats ya existe. Además: SystemAlert constructor corregido (type, message, severity — sin metadata), run() race condition resetea match_status a 'unmatched', forward flow mismatch linkea payment_id, reverse flow two-step matching (estricto + fallback solo ref), overpayment notification vía `notifyOverpayment()`, IC-8 request body actualizado (`{bank_code, raw_body}` + source_type via URL). | Documentación desactualizada por evolución del código durante implementación. |
| 2026-06-23 | **S8f completado** — Reconciliation Dashboard: KPIs, match rate, orphan payments/notifications, shadow mode toggle. Route GET /admin/reconciliation + PATCH /admin/reconciliation/shadow-mode. Card en admin-panel. | Oversight UI del sistema de conciliación completo. Todas las fases 0-7 y S8a-S8f están implementadas. Pendiente solo Fase 8 (App Android) y endpoints API (S3). |
| 2026-06-22 | **S8e completado + mejoras post-facto** — PaymentNotification viewer: controller con index (filtros por parse_status, bank_code como Select dinámico, reference en raw_text+parsed_data, fecha) + reprocess, página Inertia con tabla expandible, card renombrada a "Notificaciones Bancarias", factory con datos realistas de SMS bancarios. Cards de admin renombradas: "Notifications" → "Anuncios", "Notificaciones" → "Notificaciones Bancarias". 12 feature tests (101 assertions) + 10 browser tests, 120 landlord tests sin regresiones. | Interfaz para monitorear notificaciones bancarias entrantes y reprocesar fallidas. |
| 2026-06-22 | **S8d completado** — PaymentMethodConfig CRUD: Controller RESTful, 3 páginas Inertia (index agrupado por tipo, create con selector de tipo, edit con type read-only), Form Requests, 21 tests (78 assertions). Seeder actualizado a bancos reales BDV/BNC. Banner informativo al borrar última cuenta activa de un tipo. ~617 líneas, 8 archivos. | UI faltante para gestionar cuentas bancarias. El modelo `PaymentMethodConfig` ya existía y se usaba en producción, pero no tenía interfaz de administración — dependencia de tinker/seeder para cualquier cambio. |
| 2026-06-22 | **S8c completado** — Alert Dashboard: AlertController (index/read), alerts.tsx con filtros por severidad/leída/fecha, sidebar badge con conteo no leídas, fix HandleInertiaRequests para Landlord. 13 tests feature (76 assertions), 100 landlord tests sin regresiones. | UI faltante para que admins vean y gestionen alertas de infraestructura (SystemAlert). |
| 2026-06-21 | **S8b completado** — SystemConfig UI: tabla agrupada con modal type-aware, validación regex, cache invalidation. 12 tests. | UI completa para gestionar configs dinámicas sin depender de tinker/seeders. |
| 2026-06-21 | **S8a completado** — PaymentMatch UI: OrderController con eager-load, payment-details-card.tsx con verifier, cancellationTypeBadge, PaymentMatch section. 17 tests (8 feature + 9 browser). | UI extension del backend existente. No se crearon nuevas columnas. |
| 2026-06-21 | **S8 reorganizado en sub-slices atómicos S8a–S8f** — Se excluyen endpoints Android (IC-8) y se agregan UIs faltantes: PaymentMatch en vistas, SystemConfig, Alertas, PaymentMethodConfig, PaymentNotification viewer, y Dashboard de Conciliación. | En code review se identificó que el backend tiene mucha más capacidad de la que la UI expone. Se subdivide para mantener slices atómicos, testeables y verificables manualmente. Los endpoints Android (IC-8) se postergan hasta S3. |
| 2026-07-08 | **Nuevas columnas en payment_matches** — `parsed_sender_phone_number`, `parsed_sender_phone_first4`, `parsed_bank_code`. Requeridas por PaymentMatchGuard para validación multifield. | El multifield guard necesita datos de teléfono y banco de la notificación para comparar contra el reporte del tenant antes de verificar. |
| 2026-07-03 | **source_type en payment_notifications** — Columna `source_type` (varchar 20, default `bank-app`) con índice. FK a devices cambia a `nullOnDelete`. Enum `SourceType` con `BankApp` y `Sms`. | Cada banco puede tener formatos de notificación distintos según el canal (SMS vs app bancaria). El parser ahora elige el regex según `bank_code + source_type`. |
| 2026-06-30 | **Drop android fields** — Eliminados `raw_title`, `package_name` de `payment_notifications`. `raw_text` es el único campo de contenido crudo. | Simplificación: el teléfono solo envía el texto de la notificación, el backend no necesita el título ni el package name para parsear. |
| 2026-06-29 | **Devices ya no tienen bank_code** — Columna `bank_code` eliminada de `devices`. Los dispositivos ahora se registran sin banco asociado. | Los dispositivos Android se autentican por token, no por banco. El bank_code se resuelve en el backend cuando la notificación llega con el campo correspondiente. También se elimina `tenant_id` de devices. |
| 2026-06-27 | **Device Invite Codes** — Tabla `device_invite_codes` para registro de dispositivos. El teléfono se registra con un código de invitación, no con tenant_id. | Separación completa del dispositivo Android de cualquier tenant. El dispositivo es del landlord, no del tenant. |
| 2026-06-25 | **Device heartbeat_interval default 1 min** — Cambiado de 5 a 1 minuto en seeder. | Detección más rápida de dispositivos offline. |
| 2026-06-24 | **Devices con android_device_id y heartbeat_ip** — Nuevas columnas en tabla devices. | `android_device_id` identifica el dispositivo físico para dedup en re-registro. `last_heartbeat_ip` para diagnóstico de conectividad. |
| 2026-06-21 | **Fix: tests borraban DB de desarrollo** — Creado `.env.testing` con `DB_DATABASE=spatie-laravel-multitenancy_testing` y base separada en PostgreSQL. | `phpunit.xml` no configurable DB_DATABASE, tests corrían contra la misma DB de desarrollo. `BrowserTestCase::refreshDatabase()` ejecutaba `migrate:fresh` y borraba todos los datos. |
| 2026-06-20 | **S5b completado** — IngestPaymentNotification job + ExpirePendingPayments + ReprocessFailedNotifications. Fix: NotTenantAware en el job. 10 tests Pest, 4 bloques de pruebas manuales. | Conexión del motor de matching con el mundo real. S5b contiene el job IngestPaymentNotification (parse → match → eventos IC-4), el comando de expiración (payments:expire-pending), y el comando de backfill (reconciliation:reprocess). Se descubrió que Spatie Multitenancy v4 tiene queues_are_tenant_aware_by_default=true, por lo que jobs landlord deben implementar NotTenantAware, de lo contrario los cambios no persisten. |
| 2026-06-20 | **S5a completado** — PaymentMatch + ReconciliationOrchestrator implementado y verificado manualmente. 13 tests Pest, 5 escenarios manuales en tinker. | Slice progresivo del motor de matching. S5a contiene el modelo PaymentMatch, el DTO ReconciliationResult, y el orquestador con 5 pasos (duplicate → select → single/multiple/none). |
| 2026-06-20 | **Fix: SystemConfig::set()** — Corregido: ahora deriva `group` del prefijo de la key, auto-detecta `type` del valor PHP, y maneja arrays/booleanos correctamente. | El método `set()` existente no asignaba `group` (nullable en DB pero necesario para consistencia) ni infería `type`, causando que valores booleanos se guardaran como string "Array" al castear `(string) $value` sobre un array. |
| 2026-06-20 | **Fix: PagoMovilGateway::resolveReceivingAccount()** — Cambiado de retornar arreglo de error a usar `abort_if(422)`. | La versión anterior retornaba `['error' => '...']` que no era manejado por `PaymentService::recordPayment()`, causando SQL error por datos inválidos. |
| 2026-06-19 | **S3 (Devices + API) postpone** — Se reordenan los slices. S3 se ejecuta cuando el Android esté más avanzado. | S3 crea endpoints que nadie usa aún. |

> **Nuevo orden de slices**: S1 ✅ → S2 ✅ → **S3 deferred** → S4 ✅ → S5 ✅ → S6 ✅ → S7 ✅ → S8a ✅ → S8b ✅ → S8c ✅ → S8d ✅ → S8e ✅ → **S8f ✅**
>
> **Cuándo retomar S3**: Cuando el proyecto Android tenga el `NotificationListenerService` implementado y necesite enviar notificaciones al backend. Hasta entonces, las notificaciones se prueban con `SimulatePaymentNotification` (S2).

---

## Sinopsis para Revisión

### Qué queremos construir

Sistema de reconciliación bancaria automática para pagos por PagoMóvil en Venezuela. Un teléfono Android dedicado captura notificaciones push de bancos, Laravel las parsea con regex almacenados en base de datos (segregados por canal SMS vs app bancaria), un motor de matching las vincula con pagos reportados por clientes, y si hay match exacto (referencia + monto + ventana temporal + validación multifield opcional de banco+teléfono), el sistema auto-verifica el pago sin intervención humana. El sistema opera en "shadow mode" por canales (sugiere pero no auto-aprueba) y luego se activa gradualmente removiendo canales de la lista de shadow.

### Stack del proyecto

- **Backend**: Laravel 13, PHP 8.5, PostgreSQL
- **Frontend**: Inertia.js v3 + React 19, Tailwind CSS v4
- **Testing**: Pest v4, PHPUnit v12, 502+ tests, ~1900+ assertions
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
| **Reconciliation Dashboard** | ✅ Completo | S8f: KPIs (match rate, auto-verificados hoy, alertas activas), orphans (payments huérfanos, notificaciones huérfanas), shadow mode channels toggle |
| **CheckDeviceHeartbeats** | ✅ Completo | Comando `devices:check-heartbeats` que detecta dispositivos offline, los desactiva automáticamente y envía SystemAlert a admins. Timeout = 3x heartbeat_interval_minutes. |

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
| **Payment (Supertipo)** | ✅ Completo | Tabla central con status, amount_cents, transaction_id, verificación, metadata, cancellation_type |
| **PagoMovilDetail (Subtipo)** | ✅ Completo | Snapshot receiver + sender report (phone, bank, rif, sender fields) |
| **BankTransferDetail (Subtipo)** | ✅ Completo | Snapshot receiver + sender report (account, holder, sender fields) |
| **PaymentGatewayInterface** | ✅ Completo | Strategy pattern con PagoMovilGateway + BankTransferGateway |
| **PaymentService** | ✅ Completo | Orquestador con transacciones, verifyPayment(), cancelPayment(), recordPayment() con guards de idempotencia y duplicados, attemptReverseMatch() |
| **PaymentMethodConfig** | ✅ Completo | Cuentas receptoras configurables por tipo |
| **Validation** | ✅ Completo | Referencia normalizada vía normalizeRef(), UNIQUE cross-tenant, DuplicatePaymentReferenceException, estado de orden, sender fields |
| **Events/Listeners** | ✅ Completo | PaymentVerified → ActivateSubscription, PaymentCancelled → NotifyPaymentRejected (tenant + SystemAlert si es duplicate), OrderExpired, PendingPaymentCreated |
| **Reconciliation Dashboard** | ✅ Completo | S8f: KPIs (match rate, auto-verificados hoy, alertas activas), orphans (payments huérfanos, notificaciones huérfanas), shadow mode channels indicator + toggle |
| **Shadow Mode** | ✅ Completo | S7+S8f: toggle por canales `reconciliation.shadow_mode_channels` (JSON array de `source_type` values). Vacío = todos verifican automáticamente. Con canales en la lista, esos canales operan en modo sombra. |
| **PaymentMatchGuard** | ✅ Completo | Validación multifield (banco emisor + teléfono) antes de auto-verificar. Compara el banco y teléfono de la notificación contra el reporte del tenant usando BankCode enum para determinar si aplica canonical phone (BNC: first4+last4) o full digits (BDV). |
| **Frontend Landlord** | ✅ Completo | Order list/detail, verificación/cancelación de pagos |
| **Frontend Tenant** | ✅ Completo | Order list/detail, formulario de reporte de pago |

### Funcionalidad actual — Infraestructura

| Componente | Estado | Descripción |
|-----------|--------|-------------|
| **Multitenancy** | ✅ Completo | Spatie v4, multi-database (landlord DB para billing, tenant DB independiente para datos por tenant), tenant resolution por dominio |
| **Auth** | ✅ Completo | Fortify, registro, login, 2FA, passkeys |
| **Browser Tests** | ✅ Completo | 6+ archivos, 30+ tests, 177+ assertions |
| **Feature/Unit Tests** | ✅ Completo | 514+ tests, 1915+ assertions |
| **Order Expiration** | ✅ Completo | Comando programado `orders:expire` |
| **Subscription Expiration** | ✅ Completo | Comando programado `subscriptions:expire` |
| **Payment Expiration** | ✅ Completo | Comando programado `payments:expire-pending` (pagos pending > match_window_hours + 24h) |
| **Device Management** | ✅ Completo | Modelo Device, factory con estados (stale, inactive, withoutHeartbeat), auto-desactivación en heartbeat timeout |
| **Device Heartbeat Check** | ✅ Completo | Comando `devices:check-heartbeats` — scheduled, timeout configura vía `device.heartbeat_interval_minutes` |
| **SystemConfig** | ✅ Completo | Cache sentinel pattern, cache invalidation en save/set, type-aware casting (int, bool, json, string) |
| **PaymentNotification** | ✅ Completo | Modelo con source_type (SourceType enum: bank-app/sms), computeDedupHash() basado en normalización semántica, markParsed/markFailed, scopes, parsed_data display accessor |
| **normalizeRef helper** | ✅ Completo | Helper global `normalizeRef()` en `app/Helpers/normalizeRef.php`: trim + strtoupper. Usado por parser, PaymentService, y queries de matching. |
| **BankCode Enum** | ✅ Completo | Casos: BDV, BNC. Métodos: code(), name(), appliesCanonicalPhone(), dateFormats(), androidPackage(), toArray() |
| **SourceType Enum** | ✅ Completo | Casos: BankApp, Sms. Métodos: label(), values() |
| **PaymentMatchFactory** | ✅ Completo | Factory con datos por defecto realistas |

### Qué falta (no implementado aún)

> **Todo el backend (Fases 0-7) y frontend operacional (S8a-S8f) están COMPLETOS e implementados.**
> Lo que falta se limita a los componentes del ecosistema Android y endpoints API.

- **Dispositivo Android** como capturador de notificaciones (app nativa con NotificationListenerService)
- Tabla `devices` + modelo `Device` con autenticación por token (backend listo, falta app Android que lo use)
- Comando `CheckDeviceHeartbeats` (backend ✅ listo, esperando dispositivos reales que reporten)
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

> **Alcance de este plan**: Solo conciliación de **PagoMóvil**. La conciliación de Transferencia Bancaria queda deferred. PagoMóvil tiene un formato de notificación **estandarizado por banco pero distinto por canal** (SMS vs app bancaria) — si el sistema recibe pagos de Banco X, necesita un parser para ese banco Y canal. Si luego recibe de Banco Y, necesita otro parser. Cada banco tiene su propio formato push y cada canal dentro del banco puede diferir.

### Por qué solo PagoMóvil (y no Transferencia Bancaria)

PagoMóvil es el método de pago **principal** en Venezuela — tiene volumen alto y verificación inmediata (el dinero se debita al instante). Transferencia Bancaria es secundaria y tiene un proceso de verificación diferente (extracto bancario, horarios bancarios, etc.). Conciliar transferencias requiere un enfoque distinto (CSV/extracto) que no aplica a notificaciones push. Por eso este plan se enfoca solo en PagoMóvil.

---

## Principios de Diseño

- **Pipeline único de aprobación**: tanto el flujo manual como el automático terminan llamando al mismo `PaymentService::verifyPayment()` que dispara `PaymentVerified` → `ActivateSubscription`. No hay caminos paralelos.
- **Datos inmutables**: los raw notifications nunca se modifican, solo se referencian.
- **Idempotencia desde el día 1**: `createFromParsed()` con 4-step dedup (por notification_id, por referencia unmatched, por referencia matched, unmatched nuevo) y `computeDedupHash()` con normalización semántica de campos.
- **Matching determinista + multifield guard**: o hay coincidencia exacta (referencia + monto + ventana temporal) → pasa al multifield guard → verifica, o no hay → va a cola de revisión manual. El guard opcional valida banco emisor + teléfono entre la notificación y el reporte del tenant, alertando si difieren sin bloquear (match_status → pending + SystemAlert).

> **Por qué matching determinista (sin confidence scores)**: En sistemas financieros, los falsos positivos son peores que los falsos negativos. Un pago auto-verificado incorrectamente puede causar pérdida de dinero o activar un servicio sin pago real. Un falso negativo solo requiere revisión manual — inconveniente, pero no destructivo. Por eso decidimos: o es match exacto (referencia + monto), o va a cola manual. Sin punto medio probabilístico.

> **Por qué el teléfono NO entra en matching**: Los bancos venezolanos enmascaran el teléfono del pagador en las notificaciones push. Ejemplo real: BNC muestra `0416***9503` en vez del número completo. BDV muestra el número completo. Si usáramos teléfono como criterio, BNC nunca matchearía. La referencia (6-12 dígitos, única por transacción) + monto son suficientes y confiables en todos los bancos.

> **Por qué ventana temporal**: Sin ventana, un pago muy viejo (meses) podría tener la misma referencia + monto que uno nuevo por coincidencia. La ventana (default 72h) rechaza pagos antiguos y reduce falsos positivos.

- **Rechazo con semántica explícita**: `CancellationType` enum distingue duplicado de fraude de otras cancelaciones, con routing distinto para alertas internas vs. notificación al cliente. `cancellation_reason` es texto libre para humanos.

> **Por qué CancellationType enum + cancellation_reason texto libre**: El enum resuelve un problema técnico: cada tipo de cancelación tiene un "routing" diferente (duplicate → notificar admin + tenant; expired → solo notificar tenant; manual → notificar solo tenant). El texto libre resuelve un problema humano: el admin o el sistema necesitan escribir "Pago duplicado por error del cliente" o "Referencia 006236568762 ya verificada en pago #45". Son dos necesidades distintas que coexisten sin conflicto.

- **FK directas, sin polimorfismo**: `payment_matches` usa `payment_id` FK directa a `payments`, no `morphs`. Integridad referencial real, agnóstico a framework.

> **Por qué FK directa en vez de morphs**: El sistema ya usa Supertipo/Subtipo (FK directas) para Payment → PagoMovilDetail/BankTransferDetail. `payment_matches` solo matchea contra `Payment` — nunca contra otros tipos. Usar morphs aquí sería inconsistente con la arquitectura existente y perdería integridad referencial a nivel DB. Si otro stack (Go, Node) necesita leer estos datos, una FK estándar es universal; un morph de Laravel es framework lock-in.

- **Configuración centralizada**: toda la configuración vive en `system_configs` (tabla en DB). No hay `config/payment.php`. Una sola fuente de verdad, cacheada en application boot.

> **Por qué system_configs en vez de config file**: `config/payment.php` requiere deploy para cambiar cualquier valor. En un SaaS multi-tenant, el landlord necesita cambiar expiración de órdenes, activar/desactivar shadow mode por canales, o actualizar regex de parsers SIN deployear. `system_configs` con cache de 1h da esa flexibilidad. También simplifica la migración: un solo Seeder crea todos los registros iniciales.

- **PaymentMethodConfig como única fuente**: `PagoMovilGateway` y `BankTransferGateway` siempre requieren un `PaymentMethodConfig` activo. Sin fallback a config file.

- **Regex por canal, no por banco**: Los formatos de notificación varían no solo por banco sino también por canal (SMS vs app bancaria). El parser resuelve `regex_{bankCode}_{sourceType}`. Si un canal no tiene regex, el parser retorna null sin fallback. Esto evita matchear un SMS con un regex diseñado para app bancaria (que podría tener formato diferente).

- **Shadow mode por canales**: En vez de un booleano global, `reconciliation.shadow_mode_channels` es un JSON array de canales (`["sms", "bank-app"]`) que operan en modo sombra. Un array vacío significa que ningún canal tiene shadow — todos verifican automáticamente. Esto permite activar shadow solo para SMS mientras bank-app verifica automáticamente, o viceversa.

- **Validación multifield post-match**: `PaymentMatchGuard` verifica que el banco emisor y el teléfono (total o canonical según el banco) de la notificación coincidan con lo que reportó el tenant. Si no coinciden, el match se marca como `pending` y se envía un `SystemAlert` al admin. No bloquea — alerta. Telefono canonical para BNC (enmascarado): compara first4+last4. Para BDV (completo): compara digits completos.

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
  --phone=04141234567 \
  --source=sms
```

**Nota**: El comando ahora acepta `--source` (sms o bank-app, validado contra SourceType enum) y usa `PaymentNotification::computeDedupHash()` con normalización semántica para generar el hash. También valida `--bank` contra el `BankCode` enum.

### 0.2 Database Seeder opcional

10-20 ejemplos representativos por banco destino, incluyendo edge cases
(montos con cero decimales, referencias cortas, espacios irregulares). Para datos de prueba, usar `PaymentNotificationFactory` con estados `pending()` o `failed()`.

**Output**: filas en `payment_notifications` con `parse_status = pending`.

---

## Fase 1 — Backend: Migraciones y Modelos (✅ COMPLETADA)

### 1.0 Tabla `system_configs` (configuración centralizada — ÚNICA fuente de verdad)

```php
Schema::create('system_configs', function (Blueprint $table) {
    $table->id();
    $table->string('group');              // 'payment', 'reconciliation', 'devices', 'regex'
    $table->string('key')->unique();      // 'payment.order_expiry_hours', 'regex_bdv_sms', etc.
    $table->text('value');
    $table->string('type')->default('string'); // 'string', 'integer', 'boolean', 'json'
    $table->text('description')->nullable();
    $table->timestamps();

    $table->index('group');
});
```

**Registros iniciales (seeder):**

| group | key | value | type | description |
|-------|-----|-------|------|-------------|
| `admin` | `admin.polling_interval_seconds` | `30` | integer | Segundos entre auto-refresh del badge de notificaciones bancarias |
| `payment` | `payment.order_expiry_hours` | `48` | integer | Horas antes de expirar una order pending |
| `reconciliation` | `reconciliation.match_window_hours` | `72` | integer | Ventana de tiempo para matching y expiración de pagos pending |
| `reconciliation` | `reconciliation.shadow_mode_channels` | `[]` | json | Canales en modo sombra (JSON array de source_type values). Vacío = sin shadow mode |
| `reconciliation` | `reconciliation.polling_interval_seconds` | `30` | integer | Segundos entre auto-refresh del dashboard de conciliación |
| `device` | `device.heartbeat_interval_minutes` | `1` | integer | Intervalo esperado de heartbeat del dispositivo |
| `device` | `device.heartbeat_retention_days` | `30` | integer | Días de retención de heartbeats históricos |
| `regex` | `regex_bdv_sms` | `/(?<amount>...)...(?<reference>...).../i` | string | Regex BDV para SMS |
| `regex` | `regex_bdv_bank-app` | `/(?<amount>...)...(?<reference>...).../i` | string | Regex BDV para app bancaria |
| `regex` | `regex_bnc_sms` | `/(?<amount>...)...(?<reference>...).../i` | string | Regex BNC para SMS |
| `regex` | `regex_bnc_bank-app` | `/(?<amount>...)...(?<reference>...).../i` | string | Regex BNC para app bancaria |

**Grupos de configuración**: `admin`, `payment`, `reconciliation`, `device`, `regex`.

**Modelo `SystemConfig` (con cache sentinel)**:

Incluye métodos `get()`, `set()`, cache invalidation en `save()` y `set()`. La caché usa sentinel pattern para distinguir "valor cacheado null" de "cache miss". `type` permite cast automático (integer, boolean, json, string).

```php
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
    // Sentinel pattern atómico: evita condición de carrera has/get.
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
```

### 1.1 Gateways sin fallback a config file

**`PagoMovilGateway`**: Siempre requiere un `PaymentMethodConfig` activo. No hay fallback a config file. `resolveReceivingAccount()` aborta con 422 si el config no existe o está inactivo.

**`BankTransferGateway`**: Sin cambios — ya requiere `PaymentMethodConfig` explícito.

**Seeder obligatorio**: Al menos 1 cuenta `PaymentMethodConfig` por tipo (`pago_movil`, `bank_transfer`) al iniciar el sistema.

### 1.2 Tabla `devices` (autenticación de teléfonos)

```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('token', 64)->unique();     // token de autenticación (revocable)
    $table->string('android_device_id')->nullable(); // ID físico del dispositivo para dedup en re-registro
    $table->timestamp('last_heartbeat_at')->nullable();
    $table->string('last_heartbeat_ip', 45)->nullable(); // IP para diagnóstico
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Migraciones adicionales**:
- `2026_06_24_000003_drop_bank_code_from_devices_table`: Elimina `bank_code` de devices — el banco ya no es propiedad del dispositivo.
- `2026_06_29_000002_drop_tenant_id_from_devices`: Elimina `tenant_id` — el dispositivo es del landlord, no del tenant.
- `2026_06_27_000001_create_device_invite_codes`: Tabla para registro con código de invitación.

**Registro de dispositivos**: El teléfono se registra usando un `DeviceInviteCode` (código de invitación generado por el admin). El endpoint genera el token de autenticación y lo devuelve al teléfono. El token se reemplaza si el mismo `android_device_id` se registra de nuevo (re-registro post-fábrica).

### 1.3 Tabla `payment_notifications` (inmutable)

```php
Schema::connection('landlord')->create('payment_notifications', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('device_id')->nullable();
    $table->string('source_type', 20)->default('bank-app');  // 'bank-app' | 'sms'
    $table->string('bank_code', 20);              // ej. 'bdv', 'bnc' — SIEMPRE lowercase
    $table->text('raw_text');                     // texto completo de la notificación (SMS o push)
    $table->string('dedup_hash', 64)->unique();   // hash semántico: SHA256(bankCode + normalized_fields)
    $table->string('parse_status', 20)->default('pending'); // pending | parsed | failed
    $table->json('parsed_data')->nullable();       // JSON con amount_cents, reference, phone, raw_groups
    $table->text('parse_error')->nullable();       // mensaje de error si falló el parseo
    $table->timestamp('parsed_at')->nullable();
    $table->timestamps();

    $table->foreign('device_id')
          ->references('id')->on('devices')
          ->nullOnDelete();                        // si se borra el device, las notis sobreviven
    $table->index('bank_code');
    $table->index('parse_status');
    $table->index('source_type');
});
```

> **`source_type`**: Agregado en migración `2026_07_03`. Usa `SourceType` enum (`bank-app`, `sms`). Default `bank-app`. Permite elegir el regex correcto según el canal de origen.

> **`raw_text`**: Un solo campo de texto (no `raw_title`/`raw_body`). El título nunca fue necesario porque el body contiene toda la información. La migración `2026_06_30` eliminó `raw_title` y `package_name`.

> **`dedup_hash`**: Ya NO es `SHA256(bank_code_lowercase + raw_body)`. Ahora se calcula vía `PaymentNotification::computeDedupHash()` que normaliza semánticamente los campos (monto, teléfono canonical/complete según banco, fecha, referencia) antes de hashear. Esto garantiza que notificaciones SMS vs app bancaria del mismo pago tengan hash idéntico.

### 1.4 Model `PaymentNotification`

```php
class PaymentNotification extends Model
{
    use HasFactory, UsesLandlordConnection;

    protected $table = 'payment_notifications';

    protected $fillable = [
        'parse_status', 'parsed_data', 'parse_error', 'parsed_at', 'source_type',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
            'parsed_at' => 'datetime',
            'source_type' => SourceType::class,
        ];
    }

    // Métodos clave:
    public static function computeDedupHash(string $bankCode, string $rawText, string $sourceType): string;
    public function markParsed(ParsedPayment $parsed): void;
    public function markFailed(string $error): void;
    public function scopePending($query);
    public function scopeFailed($query);
    public function match(): HasOne;  // relación a PaymentMatch
    public function getParsedPaymentAttribute(): ?ParsedPayment;  // accessor: reconstruye DTO del JSON
    protected function parsedDataDisplay(): Attribute;  // accessor: parsed_data sin raw_groups (para UI)
}
```

Muta solo `parse_status`, `parsed_data`, `parse_error`, `parsed_at`. Los campos raw (`bank_code`, `raw_text`, `dedup_hash`) son inmutables. Los campos están fuera de `$fillable`.

### 1.5 Tabla `payment_matches` (resultado del parseo + matching)

```php
Schema::connection('landlord')->create('payment_matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_notification_id')
        ->constrained('payment_notifications')->cascadeOnDelete();
    $table->foreignId('payment_id')
        ->nullable()->constrained('payments')->nullOnDelete();
    $table->string('parsed_reference', 20)->nullable();
    $table->integer('parsed_amount_cents');
    $table->string('parsed_sender_phone_last4', 4)->nullable();
    $table->string('parsed_sender_phone_number', 30)->nullable();  // ADDED 2026-07-08
    $table->string('parsed_sender_phone_first4', 4)->nullable();   // ADDED 2026-07-08
    $table->string('parsed_bank_code', 10)->nullable();            // ADDED 2026-07-08
    $table->string('match_status', 30);       // pending | unmatched | matched | duplicate_attempt
    $table->timestamp('matched_at')->nullable();
    $table->timestamps();

    $table->index('match_status');
    $table->index('payment_id');
});
```

**Partial unique index** (migración separada):
```php
DB::statement('CREATE UNIQUE INDEX idx_payment_matches_matched ON payment_matches (payment_id) WHERE match_status = \'matched\'');
```

**Columnas adicionales** (agregadas en `2026_07_08_000001_add_phone_and_bank_to_payment_matches.php`):
- `parsed_sender_phone_number`: teléfono completo extraído de la notificación (ej. `04123153557` o `0416***9503`)
- `parsed_sender_phone_first4`: primeros 4 dígitos del teléfono (para BNC que enmascara)
- `parsed_bank_code`: código del banco extraído de la notificación (para `PaymentMatchGuard`)

Estas columnas fueron añadidas para soportar la validación multifield de `PaymentMatchGuard`.

**Modelo `PaymentMatch`**:

```php
class PaymentMatch extends Model
{
    use HasFactory, UsesLandlordConnection;

    protected $fillable = [
        'payment_notification_id', 'payment_id',
        'parsed_reference', 'parsed_amount_cents',
        'parsed_sender_phone_last4', 'parsed_sender_phone_number',
        'parsed_sender_phone_first4', 'parsed_bank_code',
        'match_status', 'matched_at',
    ];

    public function notification(): BelongsTo;
    public function payment(): BelongsTo;

    /**
     * 4-step dedup algorithm:
     * 1. Idempotency — same notification → return existing
     * 2. Same reference, unmatched exists → reuse (link notification)
     * 3. Same reference, matched exists → create duplicate_attempt
     * 4. No match → create new unmatched
     */
    public static function createFromParsed(PaymentNotification $notification, ParsedPayment $parsed): static;
}
```

> **4-step dedup**: A diferencia del `firstOrCreate` simple del diseño original, la implementación actual (1) verifica si la misma notificación ya tiene match (idempotencia), (2) si existe un match unmatched con la misma referencia, reusa ese registro y linkea la nueva notificación, (3) si existe un match matched con la misma referencia, crea un nuevo match marcado como `duplicate_attempt`, (4) si no existe nada, crea un nuevo match `unmatched`.

### 1.5b Notificaciones de infraestructura (reutiliza `notifications` de Laravel)

> **Por qué reutilizar `notifications` en vez de tabla separada**: La tabla `notifications` ya existe en Landlord DB, tiene `notifiable_type`/`notifiable_id`, `data` (JSON), y `read_at`. Las alertas de infraestructura se envían como notificaciones a users con rol admin del landlord. Se distinguen de las notificaciones de negocio por el `type` de la clase `SystemAlert`.

```php
class SystemAlert extends Notification
{
    public function __construct(
        public string $type,      // device_offline | parser_failed | duplicate_reference | payment_multifield_mismatch | payment_amount_mismatch
        public string $message,
        public string $severity = 'warning',  // critical | warning | info
    ) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'system',
            'type' => $this->type,
            'message' => $this->message,
            'severity' => $this->severity,
        ];
    }
}
```

> **Nota**: `SystemAlert` usa solo 3 parámetros: `type`, `message`, `severity` (con default `'warning'`). No tiene campo `title` ni `metadata`.

### 1.6 Enum `CancellationType`

```php
enum CancellationType: string {
    case Manual          = 'manual';
    case SystemDuplicate = 'system_duplicate';
    case SystemExpired   = 'system_expired';
    case MethodChanged   = 'method_changed';
}
```

### 1.7 Enum `BankCode`

```php
enum BankCode: string {
    case Bdv = 'bdv';
    case Bnc = 'bnc';

    public function code(): string;
    public function name(): string;                  // "Banco de Venezuela", "Banco Nacional de Crédito"
    public function appliesCanonicalPhone(): bool;   // BNC=true (enmascarado), BDV=false (completo)
    public function dateFormats(): array;            // formatos de fecha por banco
    public function androidPackage(): ?string;       // package name de la app Android
    public function toArray(): array;                // serialización completa
}
```

### 1.8 Enum `SourceType`

```php
enum SourceType: string {
    case BankApp = 'bank-app';
    case Sms = 'sms';

    public function label(): string;    // "Bank App", "SMS"
    public static function values(): array; // ['bank-app', 'sms']
}
```

### 1.9 Helper global `normalizeRef()`

```php
// app/Helpers/normalizeRef.php
if (! function_exists('normalizeRef')) {
    function normalizeRef(string $ref): string
    {
        return trim(strtoupper($ref));
    }
}
```

Autoloaded vía `composer.json` → `autoload.files`.

`PaymentService::recordPayment()` normaliza el `transaction_id` al guardar:
```php
$payment->update(['transaction_id' => normalizeRef($transactionId)]);
```

---

## Fase 2 — Backend: Parser Único + Regex en DB (✅ COMPLETADA)

> **Importante**: PagoMóvil tiene un formato de notificación push **estandarizado por banco pero distinto por canal** (SMS vs app bancaria). Cada banco envía la notificación con un formato diferente según el canal. En vez de crear una clase por banco (que obligaría a compilar y deployear por cada cambio), usamos un **parser único guiado por datos** — un solo componente que aplica el regex correcto según el banco Y el canal.

> **Por qué parser único + regex en DB (en vez de una clase por banco)**: Los bancos venezolanos cambian el formato de sus notificaciones constantemente (añaden un espacio, cambian guiones por barras, quitan palabras). Si tuviéramos una clase `BNCParser.php` hardcodeada, cada cambio de formato requeriría: modificar código → compilar → subir a Play Store → usuario actualiza. Con regex en DB: actualizar una fila en `system_configs` → todos los dispositivos reciben el nuevo patrón sin actualización de la app. Esto fue confirmado por investigación externa: "ningún banco publica la documentación de sus plantillas de texto, y esos formatos cambian mediante actualizaciones silenciosas".

**Regex por canal**: Los regex se almacenan como `regex_{bankCode}_{sourceType}` (ej. `regex_bdv_sms`, `regex_bdv_bank-app`, `regex_bnc_sms`, `regex_bnc_bank-app`). No hay fallback genérico — si el regex para el canal específico no existe, el parser retorna null. Esto evita matchear un SMS con un regex de app bancaria.

### Ejemplos reales de formatos

| Campo | BDV (SMS) | BNC (App Android) |
|-------|-----------|-----------|
| Texto ejemplo | `Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40` | `BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion` |
| Teléfono | Completo: `0424-3153557` | **Enmascarado**: `0416***9503` |
| Referencia | `006236568762` | `603185603` |
| Monto | `Bs. 3.000,00` (con separador de miles) | `Bs.10455,00` (sin separador) |
| Fecha | `02-06-26` (DD-MM-YY) | `31/05/26` (DD/MM/YY) |
| Hora | `09:40` | `20:25` |

**Conclusión**: El teléfono NO es confiable para matching — algunos bancos lo enmascaran. El matching se basa en **referencia + monto + ventana temporal + validación multifield opcional**.

### Arquitectura: Parser Único + Regex en DB

```
Notificación llega al endpoint
        │
1. Endpoint resuelve: bank_code (strtolower)
        │
2. Determina source_type: 'sms' o 'bank-app'
        │
3. Busca en system_configs: regex_{bank_code}_{source_type}
        │
4. Parser único aplica regex al texto crudo
        │
5. Normaliza datos (monto, referencia, fecha)
        │
6. Devuelve ParsedPayment o null
```

**Ventaja clave**: Si un banco cambia su formato → se actualiza el regex para el canal específico en `system_configs` → todos los dispositivos reciben el nuevo patrón sin actualizar la app Android.

### Package IDs de bancos (Android)

| Banco | Package ID (BankCode::androidPackage()) | Estado |
|-------|-----------|--------|
| BDV | `com.bdv.pagomovil` | ✅ Según BankCode enum |
| BNC | `com.bnc.pagomovil` | ✅ Según BankCode enum |

> **Nota**: Los package IDs están definidos en `BankCode::androidPackage()`. Solo BDV y BNC están en el enum actualmente.

### Regex por banco y canal (almacenados en `system_configs`)

| Key en DB | Regex | Estado |
|-----------|-------|--------|
| `regex_bdv_sms` | `/(?i)Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/` | ✅ Verificado |
| `regex_bdv_bank-app` | Mismo que SMS (por ahora) | ✅ Verificado |
| `regex_bnc_sms` | `/(?i)BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/` | ✅ Verificado |
| `regex_bnc_bank-app` | Mismo que SMS (por ahora) | ✅ Verificado |

### 2.1 `PaymentNotificationParser` (parser único)

```php
class PaymentNotificationParser
{
    public function parse(string $bankCode, string $text, string $sourceType): ?ParsedPayment
    {
        // 1. Get regex for this bank+channel (cached 1h via SystemConfig)
        $regex = $this->resolveRegex($bankCode, $sourceType);
        if (! $regex) return null;

        // 2. Apply regex
        if (preg_match($regex, $text, $matches) !== 1) return null;

        // 3. Validate required groups
        if (empty($matches['amount']) || empty($matches['reference'])) return null;

        // 4. Normalize and return
        return new ParsedPayment(
            amountCents: $this->normalizeAmount($matches['amount']),
            reference: normalizeRef($matches['reference']),
            senderPhoneLast4: $this->extractLast4($matches['phone'] ?? null),
            parsedAt: $this->parseDate($matches['date'] ?? null, $matches['time'] ?? null, $this->getDateFormat($bankCode)),
            rawGroups: $namedGroups,
            senderPhoneNumber: $matches['phone'] ?? null,   // nuevo campo
            senderPhoneFirst4: $this->extractFirst4($matches['phone'] ?? null),  // nuevo campo
        );
    }

    private function resolveRegex(string $bankCode, string $sourceType): ?string
    {
        $key = "regex_{$bankCode}_{$sourceType}";
        return SystemConfig::get($key);  // NO fallback a regex_{bankCode}
    }

    // normalizeAmount, getDateFormat, extractLast4, extractFirst4, parseDate, canonicalPhone, normalizeForDedup...
}
```

**Métodos adicionales** (no documentados en diseño original):

- **`normalizeForDedup(string $bankCode, string $rawBody, string $sourceType): string`**: Normaliza el texto crudo para hashing determinista. Aplica el regex, normaliza amount, phone (canonical o completo según banco), date (ISO 8601), reference → produce un string pipe-delimited para SHA256. Usado por `PaymentNotification::computeDedupHash()`.
- **`canonicalPhone(string $phone): string`**: Para bancos con teléfono enmascarado (BNC): retorna `first4 + last4`.
- **`extractFirst4(?string $phone): ?string`**: Primeros 4 dígitos del teléfono (para validación multifield).
- **`parseDateMultiFormat(string $date, string $time, array $formats): string`**: Prueba múltiples formatos de fecha, retorna ISO 8601.
- **`resolveRegex(string $bankCode, string $sourceType): ?string`**: Resuelve `regex_{bankCode}_{sourceType}` SIN fallback al genérico `regex_{bankCode}`.

### 2.2 `ParsedPayment` DTO

```php
class ParsedPayment
{
    public function __construct(
        public readonly int $amountCents,
        public readonly ?string $reference,
        public readonly ?string $senderPhoneLast4,
        public readonly ?Carbon $parsedAt,
        public readonly ?array $rawGroups = null,
        public readonly ?string $senderPhoneNumber = null,   // nuevo campo
        public readonly ?string $senderPhoneFirst4 = null,   // nuevo campo
    ) {}
}
```

> **Campos nuevos**: `senderPhoneNumber` (teléfono completo como viene en la notificación) y `senderPhoneFirst4` (primeros 4 dígitos) fueron añadidos para soportar `PaymentMatchGuard` y `normalizeForDedup`.

### 2.3 Validación de regex antes de guardar

En `SystemConfigController`: Las config con key que empieza con `regex_` se validan al guardar — el regex debe compilar y tener los grupos nombrados `amount` y `reference`. Si falla, se rechaza con 422.

---

## Fase 3 — Backend: Job de Parsing (✅ COMPLETADA)

### 3.1 Orquestador `IngestPaymentNotification`

```php
class IngestPaymentNotification implements NotTenantAware, ShouldQueue
{
    public function handle(): void
    {
        // 1. Parsear — ahora con source_type
        $parsed = app(PaymentNotificationParser::class)->parse(
            $this->notification->bank_code,
            $this->notification->raw_text,
            $this->notification->source_type?->value,  // nuevo 3er parámetro
        );

        if ($parsed === null) {
            $this->notification->markFailed('Regex did not match');
            return;
        }

        // 2. Matching en transacción (incluye PaymentMatchGuard)
        $result = DB::transaction(function () use ($parsed) {
            $match = PaymentMatch::createFromParsed($this->notification, $parsed);
            return app(ReconciliationOrchestrator::class)->run($match);
        });

        // 3. Post-procesamiento
        $this->notification->markParsed($parsed);

        // 4. Eventos post-commit (IC-4)
        $this->dispatchPostCommitEvents($result);
    }

    public function failed(?\Throwable $e): void
    {
        $this->notification->markFailed($e?->getMessage() ?? 'Unknown error');
    }
}
```

### 3.2 Comando de backfill

```bash
php artisan reconciliation:reprocess --parse-status=failed
```

Itera sobre `PaymentNotification` con el `parse_status` indicado y redispara `IngestPaymentNotification` para cada una.

---

## Fase 4 — Backend: Motor de Matching (✅ COMPLETADA)

### 4.1 `ReconciliationOrchestrator`

**Shadow mode**: Usa `reconciliation.shadow_mode_channels` (JSON array de source types). Vacío = shadow mode off para todos. Con canales en la lista → esos canales operan en modo sombra y NO verifican automáticamente.

**`shouldShadow(PaymentMatch $match): bool`**: Resuelve el `source_type` via FK `PaymentMatch → PaymentNotification`. Si el canal está en `shadow_mode_channels`, devuelve true.

**`run(PaymentMatch $match): ReconciliationResult`**:

**Guard de estado**: Si el match ya fue procesado (status != 'unmatched'), retorna resultado vacío.

**Paso 0 — Duplicado** (CORRE PRIMERO, SIEMPRE): Busca `Payment Verified` con misma referencia + monto. Si existe → `match_status = duplicate_attempt`, cancela cualquier pago pendiente con esa referencia.

**Paso 1 — Matching normal**: Busca `Payment Pending` con misma referencia + monto + ventana temporal (desde `match.created_at`). `SELECT FOR UPDATE`. Filtra payments que ya tienen un match 'matched'.

**Paso 2 — Único candidato**: Verifica status Pending (race condition guard — si el status cambió, resetea `match_status = 'unmatched'` y retorna vacío), ejecuta **PaymentMatchGuard::validate()** para validación multifield (banco + teléfono). Si hay mismatch, linkea `payment_id` al match y setting `match_status = 'pending'` + SystemAlert. Si pasa y `shouldShadow()` false → `verifyPayment()`, match_status = 'matched'. Si shadow → match_status = 'pending'.

**Paso 3 — Múltiples candidatos**: match_status = 'pending' (cola de revisión manual).

**Paso 4 — Sin candidatos**: match_status = 'unmatched'.

**`runReverse(PaymentMatch $match, Payment $payment): ReconciliationResult`**:

1. Guards: `payment.status === Pending`, `match.match_status === 'unmatched'`
2. Linkea `payment_id` al match
3. PaymentMatchGuard::validate() — si mismatch → `pending` + alerta
4. shouldShadow() → pending o verify + matched

> **Flujo de duplicado en recordPayment()**: El control de duplicado se maneja en dos niveles. En `PaymentService::recordPayment()`, antes de crear el pago, se busca si ya existe un `Payment Verified` con la misma referencia — si existe, lanza `DuplicatePaymentReferenceException`. En el orquestador, si el pago ya pasó ese guard pero llega una notificación con referencia ya verificada, el Paso 0 detecta el duplicado.

> **Overpayment notification**: Cuando `attemptReverseMatch()` encuentra un match vía fallback de solo referencia (Step 2 — amount mismatch), después de la verificación automática envía un `SystemAlert` tipo `payment_amount_mismatch` a los admins del landlord reportando la discrepancia de monto (monto reportado vs. monto bancario).

> **Reverse flow two-step matching** (`PaymentService::attemptReverseMatch()`): El reverse match busca payment_matches sin vincular en dos pasos:
> 1. **Match estricto**: `parsed_reference = transaction_id AND parsed_amount_cents = amount_cents` — match ideal, sin discrepancia.
> 2. **Fallback solo referencia**: Si Step 1 no encuentra nada, busca solo por `parsed_reference = transaction_id` (sin filtro de monto). Si encuentra, marca `amountMismatch = true` y después de la verificación envía la alerta de overpayment.
>
> Esto permite vincular pagos donde el monto reportado por el cliente difiere del monto real del banco (sobrepago/subpago), en vez de perder el match completamente.

### 4.2 `PaymentMatchGuard` (validación multifield)

> **Nuevo componente**: No existía en el diseño original. Se agregó durante implementación como capa extra de seguridad.

```php
class PaymentMatchGuard
{
    public static function validate(PaymentMatch $match, Payment $payment): ?array
    {
        // Carga pagoMovilDetail
        // Resuelve BankCode desde parsed_bank_code
        // Valida: sender_bank del reporte == BankCode::name()
        // Valida teléfono según banco:
        //   Si appliesCanonicalPhone (BNC): compara first4+last4
        //   Si no (BDV): compara digits completos
        // Retorna null si OK, array con detalles del mismatch si falla
    }
}
```

Se ejecuta tanto en `run()` (forward) como en `runReverse()` (reverse). Si hay mismatch, el match queda como `pending` y se envía `SystemAlert` al admin. No bloquea la verificación — alerta para revisión manual.

### 4.3 Comando programado: expiración de pagos pendientes

```bash
php artisan payments:expire-pending
```

(Scheduled en `routes/console.php` cada hora)

```php
$windowHours = (int) SystemConfig::get('reconciliation.match_window_hours', 72);
$cutoff = now()->subHours($windowHours + 24);

Payment::where('status', PaymentStatus::Pending)
    ->where('created_at', '<', $cutoff)
    ->get()
    ->each(fn($payment) => $paymentService->cancelPayment(...));
```

Después de cancelar, dispara `event(new PaymentCancelled(...))`.

### 4.4 Comando `CheckDeviceHeartbeats`

```bash
php artisan devices:check-heartbeats
```

**Ya implementado** (no pendiente como dice el diseño original):
- Toma `device.heartbeat_interval_minutes` de SystemConfig (default 1)
- Timeout = 3x el intervalo
- Busca dispositivos activos cuyo `last_heartbeat_at` superó el timeout
- **Auto-desactiva** el dispositivo (`is_active = false`)
- Envía `SystemAlert` con `type = device_offline`, `severity = warning`
- Si el dispositivo vuelve, se re-registra via `android_device_id`

### 4.5 Scheduler: tareas programadas

| Comando | Frecuencia | Qué hace |
|---------|-----------|----------|
| `orders:expire` | Cada hora | Cancela órdenes pending vencidas (expires_at) |
| `payments:expire-pending` | Cada hora | Cancela payments pending más viejos que `match_window_hours + 24h` (~96h) |
| `subscriptions:expire` | Diario | Desactiva suscripciones vencidas |
| `devices:check-heartbeats` | Cada 5 min | Detecta dispositivos offline, desactiva y alerta |

---

## Fase 5 — Backend: Eventos y Notificaciones (✅ COMPLETADA)

### 5.1 PaymentService::verifyPayment()

```php
public function verifyPayment(Payment $payment, ?int $adminId = null): void
```

- `$adminId = null` → pago verificado automáticamente (verified_by = null)
- Envuelto en `DB::transaction()`
- NO despacha `PaymentVerified` internamente — lo hace el caller (IC-4)

### 5.2 PaymentCancelled event

```php
class PaymentCancelled {
    public function __construct(
        public readonly Payment $payment,
        public readonly CancellationType $type,
        public readonly ?string $reason = null,
    ) {}
}
```

### 5.3 NotifyPaymentRejected listener

Escucha `PaymentCancelled` y decide según `CancellationType`:
- `SystemDuplicate` → `PaymentRejected` al tenant + `SystemAlert` a landlord admins
- `SystemExpired` → solo `PaymentRejected` al tenant
- `Manual` → solo `PaymentRejected` al tenant
- `MethodChanged` → no notifica (acción legítima del usuario)

### 5.4 SystemAlert notification

```php
class SystemAlert extends Notification {
    public function __construct(
        public string $type,      // device_offline | duplicate_reference | parser_failed | payment_multifield_mismatch | payment_amount_mismatch
        public string $message,
        public string $severity = 'warning',  // critical | warning | info
    ) {}
}
```

No tiene campo `title` separado ni `metadata`. Se usa `type` + `severity` + `category = 'system'` para routing.

---

## Fase 6 — Backend: Dashboard de Alertas (Inertia) (✅ COMPLETADA)

Routes:
- `GET /admin/alerts` → `AlertController::index()`
- `POST /admin/alerts/{notification}/read` → marcar como leída

Badge en sidebar del panel landlord con conteo de no leídas.

---

## Fase 7 — Transición: Shadow Mode (✅ COMPLETADA)

**Controlado por**: `SystemConfig::get('reconciliation.shadow_mode_channels')` — JSON array.

- `[]` = sin shadow mode, todos los canales verifican automáticamente
- `['sms']` = solo SMS en modo sombra, bank-app verifica automáticamente
- `['bank-app', 'sms']` = ambos en modo sombra

**Activación gradual**: Admin quita canales de la lista uno a uno a medida que gana confianza en el matching de ese canal.

**Dashboard** expone el toggle y el estado actual de shadow mode por canal.

---

## S8 — Frontend Operacional (UI para Landlord)

Todos los sub-slices S8a–S8f están COMPLETOS. Ver tabla de status al inicio del documento.

**Excluido de S8** (postergado a S3 — App Android):
- IC-8: API endpoints `/api/device/notifications` y `/api/device/heartbeat`
- Middleware `device.auth`
- Dashboard de dispositivos / heartbeat status

---

## IC-8: API Endpoints para App Android (PENDIENTE — S3)

| Método | Ruta | Auth | Request Body | Response |
|--------|------|------|-------------|----------|
| POST | `/api/device/notifications` | `X-Device-Token` header | `{bank_code, raw_body}` + source_type via URL segment | `{status: "created", id: N}` o `{status: "duplicate_ignored"}` (200) |
| POST | `/api/device/heartbeat` | `X-Device-Token` header | `{battery_level, notifications_pending_count}` | `{status: "ok", heartbeat_interval_minutes: N}` |

> **Notas IC-8**:
> - `source_type` se resuelve desde el segmento de la URL (ej. `/api/device/notifications/bank-app`), no desde el body del request.
> - `dedup_hash` se calcula server-side vía `PaymentNotification::computeDedupHash()` — el dispositivo no lo envía.
> - `received_at` no existe en el schema actual — los timestamps se manejan automáticamente.

**Note**: Los controllers API (`IngestController`, `DeviceController`) existen en el codebase pero no están activos hasta que la app Android esté en producción. Las rutas están definidas en `routes/api.php`.

---

## Fase 8 — App Android (PENDIENTE)

Ver diseño original en la sección 8 del plan. Sin cambios sustanciales.

---

## Tests

| Suite | Archivos | Tests | Assertions |
|-------|----------|-------|------------|
| Feature Landlord | Múltiples | 514+ | 1915+ |
| Browser | 6+ | 30+ | 177+ |
| PaymentNotificationController | 1 | 12 | 101 |
| PaymentNotificationBrowser | 1 | 10 | — |
| PaymentService | 1 | — | — |
| ReconciliationOrchestrator | 1 | — | — |
| IngestPaymentNotification | 1 | — | — |
| PaymentMatchGuard | 1 | — | — |
| PaymentNotificationParser | 2 (unit + integration) | — | — |
| PaymentNotificationParserIntegration | 1 | — | — |
| Sistema Completo (landlord) | — | 120 | sin regresiones |

**Nunca ejecutar el suite completo** — timeout de 20+ minutos. Usar `--filter`.

---

## Glosario de Términos

| Término | Definición |
|---------|-----------|
| **source_type** | Canal de origen de la notificación (`bank-app` o `sms`). Determina qué regex usar. |
| **shadow_mode_channels** | JSON array de source types que operan en modo sombra. Vacío = sin shadow mode. |
| **PaymentMatchGuard** | Validación multifield que compara banco emisor y teléfono entre notificación y reporte del tenant. |
| **BankCode** | Enum con bancos soportados (BDV, BNC). Define formato de fecha, canonical phone, y package ID Android. |
| **normalizeRef** | Helper global: `trim(strtoupper($ref))`. Usado en parser, matching, y recordPayment. |
| **computeDedupHash** | Hash semántico: normaliza campos individuales antes de hashear, para que SMS y app bancaria del mismo pago tengan hash idéntico. |
| **4-step dedup** | Algoritmo de PaymentMatch::createFromParsed: (1) misma notificación, (2) misma referencia unmatched, (3) misma referencia matched, (4) nuevo unmatched. |
| **canonical phone** | Para bancos que enmascaran (BNC): compara first4+last4. Para bancos con teléfono completo (BDV): compara digits completos. |
