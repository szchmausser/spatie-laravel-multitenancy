# Pendientes — Fase Temprana

> Basado en el código actual. Sin testing porque estamos en flujo.

---

## ✅ Hecho

### Bugfix: guard de precondiciones en `creating` callback

Se agregó `assertTenantsTableExists()` al inicio del callback `creating` en `Tenant::booted()`. Si la tabla `tenants` no existe en la BD landlord, lanza `RuntimeException` con el comando exacto a ejecutar, **antes** de crear la BD del tenant o correr migraciones.

**Archivo:** `app/Models/Tenant.php`

### Filesystem isolation + avatar upload

Se implementó el aislado de filesystem por tenant usando `league/flysystem-path-prefixing` + `SwitchFilesystemTask` + `spatie/laravel-medialibrary` v11. Cada tenant tiene su propio directorio (`tenant_{id}/`) en `storage/app/public/tenant/`. Se agregó avatar upload en settings/profile con preview, subida y borrado, verificado manualmente en ambos dominios (landlord y tenant).

**Archivos:** `app/Multitenancy/Tasks/SwitchFilesystemTask.php`, `app/Http/Controllers/Settings/AvatarController.php`, `resources/js/components/avatar-upload.tsx`, `config/filesystems.php`, `config/multitenancy.php`

**Lección:** El middleware `IdentifyTenantIfPresent` que sugerí era innecesario — PostgreSQL resuelve `database => null` a la base default en requests HTTP reales. Se removió sin impacto.

---

## Pendientes ordenados por cuándo importan

### ~~CRUD completo de tenants~~ ✅

`TenantController` tiene CRUD completo (index, create, store, show, edit, update, destroy), páginas Inertia para cada una, 10 feature tests y 8 browser tests. Todo implementado como parte de T1 del cambio `filesystem-isolation`.

---

## Mapa de aislamiento tenant-aware

| Capa | Tenant-aware | Cómo |
|------|:-----------:|------|
| **Base de datos** | ✅ Package | `SwitchTenantDatabaseTask` — BD separada por tenant |
| **Cache** (redis/file) | ✅ Package | `PrefixCacheTask` — prefijo `tenant_{id}_` en todas las keys |
| **Colas** | ✅ Package | `queues_are_tenant_aware_by_default` — serializa y restaura el tenant |
| **Filesystem** | ✅ Custom | `SwitchFilesystemTask` — `scoped` driver con prefijo `tenant_{id}` |
| **Log context** | ✅ Custom | `SwitchTenantLoggingTask` — `Log::shareContext()` |
| **Sesiones** | ✅ Por defecto | Cookie scoped al subdominio actual, no hay colisión entre tenants ni con landlord |
| **Config cache** | ✅ No afecta | `php artisan config:cache` no congela el runtime — `config(['key' => 'val'])` sigue funcionando |
| **Mail por tenant** | ✅ Decidido: no | Solo se usan subdominios de la app, no dominios personalizados por cliente |
| **Broadcasting** | 🟡 Cuando se necesite | Namespace de canales por tenant |
| **Artisan por tenant** | 🟡 Cuando se necesite | `php artisan tenant:artisan {comando}` ya disponible en el paquete |

---

## Antes de producción

### 🔴 Status / suspensión de tenants — PENDIENTE

No hay columna `status` en tenants ni middleware que rechace requests a inquilinos suspendidos. En un SaaS, los impagos y la necesidad de bloquear existen desde el día 1.

**Qué haría falta:**
- Columna `status` (enum: `active`, `suspended`, `trial`)
- Middleware `EnsureTenantIsActive` en el grupo `tenant`
- Opcional: vista pública de "cuenta suspendida" en vez de error 500

### Rate limiting por tenant

Hoy es global. Un tenant que haga scraping o un bucle mal escrito puede tirar abajo a todos los demás. Cada tenant debería tener su propio límite de requests.

### Resource quotas

Máximo de usuarios, almacenamiento, requests por minuto. Sin esto, un tenant abusivo consume todo el servidor y los demás sufren.

---

## Cuando el negocio lo exija

- **Provisioning async** — Hoy crear un tenant es sincrónico (crea BD + migra). Con 20 pasos, el usuario espera. Cuando haya más pasos provisionando, conviene cola.
- **Backup por BD** — Cada tenant tiene su propia BD, cada una necesita su propio backup. No es complejo, pero hay que tenerlo en el radar.
- **GDPR / export de datos** — El día que un tenant quiera irse, tenés que poder darle todos sus datos. Tenerlo pensado desde ahora evita dolores de cabeza.
- **Billing** — Si es SaaS, hay que cobrar.
- ~~**Gestión de usuarios del tenant desde landlord**~~ ✅ **Decisión: NO implementar**. Rompe el aislamiento de datos del tenant, expone a riesgo legal (GDPR), y no es práctica estándar en la industria (Slack no ve tus mensajes, GitHub no ve tus repos privados). Si surge una necesidad real de soporte, se resuelve con self-service (reset de password por email) o acceso temporal "break glass" con consentimiento explícito, auditoría y expiración.
- ~~**Listeners de eventos del paquete**~~ ✅ **Decisión: NO implementar**. Spatie dispara eventos (`MakingTenantCurrentEvent`, `TenantForgotCurrent`, etc.) que serían útiles para webhooks, invalidación de caché Redis, o auditoría de accesos. Hoy no hay nada de eso.
- **Feature flags / config por tenant** (columna `config` JSON en tenants)
- **Validación de dominio landlord vs tenant domains**
- **Comando artisan `make:tenant`** (para dev/CI)

---

## Resumen

| Cuándo | Qué |
|--------|-----|
| ✅ Ahora | Bugfix — guard de precondiciones en creating callback |
| ✅ Ahora | Filesystem isolation + avatar upload |
| ✅ Ahora | CRUD completo de tenants (edit/delete) |
| ✅ Ahora | Logging context (SwitchTenantLoggingTask) |
| 🔴 Antes de producción | Status / suspensión de tenants |
| 🟡 Escalando | Rate limiting, resource quotas, backups, GDPR, billing |
| 🟢 Cómodo | Feature flags, provisioning async, domain validation, artisan command |
