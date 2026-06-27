# S8c — Dashboard de Alertas (SystemAlert) — Spec

## Resumen de funcionalidades

| ID | Funcionalidad | Tipo |
|----|--------------|------|
| F1 | Fix unread_notifications_count para Landlord | Modificación |
| F2 | AlertController::index con filtros | Nueva |
| F3 | AlertController::read — marcar como leída | Nueva |
| F4 | Página Inertia landlord/alerts.tsx | Nueva |
| F5 | Sidebar nav "Alertas" con badge de no leídas | Nueva |

## Requisitos funcionales

### RF-1: Fix HandleInertiaRequests

`resolveUnreadNotificationsCount()` en `HandleInertiaRequests` MUST incluir `$user instanceof Landlord` en la guarda actual (`$user instanceof User`).

### RF-2: Index de alertas con filtros

`AlertController::index` MUST retornar notificaciones paginadas (20 por página) con `data->>'category' = 'system'`, filtrables por:
- `severity` — critical, warning, info (válido contra valores conocidos)
- `read` — true/false para filtrar por `read_at`
- `from` / `to` — rango de `created_at`

### RF-3: Marcar alerta como leída

`AlertController::read` MUST setear `read_at = now()` en la notificación, validar que exista y que pertenezca a category=system.

### RF-4: Página Inertia con filtros

`landlord/alerts.tsx` SHOULD mostrar tabla con severity badge, timestamp, mensaje, botón "marcar leída". Los filtros MUST disparar visit Inertia con query params. SHOULD mostrar estado vacío cuando no hay alertas.

### RF-5: Sidebar badge

El admin sidebar MUST incluir nav item "Alertas" con icono `Bell` y badge rojo con `unread_notifications_count`. El tenant sidebar MUST NOT mostrar este item.

## Requisitos no funcionales

| ID | Descripción |
|----|-------------|
| RNF-1 | Badge count MUST usar `count()`, no `get()` |
| RNF-2 | Filtros JSON (`data->>'category'`) MUST funcionar en PostgreSQL |
| RNF-3 | No se crean migraciones ni tablas nuevas |
| RNF-4 | Rutas MUST estar tras middleware `auth` + `verified` + `EnsureUserIsAdmin` |

## Escenarios de prueba

### Controller index

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Listado default | Notifications con category=system existen | GET /admin/alerts | 200, paginated, data[] con alerts |
| Filtro severity | Notifications con severity=critical y warning | GET /admin/alerts?severity=critical | Solo critical |
| Filtro read=false | Notifications leídas y no leídas | GET /admin/alerts?read=false | Solo no leídas |
| Filtro date range | Notifications de distintas fechas | GET /admin/alerts?from=2025-01-01&to=2025-06-01 | Solo rango |
| Empty state | No hay system notifications | GET /admin/alerts | 200, data: [] |
| Severity inválido | ?severity=invalid | GET /admin/alerts | 400 o ignore silently |

### Controller read

| Escenario | Precondición | Acción | Resultado esperado |
|-----------|-------------|--------|--------------------|
| Marcar leída | Notification unread | POST /admin/alerts/{id}/read | read_at set, redirect back |
| Idempotente | Notification ya leída | POST /admin/alerts/{id}/read | read_at unchanged, no error |
| No existe | UUID inválido | POST /admin/alerts/fake/read | 404 |
| No es system | notification.data.category != system | POST /admin/alerts/{id}/read | 404 |

### Página Inertia

| Escenario | Resultado esperado |
|-----------|--------------------|
| Render con alertas | Severity badge, timestamp, message, mark-as-read button |
| Cambio de filtro | Inertia visit con query params, URL actualizada |
| Sin alertas | Empty state: "No hay alertas de sistema" |

### Sidebar

| Escenario | Resultado esperado |
|-----------|--------------------|
| Admin logueado | Nav item "Alertas" con Bell icon, badge con count |
| Tenant logueado | Sin nav item "Alertas" |

## Criterios de aceptación

- ✅ RF-1: Admin ve unread count real en shared prop (no 0)
- ✅ RF-2: GET /admin/alerts retorna alerts paginadas, filtrables por severity/read/fecha
- ✅ RF-3: POST /admin/alerts/{id}/read setea read_at y es idempotente
- ✅ RF-4: Página muestra alerts con filtros funcionales y empty state
- ✅ RF-5: Admin sidebar muestra badge; tenant sidebar no
- ✅ Tests: `php artisan test --compact --filter=AlertController` pasa sin errores
