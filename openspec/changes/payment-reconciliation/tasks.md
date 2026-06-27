# Tasks: Payment Reconciliation — Functional Slicing (v2)

## Status Summary (2026-06-20)

| Slice | State | PR Branch |
|-------|-------|-----------|
| **S1** — Config DB + Parser + Helper | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S2** — Simulator + Notification Storage | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S3** — Devices + Auth + API Endpoints | ⏳ Deferred (no Android aún) | — |
| **S4** — Cancellation Types + Service Changes | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S5a** — PaymentMatch + Orchestrator | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S5b** — Job + Maintenance Commands | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S6** — Reverse Matching | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S7** — IC-4 Compliance + Eventos + Notificaciones | ✅ Done | `feature/payment-reconciliation-pr1` |
| **S8** — Dashboard de Alertas (Inertia) | 🔜 **Next** | `feature/payment-reconciliation-pr1` |
| **S9** — Shadow Mode Documentation | ⏳ Pending | `feature/payment-reconciliation-pr1` |

> **Current focus**: S8 — Dashboard de Alertas. Interfaz Inertia para que el admin del landlord vea y gestione alertas del sistema.

## Delivery Strategy

| Field | Value |
|-------|-------|
| Chain strategy | feature-branch-chain |
| PR approach | Each functional slice = 1 PR |
| Budget per slice | ≤400 changed lines |
| Verification | Test-driven, user verifies before next slice |

## Implementation Order

```
S1 ✅ → S2 ✅ → S3 (DEFERRED) → S4 ✅ → S5a ✅ → S5b ✅ → S6 ✅ → S7 ✅ → S8 → S9
```

Each slice produces something **testable immediately**. User verifies before next slice.

> **2026-06-19: S3 deferred** — S3 (Devices + Auth + API Endpoints + Heartbeat) se pospone porque crea endpoints HTTP que el Android aún no consume. El matching (S5a/S5b) funciona directamente con `IngestPaymentNotification` job. Incluye `CheckDeviceHeartbeats` command (detección de dispositivos offline), que es una optimización de infraestructura para cuando los dispositivos Android ya estén en producción — no tiene sentido sin ellos. S3 se retoma cuando el Android tenga `NotificationListenerService` implementado. Nuevo orden efectivo: S1→S2→S4→S5a→S5b→S6→S7→S8→S9.
>
> **2026-06-19: S5 split into S5a + S5b** — S5 era demasiado complejo (8 archivos, orchestrator + job + 2 comandos). S5a (PaymentMatch + Orchestrator) se testea independientemente como clase pura. S5b (Job + Comandos) depende de S5a.

---

## S1: Config DB + Parser + Helper

**Función**: Parser con regex de DB produce `ParsedPayment` con datos correctos por banco
**Dependencias**: Ninguna
**Verificar**: Parser con texto BDV → ParsedPayment con amount_cents=300000, reference="006236568762"

> **Contexto — La base de todo**
> Cada banco venezolano envía notificaciones push con un formato diferente. En vez de crear una clase Parser por banco (que obligaría a compilar y deployear por cada cambio de formato), usamos un parser único guiado por regex almacenados en `system_configs`. Si un banco cambia su formato → se actualiza una fila en DB → todos los dispositivos reciben el nuevo patrón sin actualización de la app Android. Este slice crea la columna `system_configs` (única fuente de verdad), el parser, y el helper `normalizeRef()` que garantiza que la normalización de referencias sea consistente en todo el sistema.

