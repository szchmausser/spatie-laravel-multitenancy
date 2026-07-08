# Design: Multifield Payment Validation

**Change**: `payment-validation-multifield`
**Status**: Design
**Date**: 2026-07-08

---

## 1. Arquitectura general

El sistema de reconciliación actual funciona con 2 flujos:

```
FLUJO DIRECTO (notificación bancaria llega primero):
  IngestPaymentNotification job
    → PaymentNotificationParser::parse()
    → PaymentMatch::createFromParsed()  [status=unmatched]
    → ReconciliationOrchestrator::run()
        → Busca Payment candidato (ref+monto)
        → shouldShadow() / verifyPayment()

FLUJO INVERSO (usuario reporta pago primero, ~80% casos):
  Tenant/PaymentController@store
    → PaymentService::recordPayment()
    → PaymentService::attemptReverseMatch()
        → Busca PaymentMatch candidato (ref+monto)
        → ReconciliationOrchestrator::runReverse()
            → shouldShadow() / verifyPayment()
```

**Inyección de PaymentMatchGuard** (el cambio de este design):

```
FLUJO DIRECTO:
  ...run()
    → 1 candidato encontrado
    → 🔵 PaymentMatchGuard::validate($match, $payment)  ← NUEVO
        ├─ Ok → shouldShadow() / verifyPayment()  (sin cambios)
        └─ Mismatch → status=pending + SystemAlert + return vacío

FLUJO INVERSO (doble guardia):

  attemptReverseMatch()
    → candidato encontrado
    → 🔵 PaymentMatchGuard::validate($match, $payment)  ← NUEVO (early exit)
        ├─ Ok → runReverse()
        └─ Mismatch → return (no-op)

  runReverse()
    → guards iniciales (status, match_status)
    → payment_id linkeado
    → 🔵 PaymentMatchGuard::validate($match, $payment)  ← NUEVO (defense-in-depth)
        ├─ Ok → shouldShadow() / verifyPayment()
        └─ Mismatch → status=pending + SystemAlert + return vacío
```

### Principio de diseño

La guardia se aplica **en ambos flujos** y en **2 capas del flujo inverso**:

| Capa | Ubicación | Propósito |
|------|-----------|-----------|
| 1ª | `attemptReverseMatch()` | Early exit: evitar llamar a `runReverse()` si hay mismatch. No modifica estado, no envía alerta. |
| 2ª | `runReverse()` | Defense-in-depth: protege aunque alguien llame a `runReverse()` directamente. Actualiza estado + alerta. |
| Única | `run()` | Forward flow: único punto de guardia. Actualiza estado + alerta. |

---

## 2. Migración

### Archivo

`database/migrations/landlord/2026_07_08_000001_add_phone_and_bank_to_payment_matches.php`

### Columnas

| Columna | Tipo | Default | Descripción |
|---------|------|---------|-------------|
| `parsed_sender_phone_number` | `varchar(30)` | `nullable` | Teléfono crudo del regex (ej: `0426***6568`) |
| `parsed_sender_phone_first4` | `varchar(4)` | `nullable` | Primeros 4 dígitos (ej: `0426`) |
| `parsed_bank_code` | `varchar(10)` | `nullable` | Código del banco parseado (ej: `bnc`, `bdv`) |

### Schema Blueprint

```php
Schema::table('payment_matches', function (Blueprint $table) {
    $table->string('parsed_sender_phone_number', 30)->nullable()->after('parsed_amount_cents');
    $table->string('parsed_sender_phone_first4', 4)->nullable()->after('parsed_sender_phone_number');
    $table->string('parsed_bank_code', 10)->nullable()->after('parsed_sender_phone_first4');
});

// Down
Schema::table('payment_matches', function (Blueprint $table) {
    $table->dropColumn(['parsed_sender_phone_number', 'parsed_sender_phone_first4', 'parsed_bank_code']);
});
```

### Consideraciones

- Todas las columnas son `nullable` para backward compatibility con registros existentes.
- `parsed_sender_phone_number` guarda el valor crudo del regex (incluyendo máscaras como `***`). Esto permite auditoría y comparaciones futuras.
- `parsed_sender_phone_first4` se calcula en el parser, no en la BD. No hay triggers ni computed columns.
- `parsed_bank_code` se toma directamente de `$notification->bank_code`.

---

## 3. ParsedPayment DTO

**Archivo**: `app/Services/Payment/ParsedPayment.php`

### Constructor actual

```php
public function __construct(
    public readonly int $amountCents,
    public readonly ?string $reference,
    public readonly ?string $senderPhoneLast4,
    public readonly ?Carbon $parsedAt,
    public readonly ?array $rawGroups = null,
) {}
```

