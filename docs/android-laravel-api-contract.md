# Contrato API Android ↔ Laravel

> **⚠️ Nota importante**: Este documento es una **propuesta de contrato** entre la app Android y el backend Laravel. El backend Laravel es la autoridad final — si hay conflicto entre lo que Android envía y lo que Laravel espera, **Android se ajusta al backend**, no al revés. Este documento existe para que ambas partes conozcan el estado actual de cada lado y puedan negociar los puntos de diferencia antes de codificar.

---

## Estado actual de cada parte

### Android (hoy — implementado y funcionando)

**Envía a**:
- `POST api/notifications` ← **sin** `/device/` en la ruta
- `POST /api/device/heartbeat` ← ✅ consistente

**Request de notificación** (payload real):
```json
{
  "bank_code": "bdv",
  "raw_body": "Recibiste un PagomovilBDV por Bs. 3.000,00 del 04121234567 Ref: 123456 en fecha: 23/06/2026 hora: 20:00",
  "dedup_hash": "a1b2c3d4e5f67890...",
  "monto": "3.000,00",
  "telefono": "04121234567",
  "referencia": "123456",
  "fecha": "23/06/2026",
  "hora": "20:00"
}
```

**Request de heartbeat** (payload real):
```json
{
  "device_id": "1a90f3fd570cf905",
  "battery_level": 85,
  "pending_count": 3
}
```

**No envía**: `X-Device-Token` header (está en modo mock).

### Laravel (diseño actual — no implementado)

**Espera según diseño S3** (`plan-conciliacion-automatica.md`):
- `POST /api/device/notifications`
- `POST /api/device/heartbeat`
- Middleware `device.auth` validando `X-Device-Token`
- Tabla `payment_notifications` con columnas: `raw_title`, `raw_body`, `device_id` (FK), `bank_code`, `dedup_hash` (UNIQUE), `received_at`, `parse_status`
- Tabla `devices` con: `id`, `name`, `token` (64 chars, unique), `last_heartbeat_at`, `is_active`

---

## Diferencias detectadas (requieren decisión)

| Aspecto | Android envía | Laravel espera (diseño) | Decisión necesaria |
|---------|--------------|------------------------|-------------------|
| Ruta notificaciones | `api/notifications` | `api/device/notifications` | Mover Android a `api/device/notifications` para agrupar bajo middleware único |
| `raw_title` | No lo envía | `raw_title` en tabla | Hacer nullable en Laravel o agregarlo en Android |
| Campos parseados | `monto`, `telefono`, `referencia`, `fecha`, `hora` | No existen en la tabla | ¿Agregarlos como `android_*` metadata o ignorarlos? |
| `device_id` en heartbeat | Envía `device_id` = ANDROID_ID | `device_id` es FK numérica en Laravel | Renombrar a `android_device_id` para evitar confusión |
| Autenticación | No envía `X-Device-Token` | Middleware `device.auth` | Se agrega cuando Android apunte al backend real |
| Registro de dispositivo | No existe | `POST /api/device/register` o comando artisan | Definir flujo de provisionamiento |

---

## Propuesta de contrato

> La siguiente es una **recomendación** basada en el estado actual de Android y el diseño de Laravel. Laravel puede modificarla, ignorarla, o aceptarla parcialmente. Android se adaptará a la decisión final.

### Endpoints

| Método | Path | Auth | Propósito |
|--------|------|------|-----------|
| `POST` | `/api/device/notifications` | `X-Device-Token` | Enviar notificación PagoMóvil capturada |
| `POST` | `/api/device/heartbeat` | `X-Device-Token` | Heartbeat periódico del dispositivo |
| `POST` | `/api/device/register` | API key de admin | Registrar dispositivo y obtener token |

### Autenticación

**Header**: `X-Device-Token`

**Flujo**:
1. Admin registra dispositivo en el panel Laravel (o vía comando artisan) → se genera `token` de 64 chars aleatorio
2. Token se provisiona en el teléfono (código QR, copia manual, etc.)
3. Android guarda el token en `DeviceSettings` (Room)
4. Cada request a `/api/device/*` incluye `X-Device-Token` en el header
5. Middleware `device.auth` busca el token en `devices.token`:
   - Existe y `is_active = true` → continúa, inyecta `device_id` (PK) en el request
   - No existe o `is_active = false` → `401 Unauthorized`

