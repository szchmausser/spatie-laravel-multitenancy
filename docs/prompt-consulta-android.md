Tengo un proyecto Laravel 13 que funciona como SaaS multi-tenant (Spatie Multitenancy v4, PostgreSQL). Es un sistema de gestión de suscripciones con pagos por PagoMóvil (Venezuela).

Ya tengo implementado y funcionando todo el backend de conciliación automática de pagos:

- **Parser único** de notificaciones bancarias (BDV y BNC) con regex almacenados en DB (`system_configs`)
- **Motor de matching determinista**: referencia + monto + ventana temporal. Sin confidence scores.
- **Job asincrónico** `IngestPaymentNotification` (parsea → matchea → verifica → dispara eventos)
- **Reverse matching**: cuando el cliente reporta el pago antes de que llegue la notificación, `PaymentService::recordPayment()` busca notificaciones "unmatched" con la misma referencia y monto, y si encuentra, auto-verifica.
- **Shadow mode**: sistema puede sugerir matches sin aprobar, para testing supervisado.
- **Eventos**: `PaymentCancelled` con `CancellationType` enum (Manual, SystemDuplicate, SystemExpired), listener `NotifyPaymentRejected` que envía `PaymentRejected` al tenant + `SystemAlert` a admins según el tipo.
- **UI completa en el panel Landlord**: Dashboard de conciliación con KPIs, tabla de notificaciones bancarias con filtros, alertas del sistema, CRUD de configuraciones, CRUD de cuentas bancarias, vista de payment matches. Todo con Inertia.js v3 + React 19 + Tailwind v4.
- **Comando Artisan** `simulate:payment-notification` para generar notificaciones de prueba.
- **Comandos**: `payments:expire-pending`, `reconciliation:reprocess`, `orders:expire`.
- **Tests**: 530+ tests, ~1900 assertions, incluyendo browser tests con Pest.

Lo que me falta es la **app Android** que captura las notificaciones push que los bancos envían al teléfono y las manda al backend. Es una app de infraestructura — no tiene usuarios, login, ni UI compleja.

### Lo que la app Android debe hacer:

1. **NotificationListenerService**: Escuchar notificaciones de apps bancarias específicas (BDV, BNC, Banesco, Mercantil, Provincial), extraer título + body, calcular SHA256(bank_code + body), guardar en SQLite local, hacer POST al backend.
2. **Heartbeat**: Cada N minutos, POST al backend con estado del dispositivo (batería, notificaciones pendientes).
3. **BootReceiver**: Al reiniciar el teléfono, reiniciar servicios automáticamente.
4. **Modo simulación**: Una pantalla simple con un botón para generar notificaciones de prueba y verificar el pipeline sin banco real.

### Stack técnico decidido:

| Componente | Decisión |
|-----------|----------|
| Lenguaje | Kotlin |
| UI | Jetpack Compose |
| Networking | Retrofit + OkHttp |
| DB local | Room |
| Tasks programadas | WorkManager |
| Inyección de dependencias | Manual / ServiceLocator simple |
| SDK mínimo | API 26 (Android 8.0) |

### Estado de los endpoints del backend:

NO existen aún — hay que crearlos:

- `POST /api/device/notifications` — ingesta de notificaciones (auth: X-Device-Token)
- `POST /api/device/heartbeat` — heartbeat (auth: X-Device-Token)
- Middleware `device.auth` — validación de token contra tabla `devices`
- Tabla `devices` + modelo `Device` — migración nueva en landlord DB
- Comando `CheckDeviceHeartbeats` — scheduled task que alerta si un dispositivo no hace heartbeat
- Migración a `payment_notifications` existente: agregar `device_id` nullable

Package IDs de bancos soportados (solo BDV y BNC verificados, los demás son estimaciones):
- BDV: `com.bancodevenezuela.bdvapp`
- BNC: `com.bnc.bncmovil`
- Banesco: `com.banesco.bancamovil`
- Mercantil: `com.synergygb.mercantil.tpago`
- Provincial: `com.dinerorapido.bancamovil`

### Mi nivel

No tengo experiencia previa con desarrollo Android. Conozco programación en general (PHP, JavaScript, React), pero nunca toqué Kotlin, Android Studio, ni el ecosistema Android.

### Preguntas concretas que necesito responder:

1. ¿Este plan de desarrollo es razonable o estoy subestimando/sobrestimando algo?
2. ¿Hayriesgos que no estoy viendo?
3. ¿Recomendarías algún cambio en el stack técnico o en el orden de las fases?
4. ¿Android Studio es obligatorio o puedo usar VS Code + plugins?
5. ¿Qué recomendaciones concretas darías para alguien que arranca de cero?

### Documentación generada del proyecto

Si necesitás más contexto, estos archivos existen en el repositorio:
- `docs/plan-conciliacion-automatica.md` — documentación viva de todo el backend de conciliación (Fases 0-7 y S8 completas)
- `docs/android-app-estrategia-desarrollo.md` — estrategia de desarrollo de la app Android
- `docs/s6-reverse-matching-flow.mmd` — diagrama Mermaid del flujo de matching forward + reverse
