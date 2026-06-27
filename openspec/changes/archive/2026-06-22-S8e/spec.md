# S8e — PaymentNotification viewer + reprocess failed — Spec

## Resumen de funcionalidades

| ID | Funcionalidad | Tipo |
|----|--------------|------|
| RF-1 | Index paginado con filtros | Nueva |
| RF-2 | Detalle expandible por fila | Nueva |
| RF-3 | Reprocesar notificación fallida | Nueva |
| RF-4 | Card en admin-panel.tsx | Nueva |
| RF-5 | match() hasOne en PaymentNotification | Nueva |

## Requisitos funcionales

### RF-1: Index paginado con filtros

`PaymentNotificationController::index` MUST retornar notificaciones paginadas (20 por página, `created_at` DESC, eager-load `match.payment`), filtrables por:
- `parse_status` — pending, success, failed
- `bank_code` — string exact match
- `from` / `to` — rango de `created_at`

Query-string params MUST preservarse en paginación.

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Listado default | Notifications existen con varios estados | GET /admin/payment-notifications | 200, 20 records, sorted DESC |
| Filtro parse_status | Notifications pending, success, failed | ?parse_status=failed | Solo failed |
| Filtro bank_code | Notifications con BNC y BDV | ?bank_code=BNC | Solo BNC |
| Filtro date range | Notifications de jun vs jul | ?from=2025-06-01&to=2025-06-30 | Solo junio |
| Empty | No notifications coinciden | GET /admin/payment-notifications | 200, data: [] |

### RF-2: Detalle expandible

Each row MUST toggle to reveal: `raw_text` (preformatted), `parsed_data` (JSON pretty-print), `parse_error` (solo si status=failed), y `match` info (referencia, monto, estado del pago si existe).

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Parsed con match | Notification parsed + PaymentMatch vinculado | Expandir fila | raw_text, parsed_data, match.reference/amount/payment.status |
| Failed | parse_status = failed | Expandir fila | raw_text, parse_error, sin match |
| Parsed sin match | Notification parsed, sin PaymentMatch | Expandir fila | raw_text, parsed_data, indicador "Sin match" |

### RF-3: Reprocesar notificación fallida

POST `/admin/payment-notifications/{id}/reprocess` MUST dispatch `IngestPaymentNotification` job si `parse_status = failed`, y redirect back con flash. Button MUST mostrarse solo para failed y deshabilitarse durante el submit.

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Reprocess exitoso | parse_status = failed | POST /reprocess/{id} | Job dispatched, redirect con success flash |
| Reprocess no-failed | parse_status = parsed | POST /reprocess/{id} | 422 o error flash, no job |
| Reprocess missing | ID inexistente | POST /reprocess/99999 | 404 |
| No autorizado | Usuario no admin autenticado | GET /admin/payment-notifications | 403 |

### RF-4: Admin panel card

Admin panel MUST incluir card con título "Notificaciones", descripción "Monitorear notificaciones bancarias entrantes", icono bell, link a `/admin/payment-notifications`.

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Admin ve card | Admin logueado | Ver admin-panel | Card visible con título/desc/link |
| Tenant no ve | Tenant logueado | Ver admin-panel | Card NO visible |

### RF-5: match() relationship

`PaymentNotification` MUST tener `match()` hasOne → `PaymentMatch`. Index query MUST eager-load `match.payment`.

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Eager load | Notification con PaymentMatch existente | Index query | match.payment cargado sin N+1 |

## Criterios de aceptación

- ✅ RF-1: GET /admin/payment-notifications retorna paginado, filtrable por parse_status/bank_code/rango
- ✅ RF-2: Detalle expandible muestra raw_text, parsed_data, match info, parse_error según estado
- ✅ RF-3: Reprocess POST dispatches job solo para failed, redirect con flash
- ✅ RF-4: Admin panel tiene card "Notificaciones"; tenant no lo ve
- ✅ RF-5: match() hasOne existe + eager-loaded en index
- ✅ Tests: `php artisan test --compact --filter=PaymentNotificationController` pasa

## Escenarios de prueba

### Feature tests (controller)

| Escenario | Tipo | Asserts clave |
|-----------|------|---------------|
| Index default | Feature | 200, 20 records, JSON structure |
| Filter by parse_status | Feature | Only matching statuses |
| Filter by bank_code | Feature | Only matching bank_code |
| Filter by date range | Feature | Only records within range |
| Reprocess success | Feature | Job dispatched, flash success |
| Reprocess non-failed | Feature | 422, no job dispatched |
| Reprocess 404 | Feature | 404 |
| Unauthorized | Feature | 403 non-admin |
| Empty state | Feature | 200, data vacío |

### Browser tests (frontend)

| Escenario | Asserts clave |
|-----------|---------------|
| Table renders with data | Rows visible, pagination present |
| Filters update results | Select parse_status → table refreshes |
| Expandable detail shows raw_text | Preformatted block visible |
| Reprocess button on failed row | Button visible, click dispatches POST |
| Admin panel has card | Card rendered with text |