- [ ] Create migration `create_system_configs_table` — group, key (unique), value, type, description
- [ ] Create `SystemConfig` model — UsesLandlordConnection, sentinel-cache (1h TTL), get/set/save with cache invalidation
- [ ] Create `SystemConfigSeeder` — seed all initial values (payment config + regex + reconciliation defaults + device heartbeat)
- [ ] Remove `config/payment.php`, update all 11 `config('payment.*')` callers to `SystemConfig::get()`
- [ ] Modify `PaymentService::createOrder()` — read expiry from `SystemConfig::get('payment.order_expiry_hours', 48)`
- [ ] Modify `PagoMovilGateway::resolveReceivingAccount()` — require PaymentMethodConfig, remove config fallback, return 422 on missing
- [ ] Create `PaymentMethodConfigSeeder` — ensure 1 active pago_movil + 1 active bank_transfer record
- [ ] Create `normalizeRef()` global helper in `app/Helpers/normalizeRef.php`, register in composer.json autoload.files
- [ ] Create `ParsedPayment` DTO — amount_cents, reference, sender_phone_last4, parsed_at
- [ ] Create `PaymentNotificationParser` — parse(), getDateFormat(), normalizeAmount(), extractLast4(), parseDate()
- [ ] Add regex validation logic (compile check + required named groups) to SystemConfig save path
- [ ] Write unit tests: SystemConfig get/set/cache (3+), parser per bank format × edge cases (10+ per bank), normalizeAmount (5+), normalizeRef (5+), gateway requires PaymentMethodConfig (2+), seeder verification (1)

**Archivos**: 1 migration, 1 model, 1 seeder, 1 deleted config, ~11 modified callers, 1 DTO, 1 parser, 1 helper, 1 seeder, 1 modified gateway, 31+ tests

**Test inmediato**: `php artisan test --filter=PaymentNotificationParserTest` — parser produce datos correctos por cada banco

---

## S2: Simulator + Notification Storage

**Función**: Crear notificaciones fake y almacenarlas para testear el pipeline completo
**Dependencias**: S1 (parser + config)
**Verificar**: `php artisan simulate:payment-notification --bank=bdv --amount=3000 --reference=006236568762` crea fila en payment_notifications, y el parser la parsea correctamente

> **Contexto — ¿Por qué un simulador?**
> Mientras el Android no esté listo, necesitamos una forma de crear notificaciones de prueba que el parser pueda procesar. El comando `simulate:payment-notification` crea filas directamente en `payment_notifications` con textos reales de cada banco (no textos fake). Esto permite iterar el pipeline completo (simulador → parser → matching) sin dispositivos reales. El seeder crea 8 samples (4 BDV + 4 BNC) representando montos variados, horarios, y edge cases.

- [ ] Create migration `create_payment_notifications_table` — FK to devices (nullable for simulator), dedup_hash (unique), bank_code + parse_status indexes
- [ ] Create `PaymentNotification` model — immutable (no public $fillable on raw fields), only parse_status updatable
- [ ] Create `SimulatePaymentNotification` Artisan command — accepts --bank, --amount, --reference, --sender-phone, inserts raw payment_notifications row with realistic bank-specific text
- [ ] Create `NotificationSampleSeeder` — 10-20 representative examples per bank (edge cases: zero decimals, short refs, irregular spacing)
- [ ] Write tests: command creates notification (2+), seeder creates expected count (1+), parser processes simulated notification (3+)

**Archivos**: 1 migration, 1 model, 1 command, 1 seeder, 6+ tests

**Test inmediato**: Correr simulador → ver fila en DB → parser la procesa → ParsedPayment correcto

---

## S3: Devices + Auth + API Endpoints — ⏳ DEFERRED

> **Deferred (2026-06-19)**: Este slice se pospone porque crea endpoints HTTP que el Android aún no consume. El matching (S5) funciona directamente con `IngestPaymentNotification` job sin pasar por HTTP. Se retoma cuando el Android tenga `NotificationListenerService` implementado.

**Función**: Registrar dispositivos, autenticar requests, recibir notificaciones vía API
**Dependencias**: S2 (payment_notifications table)
**Verificar**: POST `/api/device/notifications` con token válido → 200, sin token → 401