### Constructor modificado

```php
public function __construct(
    public readonly int $amountCents,
    public readonly ?string $reference,
    public readonly ?string $senderPhoneLast4,
    public readonly ?Carbon $parsedAt,
    public readonly ?array $rawGroups = null,
    // NUEVOS:
    public readonly ?string $senderPhoneNumber = null,
    public readonly ?string $senderPhoneFirst4 = null,
) {}
```

### Regla de negocio

- `senderPhoneLast4` ya existe y se mantiene.
- `senderPhoneNumber` = valor crudo de `$matches['phone']` del regex. Incluye máscaras como `***`.
- `senderPhoneFirst4` = primeros 4 dígitos después de limpiar no-dígitos. Para BNC enmascarado: `0416***9503` → `0416`. Para BDV completo: `0424-3153557` → `0424`.

### Impacto en callers existentes

El constructor actual tiene 1 caller en `PaymentNotificationParser::parse()`:

```php
return new ParsedPayment(
    amountCents: ...,
    reference: ...,
    senderPhoneLast4: $this->extractLast4($matches['phone'] ?? null),
    parsedAt: ...,
    rawGroups: $namedGroups,
);
```

Con los nuevos parámetros opcionales al final, este caller **no necesita cambios** — los valores default `null` aplican hasta que se agreguen explícitamente.

---

## 4. PaymentNotificationParser

**Archivo**: `app/Services/Payment/PaymentNotificationParser.php`

### Cambio en `parse()`

Después de obtener `$matches['phone']` y antes de construir `ParsedPayment`, extraer:

```php
// Después de $this->extractLast4(...), antes de new ParsedPayment(...)

$senderPhoneNumber = $matches['phone'] ?? null;

$senderPhoneFirst4 = null;
if ($senderPhoneNumber !== null) {
    $digits = preg_replace('/\D/', '', $senderPhoneNumber);
    $senderPhoneFirst4 = strlen($digits) >= 4 ? substr($digits, 0, 4) : null;
}
```

### Actualizar constructor de ParsedPayment

```php
return new ParsedPayment(
    amountCents: $this->normalizeAmount($matches['amount']),
    reference: normalizeRef($matches['reference']),
    senderPhoneLast4: $this->extractLast4($matches['phone'] ?? null),
    parsedAt: $this->parseDate(...),
    rawGroups: $namedGroups,
    senderPhoneNumber: $senderPhoneNumber,
    senderPhoneFirst4: $senderPhoneFirst4,
);
```

### Método helper reusable (opcional)

Se puede extraer un método privado `extractFirst4(?string $phone): ?string` siguiendo el mismo patrón que `extractLast4()`. Esto mantiene coherencia y permite unit testing directo.

```php
private function extractFirst4(?string $phone): ?string
{
    if (! $phone) {
        return null;
    }

    $digits = preg_replace('/[^0-9]/', '', $phone);

    return strlen($digits) >= 4 ? substr($digits, 0, 4) : null;
}
```

### Casos de prueba

| Input | senderPhoneNumber | senderPhoneFirst4 | senderPhoneLast4 |
|-------|-------------------|-------------------|------------------|
| `0416***9503` | `0416***9503` | `0416` | `9503` |
| `0424-3153557` | `0424-3153557` | `0424` | `3557` |
| `null` | `null` | `null` | `null` |

---

## 5. PaymentMatch::createFromParsed

**Archivo**: `app/Models/PaymentMatch.php`

### Fillable

Agregar al `$fillable` del modelo:

```php
protected $fillable = [
    'payment_notification_id',
    'payment_id',
    'parsed_reference',
    'parsed_amount_cents',
    'parsed_sender_phone_last4',
    'parsed_sender_phone_number',    // ← NUEVO
    'parsed_sender_phone_first4',    // ← NUEVO
    'parsed_bank_code',              // ← NUEVO
    'match_status',
    'matched_at',
];
```

### Creación de match

En las 2 ramas donde se crea un nuevo `PaymentMatch` (actualmente líneas 83-89 y 94-100), pasar los nuevos campos:

```php
// En Step 3 (duplicate) y Step 4 (new), donde se hace static::create([...]):
'parsed_sender_phone_number' => $parsed->senderPhoneNumber,
'parsed_sender_phone_first4' => $parsed->senderPhoneFirst4,
'parsed_bank_code' => $notification->bank_code,
```

### Nota sobre Steps 1 y 2

