# App Android — Especificación Técnica

> **Enfoque**: KISS + YAGNI. Mínimo viable, bien hecho, sin capas innecesarias.
> El NotificationListenerService habla DIRECTAMENTE con Room y Retrofit. No hay repositorios, ViewModels, ServiceLocators, ni Clean Architecture que no necesitamos para 4 componentes.

---

## 1. Resumen del Sistema

App Android que captura notificaciones push de bancos venezolanos y las envía al backend Laravel de conciliación automática de PagoMóvil.

**Backend**: Laravel 13 (completo — Fases 0-7, S8a-S8f)
**App**: Kotlin + Jetpack Compose, Android 8.0+

---

## 2. Stack

| Componente | Elección | Por qué esta y no otra |
|-----------|----------|----------------------|
| Lenguaje | **Kotlin** | El estándar Android. No hay alternativa real. |
| UI | **Jetpack Compose** | XML es legacy. Para 1 pantalla, Compose es 10 líneas. |
| Networking | **Retrofit + OkHttp** | El estándar. Ktor es para KMP (no tenemos iOS). |
| DB local | **Room** | El estándar sobre SQLite. Type-safe, corrutinas nativas. |
| Tareas programadas | **WorkManager** | Sobrevive a reinicios, constraints de red/batería. |
| Async | **Corrutinas** | Estándar Kotlin para async. |
| DI | **Nada** | Singletons con `object` alcanza y sobra para 4 componentes. |

---

## 3. Estructura del Proyecto (mínima)

```
com.spatie.pagomovilcapture/
├── App.kt                       # Application class (inicializa Room + WorkManager)
├── MainActivity.kt              # Pantalla de simulación (Compose, ~60 líneas)
├── NotificationListener.kt      # NotificationListenerService + envío directo
├── HeartbeatWorker.kt           # WorkManager para heartbeat periódico
├── BootReceiver.kt              # Reinicia servicios al boot
├── data/
│   ├── AppDatabase.kt           # Room: Entity + DAO + Database (1 archivo)
│   └── ApiService.kt            # Retrofit interface + client singleton
└── util/
    └── HashUtils.kt             # SHA256
```

**Total: 8 archivos de código**. Sin repositorios, sin ViewModels, sin ServiceLocators, sin DTOs separados, sin capas modulares.

---

## 4. API Contract

El backend Laravel expone 2 endpoints. Ninguno existe aún — hay que crearlos.

### POST /api/device/notifications

**Auth**: Header `X-Device-Token`

```
Request:
{
  "bank_code": "bdv",
  "body": "BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Ref:603185603",
  "received_at": "2026-06-23T10:30:00Z",
  "dedup_hash": "a1b2c3d4e5f6..."
}

201 → { "status": "created" }
200 → { "status": "duplicate_ignored" }  (hash ya existía)
401 → { "error": "Invalid device token" }
422 → { "error": "...", "errors": {...} }
```

### POST /api/device/heartbeat

**Auth**: Header `X-Device-Token`

```
Request:
{
  "battery_level": 85,
  "notifications_pending_count": 3
}

200 → { "status": "ok", "heartbeat_interval_minutes": 5 }
401 → { "error": "Invalid device token" }
```

---

## 5. Room Database (1 archivo)

```kotlin
// data/AppDatabase.kt

import androidx.room.*

@Entity(tableName = "notifications")
data class NotificationEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    @ColumnInfo(name = "dedup_hash") val dedupHash: String,
    @ColumnInfo(name = "bank_code") val bankCode: String,
    @ColumnInfo(name = "raw_title") val rawTitle: String = "",
    @ColumnInfo(name = "raw_body") val rawBody: String,
    @ColumnInfo(name = "received_at") val receivedAt: Long,
    @ColumnInfo(name = "delivered") val delivered: Boolean = false,
    @ColumnInfo(name = "retry_count") val retryCount: Int = 0,
    @ColumnInfo(name = "last_attempt_at") val lastAttemptAt: Long? = null,
    @ColumnInfo(name = "created_at") val createdAt: Long = System.currentTimeMillis()
)

@Dao
interface NotificationDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(notification: NotificationEntity): Long

    @Query("SELECT * FROM notifications WHERE delivered = 0 ORDER BY created_at ASC")
    suspend fun getPending(): List<NotificationEntity>

    @Query("UPDATE notifications SET delivered = 1 WHERE dedup_hash = :hash")
    suspend fun markDelivered(hash: String)

    @Query("UPDATE notifications SET retry_count = retry_count + 1, last_attempt_at = :now WHERE dedup_hash = :hash")
    suspend fun incrementRetry(hash: String, now: Long = System.currentTimeMillis())

    @Query("SELECT COUNT(*) FROM notifications WHERE delivered = 0")
    suspend fun pendingCount(): Int
}

@Database(entities = [NotificationEntity::class], version = 1)
abstract class AppDatabase : RoomDatabase() {
    abstract fun notificationDao(): NotificationDao

    companion object {
        @Volatile private var INSTANCE: AppDatabase? = null

        fun getInstance(context: Context): AppDatabase {
            return INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "pagomovil.db"
                ).build().also { INSTANCE = it }
            }
        }
    }
}
```

