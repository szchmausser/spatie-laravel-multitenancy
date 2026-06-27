# PaymentMethodConfig CRUD Specification

## Purpose

Proveer gestión visual de cuentas bancarias receptoras (PagoMóvil y Transferencia Bancaria) para administradores landlord — listado agrupado por tipo, creación/edición con campos condicionales según tipo, y borrado con advertencia informativa.

## Requirements

### Requirement: Listar configuraciones

El sistema MUST exponer `GET /admin/payment-configs` retornando configuraciones agrupadas por `type`. Endpoint SHALL usar middleware `auth`, `verified`, `EnsureUserIsAdmin`.

#### Scenario: Vista agrupada con datos

- GIVEN admin autenticado con configs existentes
- WHEN navega a /admin/payment-configs
- THEN ve secciones PagoMóvil y Transferencia Bancaria
- AND cada fila: label, banco, número/teléfono, titular, RIF, badge activo/inactivo, acciones

#### Scenario: Estado vacío

- GIVEN admin sin configs registradas
- WHEN navega a /admin/payment-configs
- THEN ve mensaje "No hay cuentas bancarias configuradas"

### Requirement: Crear configuración

El sistema MUST exponer `GET /admin/payment-configs/create` y `POST /admin/payment-configs`. Incluye selector de tipo (radio solo en creación) y labels condicionales.

**Reglas de validación (STORE):**

| Campo | Regla |
|-------|-------|
| type | required, in:Pagomovil,Transferencia |
| label | required, string, max:255, unique:payment_method_configs,label,NULL,NULL,type,{type} |
| bank_name | required, string, max:255 |
| account_number | required, string, max:255 |
| account_holder | required, string, max:255 |
| holder_id | required, string, max:20 |
| is_active | boolean, nullable |

**Campos ocultos:** metadata → NULL, sort_order → default 0.
**Label condicional:** account_number → "Teléfono" para Pagomovil, "Número de Cuenta" para Transferencia.

#### Scenario: Crear cuenta PagoMóvil

- GIVEN admin en /admin/payment-configs/create
- WHEN selecciona PagoMóvil, completa campos con "Teléfono" visible, envía
- THEN cuenta se guarda, redirige a index con mensaje de éxito

#### Scenario: Crear cuenta Transferencia

- GIVEN admin en /admin/payment-configs/create
- WHEN selecciona Transferencia con "Número de Cuenta" visible, envía
- THEN cuenta se guarda, redirige a index con éxito

#### Scenario: Validación rechaza datos inválidos

- GIVEN admin enviando formulario
- WHEN omite required o ingresa label duplicado para el mismo type
- THEN 422 con errores, formulario preserva datos ingresados

#### Scenario: Loading durante submit

- GIVEN admin enviando formulario
- WHEN hace clic en Guardar
- THEN botón se deshabilita con texto "Guardando..."
- AND se rehabilita al completar o fallar

### Requirement: Editar configuración

El sistema MUST exponer `GET /admin/payment-configs/{paymentMethodConfig}/edit` y `PUT /admin/payment-configs/{paymentMethodConfig}`. El tipo NO SHALL ser editable en edición. Reglas de UPDATE igual que STORE, excepto type (no se envía) y unique:label ignora el registro actual.

#### Scenario: Editar campos existentes

- GIVEN admin en /admin/payment-configs/{id}/edit
- WHEN modifica label, bank_name, is_active y envía
- THEN cambios persisten, redirige a index con éxito

#### Scenario: Tipo no editable

- GIVEN admin editando cuenta
- WHEN ve formulario de edición
- THEN tipo se muestra como texto informativo, sin selector

#### Scenario: Error de validación en edición

- GIVEN admin editando con label duplicado
- WHEN envía
- THEN 422 con error en campo label, formulario preserva cambios

### Requirement: Eliminar configuración

El sistema MUST exponer `DELETE /admin/payment-configs/{paymentMethodConfig}`. SHALL mostrar diálogo de confirmación. SHALL mostrar banner informativo al eliminar la última cuenta activa de un type.

#### Scenario: Eliminación con confirmación

- GIVEN admin en index
- WHEN clic ícono borrar
- THEN diálogo "¿Eliminar cuenta?" con botones Confirmar/Cancelar
- AND si confirma, cuenta se elimina y listado se actualiza

#### Scenario: Última cuenta activa del tipo

- GIVEN admin eliminando única cuenta activa de Pagomovil
- WHEN confirma eliminación
- THEN cuenta eliminada + banner: "No quedan cuentas PagoMóvil activas. Los tenants no podrán pagar con este método hasta que agregues una nueva."

### Requirement: Card en admin panel

`admin-panel.tsx` MUST incluir card con icono CreditCard, título, descripción y href a /admin/payment-configs.

#### Scenario: Card visible en dashboard

- GIVEN dashboard landlord renderizado
- THEN card "Cuentas Bancarias" visible con href /admin/payment-configs
- AND descripción: "Gestionar cuentas receptoras PagoMóvil y Transferencia Bancaria"
