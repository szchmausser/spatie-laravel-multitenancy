# App Android — Estrategia de Desarrollo

## Contexto

Este documento describe la estrategia para desarrollar la app Android que captura notificaciones push de bancos venezolanos y las envía al backend Laravel para el sistema de conciliación automática de PagoMóvil.

**Backend**: Ya está completo (Fases 0-7, S8a-S8f). Solo faltan los endpoints API para dispositivos Android.
**App Android**: Desde cero. Sin experiencia previa en Android.

---

## Setup del Entorno (notas de novato)

### ¿Qué es Android Studio?

Android Studio es el **IDE oficial de Android** (como VS Code para JavaScript, pero hecho por Google específicamente para Android). Viene con:

- Editor de código Kotlin/Java/XML
- **Emulador** de teléfonos (para probar apps sin teléfono físico)
- **Compilador** Gradle integrado
- Debugger, profiler, layout inspector
- Templates de proyectos

**No es opcional**. Sin Android Studio no podés compilar ni probar una app Android moderna.

Hay quien dice que se puede usar VS Code + plugins de Kotlin. Técnicamente se puede, pero en la práctica te vas a querer matar. Android Studio te da el emulador, el Logcat, el Layout Inspector, la gestión de SDKs y la compilación con Gradle integrada. VS Code solo te da el editor — todo lo demás lo tenés que configurar a mano desde terminal, y cuando algo falle (y va a fallar), vas a estar googleando errores de configuración en vez de aprendiendo Android.