---

## 6. Retrofit Client (1 archivo)

```kotlin
// data/ApiService.kt

import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.Body
import retrofit2.http.POST
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import com.google.gson.annotations.SerializedName
import java.util.concurrent.TimeUnit

// ---------- DTOs inline (no merecen archivo propio) ----------
data class NotificationRequest(
    @SerializedName("bank_code") val bankCode: String,
    val body: String,
    @SerializedName("received_at") val receivedAt: String,
    @SerializedName("dedup_hash") val dedupHash: String,
)

data class HeartbeatRequest(
    @SerializedName("battery_level") val batteryLevel: Int,
    @SerializedName("notifications_pending_count") val pendingCount: Int,
)

data class StatusResponse(val status: String)

data class HeartbeatResponse(
    val status: String,
    @SerializedName("heartbeat_interval_minutes") val heartbeatIntervalMinutes: Int,
)

// ---------- Interface Retrofit ----------
interface ApiService {
    @POST("api/device/notifications")
    suspend fun sendNotification(@Body request: NotificationRequest): retrofit2.Response<StatusResponse>

    @POST("api/device/heartbeat")
    suspend fun sendHeartbeat(@Body request: HeartbeatRequest): retrofit2.Response<HeartbeatResponse>
}

// ---------- Singleton ----------
object ApiClient {
    // CAMBIAR: poner URL real del backend
    private const val BASE_URL = "https://spatie-laravel-multitenancy.test/"

    private val okHttp = OkHttpClient.Builder()
        .addInterceptor(HttpLoggingInterceptor().setLevel(HttpLoggingInterceptor.Level.BODY))
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    val service: ApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(okHttp)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ApiService::class.java)
    }
}
```

---

## 7. NotificationListenerService (el núcleo)

Hace TODO directamente: recibe la notificación → guarda en Room → intenta POST → maneja resultado.