- **Step 1 (idempotencia)**: Retorna el match existente sin modificarlo. No aplican nuevos campos.
- **Step 2 (reuse de unmatched)**: Usa `update()` solo para `payment_notification_id`. No actualiza datos parseados porque el match ya existe y sus campos están poblados; si se desea actualizar, se podría hacer un `update()` adicional con los nuevos campos, pero no es necesario para el funcionamiento — el dato original del primer parseo es suficiente.

### Resumen de ramas afectadas

| Step | Acción | ¿Nuevos campos? |
|------|--------|----------------|
| 1 | return existing | No (no toca) |
| 2 | update notification_id | No (solo link) |
| 3 | create duplicate | Sí |
| 4 | create unmatched | Sí |

---

## 6. PaymentMatchGuard

**Archivo**: `app/Services/Payment/PaymentMatchGuard.php` (NUEVO)

### Diseño

Clase con un único método estático que encapsula toda la lógica de validación multifield. Esto la hace:
- **Testeable** de forma aislada (sin mockear orchestrator ni service).
- **Reutilizable** desde `run()`, `runReverse()`, y `attemptReverseMatch()`.
- **Independiente** de efectos secundarios (no toca BD, no envía notificaciones).

### Firma

```php
class PaymentMatchGuard
{
    /**
     * Validate bank code and phone between a PaymentMatch and a Payment.
     *
     * @param PaymentMatch $match  The match from notification parsing.
     * @param Payment $payment     The payment reported by the user.
     * @return array|null          null = ok, array with mismatch details.
     */
    public static function validate(
        PaymentMatch $match,
        Payment $payment,
    ): ?array
    {
        // ...
    }
}
```

### Algoritmo detallado

```
1. Cargar $payment->pagoMovilDetail
2. Si no existe → return null  (skip phone, no data to compare)

3. Determinar bank code del match:
   $bankCode = BankCode::tryFrom($match->parsed_bank_code)
   Si $bankCode es null → return null  (no se puede validar banco)

4. Validar banco:
   $notificationBankName = $bankCode->name()
   $paymentBankName = $payment->pagoMovilDetail->sender_bank
   Si $notificationBankName !== $paymentBankName:
         return ['field' => 'sender_bank', 'payment_value' => $paymentBankName, 'notification_value' => $notificationBankName, 'match_id' => $match->id]

5. Validar teléfono:
   5a. Si $match->parsed_sender_phone_first4 es null → skip phone validation (return null)
   
   5b. Si $bankCode->appliesCanonicalPhone() (BNC):
         Extraer first4 + last4 de $payment->pagoMovilDetail->sender_phone:
           $paymentDigits = preg_replace('/\D/', '', $payment->pagoMovilDetail->sender_phone)
           $paymentFirst4 = substr($paymentDigits, 0, 4)
           $paymentLast4 = substr($paymentDigits, -4)
         
         Si $paymentFirst4 !== $match->parsed_sender_phone_first4 
            O $paymentLast4 !== $match->parsed_sender_phone_last4:
              return datos del mismatch

   5c. Si NO appliesCanonicalPhone() (BDV u otros):
         Extraer dígitos completos de ambos lados:
           $paymentDigits = preg_replace('/\D/', '', $payment->pagoMovilDetail->sender_phone)
           $notificationDigits = preg_replace('/\D/', '', $match->parsed_sender_phone_number ?? '')
         
         Si $paymentDigits !== $notificationDigits:
              return datos del mismatch

6. Si todo ok → return null
```

### Return value

Cuando hay mismatch, el array contiene:

```php
[
    'field' => 'sender_bank',            // 'sender_bank' | 'sender_phone'
    'payment_value' => 'Banco Nacional de Crédito',  // valor del lado del payment
    'notification_value' => 'Banco de Venezuela',     // valor del lado de la notificación
    'match_id' => 42,                                 // ID del PaymentMatch
]
```

Cuando no hay mismatch o no se puede validar, retorna `null`.

### Edge cases

| Escenario | Resultado |
|-----------|-----------|
| `pagoMovilDetail` es null (bank_transfer) | `null` — no se puede validar teléfono; banco se valida si hay `sender_bank` (n/a en transferencias) |
| `parsed_sender_phone_first4` es null | `null` — no hay datos de teléfono parseados para comparar |
| `parsed_bank_code` no se resuelve con `BankCode::tryFrom()` | `null` — no se conoce el banco, no se puede validar |
| `payment->pagoMovilDetail->sender_phone` contiene caracteres no-dígitos | Se normaliza con `preg_replace('/\D/', '', ...)` |

---

## 7. ReconciliationOrchestrator