**Descargar**: [developer.android.com/studio](https://developer.android.com/studio)

**Requisitos de hardware**:
- 8 GB RAM (16 GB si solo tenés 8 en tu máquina)
- 8 GB de espacio libre en disco (el IDE + SDKs ocupan ~4-5 GB)
- Cualquier procesador i5/i7/Ryzen de los últimos 5 años funciona

**Requisitos de software**: Solo Windows 10/11, macOS o Linux. Necesitás Java 17+ (el instalador lo maneja automáticamente).

### Instalación (primera vez)

1. Descargar el instalador de [developer.android.com/studio](https://developer.android.com/studio)
2. Ejecutar, elegir **"Standard"** (no customizar nada en la primera instalación)
3. El instalador baja el SDK, el emulador, y las herramientas necesarias automáticamente (~10-15 minutos)
4. Al terminar, Android Studio se abre y te muestra la pantalla de bienvenida

### Crear el proyecto por primera vez

1. Android Studio → "New Project"
2. Elegir **"Empty Activity"** → Next
3. Configurar:
   - **Name**: `PagoMovilCapture`
   - **Package name**: `com.spatie.pagomovilcapture`
   - **Language**: `Kotlin`
   - **Minimum SDK**: `API 26 (Android 8.0)` ← importante, no usar API 24

**¿Por qué API 26 y no API 24?**: Android 7.0 (API 24) es de 2016. Si elegís API 24, vas a tener que agregar `if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O)` en cada lugar que uses notificaciones o canales de notificación. Con API 26 eso ya no hace falta. Si tenés un teléfono físico con Android 7 que querés usar para pruebas, recién ahí considerá API 24.

4. Guardar el proyecto. La primera compilada descarga dependencias (Gradle, Kotlin, etc.) — tarda unos minutos.

### Cómo se usa Android Studio con OpenCode

Android Studio y OpenCode **no compiten, se complementan**:

```
OpenCode (escribir código)           Android Studio (compilar + probar)
     │                                        │
     │ "Creá el NotificationListener"         │ "Run" → compila APK
     │ → escribe el archivo .kt               │ → instala en teléfono
     │                                        │ → ejecuta la app
     │ "El Listener crashea en línea 45"      │ → muestra error en Logcat
     │ → arregla el código                    │
     │                                        │
     └─────────────── Loop ──────────────────┘
```

**Flujo típico de desarrollo**:

1. Abrís **OpenCode** en el directorio `android/` y le pedís que genere un archivo
2. OpenCode escribe el `.kt` en `app/src/main/java/com/spatie/pagomovilcapture/`
3. Cambiás a **Android Studio** → Android Studio detecta el cambio automáticamente
4. Click en **Run** (▶ verde) → compila, instala APK en el teléfono o emulador, abre la app
5. Si crashea → ves el error en **Logcat** (la consola de abajo en Android Studio)
6. Copiás el error, volvés a **OpenCode** y le decís "esto crashea con X, arreglalo"
7. OpenCode corrige el código → volvé al paso 3

### Probar en emulador vs teléfono físico

| | Emulador | Teléfono físico |
|--|----------|-----------------|
| Sirve para | Pantalla de simulación (MainActivity) | NotificationListenerService |
| No sirve para | NotificationListenerService (no tiene apps reales) | — |
| Cómo se usa | Android Studio crea un dispositivo virtual (Pixel 8, API 26) | USB + Opciones de desarrollador → Depuración USB |

**Consejo**: Para probar el NotificationListenerService sin banco real, instalá una app secundaria que genere notificaciones de prueba con los formatos BDV/BNC. Así el Listener las captura como si fueran del banco.

### Estructura de directorios recomendada

El proyecto Android va en **su propio directorio**, separado del backend Laravel:

```
proyecto/
├── laravel/                          ← El proyecto Laravel (PHP)
├── android/                          ← El proyecto Android (Kotlin)
│   ├── app/src/main/java/.../
│   ├── build.gradle.kts
│   └── ...
├── docs/
│   ├── plan-conciliacion-automatica.md
│   ├── android-app-estrategia-desarrollo.md
│   └── android-app-especificacion-tecnica.md
└── ...
```

**Importante**: Cuando uses OpenCode para la app Android, abrí la sesión en el directorio `android/`, **no** en el de Laravel. Son lenguajes, dependencias y ecosistemas completamente distintos. Si mezclás los dos en la misma sesión, el agente se va a confundir.

---

## ¿Qué necesita hacer la app?

Son **4 responsabilidades**, no más:

```
┌─────────────────────────────────────────────┐
│              App Android                     │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │ NotificationListenerService         │    │
│  │  • Escucha notificaciones de        │    │
│  │    paquetes bancarios específicos   │    │
│  │  • Extrae título + body             │    │
│  │  • Calcula SHA256 hash              │    │
│  │  • Guarda en SQLite local           │    │
│  │  • Hace POST al backend             │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │ HeartbeatService (worker)           │    │
│  │  • Cada N minutos: POST /heartbeat  │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │ BootReceiver                        │    │
│  │  • Al encender: restart servicios   │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │ Modo Simulación                     │    │
│  │  • Una Activity (pantalla) para     │    │
│  │    generar notificaciones fake      │    │
│  │    y probar el pipeline sin banco   │    │
│  └─────────────────────────────────────┘    │
└─────────────────────────────────────────────┘
```

**No tiene usuarios**, no tiene login, no tiene UI compleja. Es una app de infraestructura — corre en segundo plano y envía datos al backend.

---

## Decisiones de Arquitectura

### 1. Lenguaje: Kotlin

Kotlin es el estándar de Android desde 2019. Google apuesta todo ahí. Las APIs modernas (corrutinas, Flow) hacen que el código de red y base de datos sea 10x más legible que Java. No hay discusión posible.

### 2. UI: Jetpack Compose

Compose es el framework de UI moderno de Google. Para la pantalla de simulación (que es lo único con interfaz visual), no se necesitan más de ~30 líneas de código. XML ya es modo legacy para apps nuevas.

### 3. Networking: Retrofit + OkHttp

| Opción | Veredicto |
|--------|-----------|
| **Retrofit** | ✅ Recomendado. Estándar de la industria desde 2015. Interceptors para logs, retry, timeout. Comunidad enorme. |
| Ktor | ❌ No. Es multiplataforma (KMP), pero no vamos a compartir código con iOS porque no hay iOS. |

### 4. Base de datos local: Room

Room es el ORM oficial de Android sobre SQLite. Provee type safety en compile time, corrutinas integradas, migraciones automáticas y menos SQL crudo que mantener.

### 5. Inyección de dependencias: Manual / Service Locator (al inicio)

Para una app con ~4 componentes principales, Hilt es overkill. Arrancar con un `ServiceLocator` simple o inyección manual. Si el proyecto crece, Hilt se agrega sin romper nada.

### 6. Tareas programadas (Heartbeat): WorkManager

WorkManager es la API oficial para tareas programadas que deben sobrevivir a reinicios. Es la única opción correcta:

| Opción | Veredicto |
|--------|-----------|
| **WorkManager** | ✅ Recomendado. Sobrevive a reinicios, maneja constraints (battery, network), API moderna con corrutinas. |
| AlarmManager | ❌ No. Bajo nivel, propenso a errores, no sobrevive bien a reinicios en todas las ROMs. |
| Corrutinas solas | ❌ No. Mueren si el proceso es matado por el sistema. |

---

## Roadmap de Desarrollo

### Fase 0 — Aprendizaje mínimo viable (1-2 semanas)

Antes de tocar el proyecto real, completar los [Android Basics in Compose](https://developer.android.com/courses/android-basics-compose/course) de Google (oficial, gratuito).

**Módulos necesarios** (primeros 3):
1. Introducción a Kotlin y Compose
2. Layouts básicos, estados, eventos
3. Conexión a internet con Retrofit

**Qué entender al finalizar**:
- Qué es un Activity, un Service, un BroadcastReceiver
- Cómo funcionan las corrutinas básicas
- Cómo se usa Retrofit para un GET/POST
- Cómo se declaran permisos en AndroidManifest.xml

**NO estudiar en esta fase**: Jetpack Navigation, StateFlow avanzado, ViewModel, Hilt, Testing, Material Design 3. Solo el mínimo para entender el ciclo de vida.

---

### Fase 1 — Esqueleto: pantalla de simulación (1 semana)

Arrancar por la parte más fácil: un Activity con Compose que tiene:

- Un botón "Simular notificación"
- Campos de texto para: bank_code, raw_text, received_at
- Un TextView con el resultado (HTTP 200, error, etc.)

**Qué obliga a hacer**:
- Crear el proyecto en Android Studio
- Configurar Gradle con Retrofit + Room
- Definir el modelo de datos (`NotificationData`)
- Conectar con el backend (`POST /api/device/notifications`)
- Manejar respuesta del servidor (éxito vs 200 "duplicate_ignored")

**Verificación**: Botón en emulador envía notificación → backend la recibe y la guarda en `payment_notifications` (verificar con `php artisan tinker` o vistas del panel landlord).

**Prueba**: Sin teléfono físico. Solo emulador de Android Studio.

---

### Fase 2 — Almacenamiento local con Room (3-4 días)

Antes del NotificationListenerService:

**Definir tabla en Room**:

```kotlin
@Entity(tableName = "notifications")
data class NotificationEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,
    @ColumnInfo(name = "dedup_hash")
    val dedupHash: String,
    @ColumnInfo(name = "bank_code")
    val bankCode: String,
    @ColumnInfo(name = "raw_title")
    val rawTitle: String,
    @ColumnInfo(name = "raw_body")
    val rawBody: String,
    @ColumnInfo(name = "received_at")
    val receivedAt: Long,  // timestamp en millis
    @ColumnInfo(name = "delivered")
    val delivered: Boolean = false,
    @ColumnInfo(name = "retry_count")
    val retryCount: Int = 0,
    @ColumnInfo(name = "created_at")
    val createdAt: Long = System.currentTimeMillis()
)
```

**DAO mínimo**:
- `insert(notification)` — insertar nueva notificación
- `getPendingDeliveries(): List<NotificationEntity>` — notificaciones con `delivered = false`
- `markAsDelivered(dedupHash)` — marcar como enviada

**Verificación**: La pantalla de simulación escribe en Room, lee pendientes, muestra la cola local.

---

### Fase 3 — NotificationListenerService (2-3 semanas)

El componente central y más complejo.

**Implementación**:

1. Crear `PagoMovilNotificationListener` que extiende `NotificationListenerService`
2. Filtrar por `packageName` de los bancos soportados
3. En `onNotificationPosted()`:
   - Extraer `title` + `body` del `StatusBarNotification`
   - Mapear `packageName → bank_code` (lowercase)
   - Calcular SHA256: `SHA256(bank_code.lowercase() + raw_body)`
   - Guardar en Room
   - Intentar POST al backend
4. Manejo de errores:
   - Si POST falla: reintento exponencial (1min, 2min, 4min... hasta 32min, luego cada hora)
   - Si POST es 200 "duplicate_ignored": marcar como entregado sin reintentar
   - Si POST es 401 (token inválido): alertar y no reintentar
5. Servicio en primer plano (`FOREGROUND_SERVICE`) con notificación persistente en la barra de estado para evitar que Android lo mate.

**Package IDs de bancos**:

| Banco | Package ID |
|-------|-----------|
| BDV | `com.bancodevenezuela.bdvapp` |
| BNC | `com.bnc.bncmovil` |
| Banesco | `com.banesco.bancamovil` |
| Mercantil | `com.synergygb.mercantil.tpago` |
| Provincial | `com.dinerorapido.bancamovil` |

**Datos técnicos**:

```xml
<!-- AndroidManifest.xml -->
<uses-permission android:name="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

<service
    android:name=".PagoMovilNotificationListener"
    android:permission="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE"
    android:enabled="true"
    android:exported="false" />
```

**Dificultades conocidas**:

| Problema | Impacto | Mitigación |
|----------|---------|------------|
| Usuario debe activar permiso manualmente en Ajustes | Alto — sin esto el servicio no recibe nada | Abrir pantalla de accesibilidad con `Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)` |
| ROMs agresivas (Xiaomi MIUI, Huawei EMUI, Samsung One UI) | Alto — matan el servicio frecuentemente | Foreground service con notificación persistente + guía para desactivar optimización de batería |
| No se puede testear en emulador | Alto — no recibe notificaciones de bancos | Fase 1 de simulación permite probar pipeline sin banco real |
| Servicio puede ser matado en baja memoria | Medio — pérdida temporal de notificaciones | SQLite local buffer. Al reiniciar, envía pendientes. |

**Verificación**:
1. Instalar APK en teléfono físico
2. Activar permiso de acceso a notificaciones
3. Enviar una notificación de prueba desde otra app o usando `adb shell`
4. Verificar que aparece en la base local (Room)
5. Verificar que llega al backend

---

### Fase 4 — Heartbeat + BootReceiver (3-4 días)

#### HeartbeatWorker

```kotlin
class HeartbeatWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        return try {
            val api = RetrofitClient.getApi()
            val response = api.sendHeartbeat(
                deviceToken = deviceToken,
                batteryLevel = getBatteryLevel(),
                notificationsPending = getPendingCount()
            )
            if (response.isSuccessful) Result.success()
            else Result.retry()
        } catch (e: Exception) {
            Result.retry()
        }
    }
}
```

Registro en `Application.onCreate()` o `AndroidManifest.xml`:

```kotlin
val heartbeatRequest = PeriodicWorkRequestBuilder<HeartbeatWorker>(
    5, TimeUnit.MINUTES
).build()

WorkManager.getInstance(context)
    .enqueueUniquePeriodicWork(
        "heartbeat",
        ExistingPeriodicWorkPolicy.KEEP,
        heartbeatRequest
    )
```

#### BootReceiver

```kotlin
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            // Re-enqueue WorkManager tasks
            // Start foreground service
        }
    }
}
```

**Endpoint del backend**: `POST /api/device/heartbeat`
- Request: `{ device_token, battery_level, notifications_pending_count }`
- Response: `{ status: "ok", heartbeat_interval_minutes: N }`

---

### Fase 5 — Endpoints del backend (1-2 días, backend Laravel)

Estos endpoints los creamos en Laravel. Son necesarios **antes** de que la app Android funcione:

**Tabla `devices`** (migración nueva en landlord):

```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('token', 64)->unique();
    $table->timestamp('last_heartbeat_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Endpoints API**:

| Método | Ruta | Auth | Request | Response |
|--------|------|------|---------|----------|
| POST | `/api/device/notifications` | `X-Device-Token` header | `{ bank_code, title, body, received_at, dedup_hash }` | `{ status: "created" }` o `{ status: "duplicate_ignored" }` (200) |
| POST | `/api/device/heartbeat` | `X-Device-Token` header | `{ battery_level, notifications_pending_count }` | `{ status: "ok", heartbeat_interval_minutes: N }` |

**Middleware** `device.auth`: valida `X-Device-Token` contra `devices.token`. Si es inválido → 401.

**Migración a `payment_notifications` existente**: La tabla actual se creó sin `device_id` porque `devices` no existía. Hay que agregar la columna como nullable y actualizar los registros existentes (usando un device_id por defecto o dejando null).

**Comando** `CheckDeviceHeartbeats`: scheduled task que busca dispositivos cuyo `last_heartbeat_at` supere `interval * 2` minutos y genera una `SystemAlert` con `type = heartbeat_offline`, `severity = critical`.

---

## Lo que NADIE te va a decir de Android

### 1. Permiso de acceso a notificaciones

NotificationListenerService requiere que el usuario active el permiso manualmente:

```
Ajustes → Notificaciones → Acceso a notificaciones → [App Name]
```

No hay API para pedirlo programáticamente. Lo máximo que podés hacer es abrir la pantalla correcta con un `Intent`:

```kotlin
startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
```

Y esperar que el usuario sepa activarlo. Muchos no van a encontrar la opción.

### 2. ROMs chinas y coreanas matan servicios

| Marca | Comportamiento | Mitigación |
|-------|---------------|------------|
| Xiaomi (MIUI) | Auto-start desactivado por defecto. Mata servicios en 5-10 min. | Pedir desactivar optimización de batería + auto-start en Ajustes. |
| Huawei (EMUI) | Similar a MIUI. Protección de batería agresiva. | Misma mitigación. |
| Samsung (One UI) | Menos agresivo que MIUI pero aún mata servicios en reposo prolongado. | Foreground service + optimización de batería en "No optimizar". |

Esto no es opcional de manejar — es parte del desarrollo.

### 3. No podés testear en el emulador

El NotificationListenerService requiere notificaciones reales del sistema. El emulador no tiene apps bancarias instaladas. Necesitás:

- Un teléfono Android físico (cualquier Android 8+ funciona)
- `adb install` para subir el APK
- Poder cambiar `packageName` en testing para interceptar notificaciones de apps que vos controlás

**Estrategia de testing**: Instalar una app que vos mismo crees que mande notificaciones cada 30 segundos con formato BDV/BNC. Eso te permite iterar rápido sin depender del banco real.

### 4. SQLite local no es opcional

Si el POST al backend falla (teléfono sin internet, backend caído, timeout), la notificación se **pierde para siempre** si no la guardaste antes de intentar el envío.

Room te da:
- Guardado inmediato en `onNotificationPosted()`
- Reintento con WorkManager para los pendientes
- Marcado como "delivered" solo cuando el backend confirma (HTTP 200)

### 5. No necesitás Google Play Store

Para una app de infraestructura interna (monitorear un teléfono dedicado en la oficina), no necesitás pasar por validación de Google. Instalás el APK con `adb install` o lo distribuís por email/whatsapp como archivo `.apk`.

**Requisito**: Habilitar "Instalar apps de orígenes desconocidos" en el teléfono.

---

## Stack Tecnológico Completo

| Componente | Tecnología | Versión sugerida |
|------------|-----------|-----------------|
| Lenguaje | Kotlin | 1.9.x |
| SDK mínimo | API 26 (Android 8.0) | — |
| Compilacion SDK | API 35 (Android 15) | — |
| UI | Jetpack Compose | BOM 2024.x |
| Networking | Retrofit + OkHttp + Gson | Retrofit 2.9, OkHttp 4.12 |
| DB local | Room | 2.6.x |
| Tasks programadas | WorkManager | 2.9.x |
| Corrutinas | Kotlinx Coroutines | 1.7.x |
| IDE | Android Studio | Ladybug (2024.x) o posterior |

**build.gradle.kts (fragmento)**:

```kotlin
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("com.google.devtools.ksp") // para Room
}

android {
    namespace = "com.spatie.pagomovilcapture"
    compileSdk = 35
    minSdk = 26
    targetSdk = 35
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2024.10.00"))
    implementation("androidx.compose.material3:material3")
    implementation("androidx.activity:activity-compose:1.9.0")
    
    implementation("com.squareup.retrofit2:retrofit:2.9.0")
    implementation("com.squareup.retrofit2:converter-gson:2.9.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    
    implementation("androidx.room:room-runtime:2.6.1")
    implementation("androidx.room:room-ktx:2.6.1")
    ksp("androidx.room:room-compiler:2.6.1")
    
    implementation("androidx.work:work-runtime-ktx:2.9.0")
}
```

---

## Resumen del Roadmap

| Fase | Duración estimada | Dependencias | Hito verificable |
|------|-------------------|-------------|-----------------|
| **0. Aprendizaje** | 1-2 semanas | Nada | Completar 3 módulos de Android Basics in Compose |
| **1. Esqueleto + Simulación** | 1 semana | Fase 0 | Botón en emulador envía notificación → backend la recibe |
| **2. Room (almacenamiento local)** | 3-4 días | Fase 1 | Notificaciones persisten en SQLite local |
| **3. NotificationListenerService** | 2-3 semanas | Fase 1, 2 | Teléfono físico captura notificaciones reales y las envía |
| **4. Heartbeat + BootReceiver** | 3-4 días | Fase 3 | Backend recibe heartbeats periódicos |
| **5. Endpoints backend** | 1-2 días | Nada (backend Laravel) | API devices funcionando y migración de `payment_notifications` |

**Total estimado**: ~6-8 semanas desde cero a app funcionando.

---

## Referencias

- [Android Basics in Compose (Google)](https://developer.android.com/courses/android-basics-compose/course)
- [NotificationListenerService documentation](https://developer.android.com/reference/android/service/notification/NotificationListenerService)
- [Room database](https://developer.android.com/training/data-storage/room)
- [Retrofit](https://square.github.io/retrofit/)
- [WorkManager](https://developer.android.com/topic/libraries/architecture/workmanager)
- [Plan del sistema de conciliación](plan-conciliacion-automatica.md) — documentación del backend completo
- [Reverse matching flow](s6-reverse-matching-flow.mmd) — diagrama del flujo de conciliación