```kotlin
// NotificationListener.kt

import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.app.NotificationManager
import android.app.NotificationChannel
import android.app.PendingIntent
import android.content.Intent
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.*
import java.text.SimpleDateFormat
import java.util.*

class NotificationListener : NotificationListenerService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    // Los únicos packages que nos interesan
    private val supportedPackages = setOf(
        "com.bancodevenezuela.bdvapp",
        "com.bnc.bncmovil",
        "com.banesco.bancamovil",
        "com.synergygb.mercantil.tpago",
        "com.dinerorapido.bancamovil",
    )

    // Mapeo packageName → bank_code
    private fun bankCodeFor(packageName: String): String? = when (packageName) {
        "com.bancodevenezuela.bdvapp" -> "bdv"
        "com.bnc.bncmovil" -> "bnc"
        "com.banesco.bancamovil" -> "banesco"
        "com.synergygb.mercantil.tpago" -> "mercantil"
        "com.dinerorapido.bancamovil" -> "provincial"
        else -> null
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        val packageName = sbn.packageName
        val bankCode = bankCodeFor(packageName) ?: return  // No es un banco que nos interese

        val title = sbn.notification.extras.getString(android.app.Notification.EXTRA_TITLE) ?: ""
        val body = sbn.notification.extras.getString(android.app.Notification.EXTRA_TEXT) ?: return

        // SHA256(bank_code_lowercase + body) — debe coincidir con el backend
        val hash = HashUtils.sha256("$bankCode$body")

        scope.launch {
            try {
                val db = AppDatabase.getInstance(this@NotificationListener)
                val dao = db.notificationDao()

                // 1. Guardar en Room SIEMPRE primero (buffer de seguridad)
                dao.insert(NotificationEntity(
                    dedupHash = hash,
                    bankCode = bankCode,
                    rawTitle = title,
                    rawBody = body,
                    receivedAt = sbn.postTime,
                ))

                // 2. Intentar envío
                val token = getDeviceToken()
                if (token.isNullOrEmpty()) return@launch  // No hay token configurado

                val dateStr = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                }.format(Date(sbn.postTime))

                val response = ApiClient.service.sendNotification(
                    NotificationRequest(
                        bankCode = bankCode,
                        body = body,
                        receivedAt = dateStr,
                        dedupHash = hash,
                    )
                )

                if (response.isSuccessful) {
                    dao.markDelivered(hash)
                } else if (response.code() == 401) {
                    // Token inválido — no reintentar, marcar como falla permanente
                    dao.incrementRetry(hash)
                } else if (response.code() >= 500) {
                    // Error del servidor — reintentar después
                    dao.incrementRetry(hash)
                }
                // 422 o 200 duplicate → no hacer nada (ya se guardó en Room)
            } catch (e: Exception) {
                // Error de red — Room ya tiene la notificación, SyncWorker la reintentará
            }
        }
    }

    override fun onListenerConnected() {
        super.onListenerConnected()
        startForeground(NOTIFICATION_ID, createPersistentNotification())
        // Enviar pendientes acumulados
        scope.launch { retryPending() }
    }

    private suspend fun retryPending() {
        val dao = AppDatabase.getInstance(this).notificationDao()
        val pending = dao.getPending()
        for (notification in pending) {
            // mismo envío que en onNotificationPosted
            // ... (extraído a función privada)
        }
    }

    // ---------- Foreground service ----------
    private fun createPersistentNotification(): android.app.Notification {
        val channelId = "pagomovil_capture"
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
            (getSystemService(NOTIFICATION_SERVICE) as NotificationManager).createNotificationChannel(
                NotificationChannel(channelId, "Captura de pagos", NotificationManager.IMPORTANCE_LOW).apply {
                    description = "Notificación persistente para mantener el servicio activo"
                }
            )
        }
        return NotificationCompat.Builder(this, channelId)
            .setContentTitle("Capturando notificaciones")
            .setContentText("Monitoreando pagos bancarios...")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setOngoing(true)
            .build()
    }

    private fun getDeviceToken(): String? {
        return getSharedPreferences("app", MODE_PRIVATE).getString("device_token", null)
    }

    companion object {
        private const val NOTIFICATION_ID = 1001
    }
}
```

**Flujo completo**: recibe → Room → POST. Sin intermediarios. Sin repositorios. 60 líneas efectivas.

---

## 8. Manejo de Reintentos (SyncWorker inline)

Los reintentos NO necesitan un worker separado del heartbeat. Se puede hacer TODO en el HeartbeatWorker para simplificar:

```kotlin
// HeartbeatWorker.kt

import android.content.Context
import android.os.BatteryManager
import androidx.work.*

class HeartbeatWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val dao = AppDatabase.getInstance(applicationContext).notificationDao()
        val token = getDeviceToken()

        // 1. Reintentar notificaciones pendientes
        if (token != null) {
            val pending = dao.getPending()
            for (notification in pending) {
                try {
                    val dateStr = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
                        timeZone = TimeZone.getTimeZone("UTC")
                    }.format(Date(notification.receivedAt))

                    val response = ApiClient.service.sendNotification(
                        NotificationRequest(
                            bankCode = notification.bankCode,
                            body = notification.rawBody,
                            receivedAt = dateStr,
                            dedupHash = notification.dedupHash,
                        )
                    )
                    if (response.isSuccessful) {
                        dao.markDelivered(notification.dedupHash)
                    } else if (response.code() != 401) {
                        dao.incrementRetry(notification.dedupHash)
                    }
                } catch (_: Exception) {
                    dao.incrementRetry(notification.dedupHash)
                }
            }
        }

        // 2. Enviar heartbeat
        return try {
            val battery = (applicationContext.getSystemService(Context.BATTERY_SERVICE) as? BatteryManager)
                ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY) ?: -1
            val pendingCount = dao.pendingCount()

            val response = ApiClient.service.sendHeartbeat(
                HeartbeatRequest(batteryLevel = battery, pendingCount = pendingCount)
            )
            if (response.isSuccessful) Result.success() else Result.retry()
        } catch (_: Exception) {
            if (runAttemptCount < 3) Result.retry() else Result.failure()
        }
    }

    private fun getDeviceToken(): String? {
        return applicationContext.getSharedPreferences("app", MODE_PRIVATE)
            .getString("device_token", null)
    }

    companion object {
        fun schedule(context: Context) {
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                "heartbeat",
                ExistingPeriodicWorkPolicy.REPLACE,
                PeriodicWorkRequestBuilder<HeartbeatWorker>(5, TimeUnit.MINUTES)
                    .setConstraints(Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build())
                    .build()
            )
        }
    }
}
```