**Archivo**: `app/Services/Payment/ReconciliationOrchestrator.php`

### 7.1 Modificar `run()`

La guardia se inserta **después de tener el candidato único** y **antes de `shouldShadow()`**, en la rama `$candidates->count() === 1`.

```php
if ($candidates->count() === 1) {
    $payment = $candidates->first();

    if ($payment->status !== PaymentStatus::Pending) {
        $match->update(['match_status' => 'unmatched']);
        return $result;
    }

    // 🔵 NUEVO: Multifield guard
    $mismatch = PaymentMatchGuard::validate($match, $payment);
    if ($mismatch !== null) {
        $match->update([
            'payment_id' => $payment->id,
            'match_status' => 'pending',
        ]);
        $this->sendMismatchAlert($mismatch);
        return $result;  // ReconciliationResult vacío — no se verifica
    }

    // ← Resto del flujo sin cambios
    if ($this->shouldShadow($match)) {
        // ...
    }
}
```

### 7.2 Modificar `runReverse()`

La guardia se inserta **después de linkear `payment_id`** y **antes de `shouldShadow()`**:

```php
public function runReverse(PaymentMatch $match, Payment $payment): ReconciliationResult
{
    // Guards existentes (status, match_status)... sin cambios

    $match->update(['payment_id' => $payment->id]);
    $result = new ReconciliationResult;

    // 🔵 NUEVO: Multifield guard
    $mismatch = PaymentMatchGuard::validate($match, $payment);
    if ($mismatch !== null) {
        $match->update(['match_status' => 'pending']);
        $this->sendMismatchAlert($mismatch);
        return $result;  // ReconciliationResult vacío
    }

    // ← Resto del flujo sin cambios (shouldShadow / verifyPayment)
    if ($this->shouldShadow($match)) {
        // ...
    }
}
```

### 7.3 Método helper: sendMismatchAlert

```php
private function sendMismatchAlert(array $mismatch): void
{
    try {
        $fieldLabels = [
            'sender_bank' => 'Banco emisor',
            'sender_phone' => 'Teléfono emisor',
        ];

        $fieldName = $fieldLabels[$mismatch['field']] ?? $mismatch['field'];
        $paymentValue = $mismatch['payment_value'] ?? 'N/A';
        $notificationValue = $mismatch['notification_value'] ?? 'N/A';

        $admins = Landlord::all();

        if ($admins->isEmpty()) {
            return;
        }

        $message = sprintf(
            'Mismatch en validación multifield: %s. Pago: "%s". Notificación: "%s". Match #%d.',
            $fieldName,
            $paymentValue,
            $notificationValue,
            $mismatch['match_id'],
        );

        Notification::send($admins, new SystemAlert(
            type: 'payment_multifield_mismatch',
            message: $message,
            severity: 'warning',
        ));
    } catch (\Throwable $e) {
        Log::warning('Failed to send multifield mismatch alert', [
            'match_id' => $mismatch['match_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
```

### ¿Por qué el mismatch en `run()` usa `payment_id` y en `runReverse()` no hace falta?

En `run()`: el `payment_id` aún no se ha asignado (estamos en la rama de candidato único pero no se ha linkeado). El mismatch lo asigna para que el admin sepa qué payment candidato causó el mismatch.

En `runReverse()`: el `payment_id` ya se asignó 2 líneas antes (`$match->update(['payment_id' => $payment->id])`). No hace falta repetirlo.

### Importaciones necesarias

```php
use App\Models\Landlord;
use App\Notifications\SystemAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
```

---

## 8. PaymentService::attemptReverseMatch

**Archivo**: `app/Services/Payment/PaymentService.php`

### Cambio

Después de encontrar el match candidato y antes de llamar a `runReverse()`, insertar la guardia:

```php
// Línea ~110: después del null check de $match
if ($match === null) {
    return;
}

// 🔵 NUEVO: Multifield guard — early exit
$mismatch = PaymentMatchGuard::validate($match, $payment);
if ($mismatch !== null) {
    // No modifica estado, no envía alerta.
    // El match sigue unmatched; el payment sigue pending.
    // runReverse() será llamado si eventualmente llega un payment que sí coincida.
    return;
}

// ← Resto del flujo sin cambios
/** @var ReconciliationOrchestrator $orchestrator */
$orchestrator = app(ReconciliationOrchestrator::class);
$result = $orchestrator->runReverse($match, $payment);
// ...
```

### Principio

Esta guardia es un **early exit** para evitar trabajo innecesario (llamar a `runReverse()` cuando sabemos que va a fallar). No modifica estado ni envía alertas porque:

