# Design: S8d — PaymentMethodConfig CRUD

## Enfoque Técnico

Controller RESTful con `Route::resource`, 3 páginas Inertia React (index/create/edit), validación mediante Form Requests. Sin API — todo Inertia full-stack. Sigue el patrón de `PlanController` y `TenantController` del landlord.

## Decisiones Arquitectónicas

| Opción | Alternativas | Razón |
|--------|-------------|-------|
| Form Requests separados (Store/Update) | Inline validation | Consistente con Laravel best practices — `$request->validated()` mantiene controllers limpios. Update omite `type` y usa `ignore()` para unique. |
| `Route::resource('payment-configs')` | Rutas explícitas | 6 endpoints estándar, una línea en routes, mismo patrón que `plans` y `resources`. |
| Agrupación backend: `->get()->groupBy('type')` | Frontend JS groupBy | Backend es trivial y define la forma del contrato Inertia. Mismo patrón que `SystemConfigController::index()`. |
| Flash en español con claves 'success'/'warning' | Texto desde config | Consistente con el resto del panel (SystemConfig ya usa flash). |
| Type no editable en edit: texto informativo | Selector deshabilitado | El spec lo exige explícitamente. Render type como Badge evita confusión sobre campos no enviados. |

## Data Flow

```
Admin Browser                     Laravel                          DB
     │                               │                             │
     │  GET /admin/payment-configs    │                             │
     │ ─────────────────────────────► │  PaymentMethodConfig::all() │
     │                                │ ──────────────────────────► │
     │                                │  groupBy('type')            │
     │  Inertia { configsByType }    │                             │
     │ ◄───────────────────────────── │                             │
     │                                │                             │
     │  POST /admin/payment-configs   │                             │
     │  { type, label, bank_name... } │                             │
     │ ─────────────────────────────► │  StorePaymentMethodConfig   │
     │                                │  → validated() → create()  │
     │  ◄── 302 → index + flash       │                             │
     │                                │                             │
     │  DELETE /admin/payment-configs │                             │
     │  /{id}                        │                             │
     │ ─────────────────────────────► │  Delete + lastActiveCheck  │
     │  ◄── 302 → index + flash       │  (banner if last active)   │
```

## Cambios en Archivos

| Archivo | Acción | Lns | Descripción |
|---------|--------|-----|-------------|
| `app/Http/Controllers/Landlord/PaymentMethodConfigController.php` | Crear | 85 | index → grouped, create/store, edit/update, destroy + last-active check |
| `app/Http/Requests/Landlord/StorePaymentMethodConfigRequest.php` | Crear | 35 | rules store: type required, label unique per type |
| `app/Http/Requests/Landlord/UpdatePaymentMethodConfigRequest.php` | Crear | 35 | rules update: sin type, label unique ignoring self |
| `routes/landlord.php` | Modificar | +1 | `Route::resource('payment-configs', ...)` dentro del grupo admin |
| `resources/js/pages/landlord/payment-configs/index.tsx` | Crear | 110 | Tablas agrupadas x tipo + badges + acciones |
| `resources/js/pages/landlord/payment-configs/create.tsx` | Crear | 95 | Form con radio type + labels condicionales |
| `resources/js/pages/landlord/payment-configs/edit.tsx` | Crear | 100 | Form preload, type badge read-only |
| `resources/js/pages/landlord/admin-panel.tsx` | Modificar | +6 | Card "Cuentas Bancarias" con CreditCard |
| Total | | ~467 | |

## Interfaces / Contratos

```typescript
type PaymentMethodConfigType = 'pago_movil' | 'bank_transfer';

interface PaymentMethodConfig {
    id: number;
    type: PaymentMethodConfigType;
    label: string;
    bank_name: string;
    account_number: string;
    account_holder: string;
    holder_id: string;
    is_active: boolean;
    sort_order: number;
    created_at: string;
    updated_at: string;
}

interface ConfigsByType {
    pago_movil: PaymentMethodConfig[];
    bank_transfer: PaymentMethodConfig[];
}
```

```php
// StorePaymentMethodConfigRequest
type => ['required', 'string', Rule::in(['Pagomovil', 'Transferencia'])]
label => ['required', 'string', 'max:255', Rule::unique('payment_method_configs')
    ->where('type', $this->type)]

// UpdatePaymentMethodConfigRequest
label => ['required', 'string', 'max:255', Rule::unique('payment_method_configs')
    ->where('type', $this->route('payment_method_config')->type)
    ->ignore($this->route('payment_method_config')->id)]
```

## Árbol de Componentes

```
LandlordLayout
└── AdminPanel
    └── Card "Cuentas Bancarias" (CreditCard icon, href /admin/payment-configs)

PaymentConfigIndex (landlord/payment-configs/index)
├── Header + Botón "Nueva Cuenta" → Link a create
├── Sección PagoMóvil
│   └── Table: label | banco | teléfono | titular | RIF | activo/badge | ✏️ | 🗑️
├── Sección Transferencia
│   └── Table: label | banco | cuenta | titular | RIF | activo/badge | ✏️ | 🗑️
├── Empty state (card centrada, ambas secciones vacías)
└── Flash banner (success / warning)

PaymentConfigCreate (landlord/payment-configs/create)
├── Título + description
├── Radio group: PagoMóvil / Transferencia
└── ConfigForm (label, bank_name, account_number label dinámico, account_holder, holder_id, is_active)

PaymentConfigEdit (landlord/payment-configs/{id}/edit)
├── Título + description
├── Type Badge (read-only, ej. "PagoMóvil")
└── ConfigForm (pre-filled, account_number label según type)
```

## Estrategia de Validación

1. **StorePaymentMethodConfigRequest**: `rules()` contiene todas las reglas del spec. `prepareForValidation()` convierte flash data. `messages()` en español para errores comunes.
2. **UpdatePaymentMethodConfigRequest**: mismo `rules()` pero sin `type`. Usa `Rule::unique(...)->ignore($this->route('payment_method_config')->id)->where('type', $this->route('payment_method_config')->type)`.
3. **Destroy last-active check**: Controller verifica si después del delete quedan 0 activas del mismo `type`. Si es el caso, agrega `flash('warning', '...')`.
4. **Flash loading**: Botón disabled + "Guardando..." mientras processing=true.

## Estrategia de Testing

| Capa | Qué | Cómo |
|------|-----|------|
| Feature | Admin puede listar, crear, editar, eliminar configs | Pest feature tests con autenticación admin, assertions sobre Inertia props y redirects |
| Feature | Validación store: required, unique, type enum | POST con datos inválidos → assertSessionHasErrors |
| Feature | Validación update: unique ignora self | PUT mismo label → assertSessionHasNoErrors |
| Feature | Destroy last-active banner | Crear única cuenta activa, delete, assert flash warning |
| Feature | 403 para non-admin | GET/POST sin EnsureUserIsAdmin → assertStatus(403) |
| Feature | Admin panel card | GET /admin → assert card visible con href correcto |

## Migración / Rollback

Sin migraciones. El modelo y tabla `payment_method_configs` ya existen desde S1–S7.
Rollback en un commit: revertir `routes/landlord.php`, eliminar controller + Form Requests + páginas Inertia + card en `admin-panel.tsx`.

## Preguntas Abiertas

Ninguna — todas las decisiones están resueltas por proposal, spec y patrones existentes.