**Un solo worker** hace heartbeat + reintenta pendientes. Simple. Directo. No hay `SyncWorker` separado.

---

## 9. BootReceiver

```kotlin
// BootReceiver.kt

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.core.content.ContextCompat

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            // Reiniciar servicio de escucha
            ContextCompat.startForegroundService(
                context,
                Intent(context, NotificationListener::class.java)
            )
            // Reprogramar heartbeat
            HeartbeatWorker.schedule(context)
        }
    }
}
```

---

## 10. Pantalla de Simulación (MainActivity)

```kotlin
// MainActivity.kt

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.*

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Abrir ajustes de acceso a notificaciones si no tiene permiso
        // startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))

        setContent {
            MaterialTheme {
                SimulationScreen()
            }
        }
    }
}

@Composable
fun SimulationScreen() {
    val scope = rememberCoroutineScope()
    var bankCode by remember { mutableStateOf("bdv") }
    var body by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var pendingCount by remember { mutableStateOf(0) }

    // Ejemplos precargados para testing rápido
    val ejemplos = mapOf(
        "bdv" to "Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40",
        "bnc" to "BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion",
    )

    Column(modifier = Modifier.padding(16.dp).fillMaxSize()) {
        Text("Simulador PagoMóvil", style = MaterialTheme.typography.headlineSmall)

        Spacer(Modifier.height(16.dp))

        // Selector de banco
        OutlinedTextField(
            value = bankCode,
            onValueChange = { bankCode = it },
            label = { Text("Banco (bdv / bnc)") },
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(8.dp))

        // Botones para precargar ejemplos
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { body = ejemplos["bdv"] ?: "" }) { Text("BDV") }
            Button(onClick = { body = ejemplos["bnc"] ?: "" }) { Text("BNC") }
        }

        Spacer(Modifier.height(8.dp))

        // Campo de texto
        OutlinedTextField(
            value = body,
            onValueChange = { body = it },
            label = { Text("Texto de la notificación") },
            modifier = Modifier.fillMaxWidth().height(200.dp),
        )

        Spacer(Modifier.height(16.dp))

        // Botón de enviar
        Button(
            onClick = {
                scope.launch {
                    status = "Enviando..."
                    try {
                        val hash = HashUtils.sha256("$bankCode$body")
                        val dao = AppDatabase.getInstance(this@SimulationScreen::class.java.hashCode() /* context hack, ver nota */).notificationDao()

                        dao.insert(NotificationEntity(
                            dedupHash = hash, bankCode = bankCode, rawBody = body,
                            receivedAt = System.currentTimeMillis(),
                        ))

                        // Enviar al backend
                        val response = ApiClient.service.sendNotification(
                            NotificationRequest(
                                bankCode = bankCode, body = body,
                                receivedAt = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
                                    timeZone = TimeZone.getTimeZone("UTC")
                                }.format(Date()),
                                dedupHash = hash,
                            )
                        )
                        status = if (response.isSuccessful) "✓ Enviado: ${response.body()?.status}"
                                 else "✗ Error HTTP ${response.code()}"
                    } catch (e: Exception) {
                        status = "✗ Error: ${e.message}"
                    }
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Simular y Enviar")
        }

        Spacer(Modifier.height(8.dp))

        Text(status)

        Spacer(Modifier.height(16.dp))

        // Contador de pendientes
        Button(onClick = {
            scope.launch {
                val dao = AppDatabase.getInstance(applicationContext).notificationDao()
                pendingCount = dao.pendingCount()
            }
        }) { Text("Ver pendientes: $pendingCount") }
    }
}
```

> **Nota**: La Activity necesita acceso al ApplicationContext para Room. En la versión real, pasar `applicationContext` desde la Activity en vez del hack de hash.

---

## 11. App.kt