1. El forward flow (`run()`) se encargará de validar cuando la notificación se procese (o ya se procesó — el match existe).
2. No queremos duplicar alertas: si `runReverse()` ya está instrumentado para mandar alerta, y no llegamos a llamarlo, evitamos falsos positivos.
3. El payment se queda en `pending` para revisión manual del admin.

### Diagrama de decisión

```
attemptReverseMatch:
  ¿Candidato encontrado?
    ├─ No → return
    └─ Sí → PaymentMatchGuard::validate()
              ├─ Ok → runReverse() (con su propia guardia + alerta si falla)
              └─ Mismatch → return (no-op)

  (*) El mismatch en runReverse() solo ocurre si alguien llama a
      runReverse() directamente sin pasar por attemptReverseMatch,
      o si los datos cambiaron entre intentos.
```

---

## 9. Frontend

**Archivo**: `resources/js/pages/billing/orders/show.tsx`

### Estados adicionales

```tsx
const [operadora, setOperadora] = useState('');
const [phoneDigits, setPhoneDigits] = useState('');
```

### Reemplazar input de teléfono

Las líneas 389-403 (el div con `sender_phone`) se reemplazan por:

```tsx
<div className="space-y-2">
    <Label htmlFor="sender_phone">Teléfono emisor</Label>
    <div className="flex gap-2">
        <select
            id="sender_phone_operadora"
            value={operadora}
            onChange={(e) => setOperadora(e.target.value)}
            required
            className="flex h-10 w-[110px] rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
            <option value="">Op.</option>
            <option value="0412">0412</option>
            <option value="0414">0414</option>
            <option value="0416">0416</option>
            <option value="0424">0424</option>
            <option value="0426">0426</option>
        </select>
        <Input
            id="sender_phone_digits"
            type="text"
            pattern="[0-9]{7}"
            maxLength={7}
            placeholder="1234567"
            value={phoneDigits}
            onChange={(e) => setPhoneDigits(e.target.value.replace(/\D/g, '').slice(0, 7))}
            required
            className="flex-1"
        />
    </div>
    <p className="text-xs text-muted-foreground">
        Selecciona la operadora (0412, 0414, 0416, 0424, 0426) e ingresa los 7 dígitos de tu número
    </p>
</div>
```

### Actualizar handleReportPayment

En la validación temprana (`if (selectedMethod === 'pago_movil' && (!senderBank || !senderPhone || ...)`) reemplazar `!senderPhone` por `!operadora || !phoneDigits`.

En el submit (el objeto enviado a `router.post()`), reemplazar:

```tsx
// Antes:
sender_phone: selectedMethod === 'pago_movil' ? senderPhone || undefined : undefined,

// Después:
sender_phone: selectedMethod === 'pago_movil' ? (operadora && phoneDigits ? operadora + phoneDigits : undefined) : undefined,
```

### Actualizar estados del botón submit

```tsx
(selectedMethod === 'pago_movil' && (!senderBank || !operadora || !phoneDigits || !senderId || !paymentDate))
```

### Consideraciones

- `operadora` es un `<select>` con opciones fijas. Las operadoras venezolanas son: 0412, 0414, 0416, 0424, 0426. No se agregan 0410 ni 0422 porque no están en uso para Pago Móvil en los bancos soportados.
- `phoneDigits` se limpia con `.replace(/\D/g, '')` en cada onChange para garantizar solo dígitos.
- El concatenado `operadora + phoneDigits` siempre da 11 caracteres si ambos campos están completos (4 + 7).
- El `pattern="[0-9]{7}"` provee validación HTML5 en el browser.
- Si el usuario intenta submit con menos de 7 dígitos, el browser bloquea por `pattern` mismatch.

---

## 10. Backend validation

**Archivo**: `app/Http/Controllers/Tenant/PaymentController.php`

### Cambio en regla `sender_phone`

```php
// Actual (líneas 122-126):
'sender_phone' => [
    ...($request->input('payment_method') === 'pago_movil'
        ? ['required', 'string', 'max:20']
        : ['nullable', 'string', 'max:20']),
],

// Nuevo:
'sender_phone' => [
    ...($request->input('payment_method') === 'pago_movil'
        ? ['required', 'string', 'size:11', 'regex:/^[0-9]+$/']
        : ['nullable', 'string', 'size:11', 'regex:/^[0-9]+$/']),
],
```

### Efecto