- [ ] Create migration `create_devices_table` — name, bank_code, token (unique), last_heartbeat_at, is_active
- [ ] Create `Device` model — UsesLandlordConnection, fillable fields
- [ ] Create `DeviceAuth` middleware — validate X-Device-Token, resolve device, reject inactive/missing
- [ ] Create `DeviceNotificationController` — POST /api/device/notifications with dedup handling, rate limit 60/min
- [ ] Create `DeviceHeartbeatController` — POST /api/device/heartbeat, update last_heartbeat_at
- [ ] Create API routes in `routes/api.php` with device.auth middleware
- [ ] Write tests: device auth valid/invalid (3+), notification ingestion response (2+), heartbeat (2+), dedup handling (2+)

**Archivos**: 1 migration, 1 model, 1 middleware, 2 controllers, 1 routes file, 9+ tests

**Test inmediato**: POST con token inválido → 401, con token válido → 200 + fila en payment_notifications

---

## S4: Cancellation Types + PaymentService Changes

**Función**: PaymentService actualizado con tipos de cancelación y verificación nullable
**Dependencias**: S1 (SystemConfig, PaymentMethodConfig)
**Verificar**: `cancelPayment($payment, CancellationType::Manual, $adminId)` funciona, `verifyPayment($payment, null)` funciona

> **Contexto — ¿Por qué existe este slice?**
> S5 (matching engine) necesita cancelar pagos con semántica explícita: pagos duplicados (`SystemDuplicate`), pagos expirados sin conciliación (`SystemExpired`), cancelaciones manuales (`Manual`), y cambios de método (`MethodChanged`). Sin el enum, S7 no sabe a quién notificar ni con qué mensaje (SystemDuplicate → admin+tenant, SystemExpired → solo tenant, Manual → solo tenant). Además, `verifyPayment()` necesita aceptar `null` como `adminId` para verificación automática (sin admin humano).
>
> **Expirado vs Duplicado**:
> - **Expirado**: Un pago queda `Pending` cuando el cliente lo reporta pero nadie lo matchea. El comando `payments:expire-pending` corre cada hora y cancela pagos Pending más viejos que `match_window_hours + 24h` (default 96h). El buffer de 24h evita expirar pagos dentro de la ventana de matching.
> - **Duplicado**: Llega notificación con referencia "006236568762". El orchestrator busca si ya existe un pago **Verified** con esa referencia. Si SÍ → alguien reusó una referencia ya verificada. Cancela el pago Pending con `SystemDuplicate`.

- [ ] Create migration `add_cancellation_type_to_payments` — nullable string after cancellation_reason
- [ ] Create `CancellationType` enum: Manual, SystemDuplicate, SystemExpired, MethodChanged
- [ ] Extend `Payment` model — add cancellation_type cast, paymentMatch() HasOne relation (latestOfMany)
- [ ] Modify `PaymentService::verifyPayment()` — signature `?int $adminId = null`, set verified_by=null when null
- [ ] Modify `PaymentService::cancelPayment()` — new signature with CancellationType enum + int|string actorId + ?string reason, NO internal event dispatch
- [ ] Update all cancelPayment() callers: Landlord\PaymentController::cancel()
- [ ] Write tests: cancelPayment new signature (3+), verifyPayment nullable (2+), Payment model casts (2+)

**Archivos**: 1 migration, 1 enum, 1 modified model, 1 modified service, 1 modified controller, 7+ tests

**Test inmediato**: Tests de PaymentService pasan con nuevas firmas

---

## S5a: PaymentMatch + Orchestrator

**Función**: Tabla de matching + motor de decisión (el core del sistema)
**Dependencias**: S2 (payment_notifications), S4 (cancelPayment con enum)
**Verificar**: Crear notificación + pago pending con misma referencia/monto → correr orchestrator → pago verificado, match_status=matched