```kotlin
// App.kt

import android.app.Application
import androidx.work.Configuration

class App : Application(), Configuration.Provider {
    override fun onCreate() {
        super.onCreate()
        HeartbeatWorker.schedule(this)
    }

    // WorkManager configuración por defecto suficiente
    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder().build()
}
```

---

## 12. Manifest

```xml
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <uses-permission android:name="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_DATA_SYNC" />
    <uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />

    <application
        android:name=".App"
        android:allowBackup="true"
        android:label="Capturador PagoMóvil"
        android:supportsRtl="true"
        android:theme="@style/Theme.PagoMovilCapture">

        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:label="Simulación">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>

        <service
            android:name=".NotificationListener"
            android:permission="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE"
            android:enabled="true"
            android:exported="false"
            android:foregroundServiceType="dataSync" />

        <receiver
            android:name=".BootReceiver"
            android:enabled="true"
            android:exported="false">
            <intent-filter>
                <action android:name="android.intent.action.BOOT_COMPLETED" />
            </intent-filter>
        </receiver>

    </application>
</manifest>
```

---

## 13. build.gradle.kts

```kotlin
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("com.google.devtools.ksp")
}

android {
    namespace = "com.spatie.pagomovilcapture"
    compileSdk = 35
    minSdk = 26
    targetSdk = 35

    buildFeatures { compose = true }
    composeOptions { kotlinCompilerExtensionVersion = "1.5.15" }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    // Compose
    val composeBom = platform("androidx.compose:compose-bom:2024.10.00")
    implementation(composeBom)
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.activity:activity-compose:1.9.0")

    // Retrofit
    implementation("com.squareup.retrofit2:retrofit:2.9.0")
    implementation("com.squareup.retrofit2:converter-gson:2.9.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")

    // Room
    implementation("androidx.room:room-runtime:2.6.1")
    implementation("androidx.room:room-ktx:2.6.1")
    ksp("androidx.room:room-compiler:2.6.1")

    // WorkManager
    implementation("androidx.work:work-runtime-ktx:2.9.0")

    // Coroutines
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3")

    // Core
    implementation("androidx.core:core-ktx:1.13.0")
}
```

---

## 14. Backend Laravel (por crear)

### 14.1 Migración: `create_devices_table`

```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('bank_code');
    $table->string('token', 64)->unique();
    $table->timestamp('last_heartbeat_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 14.2 Migración: `add_device_id_to_payment_notifications`

```php
Schema::table('payment_notifications', function (Blueprint $table) {
    $table->foreignId('device_id')->nullable()->after('id')
        ->constrained('devices')->nullOnDelete();
});
```

### 14.3 Modelo `Device`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Device extends Model
{
    use UsesLandlordConnection;

    protected $fillable = ['name', 'token', 'last_heartbeat_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
```

### 14.4 Middleware `device.auth`

```php
namespace App\Http\Middleware;

use App\Models\Device;
use Closure;

class DeviceAuth
{
    public function handle($request, Closure $next)
    {
        $token = $request->header('X-Device-Token');

        if (!$token) {
            return response()->json(['error' => 'Missing X-Device-Token header'], 401);
        }

        $device = Device::where('token', $token)->where('is_active', true)->first();

        if (!$device) {
            return response()->json(['error' => 'Invalid device token'], 401);
        }

        $request->merge(['device' => $device]);

        return $next($request);
    }
}
```

### 14.5 DeviceController

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestPaymentNotification;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct()
    {
        $this->middleware('device.auth');
    }

    public function storeNotification(Request $request)
    {
        $validated = $request->validate([
            'bank_code' => 'required|string|max:50',
            'body' => 'required|string',
            'received_at' => 'nullable|date',
            'dedup_hash' => 'required|string|max:64',
        ]);

        $device = $request->get('device');

        try {
            $notification = PaymentNotification::create([
                'device_id' => $device->id,
                'bank_code' => strtolower($validated['bank_code']),
                'raw_text' => $validated['body'],
                'received_at' => $validated['received_at'] ?? now(),
                'dedup_hash' => $validated['dedup_hash'],
                'parse_status' => 'pending',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23505') { // unique_violation
                return response()->json(['status' => 'duplicate_ignored'], 200);
            }
            throw $e;
        }

        IngestPaymentNotification::dispatch($notification);

        return response()->json(['status' => 'created'], 201);
    }

    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'battery_level' => 'nullable|integer|min:0|max:100',
            'notifications_pending_count' => 'nullable|integer|min:0',
        ]);

        $device = $request->get('device');
        $device->update(['last_heartbeat_at' => now()]);

        $interval = (int) SystemConfig::get('devices.heartbeat_interval_minutes', 5);

        return response()->json([
            'status' => 'ok',
            'heartbeat_interval_minutes' => $interval,
        ]);
    }
}
```

### 14.6 Rutas

```php
// routes/api.php
Route::prefix('device')->middleware('device.auth')->group(function () {
    Route::post('/notifications', [DeviceController::class, 'storeNotification']);
    Route::post('/heartbeat', [DeviceController::class, 'heartbeat']);
});
```

### 14.7 Comando CheckDeviceHeartbeats

```php
namespace App\Console\Commands;