**En Android**: El 401 se maneja como fallo de envío. La notificación queda en pending y se reintenta. Si persiste, el usuario ve que el token es inválido.

### POST /api/device/register

**Propósito**: Crear un dispositivo y obtener su token de acceso.

**Request**:
```json
{
  "name": "Teléfono Principal BDV"
}
```

**Response** (201):
```json
{
  "device_id": 1,
  "token": "a1b2c3d4e5f67890...",
  "name": "Teléfono Principal BDV",
  "is_active": true
}
```

**Alternativa**: Si Laravel prefiere no exponer este endpoint, puede reemplazarse por un comando artisan:
```bash
php artisan device:create "Teléfono Principal BDV"
# Output: Token: a1b2c3d4e5f67890...
```

### POST /api/device/notifications

**Request**:
```json
{
  "bank_code": "bdv",
  "raw_body": "Recibiste un PagomovilBDV por Bs. 3.000,00 del 04121234567 Ref: 123456 en fecha: 23/06/2026 hora: 20:00",
  "dedup_hash": "a1b2c3d4e5f67890...",
  "monto": "3.000,00",
  "telefono": "04121234567",
  "referencia": "123456",
  "fecha": "23/06/2026",
  "hora": "20:00"
}
```

**Campos**:

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `bank_code` | string | sí | Código del banco en minúsculas: `bdv`, `bnc`, `banesco`, `mercantil`, `provincial` |
| `raw_body` | string | sí | Texto completo de la notificación (body puro, sin título) |
| `dedup_hash` | string | sí | SHA256 hex de `bank_code.lowercase() + raw_body` |
| `monto` | string | sí | Monto extraído por Android (para debug) |
| `telefono` | string | sí | Teléfono extraído por Android (para debug) |
| `referencia` | string | sí | Referencia extraída por Android (para debug) |
| `fecha` | string | sí | Fecha en formato `dd/MM/yyyy` extraída por Android (para debug) |
| `hora` | string | sí | Hora en formato `HH:mm` extraída por Android (para debug) |

> **Nota sobre `monto`, `telefono`, `referencia`, `fecha`, `hora`**: Laravel puede ignorarlos, guardarlos como metadata (`android_*`), o usarlos como hint para el parseo. El parseo **oficial** siempre se hace sobre `raw_body` con las regex de Laravel. Si se guardan, permiten detectar discrepancias entre el parseo del teléfono y el del backend (útil para debug).

**Response** (siempre 200):
```json
{"status": "created"}
```
o
```json
{"status": "duplicate_ignored"}
```

Nunca 409 ni 500 por duplicado. `dedup_hash` tiene `UNIQUE` en la tabla, usar `firstOrCreate()` o `ON CONFLICT DO NOTHING`.

**Validaciones sugeridas**:
- `bank_code`: required, string, lowercase, uno de los bancos soportados
- `raw_body`: required, string, max 5000 chars
- `dedup_hash`: required, string, SHA256 hex (64 chars), validar formato con regex `/^[a-f0-9]{64}$/`
- `monto`, `telefono`, `referencia`, `fecha`, `hora`: required, string (Laravel decide si validar formato o no)

### POST /api/device/heartbeat

**Request**:
```json
{
  "android_device_id": "1a90f3fd570cf905",
  "battery_level": 85,
  "pending_count": 3
}
```

> **Nota sobre el nombre `android_device_id`**: Android actualmente envía este campo como `device_id`. Se propone renombrarlo a `android_device_id` para evitar confusión con la FK `devices.id` de Laravel. Si Laravel prefiere el nombre original, Android lo mantiene.

**Campos**:

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `android_device_id` | string | sí | `Settings.Secure.ANDROID_ID` del teléfono |
| `battery_level` | int | sí | Nivel de batería 0-100 |
| `pending_count` | int | sí | Cantidad de notificaciones pendientes de envío en Room |

**Response** (200):
```json
{"status": "ok"}
```

