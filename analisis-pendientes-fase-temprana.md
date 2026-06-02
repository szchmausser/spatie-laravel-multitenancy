# Pendientes — Fase Temprana

> Basado en el código actual. Sin testing porque estamos en flujo.

---

## ✅ Hecho

### Bugfix: guard de precondiciones en `creating` callback

Se agregó `assertTenantsTableExists()` al inicio del callback `creating` en `Tenant::booted()`. Si la tabla `tenants` no existe en la BD landlord, lanza `RuntimeException` con el comando exacto a ejecutar, **antes** de crear la BD del tenant o correr migraciones.

**Archivo:** `app/Models/Tenant.php`

---

## Pendientes ordenados por cuándo importan

### Lo que sigue ahora — CRUD completo de tenants

El `TenantController` tiene index, create, store, show. Faltan edit/update/delete. Sin edit no podés cambiar dominio si el cliente se equivocó. Sin delete no podés eliminar un tenant (y su BD) desde el panel.

**Archivos:** `TenantController.php`, rutas landlord, vistas Inertia (create/edit/show/index)

### Cuando se acerque producción

- **Filesystem isolation** — Si los tenants van a subir archivos (avatars, documentos, etc.), un SwitchFilesystemTask que scoped el directorio por tenant-ID evita colisiones. Mientras no haya subida de archivos, no duele.
- **Logging** — Inyectar el tenant ID en el contexto del logger permite filtrar errores por tenant cuando haya que debuggear. Mientras seas vos solo en dev, no aporta.

### Cuando el negocio lo exija

- **Status field en tenants** — El día que necesites suspender a un cliente por falta de pago, vas a necesitar una columna `status` y un middleware que bloquee requests a tenants inactivos. Hasta entonces, sobra.
- **Gestión de usuarios del tenant desde landlord** — Cuando los tenants tengan usuarios y el admin necesite verlos/crearlos/bloquearlos desde el panel. Hasta entonces, cada tenant se managea solo.
- **Listeners de eventos del paquete** — Cuando necesites auditar cambios de tenant, disparar webhooks, o invalidar caché específica al rotar tenant. Hoy no hay necesidad.

### Para cuando arranque el producto SaaS

- Feature flags / config por tenant (columna `config` JSON en tenants)
- Rate limiting por tenant (hoy es global)
- Mail config por tenant
- Provisioning async (cuando haya 20 pasos y sea user-facing)
- Comando artisan `make:tenant` (para dev/CI)
- Validación de dominio landlord vs tenant domains

---

## Resumen

| Cuándo | Qué |
|--------|-----|
| ✅ Ahora | Bugfix — guard de precondiciones en creating callback |
| ▶️ Sigue | CRUD completo de tenants (edit/delete) |
 | 🟡 Pre-producción | Filesystem isolation + logging context |
| 🟡 Cuando el negocio lo pida | Status field, user management, eventos |
| 🟢 Producto SaaS | Feature flags, rate limit, mail, provisioning async |