> **Contexto — ¿Qué hace este slice?**
> Es la base del matching. Crea la tabla `payment_matches` que registra cada intento de matching, y el `ReconciliationOrchestrator` que decide qué hacer con cada uno. El orchestrator es una clase pura — recibe un `PaymentMatch` y devuelve un `ReconciliationResult`. Se testea al 100% sin HTTP, sin jobs, sin comandos.
>
> **Flujo del orchestrator (en orden estricto)**:
> 1. **Paso 0 — Duplicado**: ¿Ya existe un pago Verified con esta referencia? Si SÍ → cancelar el pago Pending que reintentó → `SystemDuplicate`.
> 2. **Paso 1 — Matching normal**: Buscar pago Pending con misma referencia + monto dentro de ventana temporal. `SELECT FOR UPDATE` para evitar race conditions.
> 3. **Paso 2 — Un candidato**: Match exacto. Si shadow mode OFF → auto-verificar. Si shadow mode ON → solo sugerir.
> 4. **Paso 3 — Múltiples candidatos**: No se puede determinar cuál es → cola de revisión manual.
> 5. **Paso 4 — Ningún candidato**: Notificación sin identificar → notificar admin.
>
> **Protección contra race conditions**: `SELECT FOR UPDATE` en el Paso 1 bloquea la fila del Payment. Si dos notificaciones llegan al mismo tiempo y matchean el mismo pago, el segundo job espera a que el primero termine. Al llegar, el guard de estado detecta que el pago ya no es Pending y descarta el match.
>
> **¿Por qué reference + amount + ventana temporal (sin teléfono)?**: El teléfono NO es confiable — algunos bancos enmascaran el número (ej. BNC: `0416***9503`). La referencia es única por transacción (6-10 dígitos). El monto confirma que no es una referencia reutilizada con monto distinto. La ventana temporal rechaza pagos viejos.

- [x] Create migration `create_payment_matches_table` — FK to payment_notifications, FK to payments (nullOnDelete), match_status + payment_id indexes
- [x] Create migration `add_partial_unique_index_to_payment_matches` — `CREATE UNIQUE INDEX idx_payment_matches_matched ON payment_matches (payment_id) WHERE match_status = 'matched'`
- [x] Create `PaymentMatch` model — createFromParsed() factory, notification/payment relations
- [x] Create `ReconciliationResult` DTO — verifiedPayment, cancelledPayment, cancelledReason
- [x] Create `ReconciliationOrchestrator` — run() with Step 0 (duplicate) → Step 1 (SELECT FOR UPDATE) → Step 2 (single) → Step 3 (multiple) → Step 4 (none)
- [x] Write tests: forward match exact/none/duplicate (6+), shadow mode ON vs OFF (2+), time window expiry (1), race condition SELECT FOR UPDATE (1)

**Archivos**: 2 migrations, 1 model, 1 DTO, 1 orchestrator, 8+ tests

**Test inmediato**: Crear notificación + pago pending → correr orchestrator → pago verificado, match_status=matched

---

## S5b: Job + Comandos de Mantenimiento

**Función**: Job que orquesta el flujo completo + comandos de limpieza
**Dependencias**: S5a (orchestrator), S1 (parser)
**Verificar**: Correr `simulate:payment-notification` → job procesa → match automático

> **Contexto — ¿Qué hace este slice?**
> S5a construye el motor de matching. S5b lo conecta con el mundo real: el job `IngestPaymentNotification` recibe una notificación, la parsea (S1), crea un `PaymentMatch`, corre el orchestrator (S5a), y despacha eventos después del commit. Los comandos son mantenimiento: expirar pagos viejos y reprocesar notificaciones fallidas.

- [x] Create `IngestPaymentNotification` job (ShouldQueue) — parse → create PaymentMatch → run orchestrator → update parse_status → dispatch events after commit
- [x] Create `ExpirePendingPayments` command — cancel payments older than match_window_hours+24h with SystemExpired
- [x] Schedule `payments:expire-pending` hourly in `routes/console.php`
- [x] Create `ReprocessFailedNotifications` command — re-dispatches IngestPaymentNotification for parse_status=failed
- [x] Write tests: job processes notification → match, job with parse failure, command expires old payments, reprocess command dispatches (~10 tests)