use App\Models\Device;
use App\Models\SystemConfig;
use App\Notifications\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckDeviceHeartbeats extends Command
{
    protected $signature = 'devices:check-heartbeats';
    protected $description = 'Detecta dispositivos offline';

    public function handle(): int
    {
        $interval = (int) SystemConfig::get('devices.heartbeat_interval_minutes', 5);
        $threshold = now()->subMinutes($interval * 2);

        $offline = Device::where('is_active', true)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', $threshold);
            })->get();

        if ($offline->isEmpty()) {
            $this->info('Todos los dispositivos están online.');
            return self::SUCCESS;
        }

        $admins = \App\Models\Landlord::all();

        foreach ($offline as $device) {
            Notification::send($admins, new SystemAlert(
                type: 'heartbeat_offline',
                severity: 'critical',
                title: "Dispositivo {$device->name} offline",
                message: "El teléfono {$device->name} no ha enviado heartbeat desde hace más de " . ($interval * 2) . " minutos.",
                metadata: ['device_id' => $device->id],
            ));
            $this->warn("Device #{$device->id} {$device->name} offline.");
        }

        return self::SUCCESS;
    }
}
```

---

## 15. Resumen: Archivos a Crear

### Android (8 archivos)

| Archivo | Líneas estimadas |
|---------|-----------------|
| `App.kt` | ~15 |
| `MainActivity.kt` | ~90 |
| `NotificationListener.kt` | ~110 |
| `HeartbeatWorker.kt` | ~70 |
| `BootReceiver.kt` | ~15 |
| `data/AppDatabase.kt` | ~60 |
| `data/ApiService.kt` | ~60 |
| `util/HashUtils.kt` | ~10 |
| **Total** | **~430 líneas** |

### Backend Laravel (5 archivos + 2 migraciones)

| Archivo | Propósito |
|---------|-----------|
| `..._create_devices_table.php` | Migración |
| `..._add_device_id_to_payment_notifications.php` | Migración |
| `app/Models/Device.php` | Modelo |
| `app/Http/Middleware/DeviceAuth.php` | Middleware |
| `app/Http/Controllers/Api/DeviceController.php` | Controller |
| `routes/api.php` | +2 rutas |
| `app/Console/Commands/CheckDeviceHeartbeats.php` | Comando |

---

## 16. Lo que NO tiene (YAGNI aplicado)

| Lo que la versión anterior tenía | Esta versión |
|----------------------------------|-------------|
| Repository layer | ❌ El Listener llama a DAO directo |
| ServiceLocator / DI | ❌ Singletons con `object` |
| ViewModel | ❌ Estado con `remember` en Compose |
| DTOs separados | ❌ Inline en ApiService.kt |
| SyncWorker separado | ❌ Unido al HeartbeatWorker |
| runForward() / runReverse() | ❌ Solo `run()` en el orquestador |
| match_type column | ❌ No existe en el schema |
| Capa de presentación separada | ❌ Una función `@Composable` |

---

## 17. Roadmap de Implementación

| Fase | Qué se hace | Archivos Android | Dependencia backend |
|------|------------|-----------------|-------------------|
| 1 | Endpoints backend + migraciones | — | Dispositivos creados vía tinker |
| 2 | Room + Retrofit + MainActivity | `AppDatabase.kt`, `ApiService.kt`, `MainActivity.kt`, `HashUtils.kt`, `App.kt` | Endpoints listos |
| 3 | NotificationListenerService | `NotificationListener.kt`, `BootReceiver.kt` | Endpoints listos |
| 4 | Heartbeat + reintentos | `HeartbeatWorker.kt` | Endpoints listos |
| 5 | Integración + testing en físico | Todos | Endpoints + scheduler |
