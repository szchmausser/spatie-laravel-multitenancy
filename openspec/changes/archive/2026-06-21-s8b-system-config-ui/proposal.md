# Proposal: S8b SystemConfig UI

## Intent

Eliminar dependencia de tinker/seeder para gestionar configs dinámicas del sistema. Los admins landlord deben poder ver y editar SystemConfig desde una UI Inertia.

## Scope

### In Scope
- `SystemConfigController` (index + update) en namespace Landlord
- Rutas GET `system-configs` / PUT `system-configs/{systemConfig}` en landlord route group
- Página Inertia React con tabla agrupada por `group`
- Edición modal por config con inputs type-aware (text, number, checkbox para boolean, textarea para regex)
- Validación de regex al guardar (el modelo ya la tiene en boot saving)
- Cache invalidado vía `SystemConfig::set()`
- Card de entrada en `admin-panel.tsx`

### Out of Scope
- Crear nuevas configs (solo editar existentes)
- Borrar configs
- Seed de configs adicionales
- Dashboard de alertas (S8c)

## Capabilities

### New Capabilities
- `system-config-ui`: Gestión visual de configs del sistema — tabla agrupada por grupo, edición modal con inputs type-aware, validación de regex en frontend y backend

### Modified Capabilities
None

## Approach

Modal per config (approach 2 del exploration). Tabla agrupada por `group` field dentro de Cards. Cada fila muestra key (badge), current value, type badge, description. Modal con inputs type-aware según `type` del model. Controller catch `InvalidArgumentException` del regex validation boot → 422 validation error. Siempre usar `SystemConfig::set()` para garantizar cache invalidation. Type coercion en controller antes de guardar.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/SystemConfigController.php` | New | index (Inertia::render) + update (validates, catches regex error) |
| `routes/landlord.php` | Modified | +2 routes (GET, PUT) con middleware auth+admin |
| `resources/js/pages/landlord/system-configs/index.tsx` | New | Grouped table + Dialog modal + type-aware form |
| `resources/js/pages/landlord/admin-panel.tsx` | Modified | +Card entry con icono, título, descripción, href |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Regex validation throws InvalidArgumentException | High | Controller catch → 422 con mensaje claro |
| Cache inconsistency por DB update directo | Medium | Siempre usar `SystemConfig::set()`, nunca `where()->update()` |
| No Switch component para booleans | Low | Usar Checkbox (ya disponible en la UI) |

## Rollback Plan

Revert routes y controller en `routes/landlord.php`, eliminar página component, eliminar card en `admin-panel.tsx`. Datos en `system_configs` no se tocan — sin migraciones que revertir.

## Dependencies

None. SystemConfig model, cache layer y seeders ya desplegados en S1.

## Success Criteria

- [ ] Admin puede ver todas las 10 configs agrupadas por grupo en la UI
- [ ] Admin puede editar el valor de una config y ver el cambio persistido al recargar
- [ ] Regex inválido devuelve 422 con mensaje de error claro en el modal
- [ ] Boolean toggle (`shadow_mode_enabled`) persiste correctamente como true/false
