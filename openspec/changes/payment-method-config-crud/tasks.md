# Tasks: S8d — PaymentMethodConfig CRUD

## Pronóstico de Carga de Revisión

| Campo | Valor |
|-------|-------|
| Líneas estimadas modificadas | ~617 |
| Riesgo de presupuesto 400 líneas | Medio |
| PR encadenados recomendados | No |
| División sugerida | PR único |
| Estrategia de entrega | single-pr |
| Estrategia de cadena | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Unidades de Trabajo Sugeridas

| Unidad | Objetivo | PR | Notas |
|--------|----------|----|-------|
| 1 | Full S8d — backend + frontend + tests | PR 1 | ~617 líneas, presupuesto 800 aceptado por el usuario |

## Fase 1: Backend — Form Requests y Controlador

- [x] 1.1 Crear `app/Http/Requests/Landlord/StorePaymentMethodConfigRequest.php` — rules: type required + in:Pagomovil,Transferencia; label required + string + max:255 + unique por type; bank_name, account_number, account_holder, holder_id required + string + max; is_active boolean|nullable
- [x] 1.2 Crear `app/Http/Requests/Landlord/UpdatePaymentMethodConfigRequest.php` — mismas reglas sin type; label unique ignora self vía `$this->route('payment_method_config')`
- [x] 1.3 Crear `app/Http/Controllers/Landlord/PaymentMethodConfigController.php` — index() con `->get()->groupBy('type')`, create/store, edit/update, destroy + último-activo check → flash warning
- [x] 1.4 Agregar ruta en `routes/landlord.php`: `Route::resource('payment-configs', PaymentMethodConfigController::class)->except('show')` dentro del grupo admin

## Fase 2: Frontend — Páginas Inertia

- [x] 2.1 Crear `resources/js/pages/landlord/payment-configs/index.tsx` — tabla agrupada por tipo, badges activo/inactivo, iconos de acción, estado vacío, banner flash
- [x] 2.2 Crear `resources/js/pages/landlord/payment-configs/create.tsx` — selector radio de tipo, labels condicionales (Teléfono / Número de Cuenta), formulario con submit
- [x] 2.3 Crear `resources/js/pages/landlord/payment-configs/edit.tsx` — formulario pre-cargado, type badge solo lectura, campos condicionales según tipo existente
- [x] 2.4 Modificar `resources/js/pages/landlord/admin-panel.tsx` — agregar card "Cuentas Bancarias" con icono CreditCard + href /admin/payment-configs

## Fase 3: Tests

- [x] 3.1 Feature test: admin puede listar configs agrupadas por tipo (assert Inertia component + props grouped)
- [x] 3.2 Feature test: admin puede crear cuenta PagoMóvil y Transferencia (assert DB + redirect + flash)
- [x] 3.3 Feature test: validación store — required, unique label por tipo, enum type (assertSessionHasErrors)
- [x] 3.4 Feature test: admin puede editar cuenta (type NO editable, assert DB update + redirect)
- [x] 3.5 Feature test: validación update — unique label ignora self (PUT mismo label sin error)
- [x] 3.6 Feature test: admin puede eliminar con confirmación (assert DB delete + redirect)
- [x] 3.7 Feature test: última activa del tipo muestra flash warning (assert flash('warning'))
- [x] 3.8 Feature test: non-admin recibe 403 en todas las rutas
- [x] 3.9 Feature test: admin panel incluye card "Cuentas Bancarias" con href correcto

## Fase 4: Limpieza

- [x] 4.1 Ejecutar `vendor/bin/pint --dirty --format agent` para formatear PHP
- [x] 4.2 Ejecutar tests y verificar: `php artisan test --compact --filter=PaymentMethodConfig`