**Archivos**: 1 job, 2 commands, 1 schedule change, 3+ tests

**Test inmediato**: `simulate:payment-notification` → job procesa → pago verificado automáticamente

---

## S6: Reverse Matching

**Función**: Cuando cliente reporta pago, buscar notificación unmatched existente (el 80% de los casos)
**Dependencias**: S5 (payment_matches)
**Verificar**: Crear notificación unmatched → cliente reporta pago → reverse match → pago verificado

> **Contexto — ¿Por qué existe este slice?**
> El PagoMóvil es casi instantáneo (1-3 segundos), pero el cliente tarda 1-5 minutos en reportar. En el ~80% de los casos, la notificación llega ANTES de que el cliente termine de llenar el formulario. Sin reverse match, el sistema falla en automatizar la mayoria de las transacciones.
>
> **¿Por qué sincrono y no Job?**: El matching toma microsegundos (SELECT simple con indices). Un Job agrega latencia innecesaria (Redis/DB queue), complejidad (DTO, IC-4 post-commit), y peor UX (el usuario espera una respuesta HTTP, no un job async). El reverse match se ejecuta DENTRO de `recordPayment()` antes de retornar la respuesta al tenant.

- [x] Add `attemptReverseMatch()` to PaymentService — guard Pending+pago_movil, duplicate check, query unmatched payment_matches, call orchestrator.runReverse()
- [x] Add `$pendingEvents` array + `getPendingEvents()` to PaymentService
- [x] Modify `PaymentService::recordPayment()` — normalizeRef on transaction_id, call attemptReverseMatch()
- [x] Modify Tenant\PaymentController::store() — dispatch getPendingEvents() after commit, show "Auto-verified" if status=Verified
- [x] Write tests: reverse match found (2+), reverse match duplicate (1+), reverse match not found (1+)

**Archivos**: 1 modified service, 1 modified controller, 4+ tests

**Test inmediato**: Crear notificación unmatched → llamar recordPayment() → pago verificado automáticamente

---

## S7: IC-4 Compliance + Eventos + Notificaciones

**Función**: Desacoplar eventos de las transacciones (IC-4) y notificar a usuarios cuando un pago es cancelado
**Dependencias**: S4 (CancellationType), S5 (orchestration), S6 (reverse matching)
**Verificar**: PaymentCancelled dispara notificación al tenant según CancellationType

> **Contexto — ¿Qué aporta este slice?**
> S4-S6 construyen la lógica de matching pero los eventos se disparan dentro de transacciones (violando IC-4)
> y `PaymentCancelled` no tiene listener. S7 arregla ambas cosas:
> - **IC-4**: Los eventos se despachan DESPUÉS del `DB::transaction()`, no adentro
> - **Tenant**: Recibe notificación cuando su pago es cancelado (con el motivo según CancellationType)
> - **Admin**: Recibe alerta solo en caso de `SystemDuplicate` (posible fraude)
> - **Sistema**: SystemAlert reutiliza la tabla `notifications` existente con `category = 'system'`
>
> **Routing de notificaciones por CancellationType**:
> - `SystemDuplicate` → tenant + admins ("referencia ya verificada")
> - `SystemExpired` → solo tenant ("pago expiró sin conciliación")
> - `Manual` → solo tenant ("cancelado por administrador")
>
> **IC-4**: Los eventos se despachan DESPUÉS del `DB::transaction()`. Si la transacción falla, los listeners no se ejecutan. No hay efectos parciales.