**Comportamiento en Laravel**:
- Actualizar `devices.last_heartbeat_at` para el dispositivo autenticado vía `X-Device-Token`
- Actualizar `devices.android_device_id` si es diferente al almacenado (útil para debug)
- El `pending_count` es informativo — Android maneja sus propios reintentos independientemente

### Manejo de errores

| Código | Significado | Comportamiento en Android |
|--------|-------------|--------------------------|
| 200 | Éxito (incluye duplicado) | `isSuccessful = true` → marca como `delivered` |
| 201 | Creado | `isSuccessful = true` → marca como `delivered` |
| 400 | Bad request | `isSuccessful = false` → marca como `failed`, no reintenta |
| 401 | Token inválido/inactivo | `isSuccessful = false` → marca como `failed`, reintenta (el worker podría necesitar lógica especial si el 401 persiste) |
| 422 | Unprocessable entity | `isSuccessful = false` → marca como `failed`, no reintenta |
| 429 | Rate limit excedido | `isSuccessful = false` → marca como `failed`, reintenta (el worker ya tiene backoff) |
| 5xx | Error interno | `isSuccessful = false` → marca como `failed`, reintenta |
| Timeout | Sin conexión | Excepción capturada → marca como `failed`, reintenta |

### Rate limiting

Sugerido: **60 requests/minuto por `X-Device-Token`**.

Android no necesita lógica especial para rate limiting — si recibe 429, marca como fallido y el worker reintenta después.

### Heartbeat offline detection (sugerencia para Laravel)

Comando programado que se ejecuta cada 5 minutos:
```php
// Buscar dispositivos activos cuyo last_heartbeat_at sea anterior a interval * 3
$offline = Device::where('is_active', true)
    ->where('last_heartbeat_at', '<', now()->subMinutes(15 * 3))
    ->get();
```

Cuando un dispositivo no hace heartbeat en más de `interval * 3` minutos → crear `SystemAlert` con `type = heartbeat_offline`, `severity = critical`.

### Tablas sugeridas para Laravel

#### `devices`

```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->string('name');                          // ej. 'Teléfono Principal BDV'
    $table->string('token', 64)->unique();           // token de autenticación
    $table->string('android_device_id')->nullable(); // ANDROID_ID (para debug)
    $table->timestamp('last_heartbeat_at')->nullable();
    $table->string('last_heartbeat_ip')->nullable(); // dirección IP del último heartbeat
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

#### `payment_notifications`

```php
Schema::create('payment_notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('device_id')->constrained()->cascadeOnDelete();
    $table->string('bank_code');                               // lowercase
    $table->text('raw_body');                                  // cuerpo completo de la notificación
    $table->string('dedup_hash', 64)->unique();                // SHA256 hex
    $table->timestamp('received_at')->nullable();              // timestamp del teléfono

    // Metadata de debug enviada por Android (NO es fuente de verdad para parseo)
    $table->string('android_monto')->nullable();
    $table->string('android_telefono')->nullable();
    $table->string('android_referencia')->nullable();
    $table->string('android_fecha')->nullable();
    $table->string('android_hora')->nullable();

    $table->string('parse_status')->default('pending');        // pending | parsed | failed
    $table->timestamps();

    $table->index('bank_code');
    $table->index('parse_status');
});
```

### Resumen de cambios que Android necesita para adaptarse

Cuando Laravel defina el contrato final, Android ajustará:

| Cambio | Depende de Laravel |
|--------|-------------------|
| Ruta `api/notifications` → `api/device/notifications` | ✅ Confirmado |
| `device_id` en heartbeat → nombre que Laravel decida | ❓ Pendiente |
| Agregar `X-Device-Token` header | Requiere flujo de registro |
| Guardar token en `DeviceSettings` (Room) | Requiere flujo de registro |
| Agregar `raw_title` al payload si Laravel lo requiere | ❓ Pendiente |

---

## Historial de revisiones

| Fecha | Versión | Cambio |
|-------|---------|--------|
| 2026-06-24 | 1.0 | Versión inicial. Basada en Android F5 (heartbeat worker + UI reactiva) y diseño Laravel S3 (postergado). |
