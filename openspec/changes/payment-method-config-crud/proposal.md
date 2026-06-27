# Propuesta: S8d — PaymentMethodConfig CRUD

## Propósito

Dar interfaz de administración al CRUD de cuentas bancarias receptoras (PagoMóvil / Transferencia). Hoy el modelo `PaymentMethodConfig` existe y se usa en producción, pero no hay UI para gestionarlo — dependencia de tinker/seeder para cualquier cambio.

## Alcance

### Incluye
- `PaymentMethodConfigController` full CRUD (index, create, store, edit, update, destroy)
- Ruta resource `admin/payment-configs` en landlord route group (con middleware auth+verified+admin)
- 3 páginas Inertia React: listado con indicador activo/inactivo, formulario de creación, formulario de edición
- Card de entrada en `admin-panel.tsx`
- Validación server-side: tipo requerido, campos bancarios condicionales, unicidad de `label` por `type`
- Info banner al borrar cuando queden 0 cuentas activas de ese tipo

### Excluye
- Control de sort order desde UI (existe en BD, default 0)
- Campo metadata (hidden, NULL, reservado para futuro)
- Bloqueo duro al desactivar (se confía en admin + banner informativo)
- Dashboard de alertas (S8c) o dashboard de conciliación (S8f)

## Capacidades

### Nuevas Capacidades
- `payment-method-config-crud`: Gestión completa de cuentas bancarias — listado con indicador activo/inactivo, creación y edición con validación condicional, borrado con banner de advertencia

### Capacidades Modificadas
None

## Enfoque

Controller RESTful siguiendo patrón de `TenantController`. Tres páginas Inertia: listado (tabla con badge activo/inactivo + acciones editar/borrar), create (formulario con campos condicionales según type), edit (mismo formulario + preload). Validación con Form Request. Banner informativo al borrar si es la última cuenta de ese type.

## Áreas Afectadas

| Área | Impacto | Descripción |
|------|---------|-------------|
| `app/Http/Controllers/Landlord/PaymentMethodConfigController.php` | Nuevo | Full CRUD (index, create, store, edit, update, destroy) |
| `routes/landlord.php` | Modificado | + route resource `payment-configs` |
| `resources/js/pages/landlord/payment-configs/index.tsx` | Nuevo | Tabla con indicador activo/inactivo + acciones |
| `resources/js/pages/landlord/payment-configs/create.tsx` | Nuevo | Formulario creación con campos condicionales |
| `resources/js/pages/landlord/payment-configs/edit.tsx` | Nuevo | Formulario edición con preload de datos |
| `resources/js/pages/landlord/admin-panel.tsx` | Modificado | + card entry PaymentConfigs |

## Riesgos

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| Label duplicado por type | Baja | Unique validation en Form Request |
| Borrar última cuenta de un type deja al tenant sin opción | Baja | Banner informativo + confirmación |
| Desactivar todas las cuentas de un type | Baja | Banner informativo, sin bloqueo duro |

## Plan de Rollback

Revertir rutas en `landlord.php`, eliminar controlador, eliminar páginas Inertia y card en `admin-panel.tsx`. La tabla `payment_method_configs` no se modifica estructuralmente — sin migraciones que revertir.

## Dependencias

Ninguna. Modelo `PaymentMethodConfig` y tabla `payment_method_configs` ya existen y están en producción (S1–S7).

## Criterios de Éxito

- [ ] Admin puede crear cuenta bancaria (PagoMóvil y Transferencia) desde UI
- [ ] Admin puede ver listado con indicador activo/inactivo y acciones
- [ ] Admin puede editar campos de una cuenta existente
- [ ] Admin puede borrar una cuenta; si es la última de su tipo, ve banner informativo
- [ ] Validación server-side rechaza datos inválidos con mensajes claros