- [x] Create `PaymentCancelled` event — Payment, CancellationType, optional reason (creado en S5b)
- [x] Create `NotifyPaymentRejected` listener — route by CancellationType: SystemDuplicate→tenant+admins, SystemExpired→tenant only, Manual→tenant only
- [x] Create `SystemAlert` notification class — via database, toArray with category=system
- [x] Refactor `verifyPayment()` — remove `event(PaymentVerified)` from inside DB::transaction()
- [x] Wire event dispatch in callers AFTER DB::transaction() commit (IC-4): IngestPaymentNotification job, PaymentController (manual verify/cancel), ExpirePendingPayments command
- [x] Register PaymentCancelled → NotifyPaymentRejected in EventServiceProvider (auto-discovery via App\Listeners)
- [x] Write tests: event dispatch routing by type (3+), IC-4 post-commit verification (2+)

**Archivos**: 1 event (ya existe), 1 listener, 1 notification, 3 callers modificados, 1 provider, 5+ tests

**Test inmediato**: `php artisan test --filter=PaymentCancelledTest` — evento dispara notificación correcta según tipo

---

## S8: Dashboard de Alertas (Inertia)

**Función**: Interfaz para que el admin del landlord vea y gestione alertas del sistema
**Dependencias**: S7 (SystemAlert notification, notificaciones en DB)
**Verificar**: Admin ve alertas del sistema en el dashboard, puede marcarlas como leídas

> **Contexto — ¿Qué aporta este slice?**
> S7 crea las alertas en DB. S8 las hace visibles: una página Inertia donde el admin ve alertas
> de infraestructura (parser fallido, pagos sin match) con filtro por severidad, y puede
> marcarlas como leídas. Es puramente frontend + controller — no toca lógica de negocio.

- [ ] Create `AlertController` — GET /landlord/alerts (filter system notifications), POST .../read (mark read)
- [ ] Create Inertia alert page — unread system alerts with severity filter, mark-as-read button
- [ ] Write browser tests: list + mark read (3+)

**Archivos**: 1 controller, 1 Inertia page + component, 3+ browser tests

**Test inmediato**: Abrir /landlord/alerts → ver alertas sin leer → marcar una como leída

---

## S9: Shadow Mode Documentation

**Función**: Documentar procedimiento de activación gradual
**Dependencias**: Todos los anteriores
**Verificar**: Documento existe con pasos claros

> **Contexto — ¿Por qué shadow mode?**
> El sistema tiene un interruptor: `reconciliation.shadow_mode_enabled` (default `true`). Cuando está activo, el matching SUGIERE matches pero no auto-verifica. El admin revisa manualmente antes de activar la verificación automática. Esto permite validar que el parser + matching funcionan correctamente en producción SIN arriesgar pagos reales.
>
> **Flujo de activación**:
> 1. Deploy con shadow mode ON (default) — el sistema solo sugiere, no auto-verifica
> 2. Observar 1-2 semanas — revisar que los matches sugeridos sean correctos
> 3. Si todo está bien → toggle `shadow_mode_enabled = false` → auto-verificación activa
> 4. Rollback plan: si algo falla → toggle de vuelta a `true`

- [ ] Document shadow mode activation procedure — toggle SystemConfig, observe 1-2 weeks, rollback plan
- [ ] Verify end-to-end with shadow mode ON (suggest-only) then OFF (auto-verify) in staging

**Archivos**: 1 documento, verificación manual

---

## PR Mapping

| PR | Slices | Content | ~Lines |
|----|--------|---------|--------|
| 1 | S1+S2 | Config + Parser + Simulator | 600 |
| 2 | S4+S5a | Cancellation Types + Matching Engine | 500 |
| 3 | S5b+S6 | Job + Commands + Reverse matching | 500 |
| 4 | S7+S8 | IC-4 + Eventos + Dashboard | 500 |
| 5 | S9 | Shadow Mode Docs | ~50 |
| future | S3 | Devices + Auth + API Endpoints (deferred) | ~300 |

Each PR targets `feature/payment-reconciliation` branch. Tracker PR is draft/no-merge.