| Condición | Antes | Después |
|-----------|-------|---------|
| `04243153557` (11 dígitos) | ✅ pasa | ✅ pasa |
| `0424-3153557` (con guión) | ✅ pasa | ❌ `regex` falla |
| `0424153557` (10 dígitos) | ✅ pasa | ❌ `size:11` falla |
| `0424315355712` (12 dígitos) | ✅ pasa | ❌ `size:11` falla |

---

## 11. SystemAlert en mismatch

### Diseño de la alerta

Cuando `PaymentMatchGuard` detecta un mismatch en el orchestrator, se envía un `SystemAlert` a todos los `Landlord` admins.

### Parámetros del SystemAlert

| Parámetro | Valor |
|-----------|-------|
| `type` | `'payment_multifield_mismatch'` |
| `severity` | `'warning'` |
| `message` | `"Mismatch en validación multifield: {campo}. Pago: \"{valor_pago}\". Notificación: \"{valor_notificacion}\". Match #{id}."` |

### Quién lo envía

- `ReconciliationOrchestrator::run()` — mismatch detectado en forward flow.
- `ReconciliationOrchestrator::runReverse()` — mismatch detectado en reverse flow.
- NO se envía desde `PaymentService::attemptReverseMatch()` (early exit silencioso).

### Lógica de envío

Ver método `sendMismatchAlert()` en la sección 7.3.

### Datos en `toArray`

Se usa el campo `message` del `SystemAlert` existente. Como actualmente `toArray()` solo retorna `category`, `type`, `message`, `severity`, el detalle completo del mismatch va en el string `message`. Si en el futuro se necesita acceso programático a los campos individuales, se puede agregar `data` al `SystemAlert`:

```php
// Futura mejora (no incluida en este design):
public function __construct(
    public string $type,
    public string $message,
    public string $severity = 'warning',
    public ?array $data = null,        // ← nuevo
) {}

public function toArray(object $notifiable): array
{
    return [
        'category' => 'system',
        'type' => $this->type,
        'message' => $this->message,
        'severity' => $this->severity,
        'data' => $this->data,         // ← nuevo
    ];
}
```

Pero para mantener el scope reducido, el design actual usa solo el `message` formateado.

---

## 12. Modelo de datos final

### `payment_matches` (landlord)

| Columna | Tipo | Restricciones | Descripción |
|---------|------|---------------|-------------|
| `id` | `bigint` | PK, autoincrement | |
| `payment_notification_id` | `bigint` | FK → `payment_notifications.id`, NOT NULL | |
| `payment_id` | `bigint` | FK → `payments.id`, nullable | Se asigna cuando se encuentra candidato |
| `parsed_reference` | `varchar(20)` | nullable | Referencia parseada de la notificación |
| `parsed_amount_cents` | `integer` | NOT NULL | Monto en céntimos |
| `parsed_sender_phone_number` | `varchar(30)` | **nullable** ✦ | Teléfono crudo del regex |
| `parsed_sender_phone_first4` | `varchar(4)` | **nullable** ✦ | Primeros 4 dígitos del teléfono |
| `parsed_sender_phone_last4` | `varchar(4)` | nullable | Últimos 4 dígitos (existente) |
| `parsed_bank_code` | `varchar(10)` | **nullable** ✦ | Código del banco (`bnc`, `bdv`) |
| `match_status` | `varchar(30)` | default `'unmatched'` | `unmatched`, `matched`, `pending`, `duplicate_attempt` |
| `matched_at` | `timestamp` | nullable | |
| `created_at` | `timestamp` | NOT NULL | |
| `updated_at` | `timestamp` | NOT NULL | |

✦ = nueva columna en esta migración

### PaymentMatch PHP Model (`$fillable` actualizado)

```php
protected $fillable = [
    'payment_notification_id',
    'payment_id',
    'parsed_reference',
    'parsed_amount_cents',
    'parsed_sender_phone_last4',
    'parsed_sender_phone_number',     // new
    'parsed_sender_phone_first4',     // new
    'parsed_bank_code',               // new
    'match_status',
    'matched_at',
];
```

### TypeScript (frontend)

En `resources/js/types/payment.ts` (crear si no existe) o directamente en los tipos inline de `billing/orders/show.tsx` y `admin/orders/show.tsx`:

```ts
type PaymentMatch = {
    id: number;
    match_status: string;
    matched_at: string | null;
    parsed_reference: string | null;
    parsed_amount_cents: number;
    parsed_sender_phone_last4: string | null;
    parsed_sender_phone_number: string | null;   // new
    parsed_sender_phone_first4: string | null;    // new
    parsed_bank_code: string | null;              // new
};
```

#### Archivos con tipos PaymentMatch inline

| Archivo | Acción |
|---------|--------|
| `resources/js/components/payment-details-card.tsx` | Agregar 3 nuevos campos al type |
| `resources/js/pages/admin/orders/show.tsx` | Agregar 3 nuevos campos al type |
| `resources/js/pages/billing/orders/show.tsx` | No tiene type PaymentMatch (no aplica) |

---

## 13. Flujo completo actualizado

### 13.1 Forward flow (notificación bancaria llega primero)

```
1. Llega notificación bancaria
2. IngestPaymentNotification job:

   a. PaymentNotificationParser::parse()
      └─ matches['phone'] = "0426***6568"
      └─ senderPhoneNumber = "0426***6568"
      └─ senderPhoneFirst4 = "0426"
      └─ senderPhoneLast4 = "6568"

   b. PaymentMatch::createFromParsed()
      └─ parsed_sender_phone_number = "0426***6568"
      └─ parsed_sender_phone_first4 = "0426"
      └─ parsed_bank_code = "bnc"
      └─ match_status = "unmatched"

   c. ReconciliationOrchestrator::run()
      └─ Candidates: Payment pendiente con misma ref + monto (per-payment comparison, not per-order — 72h window)
         │
         ├─ 0 candidatos → match_status = "unmatched"
         │
         ├─ 1 candidato → PaymentMatchGuard::validate($match, $payment)
         │   │
         │   ├─ null (ok)
         │   │   ├─ shouldShadow? → sí: status = "pending" (shadow)
         │   │   └─ shouldShadow? → no: verifyPayment(), status = "matched"
         │   │
         │   └─ array (mismatch)
         │       ├─ status = "pending", payment_id linkeado
         │       ├─ SystemAlert (tipo: payment_multifield_mismatch)
         │       └─ NO verifyPayment()
         │
         └─ >1 candidatos → status = "pending" (revisión manual)
```

### 13.2 Reverse flow (usuario reporta pago primero, ~80%)

```
3. Usuario reporta pago:

   a. Frontend (billing/orders/show.tsx)
      └─ Operadora: [select] "0424"
      └─ 7 dígitos: [input] "3153557"
      └─ Submit: senderPhone = "0424" + "3153557" = "04243153557"

   b. Tenant/PaymentController@store
      └─ sender_phone validation: required|string|size:11|regex:/^[0-9]+$/
         └─ "04243153557" → ✅ pasa

   c. PaymentService::recordPayment()
      └─ Crea Payment (status = Pending)
      └─ Crea PagoMovilDetail (sender_phone = "04243153557",
                               sender_bank = "Banco Nacional de Crédito")
      └─ PaymentService::attemptReverseMatch()
         └─ Busca PaymentMatch: ref + monto
            │
            ├─ No match → return (no-op)
            │
            └─ Match encontrado → PaymentMatchGuard::validate($match, $payment)
               │
               ├─ Ok → ReconciliationOrchestrator::runReverse($match, $payment)
               │   └─ PaymentMatchGuard::validate() (defense-in-depth)
               │      ├─ Ok → verifyPayment(), status = "matched"
               │      └─ Mismatch → status = "pending", SystemAlert
               │
               └─ Mismatch → return (no-op, no alerta)
                  └─ Payment sigue Pending (admin revisa manualmente)
```

### 13.3 Mapa de transiciones de `match_status`

```
                    ┌──────────┐
                    │unmatched │
                    └────┬─────┘
                         │
              ┌──────────┼──────────┐
              │          │          │
         [no payment] [mismatch] [all ok]
              │          │          │
              ▼          ▼          ▼
         ┌────────┐ ┌────────┐ ┌────────┐
         │unmatch.│ │pending │ │matched │
         └────────┘ └────────┘ └────────┘
              │          │
         (sigue     [admin revisa,
          igual)     decide verify
                     o cancel)
```

---

## 14. Orden de implementación propuesto

| Orden | Archivo | Cambio | Dependencia |
|-------|---------|--------|-------------|
| 1 | `database/migrations/landlord/2026_07_08_000001_add_phone_and_bank_to_payment_matches.php` | Migración | Ninguna |
| 2 | `app/Services/Payment/ParsedPayment.php` | Agregar `senderPhoneNumber`, `senderPhoneFirst4` | Migración corrida |
| 3 | `app/Services/Payment/PaymentNotificationParser.php` | Extraer `senderPhoneFirst4`, pasar a ParsedPayment | #2 |
| 4 | `app/Models/PaymentMatch.php` | Agregar `$fillable` nuevos, actualizar `createFromParsed()` | #1, #2 |
| 5 | `app/Services/Payment/PaymentMatchGuard.php` | Nueva clase | BankCode enum |
| 6 | `app/Services/Payment/ReconciliationOrchestrator.php` | Guardia en `run()` y `runReverse()`, método `sendMismatchAlert()` | #5 |
| 7 | `app/Services/Payment/PaymentService.php` | Guardia en `attemptReverseMatch()` | #5 |
| 8 | `resources/js/pages/billing/orders/show.tsx` | Operadora select + 7-digit input | Ninguna |
| 9 | `app/Http/Controllers/Tenant/PaymentController.php` | `sender_phone` → 11-digit validation | #8 |
| 10 | `resources/js/components/payment-details-card.tsx`, `resources/js/pages/admin/orders/show.tsx` | Tipos TS | #4 |

---

## 15. Pruebas

### Unit tests

| Test | Clase | Escenario |
|------|-------|-----------|
| `PaymentMatchGuardTest` | `PaymentMatchGuard` | Bank match ok |
| | | Bank mismatch |
| | | Phone match (BNC canonical) |
| | | Phone match (BDV full digits) |
| | | Phone mismatch (BNC) |
| | | Phone mismatch (BDV) |
| | | `pagoMovilDetail` es null → skip |
| | | `parsed_sender_phone_first4` es null → skip |
| | | `parsed_bank_code` inválido → skip |
| `PaymentNotificationParserTest` | `PaymentNotificationParser` | `extractFirst4()` con BNC masked |
| | | `extractFirst4()` con BDV completo |
| | | `extractFirst4()` con null |
| | | ParsedPayment tiene senderPhoneNumber + senderPhoneFirst4 |

### Feature tests

| Test | Flujo | Escenario |
|------|-------|-----------|
| `ReconciliationOrchestratorTest` | Forward | All match → auto-verify |
| | Forward | Bank mismatch → pending + alert |
| | Forward | Phone mismatch → pending + alert |
| | Reverse | All match → auto-verify |
| | Reverse | Mismatch → pending + alert |
| `PaymentServiceTest` | Reverse | `attemptReverseMatch`: mismatch → no-op |
| `PaymentControllerTest` | Store | `sender_phone` 11 digits → ok |
| | Store | `sender_phone` 10 digits → validation error |
| | Store | `sender_phone` con guión → validation error |

### Browser tests (si aplica)

| Test | Escenario |
|------|-----------|
| Seleccionar operadora e ingresar 7 dígitos | Submit exitoso |
| Ingresar menos de 7 dígitos | Browser validation bloquea |
| No seleccionar operadora | Submit no procede |

---

## 16. Riesgos y mitigaciones

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| `pagoMovilDetail->sender_phone` tiene formato inesperado (espacios, código país +58) | Baja | `preg_replace('/\D/', '',...)` normaliza; si tiene +58412..., los dígitos son 58412... → no va a matchear con 0412... → mismatch intencional (correcto) |
| `parsed_sender_phone_number` puede tener menos de 4 dígitos después de limpiar | Baja | `senderoPhoneFirst4` será null → skip phone validation (graceful) |
| Alerta duplicada si `runReverse()` se llama sin pasar por `attemptReverseMatch` | Baja | `runReverse()` tiene su propia guardia con alerta. `attemptReverseMatch()` valida antes y evita llamar a `runReverse()` en mismatch. Si alguien llama a `runReverse()` directo, la alerta es correcta. |
| Rendimiento: cargar `pagoMovilDetail` por cada match | Media | `PaymentMatchGuard::validate()` carga `$payment->pagoMovilDetail`. Como solo se ejecuta para 1 candidato por match, es O(1) por notificación. Sin impacto mensurable. |

---

## 17. Rollback

1. `php artisan migrate:rollback --path=database/migrations/landlord/2026_07_08_000001_add_phone_and_bank_to_payment_matches.php`
2. Revertir cambios en `ReconciliationOrchestrator::run()` y `runReverse()` (quitar guardia y `sendMismatchAlert`)
3. Revertir cambios en `PaymentService::attemptReverseMatch()` (quitar guardia)
4. Eliminar `app/Services/Payment/PaymentMatchGuard.php`
5. Revertir `PaymentMatch::createFromParsed()` (quitar nuevos campos del `$fillable` y `create()`)
6. Revertir `PaymentNotificationParser::parse()` (quitar extracción de `senderPhoneFirst4`)
7. Revertir `ParsedPayment.php` (quitar nuevos parámetros del constructor)
8. Revertir frontend + controller validation a valores anteriores
9. No hay pérdida de datos: las columnas se eliminan en el rollback de migración, los registros existentes no se ven afectados
