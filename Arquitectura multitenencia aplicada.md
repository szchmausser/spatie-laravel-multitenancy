# Guía de Implementación: Multitenancy en Laravel 13 con Spatie Multitenancy v4

> **Stack:** Laravel 13, React (Inertia.js), PostgreSQL, Spatie Multitenancy v4, Laragon (Windows).
> **Base de datos central:** `spatie-laravel-multitenancy`

---

## 0. Crear la BD del landlord

Crear en PostgreSQL la base de datos central que albergará los datos del landlord, las sesiones, caché, jobs y la tabla `tenants`:

```sql
CREATE DATABASE "spatie-laravel-multitenancy";
```

---

## 1. Configurar el `.env`

```env
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
# En local con Laragon, usar el dominio en vez de localhost para que las URLs
# absolutas de archivos (avatares, imágenes subidas) resuelvan correctamente.
APP_URL=http://spatie-laravel-multitenancy.test

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# La conexión por defecto DEBE ser landlord
DB_CONNECTION=pgsql

# Datos de la Base de Datos Central (Principal)
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=spatie-laravel-multitenancy
DB_USERNAME=postgres
DB_PASSWORD=postgres

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
```

> **Punto clave:** `DB_CONNECTION=pgsql` apunta a la BD del landlord. Laravel usa esta conexión por defecto para sesiones, caché, jobs, etc.

> **Nota sobre el `.env` completo:** El bloque de arriba muestra **solo las claves relevantes para multitenancy**. Un `.env` recién generado por el starter kit también tiene claves de mail, redis, vite, aws, etc., que se mantienen con sus valores default y no necesitan tocarse para que la multitenancy funcione.

> **Entorno de testing:** El proyecto usa `.env.testing` (gitignoreado) con sus propios valores. Ver §22 para el detalle completo. `phpunit.xml` solo define `APP_ENV=testing`; el resto de la config de testing vive en `.env.testing`.

---

## 2. Migración inicial del proyecto base

```bash
php artisan migrate:fresh
```

Esto crea las tablas base (`users`, `sessions`, `cache`, `jobs`) en la BD landlord. Verificar que el login funciona en `http://spatie-laravel-multitenancy.test`.

---

## 3. Instalar Spatie Multitenancy y dependencias adicionales

```bash
composer require spatie/laravel-multitenancy
php artisan vendor:publish --provider="Spatie\Multitenancy\MultitenancyServiceProvider" --tag="multitenancy-config"
```

Dependencias adicionales para filesystem isolation por tenant y avatar upload:

```bash
composer require spatie/laravel-medialibrary "^11.23"
composer require league/flysystem-path-prefixing
```

> `spatie/laravel-medialibrary` maneja la subida de imágenes de perfil (avatares) con conversiones (thumbnails). `league/flysystem-path-prefixing` permite el disco `scoped` que wrappea el disco `public` con un prefijo dinámico por tenant (ver §19.5).

### 3.1 Publicar config de medialibrary

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="media-library-config"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="media-library-migrations"
php artisan migrate --database=landlord
```

> La migración de `media` se corre en la BD landlord (y en cada BD tenant vía el callback de provisioning). La tabla `media` vive donde se suban los archivos; en el caso del avatar, que es del usuario, se guarda en la BD del tenant activo o en la BD landlord según quién suba el archivo.

---

## 4. Configurar `config/multitenancy.php`

Los valores relevantes a modificar:

```php
'tenant_finder' => DomainTenantFinder::class,

'switch_tenant_tasks' => [
    \Spatie\Multitenancy\Tasks\PrefixCacheTask::class,       // Prefijo tenant_{id}_ en cache keys
    SwitchTenantDatabaseTask::class,                         // BD dedicada por tenant
    \App\Multitenancy\Tasks\SwitchFilesystemTask::class,     // Directorio tenant_{id} en filesystem (ver §19.5)
    \App\Multitenancy\Tasks\SwitchTenantLoggingTask::class,  // tenant_id en contexto de log (ver §19.6)
    // \Spatie\Multitenancy\Tasks\SwitchRouteCacheTask::class,
],

'tenant_database_connection_name' => 'tenant',
'landlord_database_connection_name' => 'landlord',
```

---

## 5. Configurar `config/database.php`

Agregar las conexiones `landlord` y `tenant`:

```php
'connections' => [
    // ... conexiones default (sqlite, mysql, etc.) ...

    /** Conexión default de Laravel (sesiones, caché, jobs, etc.)
     * Apunta a la misma BD que landlord. Mantenida separada para
     * no interferir con el driver interno de Laravel. */
    'pgsql' => [
        'driver' => 'pgsql',
        'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'laravel'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => env('DB_SSLMODE', 'prefer'),
    ],

    /** Conexión landlord — Spatie la usa para leer la tabla tenants
     * y para modelos con UsesLandlordConnection.
     * Apunta a la misma BD que pgsql, pero con nombre semántico propio. */
    'landlord' => [
        'driver' => 'pgsql',
        'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'laravel'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => env('DB_SSLMODE', 'prefer'),
    ],

    /** Conexión dinámica (tenant).
     * Spatie inyecta aquí el nombre de la BD del tenant activo
     * en tiempo de ejecución. database DEBE ser null. */
    'tenant' => [
        'driver' => 'pgsql',
        'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => null, // OBLIGATORIO: Spatie inyectará el nombre de la BD aquí. Se establecerá dinámicamente en tiempo de ejecución
        'username' => env('DB_USERNAME', 'root'), // Mismo usuario con permisos para leer/manipular la base de datos del inquilino
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => env('DB_SSLMODE', 'prefer'),
    ],
],
```

> **Punto clave:** La conexión `tenant` tiene `database => null` porque Spatie la resuelve dinámicamente según el tenant activo.

**`config/media-library.php`** — configuración de spatie/laravel-medialibrary. El valor clave para multitenancy es `disk_name`, que se sobreescribe en runtime por `SwitchFilesystemTask` (ver §19.5). Las configs relevantes:

```php
return [
    'disk_name' => env('MEDIA_DISK', 'public'),
    'conversions_disk_name' => env('MEDIA_CONVERSIONS_DISK', null),
    'max_file_size' => 1024 * 1024 * 10, // 10MB
    'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),
    'queue_conversions_by_default' => env('QUEUE_CONVERSIONS_BY_DEFAULT', true),
    'media_model' => Spatie\MediaLibrary\MediaCollections\Models\Media::class,
    'image_driver' => env('IMAGE_DRIVER', 'gd'),
    'default_loading_attribute_value' => null,
    'prefix' => env('MEDIA_PREFIX', ''),
];
```

> **Punto clave:** El resto de las 360+ líneas de `config/media-library.php` son configs de optimización de imágenes, conversiones, responsive images, etc. — no relevantes para multitenancy. El archivo completo incluye generadores de thumbnail, optimizers (Jpegoptim, Pngquant, Svgo, etc.) y configs de FFMPEG para video, pero ninguna de esas opciones afecta el aislamiento entre tenants.

---

## 6. Crear y correr la migración de la tabla `tenants`

> **Por qué manual y no `vendor:publish`:** Spatie publica una migración de `tenants` por default, pero solo la auto-ejecuta si el modelo Tenant tiene `runsMigrations(true)`. En este proyecto el `creating` callback del modelo (sección 11) es quien corre las migraciones de los tenants, así que NO activamos `runsMigrations(true)` para no duplicar. Por eso la tabla `tenants` de la BD landlord la creamos con una migración manual nuestra.

Crear `database/migrations/landlord/2026_05_29_183736_create_landlord_tenants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('database')->unique();
            $table->timestamps();
        });
    }
};
```

```bash
php artisan migrate --path=database/migrations/landlord --database=landlord
```

Esto crea la tabla `tenants` en la BD landlord.

---

## 7. Configurar `bootstrap/app.php`

```php
<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Override del alias `guest` para que el redirect post-guest sea
        // role-aware: landlord → /admin, user → /dashboard. Sin esto,
        // un landlord autenticado que visite /login sería redirigido
        // a /dashboard (defaultRedirectUri del default), que no le
        // corresponde. El middleware vive en app/Http/Middleware/
        // RedirectIfAuthenticated.php — extiende el default de Laravel
        // y override del único método que computa el destino.
        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->group('tenant', [
            NeedsTenant::class,
            EnsureValidTenantSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
```

---

## 8. Configurar `routes/web.php`

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1. Rutas públicas
|--------------------------------------------------------------------------
| Sin autenticación. Accesibles desde cualquier dominio (principal o
| subdominio de tenant). Para welcome, marketing, etc.
|
| NOTA: login, register, password reset, 2FA, etc. los maneja Fortify
| automáticamente, no se definen acá.
*/
Route::inertia('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| 2. Rutas compartidas — autenticadas, sin distinción de rol
|--------------------------------------------------------------------------
| Requieren auth + email verificado. Accesibles tanto para Landlord
| como para User, desde cualquier dominio.
|
| Uso típico: edición de perfil propio, sesiones del navegador, etc.
| Definidas en settings.php.
*/
require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| 3. Rutas del producto SaaS (tenants)
|--------------------------------------------------------------------------
| Middleware: tenant + auth + verified
|
| - `tenant`: el request debe llegar desde un subdominio de tenant
|   activo. Sin tenant, retorna 404 (defensa: un Landlord en el
|   dominio principal no puede acceder a estas rutas).
| - `auth` + `verified`: el usuario debe estar logueado y con email
|   verificado.
|
| El `dashboard` del tenant vive acá, NO en el grupo compartido.
*/
Route::middleware(['tenant', 'auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Aquí van las rutas del producto SaaS para cada cliente.
});

/*
|--------------------------------------------------------------------------
| 4. Rutas del admin (landlord)
|--------------------------------------------------------------------------
| Middleware: auth + verified + EnsureUserIsAdmin
|
| Accesibles SOLO desde el dominio principal (sin subdominio de tenant).
| Protegidas por EnsureUserIsAdmin: si el usuario no es instancia de
| Landlord, retorna 403.
|
| Definidas en landlord.php.
*/
require __DIR__.'/landlord.php';
```

> **Punto clave:** `web.php` separa las rutas en **cuatro grupos** bien delimitados: públicas (`/`), compartidas con auth (settings, perfil), del producto SaaS (con `tenant` middleware — el `dashboard` del tenant vive acá), y del admin landlord (con `EnsureUserIsAdmin`, accesibles solo desde el dominio principal). Las rutas del panel admin viven en `routes/landlord.php` y se cargan con `require`. Ver §16 para la defensa role-aware (3 capas: redirect POST-auth, redirect guest, tenant middleware).

### 8.1 Rutas del panel admin — `routes/landlord.php`

Cargado por `web.php` (línea anterior). Agrupa todo el panel admin bajo el prefijo `/admin` con el namespace `landlord.*`:

```php
<?php

use App\Http\Controllers\Landlord\AdminPanelController;
use App\Http\Controllers\Landlord\TenantController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landlord / Admin Routes
|--------------------------------------------------------------------------
|
| These routes are for the platform administrator only.
| They are accessible ONLY from the landlord domain (no subdomain).
| The 'tenant' middleware is intentionally NOT applied here.
|
| Middleware stack: web → auth → verified → EnsureUserIsAdmin
|
*/

Route::middleware(['auth', 'verified', EnsureUserIsAdmin::class])->prefix('admin')->name('landlord.')->group(function () {

    // Panel (home del landlord)
    Route::get('/', [AdminPanelController::class, 'index'])->name('admin-panel');

    // Tenant management
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
});
```

> **Punto clave:** El middleware `EnsureUserIsAdmin` se aplica **dentro** de `landlord.php` (no en `web.php`) porque solo este grupo de rutas debe ser admin-only. Las rutas públicas, compartidas y de tenant no deben pasar por él — un tenant intentando ver su propio dashboard fallaría con 403.

---

## 9. Configurar el modelo `User`

`app/Models/User.php`:

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, UsesTenantConnection;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'avatar',
    ];

    /**
     * Get the URL of the first avatar media item.
     */
    protected function getAvatarAttribute(): ?string
    {
        /** @var Media|null $media */
        $media = $this->getFirstMedia('avatar');

        return $media?->getUrl();
    }

    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('avatar')
            ->singleFile()
            ->registerMediaConversions(function (): void {
                $this
                    ->addMediaConversion('thumb')
                    ->width(150)
                    ->height(150)
                    ->nonQueued();
            });
    }
}
```

---

## 10. Crear el modelo `Landlord`

`app/Models/Landlord.php`:

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\LandlordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class Landlord extends Authenticatable implements HasMedia
{
    /** @use HasFactory<LandlordFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, UsesLandlordConnection;

    /**
     * Reutiliza la tabla users del landlord (ya creada por Laravel por defecto).
     * Cada tenant tiene su propia tabla users en su BD dedicada.
     */
    protected $table = 'users';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'avatar',
    ];

    /**
     * Get the URL of the first avatar media item.
     */
    protected function getAvatarAttribute(): ?string
    {
        /** @var Media|null $media */
        $media = $this->getFirstMedia('avatar');

        return $media?->getUrl();
    }

    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('avatar')
            ->singleFile()
            ->registerMediaConversions(function (): void {
                $this
                    ->addMediaConversion('thumb')
                    ->width(150)
                    ->height(150)
                    ->nonQueued();
            });
    }
}
```

Crear su factory y seeder:

```bash
php artisan make:factory LandlordFactory
php artisan make:seeder LandlordUserSeeder
php artisan make:seeder TenantsSeeder
```

`database/factories/LandlordFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Landlord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Landlord>
 */
class LandlordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

`database/seeders/LandlordUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Landlord;
use Illuminate\Database\Seeder;

class LandlordUserSeeder extends Seeder
{
    public function run(): void
    {
        Landlord::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
        ]);
    }
}
```

`database/seeders/TenantsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the landlord database with initial tenants.
 *
 * Each Tenant::create() call automatically triggers the provisioning
 * lifecycle callback (createDatabase, configureTenantConnection,
 * runMigrations) defined in the Tenant model.
 *
 * This seeder creates two test tenants with dedicated databases.
 */
class TenantsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Tenant::create([
            'name' => 'Tenant One',
            'domain' => 'tenant1.spatie-laravel-multitenancy.test',
            'database' => 'tenant1-spatie-laravel-multitenancy',
        ]);

        Tenant::create([
            'name' => 'Tenant Two',
            'domain' => 'tenant2.spatie-laravel-multitenancy.test',
            'database' => 'tenant2-spatie-laravel-multitenancy',
        ]);
    }
}
```

`database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            LandlordUserSeeder::class,
            TenantsSeeder::class,
        ]);
    }
}
```

Ejecutar seeders:

```bash
php artisan db:seed
```

---

## 11. Crear las BDs físicas de los tenants

Spatie no crea las BDs físicamente. Crearlas en PostgreSQL manualmente:

```sql
CREATE DATABASE "tenant1-spatie-laravel-multitenancy";
CREATE DATABASE "tenant2-spatie-laravel-multitenancy";
```

> **Nota — automatización actual:** En el flujo normal de seeders y del panel de admin, este paso es automático. El callback `creating` de `app/Models/Tenant.php` (ver recuadro abajo) se encarga de todo: verifica precondiciones, crea la BD física, configura la conexión y corre migraciones. Todo eso ocurre cada vez que se invoca `Tenant::create()`. Además, `createDatabase()` es **idempotente**: chequea `pg_database` antes de crear.

El modelo `Tenant` completo, con su callback de provisioning automático y el guard de precondiciones:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Tenant extends SpatieTenant implements IsTenant
{
    use HasFactory, ImplementsTenant, UsesLandlordConnection;

    protected $fillable = ['name', 'domain', 'database'];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->assertTenantsTableExists();  // ← GUARD: falla temprano si falta la tabla
            $tenant->createDatabase();            // CREATE DATABASE si no existe
            $tenant->configureTenantConnection(); // apunta 'tenant' connection a esta BD
            $tenant->runMigrations();             // migrate --database=tenant --force
        });
    }

    protected function assertTenantsTableExists(): void
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            throw new \RuntimeException(
                'The tenants table does not exist. Run: php artisan migrate '
                .'--path=database/migrations/landlord --database=landlord'
            );
        }
    }

    protected function createDatabase(): void
    {
        $exists = DB::connection('landlord')->select(
            'SELECT 1 FROM pg_database WHERE datname = ?', [$this->database]
        );
        if (empty($exists)) {
            DB::unprepared('CREATE DATABASE "'.$this->database.'"');
        }
    }

    protected function configureTenantConnection(): void
    {
        config(['database.connections.tenant.database' => $this->database]);
        DB::purge('tenant');
    }

    protected function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--force' => true,
        ]);
    }
}
```

> **Por qué el guard `assertTenantsTableExists()`:** Sin esta precondición, si la tabla `tenants` no existe en la BD landlord, el `INSERT` de Eloquent falla después de que el callback `creating` ya creó la BD física y corrió migraciones — dejando una BD huérfana. El guard falla PRIMERO con un mensaje claro. Ver [`analisis-pendientes-fase-temprana.md`](./analisis-pendientes-fase-temprana.md) para contexto.

**`database/factories/TenantFactory.php`** — factory para crear tenants en seeders y tests:

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'domain' => fake()->unique()->domainName(),
            'database' => 'tenant_'.fake()->unique()->randomNumber(5),
        ];
    }

    public function forDatabase(string $dbName): static
    {
        return $this->state(['database' => $dbName]);
    }
}
```

> **Punto clave:** `forDatabase()` permite pinchar un nombre de BD específico sin modificar la definición base de la factory. Usado en tests que necesitan verificar el comportamiento con un database name conocido.

**`app/Http/Controllers/Landlord/TenantController.php`** — CRUD completo de tenants desde el panel admin:

```php
<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TenantController extends Controller
{
    public function index()
    {
        return Inertia::render('landlord/tenants/index', [
            'tenants' => Tenant::all(),
        ]);
    }

    public function create()
    {
        return Inertia::render('landlord/tenants/create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'unique:tenants,domain'],
            'database' => ['required', 'string', 'max:255', 'unique:tenants,database'],
        ]);

        try {
            Tenant::create($validated);
        } catch (\Exception $e) {
            // Rollback: si el callback creating falla (createDatabase,
            // configureTenantConnection, runMigrations), dropear la BD.
            DB::unprepared('DROP DATABASE IF EXISTS "'.$validated['database'].'"');
            throw $e;
        }

        return redirect()->route('landlord.tenants.index');
    }

    public function show(Tenant $tenant)
    {
        return Inertia::render('landlord/tenants/show', [
            'tenant' => $tenant,
        ]);
    }

    public function edit(Tenant $tenant)
    {
        return Inertia::render('landlord/tenants/edit', [
            'tenant' => $tenant,
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'unique:tenants,domain,'.$tenant->id],
            'database' => ['required', 'string', 'max:255', 'unique:tenants,database,'.$tenant->id],
        ]);

        $tenant->update($validated);

        return redirect()->route('landlord.tenants.index');
    }

    public function destroy(Tenant $tenant)
    {
        DB::unprepared('DROP DATABASE IF EXISTS "'.$tenant->database.'"');
        $tenant->delete();
        return redirect()->route('landlord.tenants.index');
    }
}
```

> **Punto clave:** `store()` envuelve `Tenant::create()` en un try/catch — si el callback `creating` del modelo falla (ej: PostgreSQL no disponible, migración falla), el catch dropea la BD física y relanza la excepción. Sin este rollback manual, la BD quedaría huérfana. `destroy()` dropea la BD física primero, luego elimina el registro.

---

## 12. Migrar las BDs de los tenants

```bash
php artisan tenants:artisan "migrate --database=tenant"
```

> **Nota — automatización actual:** En el flujo de seeders, este paso es automático para tenants nuevos. El callback `creating` de `app/Models/Tenant.php` (método `runMigrations()`) corre `php artisan migrate --database=tenant --force` cada vez que se invoca `Tenant::create()`. El comando `tenants:artisan` de arriba sigue siendo necesario para **propagar migraciones nuevas a tenants existentes** que ya están corriendo (no fueron recién creados). Es el flujo de actualización de schema para clientes en producción.

---

## 13. Resolver el problema de login en landlord

### Problema detectado

Hasta aquí tenemos la configuración base lista. Pero al probar el login desde el dominio landlord (`spatie-laravel-multitenancy.test/login`) con un usuario válido de la BD landlord, el login falla.

**¿Por qué falla?**

Laravel usa un "user provider" para buscar usuarios en la BD cuando alguien intenta loguearse. Por defecto, este provider siempre usa el modelo `App\Models\User`. Pero en nuestro caso:

- Cuando el login es desde un **tenant** (ej: `tenant1.spatie-laravel-multitenancy.test`), necesitamos buscar en la BD del tenant usando el modelo `User`
- Cuando el login es desde el **landlord** (ej: `spatie-laravel-multitenancy.test`), necesitamos buscar en la BD landlord usando el modelo `Landlord`

El provider por defecto no sabe esto. Siempre busca con `User`, que tiene `UsesTenantConnection`, así que en el dominio landlord intenta usar la conexión `tenant` (que no tiene BD configurada sin tenant activo) → error.

### Solución: Crear un Auth Provider personalizado

Necesitamos un provider que en tiempo de ejecución decida qué modelo usar según el dominio actual. Laravel permite crear providers personalizados que extienden `EloquentUserProvider`.

Primero, necesitamos crear la lógica que resuelve el modelo. En lugar de escribir directamente la condición dentro del provider (que luego tendríamos que duplicar en otros lugares que necesiten la misma lógica), extraemos la resolución a un **concern reutilizable**.

Crear `app/Concerns/ResolvesUserModel.php`:

```php
<?php

namespace App\Concerns;

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;

/**
 * Concern que proporciona lógica centralizada para resolver el modelo de usuario
 * apropiado según el contexto de tenancy actual.
 *
 * Esta es la FUENTE ÚNICA DE VERDAD para la resolución de modelos tenant-aware.
 * Cualquier componente que necesite saber si usar Landlord o User debe consumir
 * este concern mediante el trait.
 */
trait ResolvesUserModel
{
    /**
     * Resuelve el modelo de usuario apropiado basado en el contexto de tenancy actual.
     *
     * @return string Nombre de clase FQDN del modelo a usar
     *
     * Lógica:
     * - Si Tenant::current() retorna null (dominio landlord) → Usar Landlord
     * - Si Tenant::current() retorna una instancia de Tenant (dominio tenant) → Usar User
     */
    protected function resolveUserModel(): string
    {
        return Tenant::current() ? User::class : Landlord::class;
    }
}

// ¿Por qué este archivo es crítico?
// - Centraliza la lógica Tenant::current() ? User::class : Landlord::class que antes estaba duplicada
// - Elimina la posibilidad de inconsistencia entre componentes
// - Permite reutilización sencilla mediante traits en cualquier clase de Laravel
// - Es la única ubicación que necesita modificarse si cambia la lógica de tenancy
```

> **¿Por qué un concern y no escribirlo directamente en el provider?** Porque esta misma lógica la necesitaremos más adelante en las acciones de registro y validación de Fortify. Si la duplicamos en cada archivo, cualquier cambio futuro requiere editar múltiples lugares. Con el concern, la lógica existe en un solo sitio y todos la reutilizan.

Ahora crear el auth provider que consume este concern:

`app/Providers/MultiTenantUserProvider.php`:

```php
<?php

namespace App\Providers;

use App\Concerns\ResolvesUserModel;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Proveedor de usuarios personalizado que adapta el EloquentUserProvider estándar
 * para funcionar en un entorno multitenante usando Spatie Multitenancy.
 *
 * Este proveedor ahora ELIMINA LA DUPLICACIÓN de lógica al reutilizar
 * el mismo concern que usan los flujos de registro y validación.
 */
class MultiTenantUserProvider extends EloquentUserProvider
{
    use ResolvesUserModel; // ← CONSUMO DE LA CAPA FUNDAMENTAL (elimina duplicación)

    /**
     * Crea una nueva instancia del modelo de usuario apropiado.
     *
     * @return \Illuminate\Database\Eloquent\Model  Instancia de Landlord o User según tenancy
     */
    public function createModel()
    {
        // ← REUTILIZACIÓN: En lugar de lógica duplicada, usa el concern centralizado
        $class = $this->resolveUserModel();

        return new $class;
    }
}

// Cambios clave y su importancia:
// - Eliminado: use App\Models\Landlord; use App\Models\User; use Spatie\Multitenancy\Models\Tenant;
//  - Ya no necesarios porque la lógica se delega completamente al concern
// - Añadido: use App\Concerns\ResolvesUserModel; - Única dependencia necesaria
// - Añadido: use ResolvesUserModel; - Consumo del trait para acceder a resolveUserModel()
// - Simplificado: createModel() de 4 líneas a 2 líneas
//  - Antes: Lógica duplicada Tenant::current() ? User::class : Landlord::class
//  - Ahora: Reutiliza exactamente la misma lógica que usan registro y validación
// - Beneficio: Auth provider y flujos de registro ahora usan idéntica lógica de resolución
```

Registrar el driver `multi-tenant` en la aplicación:

`app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Auth::provider('multi-tenant', function ($app, array $config) {
            return new MultiTenantUserProvider($app['hash'], $config['model']);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
```

`config/auth.php`:

```php
<?php

use App\Models\User;

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'multi-tenant',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
```

### Resultado

Ahora el login funciona en ambos dominios:
- **Landlord:** `MultiTenantUserProvider` resuelve `Landlord` → busca en BD landlord → sesión iniciada
- **Tenant:** `MultiTenantUserProvider` resuelve `User` → busca en BD del tenant → sesión iniciada

---

## 14. Resolver el problema de registro en landlord

### Problema detectado

El login funciona, pero al intentar **registrar** un nuevo usuario desde el dominio landlord (`spatie-laravel-multitenancy.test/register`), falla con:

```
SQLSTATE[42P01]: Undefined table: 7 ERROR: no existe la relación "users"
```

**¿Por qué falla?**

La acción de registro de Fortify (`CreateNewUser.php`) siempre usa `User::class` para:
1. Validar que el email no esté duplicado: `Rule::unique(User::class)`
2. Crear el usuario: `User::create([...])`

Ambas operaciones usan el modelo `User` que tiene `UsesTenantConnection`. En el dominio landlord (sin tenant activo), esto provoca que Laravel intente usar la conexión `tenant` sin base de datos configurada → consulta fallida → error de tabla inexistente.

### Solución: Aplicar el concern `ResolvesUserModel` a los componentes de Fortify

El concern `ResolvesUserModel` ya existe (paso 13) y contiene la lógica correcta para resolver el modelo según el tenancy. Ahora lo aplicamos a los archivos que tenían el problema.

**¿Qué archivos necesitan cambios y por qué?**

1. **`ProfileValidationRules.php`** → Usa `User::class` en `Rule::unique()`. Necesita usar el modelo resuelto dinámicamente.
2. **`CreateNewUser.php`** → Usa `User::class` para crear usuarios. Necesita usar el modelo resuelto dinámicamente.

### Actualizar `app/Concerns/ProfileValidationRules.php`

```php
<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Trait que proporciona reglas de validación comunes para perfiles de usuario.
 * Ahora usa resolución dinámica de modelos para garantizar consistencia
 * entre el contexto de tenancy y la validación de unicidad.
 */
trait ProfileValidationRules
{
    use ResolvesUserModel; // ← CONSUMO DE LA CAPA FUNDAMENTAL

    /**
     * Obtiene las reglas de validación para perfiles de usuario.
     *
     * @param  int|null  $userId  ID del usuario para ignorar en validación de unicidad (null para creación)
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Reglas para validar nombres de usuario.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Reglas para validar emails de usuario.
     * Ahora usa resolución dinámica de modelos para asegurar que la validación
     * de unicidad ocurra contra la conexión de base de datos correcta.
     *
     * @param  int|null  $userId
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        // ← RESOLUCIÓN DINÁMICA: Obtiene el modelo correcto según tenancy
        $userModel = $this->resolveUserModel();

        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique($userModel)          // Validación de creación: verificar unicidad absoluta
                : Rule::unique($userModel)->ignore($userId), // Validación de actualización: ignorar el usuario actual
        ];
    }
}

// Cambios clave y su importancia:
// - Añadido: use ResolvesUserModel; - Consumo de la capa fundamental de lógica
// - Modificado: emailRules() - Ahora usa $this->resolveUserModel() en lugar de User::class hardcodeado
// - Impacto: La validación Rule::unique() ahora consulta la conexión de base de datos correcta:
//  - En landlord: verifica unicidad contra la tabla users de la BD landlord (usando modelo Landlord)
//  - En tenant: verifica unicidad contra la tabla users de la BD tenant activa (usando modelo User)
```

> **Cambio clave:** `Rule::unique(User::class)` → `Rule::unique($this->resolveUserModel())`. Ahora la validación consulta la BD correcta según el dominio.

### Actualizar `app/Actions/Fortify/CreateNewUser.php`

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesUserModel;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Acción de Fortify para crear nuevos usuarios.
 * Ahora usa resolución dinámica de modelos y cumple exactamente
 * con el tipo de retorno esperado por el contrato de Fortify.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules, ResolvesUserModel;

    /**
     * Valida y crea un nuevo usuario registrado.
     *
     * @param  array<string, string>  $input  Datos de entrada del formulario de registro
     * @return \Illuminate\Foundation\Auth\User  Instancia del usuario creado (Landlord o User según tenancy)
     */
    public function create(array $input): BaseUser
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // ← RESOLUCIÓN DINÁMICA: Obtiene el modelo correcto según tenancy
        $userModel = $this->resolveUserModel();

        // ← CREACIÓN DINÁMICA: Usa el modelo resuelto para crear el usuario
        return $userModel::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}

// Cambios clave y su importancia:
// - Añadido: use App\Concerns\ResolvesUserModel; - Acceso a la lógica centralizada
// - Añadido: use ResolvesUserModel; en la clase - Consumo del trait
// - Cambiado: Tipo de retorno de User a Illuminate\Foundation\Auth\User as BaseUser
//  - Por qué: El contrato Laravel\Fortify\Contracts\CreatesNewUsers::create() espera específicamente retornar \Illuminate\Foundation\Auth\User
//  - Tanto Landlord como User extienden esta clase base, por lo que cumple con el principio de substitutividad
//  - Evita TypeError en PHP 8.5+ cuando se retorna Landlord desde el dominio landlord
// - Modificado: Lógica de creación - Ahora usa $userModel::create([...]) en lugar de User::create([...])
```

> **Cambio clave:** `User::create([...])` → `$userModel::create([...])`. Ahora crea el usuario con el modelo correcto según el dominio.
>
> **Nota sobre el tipo de retorno:** Se usa `Illuminate\Foundation\Auth\User as BaseUser` porque el contrato `CreatesNewUsers` de Fortify requiere ese tipo específico. Tanto `Landlord` como `User` extienden esa clase base.

### Resultado

El registro funciona en ambos dominios:
- **Landlord:** Resuelve `Landlord` → valida y crea en BD landlord
- **Tenant:** Resuelve `User` → valida y crea en BD del tenant

---

## 15. Resolver el problema de reset de contraseña en landlord

### Problema detectado

Login y registro funcionan. Pero al intentar **resetear la contraseña** desde el dominio landlord, falla con:

```
TypeError: Argument #1 ($user) must be of type App\Models\User, App\Models\Landlord given
```

**¿Por qué falla?**

La acción `ResetUserPassword.php` declara el parámetro `$user` como tipo `User`:

```php
public function reset(User $user, array $input): void
```

Pero el auth provider (paso 13) ahora puede devolver tanto `Landlord` como `User` según el dominio. Cuando el reset se ejecuta desde el dominio landlord, el auth provider devuelve una instancia de `Landlord`, pero el método esperaba estrictamente `User` → `TypeError` en PHP 8.5+.

### Solución

Cambiar el tipo del parámetro para que acepte ambos modelos. Tanto `Landlord` como `User` implementan la interfaz `Illuminate\Contracts\Auth\Authenticatable`, que es el tipo más amplio que el auth provider puede devolver.

Actualizar `app/Actions/Fortify/ResetUserPassword.php`:

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Acción de Fortify para reseteo de contraseñas.
 * Ahora acepta cualquier modelo que implemente Authenticatable
 * para ser compatible tanto con Landlord como con User devueltos
 * por nuestro proveedor de auth tenant-aware.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Valida y resetea la contraseña de un usuario olvidada.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  Instancia del usuario (Landlord o User)
     * @param  array<string, string>  $input  Datos de entrada conteniendo la nueva contraseña
     * @return void
     */
    public function reset(Authenticatable $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}

// Cambios clave y su importancia:
// - Cambiado: Tipo de parámetro de User $user a Authenticatable $user
// - Por qué: Nuestro MultiTenantUserProvider puede devolver tanto Landlord como User dependiendo del contexto de tenancy
// - Ambos modelos implementan Illuminate\Contracts\Auth\Authenticatable
// - Este cambio respeta el principio de Liskov Sustitución: el método ahora acepta cualquier cosa que el contrato espera poder hacer con un usuario (autenticación)
// - Elimina el TypeError que ocurría cuando se pasaba una instancia Landlord a un método que esperaba estrictamente User
```

> **Cambio clave:** `User $user` → `Authenticatable $user`. Ahora el método acepta tanto `Landlord` como `User`, que es lo que el auth provider realmente puede devolver.

### Resultado

El reset de contraseña funciona en ambos dominios:
- **Landlord:** Acepta `Landlord` como `$user` → resetea en BD landlord
- **Tenant:** Acepta `User` como `$user` → resetea en BD del tenant

---

## 16. Proteger el panel de admin: gate de Landlord

### Problema detectado

Con las secciones 13-15, el login funciona tanto para `Landlord` como para `User`. Pero el grupo de rutas de landlord (en `routes/landlord.php`) sólo declara `['auth', 'verified']` como middleware — cualquier usuario autenticado y con email verificado, sea `Landlord` o `User`, podría acceder a `/admin`. Necesitamos una capa extra que distinga **qué modelo** está autenticado, no sólo **si** está autenticado.

### Solución: middleware `EnsureUserIsAdmin` + prop compartido `auth.is_admin`

Dos cambios complementarios:

1. **Backend (gate):** un middleware que aplica el check `instanceof Landlord` antes de permitir el paso a las rutas de admin. Si falla, aborta con 403.
2. **Frontend (UI signal):** el mismo check se publica como prop compartido de Inertia (`auth.is_admin`) para que el sidebar pueda mostrar/ocultar el menú de admin según el rol del usuario autenticado.

#### Middleware: `app/Http/Middleware/EnsureUserIsAdmin.php`

```php
<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the authenticated user is a Landlord (admin platform user).
 *
 * This middleware ensures that only users authenticated through the Landlord
 * model (which uses UsesLandlordConnection) can access admin routes.
 * Tenant users (User model) are rejected even if they somehow reach admin routes.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Landlord) {
            abort(403, 'Unauthorized: admin access required.');
        }

        return $next($request);
    }
}
```

> **¿Por qué `instanceof Landlord` y no una columna `is_admin` o un sistema de roles?** Porque el modelo **es** el rol. `Landlord` y `User` son clases distintas que viven en BDs distintas, no dos filas de una misma tabla con un flag diferenciador. `User` jamás instancia `Landlord`, así que el chequeo es seguro y no requiere mantenimiento. Si en el futuro agregás un tercer rol (por ejemplo, `Support`), agregás un tercer modelo y un nuevo check, no un sistema de roles genérico.

#### Registro en `routes/landlord.php`

El middleware se aplica al grupo de rutas `/admin` (no a `web.php` globalmente, porque eso bloquearía también a tenants):

```php
Route::middleware(['auth', 'verified', EnsureUserIsAdmin::class])->prefix('admin')->name('landlord.')->group(function () {
    // ... rutas de admin
});
```

> **Orden del stack:** `auth → verified → EnsureUserIsAdmin`. El middleware de admin va **al final**, después de que `auth` y `verified` ya poblaron `$request->user()`. Si lo pusiéramos antes, `$request->user()` sería `null` y el `instanceof` siempre fallaría con 403 (incluso para el admin legítimo).

#### Prop compartido de Inertia: `app/Http/Middleware/HandleInertiaRequests.php`

El mismo check se publica como prop compartido para que el frontend pueda condicionar la UI:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'name' => config('app.name'),
        'auth' => [
            'user' => $request->user(),
            'is_admin' => $request->user() instanceof Landlord,
        ],
        'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
    ];
}
```

> **Mismo check, dos planos diferentes:** el middleware de admin y el prop compartido hacen exactamente la misma pregunta (`$request->user() instanceof Landlord`). El middleware es el **gate** que bloquea el request HTTP. El prop es la **UI signal** que permite al sidebar mostrar/ocultar elementos según el rol. Si en el futuro agregás más roles, ambos lugares necesitan actualizarse en paralelo — y eso es OK, es el patrón estándar de Inertia.

### Resultado

- **Backend:** `/admin/*` rechaza con 403 a cualquier `User` autenticado. Sólo `Landlord` pasa el gate.
- **Frontend:** el componente `app-sidebar.tsx` lee `auth.is_admin` desde los props compartidos y muestra el menú de admin sólo a landlords. Los tenants ven un sidebar sin la sección "Admin" (ver sección 19 para el detalle del sidebar).
- **No hay mantenimiento de roles:** el modelo es el rol. No hay tabla `roles`, no hay columna `is_admin`, no hay un sistema de permisos que mantener.

#### Redirect post-auth según rol: `app/Http/Responses/RoleAwareAuthResponse.php`

Por default Fortify redirige a todos los usuarios a `config('fortify.home')` después del login y el registro — y ese valor es `/dashboard`. Pero `/dashboard` es la home de los **tenants**; la home del landlord es `/admin` (ver §8.1). Para resolverlo sin tocar el `home` config (que también consume el middleware `RedirectIfAuthenticated` de Fortify), se overridean los contracts `LoginResponse` y `RegisterResponse` con una clase que aplica el mismo `instanceof Landlord` que ya usa el resto del flujo de auth:

```php
<?php

namespace App\Http\Responses;

use App\Models\Landlord;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;

class RoleAwareAuthResponse implements LoginResponse, RegisterResponse
{
    public function toResponse($request)
    {
        return redirect()->intended(
            $request->user() instanceof Landlord ? '/admin' : '/dashboard'
        );
    }
}
```

Y los bindings en `app/Providers/FortifyServiceProvider.php::register()` — un loop sobre los contracts porque el concrete class es el mismo en ambos casos:

```php
foreach ([
    \Laravel\Fortify\Contracts\LoginResponse::class,
    \Laravel\Fortify\Contracts\RegisterResponse::class,
] as $contract) {
    $this->app->singleton($contract, \App\Http\Responses\RoleAwareAuthResponse::class);
}
```

> **¿Por qué `intended()` y no un redirect fijo?** Si el usuario estaba intentando acceder a una página protegida antes de ser enviado a `/login`, queremos que vaya a esa página (siempre que sea alcanzable para su rol). Si no hay `intended` en la sesión, cae en el fallback role-aware.
>
> **¿Por qué una sola clase que implementa ambos contracts?** Porque la lógica es idéntica para login y registro. Dos clases separadas con el mismo cuerpo serían duplicación pura.
>
> **¿Por qué no cambio el `home` config a `/admin`?** Porque el flujo "usuario ya autenticado visita `/login` o `/register`" necesita un redirect role-aware (no un único string), y eso se resuelve en otra capa: ver §16.5. El `home` config queda como fallback (lo lee `defaultRedirectUri` del default de Laravel cuando no hay override). El override en los contracts es para el POST-auth — Fortify lo invoca después de validar credenciales.

**Resultado:** landlord → `/admin`, tenant → `/dashboard`. Mismo `instanceof Landlord` que usa el gate (`EnsureUserIsAdmin`), el prop compartido (`auth.is_admin`) y ahora el redirect post-auth. La regla "el modelo es el rol" se mantiene consistente a lo largo de toda la capa de auth.

#### Redirect desde guest cuando ya estás autenticado: `app/Http/Middleware/RedirectIfAuthenticated.php`

Hay un segundo flujo de redirect que también necesita ser role-aware: cuando un usuario ya autenticado intenta acceder a una ruta de "guest" (login, register, password reset, etc.), Fortify aplica el middleware `guest:` que por default redirige a un URL único — `config('fortify.home')` o, si no está, el resultado de `defaultRedirectUri()` (que mira si existen las rutas nombradas `dashboard` o `home`). En este proyecto eso resuelve a `/dashboard`. Para un landlord autenticado eso sería incorrecto: su home es `/admin`.

La solución es override del alias `guest` en `bootstrap/app.php` para que apunte a una clase que extienda el middleware default de Laravel y aplique el mismo `instanceof Landlord`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as BaseRedirect;
use Illuminate\Http\Request;

class RedirectIfAuthenticated extends BaseRedirect
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->user() instanceof Landlord
            ? '/admin'
            : parent::redirectTo($request);
    }
}
```

Y el override del alias en `bootstrap/app.php`:

```php
$middleware->alias([
    'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
]);
```

> **¿Por qué extender el default en vez de reescribirlo?** Porque el `handle()` del default ya hace todo el trabajo que necesitamos: chequea cada guard pasado, itera, llama a `redirectTo($request)`, y finalmente hace el `redirect()`. Lo único que cambia es QUÉ URL retornar, y solo cuando el user es Landlord. Para no-landlords delegamos al parent (que usa `defaultRedirectUri` y resuelve a `route('dashboard')`).
>
> **¿Por qué override del alias `guest` y no otra cosa?** Porque Fortify usa `'guest:'.config('fortify.guard')` en todas sus rutas de login/register/password/etc. Es el único punto de entry que necesita cambiar. No tocamos las rutas de Fortify ni su service provider.
>
> **¿Por qué `redirectTo(Request $request): ?string` y no `(Request $request, string $guard)`?** Porque la signature del default de Laravel 11/12 es con un solo parámetro (`$request`). El guard ya se procesa internamente en el `handle()`. (En algunas versiones anteriores de Laravel la signature incluía el guard; acá no hace falta.)

**Resultado:** landlord autenticado que visita `/login` (por URL directa, link en email viejo, etc.) → `/admin`. Tenant autenticado que visita `/login` → `/dashboard`. Mismo `instanceof Landlord`, misma simetría que §16.4.

**Las tres capas de defensa contra "landlord en /dashboard":**

1. POST-auth redirect (§16.4) — el flujo normal nunca lo manda ahí.
2. Guest redirect (§16.5, esta sección) — un link viejo o URL directa desde `/login` lo manda a `/admin`.
3. `tenant` middleware (§7) — si igual tipea `/dashboard` directamente, recibe 404 porque no hay subdominio tenant activo en el dominio principal.

---

## 17. Configurar Laragon para subdominios

### 17.1 Virtual Host de Apache

Localiza el archivo de configuración en:

```
C:\laragon\etc\apache2\sites-enabled\
```

Cuando Laragon crea el virtual host, lo nombra con el prefijo `auto.` (ej: `auto.spatie-laravel-multitenancy.test.conf`). **Este prefijo causa que Laragon sobreescriba el archivo al reiniciar**, perdiendo los cambios guardados.

**Renombrar** el archivo quitando el prefijo `auto.`:

```
auto.spatie-laravel-multitenancy.test.conf  →  spatie-laravel-multitenancy.test.conf
```

Sin este cambio, Laragon no conserva la configuración del virtual host y el servidor de desarrollo local falla al reiniciar.

Editarlo para que quede así:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/Desarrollo/spatie-laravel-multitenancy/public"
    ServerName spatie-laravel-multitenancy.test
    ServerAlias *.spatie-laravel-multitenancy.test
    <Directory "C:/Desarrollo/spatie-laravel-multitenancy/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# If you want to use SSL, enable it by going to Menu > Apache > SSL > Enabled
```

> `ServerAlias *.spatie-laravel-multitenancy.test` captura cualquier subdominio y lo dirige al mismo proyecto Laravel. Sin esta línea, solo el dominio principal funciona.

### 17.2 Archivo hosts de Windows

El proyecto incluye dos scripts PHP standalone en `scripts/` que automatizan la edición del archivo `hosts` del sistema. Ambos detectan el OS (`PHP_OS`) y son idempotentes.

> **¿Por qué estos scripts se corren a mano en vez de estar integrados al ciclo de vida del Tenant?** Porque `C:\Windows\System32\drivers\etc\hosts` (en Windows) y `/etc/hosts` (en Linux/macOS) son archivos protegidos del sistema que sólo el usuario **Administrador** o **root** puede escribir. Cuando corrés `php artisan serve`, el proceso PHP se ejecuta con los permisos de tu usuario actual — sin esos permisos elevados, cualquier intento de escribir el `hosts` desde Laravel falla con `is_writable() == false`. La única forma de hacerlo automático sería correr Laravel como admin/root, lo que sería un agujero de seguridad inaceptable. La automatización manual vía scripts es la frontera correcta: la operás vos, a propósito, cuando sabés que la necesitás.

**Scripts disponibles:**

**`scripts/add-host.php`** — agrega una entrada al hosts. IP por defecto: `127.0.0.1`. Si el hostname ya existe, sale con código 0 (idempotente):

```php
#!/usr/bin/env php
<?php

$hostname = $argv[1] ?? null;
$ip = $argv[2] ?? '127.0.0.1';

if (! $hostname) {
    echo "Usage: php add-host.php <hostname> [ip]\n";
    exit(1);
}

$hostsPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
    ? 'C:\Windows\System32\drivers\etc\hosts'
    : '/etc/hosts';

if (! is_writable($hostsPath)) {
    echo "Error: Cannot write to {$hostsPath}\n";
    echo "Run this script as administrator (Windows) or with sudo (Linux/macOS).\n";
    exit(1);
}

$hosts = file_get_contents($hostsPath);

// Check if hostname already exists (idempotent)
$lines = explode("\n", $hosts);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (preg_match('/\s+'.preg_quote($hostname, '/').'\b/', $line)) {
        echo "Entry for '{$hostname}' already exists in hosts file.\n";
        exit(0);
    }
}

$entry = "{$ip}\t{$hostname}";
if (substr($hosts, -1) !== "\n") $hosts .= "\n";
$hosts .= $entry."\n";

if (file_put_contents($hostsPath, $hosts) !== false) {
    echo "Added: {$entry}\n";
    exit(0);
} else {
    echo "Error: Failed to write to hosts file.\n";
    exit(1);
}
```

> **Punto clave:** El script parsea el archivo hosts línea por línea y usa `preg_match` para detectar si el hostname ya está registrado. Si ya existe, sale con 0 (idempotente). Detecta automáticamente Windows vs Linux/macOS para la ruta del archivo hosts.

**`scripts/remove-host.php`** — elimina la entrada para ese hostname. Si no existe, sale con código 0:

```php
#!/usr/bin/env php
<?php

$hostname = $argv[1] ?? null;

if (! $hostname) {
    echo "Usage: php remove-host.php <hostname>\n";
    exit(1);
}

$hostsPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
    ? 'C:\Windows\System32\drivers\etc\hosts'
    : '/etc/hosts';

if (! is_writable($hostsPath)) {
    echo "Error: Cannot write to {$hostsPath}\n";
    exit(1);
}

$hosts = file_get_contents($hostsPath);
$lines = explode("\n", $hosts);
$newLines = [];
$removed = false;

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed !== '' && $trimmed[0] !== '#' && preg_match('/\s+'.preg_quote($hostname, '/').'\b/', $trimmed)) {
        $removed = true;
        continue; // Skip this line
    }
    $newLines[] = $line;
}

if (! $removed) {
    echo "No entry found for '{$hostname}' in hosts file.\n";
    exit(0);
}

if (file_put_contents($hostsPath, implode("\n", $newLines)) !== false) {
    echo "Removed: '{$hostname}' from hosts file.\n";
    exit(0);
} else {
    echo "Error: Failed to write to hosts file.\n";
    exit(1);
}
```

> **Punto clave:** `remove-host.php` reconstruye el archivo hosts completo excluyendo la línea del hostname. Si el hostname no existe, sale con 0 (idempotente). Preserva comentarios y líneas en blanco.

**Cómo correrlos** (requiere permisos elevados):

- **Windows**: abrir PowerShell o CMD **como Administrador** (`Start menu → Símbolo del sistema → Ejecutar como administrador`), ir a la raíz del proyecto, y correr `php scripts/add-host.php <hostname>`
- **Linux/macOS**: anteponer `sudo` → `sudo php scripts/add-host.php <hostname>`

**Casos de uso:**

> **Nota sobre el dominio principal:** Si usás Laragon (o tu dev stack auto-administra el dominio principal del proyecto, como Valet, Herd, o similares), el dominio raíz `spatie-laravel-multitenancy.test` se agrega al `hosts` automáticamente y no necesitás correr el script para él. Los scripts de abajo son **exclusivamente para los subdominios de tenant**, que son los que tu dev stack no conoce de antemano.

**Caso 1 — Crear un tenant nuevo (cada vez que agregás un tenant desde el panel admin o vía seeder).**

```bash
php scripts/add-host.php tenant1.spatie-laravel-multitenancy.test
```

**Caso 2 — Eliminar un tenant (cuando removés un tenant del panel admin).**

```bash
php scripts/remove-host.php tenant1.spatie-laravel-multitenancy.test
```

> **Nota sobre el callback `creating` de `Tenant`:** el callback del modelo (ver código completo en §11) automatiza la creación de la BD física y la ejecución de migraciones en esa BD, además de incluir un guard que verifica que la tabla `tenants` exista antes de hacer cualquier cosa (evita BDs huérfanas). **No toca el archivo `hosts`** por la razón explicada arriba. Después de cada `Tenant::create()` (manual, seeder, o desde el panel admin) tenés que correr `add-host.php` con el dominio del nuevo tenant. La sección 18 incluye este paso en el flujo completo de reset.

### 17.3 Reiniciar Laragon

Detener y reiniciar Laragon (Stop → Start All).

---

## 18. Flujo completo de reset de datos para desarrollo (reset total)

Esta sección documenta **el reset total**: limpia todas las BDs (landlord, tenants, y cualquier default de Laravel), borra las entradas de hosts de todos los tenants, y recrea todo desde cero ejecutando las migraciones — incluida la migración manual de la tabla `tenants` del landlord. Es el flujo "como si nunca hubieras corrido nada".

> Si solo querés repoblar datos manteniendo todo lo demás, este flujo es overkill — pero sigue siendo seguro correrlo, sólo que es más lento. El reset total es útil cuando el entorno está roto, después de cambios grandes de esquema, o cuando querés un estado de desarrollo 100% limpio.

### 18.0 Pre-flight (antes de correr el reset)

- **Laragon (o tu dev stack) está corriendo** con PostgreSQL y Apache/Nginx activos.
- **Las credenciales de BD del `.env` funcionan.** Verificá con `php artisan tinker --execute 'DB::connection("landlord")->getPdo();'`.
- **Tenés una terminal con permisos de Administrador** (Windows) o `sudo` (Linux/macOS) lista para los scripts de `hosts`. Sin privilegios elevados, los scripts fallan silenciosamente con `is_writable() == false`.
- **(Recomendado) Usá una ventana incógnita del navegador** para verificar. Las cookies de sesión viejas apuntan a IDs de sesión que ya no van a existir (la tabla `sessions` se dropea en el reset), y causan loops de redirect al `/login` o errores 419.

### 18.1 Reset total

**Cuidado: este flujo destruye TODOS los datos del landlord y de los tenants**, incluyendo el `admin@example.test` y cualquier tenant que hayas creado a mano.

```bash
# 1. (Opcional) Detener servicios con conexiones abiertas a las BDs
#    (php artisan serve, queue:work, vite dev, etc.)
```

**2. Eliminá las BDs del proyecto:** la BD del landlord (la que figura como `DB_DATABASE` en tu `.env`) y la BD de cada tenant registrado en la tabla `tenants` del landlord. Cómo hacerlo queda a tu criterio — `psql`, `pgAdmin`, `DBeaver`, un script propio, lo que prefieras. La eliminación de las BDs forma parte del reset total, independientemente de la herramienta o método que elijas.

```bash
# 3. Limpiar TODAS las entradas de hosts de subdominios de tenant
#    (No podemos consultar la BD porque la dropeamos en el paso 2, así que
#    listá los tenants según tu última corrida de TenantsSeeder. Si querés
#    descubrir los hosts entries existentes: `cat /c/Windows/System32/drivers/etc/hosts`
#    en Windows, `sudo cat /etc/hosts` en Linux/macOS.)
php scripts/remove-host.php tenant1.spatie-laravel-multitenancy.test
php scripts/remove-host.php tenant2.spatie-laravel-multitenancy.test
# ... repetí para cada tenant que hayas creado

# 4. Recreate la BD landlord
#    (Las BDs de tenants se crean después automáticamente via el callback
#    `creating` de Tenant, cuando el seeder corre Tenant::create() para cada
#    uno y createDatabase() corre sobre cada uno.)
#    El nombre debe coincidir con `DB_DATABASE` del .env (típicamente
#    "spatie-laravel-multitenancy" con guiones). Las comillas dobles son
#    obligatorias porque los guiones son caracteres especiales en psql.
psql -U postgres -c 'CREATE DATABASE "spatie-laravel-multitenancy";'

# 5. Fresh migrate del landlord
#    Corre todas las migraciones estándar de Laravel (users, sessions, cache,
#    jobs, etc.) + la migración manual de la tabla `tenants` que vive en
#    database/migrations/landlord/ (sección 6 del doc). Sin esta migración
#    manual, la tabla `tenants` no se recrea y el reset falla en el paso 6.
php artisan migrate:fresh
php artisan migrate --path=database/migrations/landlord --database=landlord

# 6. Seed del landlord
#    Crea el Landlord admin (LandlordUserSeeder) y los tenants definidos en
#    TenantsSeeder. Cada Tenant::create() dispara el callback `creating` que
#    ejecuta:
#      - createDatabase()            — chequea pg_database; crea la BD solo
#                                      si no existe (idempotente)
#      - configureTenantConnection() — apunta la conexión `tenant` a esa BD
#      - runMigrations()             — corre las migraciones pendientes
#
#    Si las BDs de los tenants no se terminaron de crear (por permisos,
#    error transitorio, o lo que sea), ver bloque 6b abajo.
php artisan db:seed

# 6b. (Solo si hace falta) Workaround para BDs de tenants que no se crearon
#      Verificá primero con `psql -U postgres -l` que sólo aparece la BD
#      del landlord. Si faltan las BDs tenant, corré esto:
psql -U postgres -c 'CREATE DATABASE "tenant1-spatie-laravel-multitenancy";'
psql -U postgres -c 'CREATE DATABASE "tenant2-spatie-laravel-multitenancy";'
php artisan tenants:artisan "migrate --database=tenant"

# 7. Re-sincronizar el archivo hosts con los subdominios de tenant
#    (Requiere permisos de Administrador en Windows o sudo en Linux/macOS.
#    El dominio principal lo maneja Laragon solo; estos son los subdominios.)
php scripts/add-host.php tenant1.spatie-laravel-multitenancy.test
php scripts/add-host.php tenant2.spatie-laravel-multitenancy.test
```

> **¿Por qué no automatizamos este flujo en un comando Artisan?** Porque mezcla acciones que requieren privilegios elevados (escribir `hosts`), con acciones destructivas irreversibles (drop databases), y con migraciones sensibles (`migrate:fresh`). Si lo necesitás seguido, escribilo como script bash con `set -e` y revisalo cada vez — pero no lo metas en un comando mágico de Laravel.

### 18.2 Post-reset housekeeping

```bash
# Limpiar todas las cachés de Laravel (config, route, view, application cache)
php artisan optimize:clear

# (Opcional) Si no tenés `npm run dev` corriendo y querés el build de producción
npm run build

# (Opcional) Verificar que las rutas estén registradas
php artisan route:list --path=admin
php artisan route:list --path=admin/tenants
```

### 18.3 Comprobación rápida post-reset

```bash
# ¿Existe el admin del landlord?
psql -U postgres -d spatie_laravel_multitenancy -c 'SELECT email FROM users;'
# Esperado: 1 fila con admin@example.test

# ¿Están los tenants registrados?
psql -U postgres -d spatie_laravel_multitenancy -c 'SELECT name, domain FROM tenants;'
# Esperado: 2 filas (Acme, Globex — o lo que tengas en TenantsSeeder)

# ¿Existen las BDs físicas de los tenants?
psql -U postgres -c "SELECT datname FROM pg_database WHERE datname LIKE 'tenant%';"
# Esperado: 2 BDs (tenant1-..., tenant2-...)

# ¿Resuelve el DNS?
nslookup tenant1.spatie-laravel-multitenancy.test
# Esperado: 127.0.0.1 (o 127.0.2.2 si tenés WARP activo — ver gotcha abajo)
```

> **Gotcha de DNS — Cloudflare WARP:** Si tenés WARP activo, la resolución de DNS pasa por un resolver local en `127.0.2.2` con su propio caché independiente del sistema. `ipconfig /flushdns` no lo toca. Si un subdominio recién agregado no resuelve, esperá ~30 segundos (caché TTL de WARP) o deshabilitá WARP temporalmente.

> **Gotcha de sesiones:** Después de este reset, las cookies viejas del navegador apuntan a IDs de sesión que ya no existen. El síntoma típico es un redirect loop al `/login` o un error 419. Solución: cerrar sesión explícita antes del reset, o usar ventana incógnita (mencionado en 18.0).

---

## 19. Configuración tenant-aware de cache, session, queue, filesystem y logging

En multitenancy, los subsistemas de Laravel que persisten estado (cache, session, queue, filesystem) deben garantizar que los datos de un tenant no contaminen a otro. Esta sección documenta qué subsistemas ya son tenant-aware por diseño o por default de Spatie, y cuáles requieren acción explícita.

> **Acción concreta de esta sección:** habilitar `PrefixCacheTask`, `SwitchFilesystemTask` y `SwitchTenantLoggingTask` en `config/multitenancy.php` (ver §4). El resto de los subsistemas (session, queue, mail) ya están cubiertos por los defaults del proyecto y no requieren cambio.

### 19.1 Cache (`CACHE_STORE=database`)

**Problema:** Con `CACHE_STORE=database`, Laravel guarda las entradas en la tabla `cache` de la conexión default (landlord). Sin tenant-awareness, las keys NO están prefijadas: si tenant1 cachea `settings:5` y tenant2 hace `Cache::get('settings:5')`, recibe data de tenant1.

**Solución:** Habilitar `Spatie\Multitenancy\Tasks\PrefixCacheTask` en `config/multitenancy.php` dentro de `switch_tenant_tasks` (de la lista comentada que viene por default en §4). Esta tarea prepende el identificador del tenant activo a cada key:

- Tenant1 activo: `settings:5` → `tenant1:settings:5`
- Tenant2 activo: `settings:5` → `tenant2:settings:5`

```php
'switch_tenant_tasks' => [
    \Spatie\Multitenancy\Tasks\PrefixCacheTask::class,
    SwitchTenantDatabaseTask::class,
],
```

> **Punto clave:** `PrefixCacheTask` va **primero** en la lista — corre antes que cualquier lectura/escritura de cache en el ciclo del request, así las keys ya salen prefijadas desde el primer acceso. El orden importa: si va después de `SwitchTenantDatabaseTask`, los caches leídos durante el switch podrían no llevar el prefijo correcto.

### 19.2 Session (`SESSION_DRIVER=database`)

**Decisión:** Mantener `SESSION_DRIVER=database` con la BD landlord y `SESSION_DOMAIN=null`.

**Por qué es seguro:**

- Las sesiones viven en la tabla `sessions` de la BD landlord (centralizada).
- `SESSION_DOMAIN=null` (default) scopea las cookies de sesión al subdomain exacto (`tenant1.example.com` y `tenant2.example.com` son dominios distintos a nivel de cookie).
- Combinado: la cookie de sesión de tenant1 no se envía cuando el browser pide tenant2.example.com. Cada subdomain tiene su propio espacio de sesión.

> **Tradeoff conocido:** si en el futuro se quiere `SESSION_DOMAIN=.example.com` (cookie compartida entre subdomains para Single Sign-On entre tenants), habrá que cambiar la estrategia (regenerar sesión al cambiar de tenant, o prefijar la key de sesión por tenant). Por ahora no se necesita.

### 19.3 Queue (`QUEUE_CONNECTION=database`)

**Decisión:** Mantener `QUEUE_CONNECTION=database` con la BD landlord. Ya es tenant-aware por default de Spatie.

**Por qué ya funciona:** dos defaults en `config/multitenancy.php` lo garantizan:

- `'queues_are_tenant_aware_by_default' => true` — todo job despachado en contexto de tenant lleva el tenant ID automáticamente.
- `'make_queue_tenant_aware_action' => MakeQueueTenantAwareAction::class` — al ejecutar el job, lo primero que hace es restaurar el tenant ID antes de procesarlo.

Resultado: el job sabe en qué BD tenant correr, sin config extra.

### 19.4 Mail (`MAIL_MAILER=log` en dev)

**Decisión:** Mantener `MAIL_MAILER=log` en dev. Sin impacto en multitenancy porque no toca DB.

> **Nota para producción:** si se usa SMTP, el `MAIL_FROM_ADDRESS` puede ser global de la plataforma. Si en el futuro se quiere un remitente por tenant, se puede resolver con un `Mailable` que lea `Tenant::current()->mail_from_address` con fallback al default global.

### 19.5 Filesystem (`FILESYSTEM_DISK=local` + disco `tenant`)

**Problema:** Sin aislamiento, dos tenants pueden subir archivos con el mismo nombre y pisarse, o un tenant puede leer archivos de otro.

**Solución:** Se creó un disco `tenant` con driver `scoped` que envuelve el disco `public` con un prefijo dinámico. Un `SwitchFilesystemTask` actualiza el prefijo cuando cambia el tenant activo.

**Paso 1 — Configurar el disco `tenant` en `config/filesystems.php`:**

Agregar dentro del array `disks`:

```php
'tenant' => [
    'driver' => 'scoped',
    'disk' => 'public',
    'prefix' => 'tenant', // Se sobreescribe en runtime por SwitchFilesystemTask
],
```

> El driver `scoped` requiere `league/flysystem-path-prefixing` (instalado en §3). Sin él, Laravel lanza `DriverException: driver [scoped] is not defined`.

**Paso 2 — Crear el symlink de storage:**

```bash
php artisan storage:link
```

Esto crea `public/storage → storage/app/public`. Sin este symlink, las URLs de archivos subidos (avatares, imágenes) no resuelven en el navegador.

**Paso 3 — Instalar spatie/laravel-medialibrary (ya hecho en §3):**

Publicar config y migración:
```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="media-library-config"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="media-library-migrations"
```

Correr la migración en la BD landlord (y se propaga a cada tenant via el callback `creating` de Tenant):
```bash
php artisan migrate --database=landlord
```

Esto crea la tabla `media` con columnas para modelos, colecciones, disk, conversiones, etc.

La migración `database/migrations/2026_06_03_175326_create_media_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }
};
```

> **Punto clave:** `$table->morphs('model')` es lo que permite que la misma tabla `media` sirva tanto para `User` (tenant) como para `Landlord` (landlord) — el `model_type` distingue a qué modelo pertenece cada media. En cada tenant se crea su propia tabla `media` vía el callback `creating` de Tenant.

**Paso 4 — Agregar `InteractsWithMedia` a los modelos User y Landlord:**

Ver §9 y §10 para el código completo. Cada modelo debe:
1. Implementar `HasMedia`
2. Usar `InteractsWithMedia`
3. Agregar `registerMediaCollections()` con una colección `avatar`, singleFile, y conversión `thumb` 150×150 no-queued
4. Agregar `getAvatarAttribute()` y `$appends = ['avatar']`

**Paso 5 — El `SwitchFilesystemTask`:**

```php
// app/Multitenancy/Tasks/SwitchFilesystemTask.php
<?php

namespace App\Multitenancy\Tasks;

use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchFilesystemTask implements SwitchTenantTask
{
    protected ?string $originalPrefix = null;
    protected ?string $originalMediaLibraryDisk = null;

    public function __construct()
    {
        $this->originalPrefix ??= config('filesystems.disks.tenant.prefix');
        $this->originalMediaLibraryDisk ??= config('media-library.disk_name');
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        config()->set('filesystems.disks.tenant.prefix', "tenant_{$tenant->getKey()}");
        config()->set('media-library.disk_name', 'tenant');
        app()->forgetInstance('filesystem');
        Storage::clearResolvedInstance('filesystem');
    }

    public function forgetCurrent(): void
    {
        config()->set('filesystems.disks.tenant.prefix', $this->originalPrefix);
        config()->set('media-library.disk_name', $this->originalMediaLibraryDisk);
        app()->forgetInstance('filesystem');
        Storage::clearResolvedInstance('filesystem');
    }
}
```

> **Punto clave:** Sin `app()->forgetInstance('filesystem')` + `Storage::clearResolvedInstance('filesystem')`, el `FilesystemManager` retiene el prefix anterior en su instancia cacheada, y los archivos se escriben en el directorio del tenant incorrecto. Ver [spatie/laravel-multitenancy Discussion #480](https://github.com/spatie/laravel-multitenancy/discussions/480).

**Registro en `config/multitenancy.php`:** `SwitchFilesystemTask::class` en `switch_tenant_tasks` (ver §4).

**Paso 6 — Avatar upload en el frontend:**

`resources/js/components/avatar-upload.tsx` (ver código completo en §19.8) renderiza un preview, un botón de cámara (input file oculto) y un botón "Remove" cuando existe avatar. La página de perfil completa que lo integra (`resources/js/pages/settings/profile.tsx`):

```tsx
import { Form, Head, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import AvatarUpload from '@/components/avatar-upload';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';

type PageProps = { auth: Auth };

export default function Profile() {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />
            <h1 className="sr-only">Profile settings</h1>
            <div className="space-y-6">
                <Heading variant="small" title="Profile" description="Update your name and email address" />
                <AvatarUpload currentUrl={auth.user.avatar ?? null} userName={auth.user.name} />
                <Separator />
                <Form {...ProfileController.update.form()} options={{ preserveScroll: true }} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" className="mt-1 block w-full" defaultValue={auth.user.name} name="name" required autoComplete="name" placeholder="Full name" />
                                <InputError className="mt-2" message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input id="email" type="email" className="mt-1 block w-full" defaultValue={auth.user.email} name="email" required autoComplete="username" placeholder="Email address" />
                                <InputError className="mt-2" message={errors.email} />
                            </div>
                            <div className="flex items-center gap-4">
                                <Button disabled={processing} data-test="update-profile-button">Save</Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
            <DeleteUser />
        </>
    );
}
Profile.layout = { breadcrumbs: [{ title: 'Profile settings', href: edit() }] };
```

Las rutas del avatar viven en `routes/settings.php` (archivo completo):

```php
<?php

use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/avatar', [AvatarController::class, 'store'])->name('profile.avatar.store');
    Route::delete('settings/profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.destroy');
});
Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::put('settings/password', [SecurityController::class, 'update'])->middleware('throttle:6,1')->name('user-password.update');
    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});
```

Y el controller `app/Http/Controllers/Settings/AvatarController.php` completo:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AvatarController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $request->user()
            ->addMediaFromRequest('avatar')
            ->sanitizingFileName(fn (string $fileName): string =>
                strtolower((string) str($fileName)->replace(['#', '/', '\\', ' '], '-')))
            ->toMediaCollection('avatar');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar updated.')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->clearMediaCollection('avatar');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar removed.')]);
        return to_route('profile.edit');
    }
}
```

> **Nota sobre cambios del snippet original a la implementación real:** La validación usa `mimes:jpeg,png,jpg,webp` (no solo `image`). El upload usa `addMediaFromRequest()` con `sanitizingFileName()` para normalizar nombres. Retorna `to_route()` en vez de `back()`, y muestra un toast vía `Inertia::flash()`. Los endpoints se resuelven con Wayfinder desde el frontend (`AvatarController.store.form()`).

> **APP_URL importa:** Las URLs de imágenes (como avatares) se generan con la URL absoluta de la app. `APP_URL` debe coincidir con el dominio real (ej: `http://spatie-laravel-multitenancy.test`) para que las URLs resuelvan en el navegador. Si está en `localhost`, las URLs de archivos apuntan a `localhost` en vez del dominio real y las imágenes no se ven.

### 19.6 Logging context

**Problema:** Sin contexto de tenant en los logs, no se puede filtrar por tenant al debuggear. Dos tenants mezclan entradas en el mismo archivo de log sin distinción.

**Solución:** `SwitchTenantLoggingTask` inyecta el `tenant_id` en el contexto compartido del logger usando `Log::shareContext()`.

**`app/Multitenancy/Tasks/SwitchTenantLoggingTask.php`:**

```php
<?php

namespace App\Multitenancy\Tasks;

use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchTenantLoggingTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        Log::shareContext(['tenant_id' => $tenant->getKey()]);
    }

    public function forgetCurrent(): void
    {
        Log::withoutContext();
        Log::flushSharedContext();
    }
}
```

**Registro en `config/multitenancy.php`:** `SwitchTenantLoggingTask::class` en `switch_tenant_tasks` (ver §4).

> **Resultado:** cada entrada de log emitida dentro de un request de tenant incluye `{"tenant_id": 42}`. En producción podés filtrar `grep '"tenant_id":42' storage/logs/laravel.log` sin tocar ningún call site.

---

### 19.7 Frontend: sidebar role-aware

El sidebar se adapta al rol del usuario usando `auth.is_admin` (compartido vía `HandleInertiaRequests` — ver §16). Dos componentes coordinan esta lógica:

**`resources/js/components/app-sidebar.tsx`** — estructura del sidebar. Usa `auth.is_admin` para decidir qué items de navegación mostrar y adónde apunta el logo. Los landlords ven un grupo "Admin" con enlace a Tenants; los tenants ven solo su Dashboard.

```tsx
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Building2, FolderGit2, LayoutGrid, Shield } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const adminNavItems: NavItem[] = [
    {
        title: 'Tenants',
        href: '/admin/tenants',
        icon: Building2,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const isAdmin = (auth as any)?.is_admin ?? false;

    const mainNavItems: NavItem[] = isAdmin
        ? [{ title: 'Panel', href: '/admin', icon: Shield }]
        : [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link
                                    href={isAdmin ? '/admin' : dashboard()}
                                    prefetch
                                >
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {isAdmin && <NavMain items={adminNavItems} label="Admin" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
```

> **Punto clave:** El sidebar renderiza contenido distinto según el rol sin necesidad de dos archivos separados. `isAdmin` controla: (1) el item de navegación principal (Panel vs Dashboard), (2) el link del logo, y (3) la visibilidad del grupo "Admin". El middleware `EnsureUserIsAdmin` es la defensa backend; este componente es solo la UI signal — si un tenant llegara a `/admin` igual recibe 403 (ver §16).

**`resources/js/components/nav-main.tsx`** — renderizador genérico de grupo de navegación. Consumido por `app-sidebar` tanto para el nav principal como para la sección Admin. Resalta la ruta activa automáticamente:

```tsx
import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items = [], label = 'Platform' }: { items: NavItem[]; label?: string }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
```

> **Punto clave:** `NavMain` es agnóstico del rol — recibe `items` y un `label`. Esto permite que `app-sidebar` lo use dos veces (nav principal + grupo Admin) sin duplicar lógica de render.

### 19.8 Frontend: avatar upload full component

The code de `resources/js/components/avatar-upload.tsx` completo (actualizando el snippet de §19.5):

```tsx
import { Form } from '@inertiajs/react';
import { Camera, Trash2, User } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import AvatarController from '@/actions/App/Http/Controllers/Settings/AvatarController';
import { Button } from '@/components/ui/button';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';

type AvatarUploadProps = {
    currentUrl: string | null;
    userName: string;
};

export default function AvatarUpload({ currentUrl, userName }: AvatarUploadProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const initials = userName
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    return (
        <div className="flex items-center gap-6">
            <div className="relative">
                <Avatar className="size-20">
                    {currentUrl ? (
                        <AvatarImage src={currentUrl} alt={userName} />
                    ) : null}
                    <AvatarFallback className="text-lg">{initials}</AvatarFallback>
                </Avatar>

                <Form
                    {...AvatarController.store.form()}
                    options={{ preserveScroll: true }}
                    encType="multipart/form-data"
                    className="absolute -bottom-1 -right-1"
                >
                    {({ processing }) => (
                        <>
                            <input
                                ref={fileInputRef}
                                type="file"
                                name="avatar"
                                accept="image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={(e: FormEvent<HTMLInputElement>) => {
                                    if (e.currentTarget.files?.length) {
                                        e.currentTarget.form?.requestSubmit();
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="secondary"
                                className="size-7 rounded-full"
                                disabled={processing}
                                onClick={() => fileInputRef.current?.click()}
                            >
                                <Camera className="size-3.5" />
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            {currentUrl && (
                <Form
                    {...AvatarController.destroy.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            <Trash2 className="mr-1.5 size-3.5" />
                            Remove
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}
```

> **Punto clave:** El formulario de upload usa `requestSubmit()` en el `onChange` del input file — el usuario selecciona el archivo y se envía automáticamente sin botón intermedio. El botón "Remove" solo se muestra cuando existe avatar. Las rutas se resuelven con Wayfinder (`AvatarController.store.form()`), lo que garantiza type-safety en los endpoints.

### 19.9 Frontend: admin panel (landlord)

Todas las páginas del landlord viven bajo `/admin` y están protegidas por `EnsureUserIsAdmin` (ver §16). Usan `useForm` de Inertia para el manejo de formularios y Wayfinder para las rutas.

**`app/Http/Controllers/Landlord/AdminPanelController.php`** — sirve la página de inicio del landlord:

```php
<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class AdminPanelController extends Controller
{
    public function index()
    {
        return Inertia::render('landlord/admin-panel');
    }
}
```

> **Punto clave:** El controller no lee datos de tenant ni aplica middleware `tenant`. Es deliberadamente simple — renderiza un placeholder que puede recibir widgets más adelante (cantidad de tenants, estado de BDs, actividad reciente). La ruta está protegida por `EnsureUserIsAdmin`, por lo que solo un `Landlord` autenticado puede acceder.

#### Admin panel home — `resources/js/pages/landlord/admin-panel.tsx`

Página de inicio del landlord. Renderiza un layout placeholder con cards para métricas futuras:

```tsx
import { Head } from '@inertiajs/react';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Panel', href: '/admin' },
];

export default function AdminPanel() {
    return (
        <>
            <Head title="Panel" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </>
    );
}

AdminPanel.layout = { breadcrumbs };
```

> **Punto clave:** `AdminPanel` es un placeholder intencional — el homepage del landlord está diseñado para recibir widgets de dashboard (cantidad de tenants, estado de BDs, últimas actividades) sin necesidad de cambiar la estructura del layout.

#### Tenant list — `resources/js/pages/landlord/tenants/index.tsx`

Lista todos los tenants con botones de acción por fila:

```tsx
import { type BreadcrumbItem } from '@/types';
import { create, show, edit } from '@/routes/landlord/tenants';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Eye, Building, Globe, Database } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
];

export default function TenantIndex({ tenants }: { tenants: any[] }) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Tenants</h1>
                <Button asChild data-testid="create-tenant-btn">
                    <a href={create().url}>
                        <Plus className="h-4 w-4" />
                        Create Tenant
                    </a>
                </Button>
            </div>
            <div className="border rounded-lg divide-y">
                {tenants.length === 0 ? (
                    <p className="p-4 text-gray-500">No tenants yet.</p>
                ) : (
                    tenants.map((tenant: any) => (
                        <div key={tenant.id} className="p-4 flex justify-between items-center" data-testid={`tenant-row-${tenant.id}`}>
                            <div className="space-y-1">
                                <p className="font-medium flex items-center gap-2" data-testid={`tenant-name-${tenant.id}`}>
                                    <Building className="h-4 w-4 text-muted-foreground" />
                                    {tenant.name}
                                </p>
                                <p className="text-sm text-gray-500 flex items-center gap-2" data-testid={`tenant-domain-${tenant.id}`}>
                                    <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                                    {tenant.domain}
                                </p>
                                <p className="text-sm text-gray-400 flex items-center gap-2" data-testid={`tenant-database-${tenant.id}`}>
                                    <Database className="h-3.5 w-3.5 text-muted-foreground" />
                                    {tenant.database}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" asChild data-testid={`edit-tenant-btn-${tenant.id}`}>
                                    <a href={edit(tenant.id).url}>
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" asChild data-testid={`view-tenant-btn-${tenant.id}`}>
                                    <a href={show(tenant.id).url}>
                                        <Eye className="h-4 w-4" />
                                        View
                                    </a>
                                </Button>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
```

> **Punto clave:** Cada fila muestra name/domain/database y ofrece botones Edit y View. Los `data-testid` habilitan selectores para los browser tests (ver §22). Usa Wayfinder para las URLs (`create().url`, `edit(tenant.id).url`, `show(tenant.id).url`).

#### Create tenant — `resources/js/pages/landlord/tenants/create.tsx`

Formulario de creación con validación y auto-provisioning de BD:

```tsx
import { type BreadcrumbItem } from '@/types';
import { FormEventHandler } from 'react';
import { useForm } from '@inertiajs/react';
import { store, index } from '@/routes/landlord/tenants';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import InputError from '@/components/input-error';
import { Building, Globe, Database, X, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Create', href: '/admin/tenants/create' },
];

export default function TenantCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        domain: '',
        database: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(store().url);
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="flex justify-between items-center mb-6">
                    <h1 className="text-2xl font-bold w-[200px] truncate">Create Tenant</h1>
                    <div className="flex gap-2 shrink-0">
                        <Button variant="outline" asChild>
                            <a href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </a>
                        </Button>
                        <Button type="submit" disabled={processing} data-testid="submit-tenant-btn">
                            <Plus className="h-4 w-4" />
                            {processing ? 'Creating...' : 'Create Tenant'}
                        </Button>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant details</CardTitle>
                        <CardDescription>
                            Configure the basic information for the new tenant.
                            The database will be created and migrated automatically.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name" className="flex items-center gap-2">
                                <Building className="h-4 w-4" />
                                Name
                            </Label>
                            <Input
                                id="name"
                                data-testid="input-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Acme Corp"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="domain" className="flex items-center gap-2">
                                <Globe className="h-4 w-4" />
                                Domain
                            </Label>
                            <Input
                                id="domain"
                                data-testid="input-domain"
                                value={data.domain}
                                onChange={(e) => setData('domain', e.target.value)}
                                placeholder="tenant1.example.com"
                            />
                            <InputError message={errors.domain} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="database" className="flex items-center gap-2">
                                <Database className="h-4 w-4" />
                                Database
                            </Label>
                            <Input
                                id="database"
                                data-testid="input-database"
                                value={data.database}
                                onChange={(e) => setData('database', e.target.value)}
                                placeholder="tenant1_database"
                            />
                            <InputError message={errors.database} />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </form>
    );
}
```

> **Punto clave:** El formulario POSTea a `store().url` (ruta Wayfinder). Al recibir los datos, el controller llama a `Tenant::create()` que dispara el callback `creating` del modelo (ver §11): crea la BD física, configura la conexión y corre migraciones. La card description le avisa al admin que el provisioning es automático.

#### Tenant detail — `resources/js/pages/landlord/tenants/show.tsx`

Muestra la información completa del tenant con opciones de edición y borrado (con confirmación):

```tsx
import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/react';
import { destroy, edit, index } from '@/routes/landlord/tenants';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Building, Globe, Database, Calendar, ArrowLeft, Pencil, Trash2 } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Details', href: '#' },
];

export default function TenantShow({ tenant }: { tenant: { id: number; name: string; domain: string; database: string; created_at: string } }) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold w-[200px] truncate">{tenant.name}</h1>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <a href={index().url}>
                            <ArrowLeft className="h-4 w-4" />
                            Back
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={edit(tenant.id).url}>
                            <Pencil className="h-4 w-4" />
                            Edit
                        </a>
                    </Button>
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive" data-testid="delete-tenant-trigger">
                                <Trash2 className="h-4 w-4" />
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete "{tenant.name}"?</DialogTitle>
                            <DialogDescription>
                                This will permanently delete the tenant and drop its database.
                                This action cannot be undone.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    data-testid="confirm-delete-btn"
                                    onClick={() => router.delete(destroy(tenant.id).url)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>Tenant details</CardTitle>
                    <CardDescription>
                        The tenant's current configuration and database information.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="flex items-center gap-2">
                            <Building className="h-4 w-4" />
                            Name
                        </Label>
                        <div
                            id="name"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.name}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="domain" className="flex items-center gap-2">
                            <Globe className="h-4 w-4" />
                            Domain
                        </Label>
                        <div
                            id="domain"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.domain}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="database" className="flex items-center gap-2">
                            <Database className="h-4 w-4" />
                            Database
                        </Label>
                        <div
                            id="database"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.database}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="created_at" className="flex items-center gap-2">
                            <Calendar className="h-4 w-4" />
                            Created
                        </Label>
                        <div
                            id="created_at"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.created_at}
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
```

> **Punto clave:** El botón Delete abre un `Dialog` de confirmación — acción destructiva irreversible (dropea la BD física). Usa `router.delete()` directo para evitar el formulario. Los `data-testid` (`delete-tenant-trigger`, `confirm-delete-btn`) son los mismos que usan los browser tests (ver §22).

#### Edit tenant — `resources/js/pages/landlord/tenants/edit.tsx`

Formulario pre-poblado con los datos actuales del tenant:

```tsx
import { type BreadcrumbItem } from '@/types';
import { FormEventHandler } from 'react';
import { useForm } from '@inertiajs/react';
import { update, index } from '@/routes/landlord/tenants';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import InputError from '@/components/input-error';
import { Building, Globe, Database, X, Save } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Edit', href: '#' },
];

export default function TenantEdit({ tenant }: { tenant: { id: number; name: string; domain: string; database: string } }) {
    const { data, setData, put, processing, errors } = useForm({
        name: tenant.name,
        domain: tenant.domain,
        database: tenant.database,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update(tenant.id).url);
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="flex justify-between items-center mb-6">
                    <h1 className="text-2xl font-bold w-[200px] truncate">Edit Tenant</h1>
                    <div className="flex gap-2 shrink-0">
                        <Button variant="outline" asChild>
                            <a href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </a>
                        </Button>
                        <Button type="submit" disabled={processing} data-testid="edit-tenant-submit-btn">
                            <Save className="h-4 w-4" />
                            {processing ? 'Saving...' : 'Save'}
                        </Button>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant details</CardTitle>
                        <CardDescription>
                            Update the tenant information. The database
                            structure is not affected by these changes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name" className="flex items-center gap-2">
                                <Building className="h-4 w-4" />
                                Name
                            </Label>
                            <Input
                                id="name"
                                data-testid="edit-input-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="domain" className="flex items-center gap-2">
                                <Globe className="h-4 w-4" />
                                Domain
                            </Label>
                            <Input
                                id="domain"
                                value={data.domain}
                                onChange={(e) => setData('domain', e.target.value)}
                                placeholder="tenant1.example.com"
                            />
                            <InputError message={errors.domain} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="database" className="flex items-center gap-2">
                                <Database className="h-4 w-4" />
                                Database
                            </Label>
                            <Input
                                id="database"
                                value={data.database}
                                onChange={(e) => setData('database', e.target.value)}
                                placeholder="tenant1_database"
                            />
                            <InputError message={errors.database} />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </form>
    );
}
```

> **Punto clave:** PUT a `update(tenant.id).url` (Wayfinder). La card description aclara que cambios en name/domain/database NO afectan la BD física ya creada — solo actualizan el registro en la tabla `tenants`. El `data-testid="edit-tenant-submit-btn"` es usado por el browser test de edición.

---

## 20. Archivos clave — Resumen

> Esta sección lista los 66 archivos clave del proyecto en dos vistas complementarias: **20.1** los agrupa por tipo de archivo (útil para entender la arquitectura — "qué hace cada pieza de código"); **20.2** los lista por sección del doc, en orden cronológico del build (útil para entender el orden de creación — "cuándo se agregó cada pieza"). El nombre del archivo es la columna compartida entre las dos vistas — usalo como join key para cruzar de una a otra.

### 20.1 Vista por tipo de archivo

#### Modelos

| Archivo | Sección | Función |
|---|---|---|
| `app/Models/User.php` | 9 | Modelo `Authenticatable` para tenants; usa el trait `UsesTenantConnection`; las queries corren sobre la conexión `tenant`. |
| `app/Models/Landlord.php` | 10 | Modelo `Authenticatable` para landlord; usa `UsesLandlordConnection`; reusa la tabla `users` del landlord. |
| `app/Models/Tenant.php` | 11 | Modelo Spatie `Tenant`; el callback `creating` ejecuta `createDatabase()` (idempotente — chequea `pg_database`), configura la conexión `tenant` y corre migraciones. |

#### Controllers (Landlord & Settings)

| Archivo | Sección | Función |
|---|---|---|
| `app/Http/Controllers/Landlord/AdminPanelController.php` | general | Renderiza la página Inertia `landlord/admin-panel` en `/admin` (home del landlord, ver §16.4); nunca entra en contexto tenant. |
| `app/Http/Controllers/Landlord/TenantController.php` | 11 | CRUD completo de tenants; `store()` llama a `Tenant::create()` y dropea la BD si el callback de provisioning lanza excepción; `destroy()` dropea la BD física y elimina el registro. |
| `app/Http/Controllers/Settings/AvatarController.php` | 19 | Subida y borrado de avatar de perfil (usando spatie/laravel-medialibrary); `store()` valida y sube, `destroy()` limpia la colección. |

#### Middleware

| Archivo | Sección | Función |
|---|---|---|
| `app/Http/Middleware/EnsureUserIsAdmin.php` | 16 | Aborta con 403 salvo que `$request->user()` sea instancia de `Landlord`. |
| `app/Http/Middleware/HandleInertiaRequests.php` | 16 | Comparte `auth.is_admin` (true si el user es `Landlord`) — consumido por `app-sidebar` para el nav role-aware. |
| `app/Http/Middleware/RedirectIfAuthenticated.php` | 16 | Extiende `Illuminate\Auth\Middleware\RedirectIfAuthenticated`; override de `redirectTo()` para que landlord vaya a `/admin` (no a `/dashboard`). Aplica al alias `guest` que Fortify usa en login/register (ver §16.5). |

#### Multitenancy Tasks

| Archivo | Sección | Función |
|---|---|---|
| `app/Multitenancy/Tasks/SwitchFilesystemTask.php` | 19.5 | Aísla el filesystem por tenant: sobreescribe el prefijo del disco `tenant` a `tenant_{id}` en `makeCurrent()`, lo restaura en `forgetCurrent()`. Incluye `Storage::clearResolvedInstance()` para evitar cache de instancia. |
| `app/Multitenancy/Tasks/SwitchTenantLoggingTask.php` | 19.6 | Inyecta `tenant_id` en el contexto compartido del logger vía `Log::shareContext()` en `makeCurrent()`, lo limpia con `withoutContext()` en `forgetCurrent()`. |

#### Auth System

| Archivo | Sección | Función |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | 13 | Registra el driver Auth `multi-tenant` que devuelve un `MultiTenantUserProvider`. |
| `app/Providers/MultiTenantUserProvider.php` | 13 | `EloquentUserProvider`; `createModel()` resuelve `Landlord` o `User` vía el trait `ResolvesUserModel`. |
| `app/Providers/FortifyServiceProvider.php` | 14 | Engancha `CreateNewUser` (modelo dinámico) y `ResetUserPassword` (tipo `Authenticatable`) a Fortify. También bindea `LoginResponse`/`RegisterResponse` → `RoleAwareAuthResponse` para redirect post-auth role-aware. |
| `app/Http/Responses/RoleAwareAuthResponse.php` | 16 | Implementa `LoginResponse` y `RegisterResponse` de Fortify; redirige a `/admin` si el user es `Landlord`, a `/dashboard` en caso contrario. Usa `redirect()->intended()`. |
| `app/Concerns/ResolvesUserModel.php` | 13 | Trait con `resolveUserModel()` = `Tenant::current() ? User::class : Landlord::class`; fuente única de verdad para la resolución del modelo. |
| `app/Concerns/ProfileValidationRules.php` | 14 | `emailRules()` invoca `resolveUserModel()` para que `Rule::unique()` apunte a la conexión activa. |
| `app/Actions/Fortify/CreateNewUser.php` | 14 | Retorna `BaseUser` para que `Landlord` cumpla el contrato `CreatesNewUsers` de Fortify. |
| `app/Actions/Fortify/ResetUserPassword.php` | 15 | Parámetro tipado como `Authenticatable` para aceptar `Landlord` o `User` sin TypeError. |

#### Config

| Archivo | Sección | Función |
|---|---|---|
| `.env.example` | 1 | Template del `.env` local con las claves relevantes para multitenancy (DB, session, cache, queue). |
| `.env.testing.example` | 22 | Template del `.env.testing` con base de datos `spatie-laravel-multitenancy-testing` y drivers array/sync para sesiones, cache, colas y mail. |
| `config/multitenancy.php` | 4, 19 | Usa `DomainTenantFinder`, `SwitchTenantDatabaseTask`, `PrefixCacheTask` (en este orden — ver §19.1) y `tenant_model => Tenant::class`. |
| `config/database.php` | 5 | Define las conexiones `pgsql`, `landlord` y `tenant` (con `database => null`) apuntando a PostgreSQL. |
| `config/auth.php` | 13 | Provider `users` con `driver => multi-tenant` para resolución tenant-aware del modelo. |
| `config/filesystems.php` | 19.5 | Define los discos `public` y `tenant` (driver `scoped` wrapping `public`); el prefix del disco `tenant` se sobreescribe en runtime. |
| `config/media-library.php` | 19.5 | Configura spatie/laravel-medialibrary; `disk_name` se setea a `tenant` y se sobreescribe por SwitchFilesystemTask. |

#### Bootstrap & Routes

| Archivo | Sección | Función |
|---|---|---|
| `bootstrap/app.php` | 7, 16 | Registra el grupo `tenant` (`NeedsTenant` + `EnsureValidTenantSession`) y override del alias `guest` → `App\Http\Middleware\RedirectIfAuthenticated` (ver §16.5). |
| `routes/web.php` | 8, 16 | Cuatro grupos bien delimitados: públicas, compartidas (carga `settings.php`), SaaS tenant (`tenant` + auth + verified, contiene `dashboard`), y admin (carga `landlord.php` con `EnsureUserIsAdmin`). El `dashboard` vive en el grupo `tenant` — un landlord en el dominio principal recibe 404 al tipearlo. |
| `routes/landlord.php` | 8 | Rutas `/admin` con stack `auth` + `verified` + `EnsureUserIsAdmin`; namespace `landlord.*`. |
| `routes/settings.php` | general | Rutas de profile/security/appearance/avatar requeridas por web.php; incluye `POST /settings/profile/avatar` y `DELETE /settings/profile/avatar` para el avatar upload. No es multitenancy-specific (los endpoints son compartidos). |

#### Tests

| Archivo | Categoría | Función |
|---|---|---|
| `tests/TestCase.php` | Base | Clase base de tests; configura el entorno con RefreshDatabase para landlord. |
| `tests/Pest.php` | Base | Pest config: `RefreshDatabase` extendido (resuelve `RefreshLandlordDatabase`), `DB::extend('tenant', ...)` para conexión tenant sin tenant activo. |
| `tests/Support/RefreshLandlordDatabase.php` | Support | Trait que ejecuta migraciones landlord (`database/migrations/landlord/`) antes de cada test. |
| `tests/Feature/Auth/AuthenticationTest.php` | Feature (adaptado) | Login de landlord; usa `Landlord` factory (no `User`), assert redirect a `/admin`. |
| `tests/Feature/Auth/PasswordResetTest.php` | Feature (adaptado) | Reset de contraseña de landlord; usa `Landlord` factory. |
| `tests/Feature/Auth/RegistrationTest.php` | Feature (adaptado) | Registro de landlord; assert redirect a `/admin` en vez de `/dashboard`. |
| `tests/Feature/DashboardTest.php` | Feature (adaptado) | Test renombrado: "landlord admin panel can be accessed by authenticated landlord"; usa `Landlord` factory, ruta `landlord.admin-panel`. |
| `tests/Feature/ExampleTest.php` | Feature (adaptado) | Test de salud: `GET /` → 200; adaptado a la estructura multi-dominio. |
| `tests/Feature/Settings/ProfileUpdateTest.php` | Feature (adaptado) | Profile update de landlord; usa `Landlord` factory con `createQuietly()`. |
| `tests/Feature/Settings/SecurityTest.php` | Feature (adaptado) | Cambio de contraseña y 2FA de landlord; usa `Landlord` factory con `createQuietly()`. |
| `tests/Feature/Tenant/TenantControllerTest.php` | Feature | 10 tests: CRUD completo de tenants (index, create, store, show, edit, update, destroy, validación, auth, forbidden). |
| `tests/Feature/Tenant/TenantTest.php` | Feature | 5 tests: Tenant model, factory, scopes. |
| `tests/Feature/Tenant/MultitenancyConfigTest.php` | Feature | 6 tests: Configuración de conexiones `tenant`/`landlord`, tareas de switch, tenant finder. |
| `tests/Feature/Tenant/SwitchFilesystemTaskTest.php` | Feature | 10 tests: Aislamiento de prefix, compatibilidad medialibrary, cache flush, interface, config. |
| `tests/Feature/Tenant/SwitchTenantLoggingTaskTest.php` | Feature | 5 tests: Contexto compartido del logger, cambio entre tenants, limpieza. |
| `tests/Unit/ExampleTest.php` | Unit | Test de humo: `expect(true)->toBeTrue()`. Solo se agregó un newline al final (EOF fix). |
| `tests/Browser/BrowserTestCase.php` | Browser | Base class para browser tests; extiende `Tests\DuskTestCase` (o Pest + Playwright) con setup tenant-aware. |
| `tests/Browser/Tenant/TenantCrudBrowserTest.php` | Browser | 8 tests: Playwright real para CRUD (index, create, show, edit, delete, validación, auth). |

#### Factories

| Archivo | Sección | Función |
|---|---|---|
| `database/factories/LandlordFactory.php` | 10 | Factory para `Landlord` con estado `admin` por defecto (`is_admin = true`). |
| `database/factories/TenantFactory.php` | 11 | Factory para `Tenant` usada en seeders y tests; genera name/domain/database únicos para `Tenant::create()`. |

#### Seeders & Migrations

| Archivo | Sección | Función |
|---|---|---|
| `database/migrations/landlord/2026_05_29_183736_create_landlord_tenants_table.php` | 6 | Crea la tabla `tenants` en la BD landlord (columnas: name, domain unique, database unique). |
| `database/migrations/2026_06_03_175326_create_media_table.php` | 19.5 | Migración de spatie/laravel-medialibrary; crea la tabla `media` con columnas para modelos, colecciones, disk, conversiones. Corre en landlord y se propaga a cada tenant vía el callback `creating` de Tenant. |
| `database/seeders/LandlordUserSeeder.php` | 10 | Crea el Landlord `admin@example.test` vía factory. |
| `database/seeders/TenantsSeeder.php` | 11 | Crea dos tenants de prueba; cada `Tenant::create()` dispara el provisioning automático (BD + conexión + migraciones). |
| `database/seeders/DatabaseSeeder.php` | 11 | Llama a `LandlordUserSeeder` y luego `TenantsSeeder` para `php artisan db:seed`. |

#### Scripts

| Archivo | Sección | Función |
|---|---|---|
| `scripts/add-host.php` | 17 | CLI standalone; agrega `<ip> <tenant-host>` al archivo hosts de Windows/Linux (requiere admin). |
| `scripts/remove-host.php` | 17 | CLI standalone; elimina la línea coincidente del archivo hosts del sistema. |

#### Frontend (Inertia/React)

| Archivo | Sección | Función |
|---|---|---|
| `resources/js/components/app-sidebar.tsx` | general | Sidebar role-aware: `mainNavItems` (Panel → `/admin` para landlords, Dashboard → `/dashboard` para tenants) y el link del logo (también role-aware: `/admin` o `/dashboard`) cambian según `auth.is_admin`. Admin suma grupo "Tenants". **Landlords nunca aterrizan en `/dashboard` por flujo normal** — ver §16.4 (POST-auth redirect) + §16.5 (guest redirect) + §7 (`tenant` middleware → 404). |
| `resources/js/components/nav-main.tsx` | general | Renderer genérico de grupo de sidebar; consumido por `app-sidebar` para ambos roles. |
| `resources/js/components/avatar-upload.tsx` | 19.5 | Componente de subida de avatar con preview, botón de cámara (input file oculto), y botón "Remove" cuando existe avatar. Consume endpoint `POST /settings/profile/avatar` y `DELETE /settings/profile/avatar`. |
| `resources/js/pages/settings/profile.tsx` | 19.5 | Página de perfil de usuario; integra `AvatarUpload` con preview del avatar actual, más el formulario de nombre/email. |
| `resources/js/pages/landlord/admin-panel.tsx` | general | Página Inertia para `/admin` (home del landlord). NO usar `dashboard.tsx` en sesión landlord. |
| `resources/js/pages/landlord/tenants/index.tsx` | general | Lista tenants con botones Edit (link a edit) y View (link a show) por fila, y botón Create Tenant. |
| `resources/js/pages/landlord/tenants/create.tsx` | general | `useForm` que postea name/domain/database a `/admin/tenants`. |
| `resources/js/pages/landlord/tenants/show.tsx` | general | Renderiza tarjeta de detalle del tenant (id, domain, database, created_at) con botones Edit y Delete (con diálogo de confirmación). |
| `resources/js/pages/landlord/tenants/edit.tsx` | 8.1 | `useForm` con PUT a `/admin/tenants/{id}` para editar name/domain/database. Incluye validación y botones Save/Cancel. |

### 20.2 Vista cronológica — Archivos por sección del doc

| Sección | Archivo | Función |
|---|---|---|
| 4 | `config/multitenancy.php` | Usa `DomainTenantFinder`, `SwitchTenantDatabaseTask` y `tenant_model => Tenant::class`. |
| 1 | `.env.example` | Template del `.env` local con claves relevantes para multitenancy. |
| 5 | `config/database.php` | Define las conexiones `pgsql`, `landlord` y `tenant` (con `database => null`) apuntando a PostgreSQL. |
| 5 | `config/filesystems.php` | Define discos `public` y `tenant` (driver `scoped` wrapping `public`). |
| 5 | `config/media-library.php` | Configura medialibrary; `disk_name` se sobreescribe por SwitchFilesystemTask. |
| 6 | `database/migrations/landlord/2026_05_29_183736_create_landlord_tenants_table.php` | Crea la tabla `tenants` en la BD landlord (columnas: name, domain unique, database unique). |
| 6 | `database/migrations/2026_06_03_175326_create_media_table.php` | Migración de spatie/laravel-medialibrary; tabla `media` para modelos, colecciones, conversiones. |
| 7 | `bootstrap/app.php` | Registra el grupo `tenant` (`NeedsTenant` + `EnsureValidTenantSession`) y override del alias `guest` → `App\Http\Middleware\RedirectIfAuthenticated` (ver §16.5). |
| 8 | `routes/web.php` | Cuatro grupos bien delimitados: públicas, compartidas (carga `settings.php`), SaaS tenant (`tenant` + auth + verified, contiene `dashboard`), y admin (carga `landlord.php` con `EnsureUserIsAdmin`). El `dashboard` vive en el grupo `tenant` — un landlord en el dominio principal recibe 404 al tipearlo. |
| 8 | `routes/landlord.php` | Rutas `/admin` con stack `auth` + `verified` + `EnsureUserIsAdmin`; namespace `landlord.*`. |
| 9 | `app/Models/User.php` | Modelo `Authenticatable` para tenants; usa el trait `UsesTenantConnection`; las queries corren sobre la conexión `tenant`. |
| 10 | `app/Models/Landlord.php` | Modelo `Authenticatable` para landlord; usa `UsesLandlordConnection`; reusa la tabla `users` del landlord. |
| 10 | `database/factories/LandlordFactory.php` | Factory para `Landlord` con estado `admin` por defecto. |
| 10 | `database/seeders/LandlordUserSeeder.php` | Crea el Landlord `admin@example.test` vía factory. |
| 11 | `app/Models/Tenant.php` | Modelo Spatie `Tenant`; el callback `creating` ejecuta `createDatabase()` (idempotente — chequea `pg_database`), configura la conexión `tenant` y corre migraciones. |
| 11 | `database/factories/TenantFactory.php` | Factory para `Tenant` usada en seeders y tests. |
| 11 | `app/Http/Controllers/Landlord/TenantController.php` | CRUD de tenants; `store()` llama a `Tenant::create()` y dropea la BD si el callback de provisioning lanza excepción. |
| 11 | `database/seeders/TenantsSeeder.php` | Crea dos tenants de prueba; cada `Tenant::create()` dispara el provisioning automático (BD + conexión + migraciones). |
| 11 | `database/seeders/DatabaseSeeder.php` | Llama a `LandlordUserSeeder` y luego `TenantsSeeder` para `php artisan db:seed`. |
| 13 | `app/Concerns/ResolvesUserModel.php` | Trait con `resolveUserModel()` = `Tenant::current() ? User::class : Landlord::class`; fuente única de verdad para la resolución del modelo. |
| 13 | `app/Providers/MultiTenantUserProvider.php` | `EloquentUserProvider`; `createModel()` resuelve `Landlord` o `User` vía el trait `ResolvesUserModel`. |
| 13 | `app/Providers/AppServiceProvider.php` | Registra el driver Auth `multi-tenant` que devuelve un `MultiTenantUserProvider`. |
| 13 | `config/auth.php` | Provider `users` con `driver => multi-tenant` para resolución tenant-aware del modelo. |
| 14 | `app/Providers/FortifyServiceProvider.php` | Engancha `CreateNewUser` (modelo dinámico) y `ResetUserPassword` (tipo `Authenticatable`) a Fortify. También bindea `LoginResponse`/`RegisterResponse` → `RoleAwareAuthResponse` (en un loop sobre los contracts) para redirect post-auth role-aware. |
| 14 | `app/Concerns/ProfileValidationRules.php` | `emailRules()` invoca `resolveUserModel()` para que `Rule::unique()` apunte a la conexión activa. |
| 14 | `app/Actions/Fortify/CreateNewUser.php` | Retorna `BaseUser` para que `Landlord` cumpla el contrato `CreatesNewUsers` de Fortify. |
| 15 | `app/Actions/Fortify/ResetUserPassword.php` | Parámetro tipado como `Authenticatable` para aceptar `Landlord` o `User` sin TypeError. |
| 16 | `app/Http/Middleware/EnsureUserIsAdmin.php` | Aborta con 403 salvo que `$request->user()` sea instancia de `Landlord`. |
| 16 | `app/Http/Middleware/HandleInertiaRequests.php` | Comparte `auth.is_admin` (true si el user es `Landlord`) — consumido por `app-sidebar` para el nav role-aware. |
| 16 | `app/Http/Middleware/RedirectIfAuthenticated.php` | Extiende `Illuminate\Auth\Middleware\RedirectIfAuthenticated`; override de `redirectTo()` para que landlord vaya a `/admin` (no a `/dashboard`). Aplica al alias `guest` que Fortify usa en login/register (ver §16.5). |
| 16 | `app/Http/Responses/RoleAwareAuthResponse.php` | Implementa `LoginResponse` y `RegisterResponse` de Fortify; redirige a `/admin` si el user es `Landlord`, a `/dashboard` en caso contrario. Usa `redirect()->intended()`. |
| 17 | `scripts/add-host.php` | CLI standalone; agrega `<ip> <tenant-host>` al archivo hosts de Windows/Linux (requiere admin). |
| 17 | `scripts/remove-host.php` | CLI standalone; elimina la línea coincidente del archivo hosts del sistema. |
| 19.5 | `app/Multitenancy/Tasks/SwitchFilesystemTask.php` | Aísla el filesystem por tenant; sobreescribe el prefijo del disco `tenant` a `tenant_{id}`. |
| 19.5 | `app/Http/Controllers/Settings/AvatarController.php` | Subida y borrado de avatar de perfil con spatie/laravel-medialibrary. |
| 19.5 | `resources/js/components/avatar-upload.tsx` | Componente React de avatar con preview, upload y remove. |
| 19.5 | `resources/js/pages/settings/profile.tsx` | Página de perfil; integra `AvatarUpload` con preview del avatar. |
| 19.6 | `app/Multitenancy/Tasks/SwitchTenantLoggingTask.php` | Inyecta `tenant_id` en el contexto compartido del logger. |
| 22 | `tests/TestCase.php` | Clase base de tests; configura entorno con RefreshDatabase para landlord. |
| 22 | `tests/Pest.php` | Pest config: RefreshDatabase extendido, DB::extend para conexión tenant. |
| 22 | `tests/Support/RefreshLandlordDatabase.php` | Trait que ejecuta migraciones landlord antes de cada test. |
| 22 | `tests/Browser/BrowserTestCase.php` | Base class para browser tests con setup tenant-aware. |
| 22 | `tests/Feature/Auth/AuthenticationTest.php` | Login de landlord (User → Landlord, redirect a /admin). |
| 22 | `tests/Feature/Auth/PasswordResetTest.php` | Reset de password de landlord (User → Landlord). |
| 22 | `tests/Feature/Auth/RegistrationTest.php` | Registro de landlord (redirect a /admin). |
| 22 | `tests/Feature/DashboardTest.php` | Test renombrado: landlord admin panel, ruta landlord.admin-panel. |
| 22 | `tests/Feature/Settings/ProfileUpdateTest.php` | Profile de landlord (User → Landlord, createQuietly). |
| 22 | `tests/Feature/Settings/SecurityTest.php` | 2FA/password de landlord (User → Landlord, createQuietly). |
| 22 | `tests/Unit/ExampleTest.php` | Test de humo: `expect(true)->toBeTrue()`. Solo EOF fix. |
| general | `app/Http/Controllers/Landlord/AdminPanelController.php` | Renderiza la página Inertia `landlord/admin-panel` en `/admin` (home del landlord, ver §16.4); nunca entra en contexto tenant. |
| general | `routes/settings.php` | Rutas stock de profile/security/appearance requeridas por web.php; no es multitenancy-specific. |
| general | `resources/js/components/app-sidebar.tsx` | Sidebar role-aware: `mainNavItems` y el link del logo cambian según `auth.is_admin` (landlord → `/admin`, tenant → `/dashboard`). Admin suma grupo "Tenants". **Landlords nunca aterrizan en `/dashboard` por flujo normal** — ver §16.4 (POST-auth redirect) + §16.5 (guest redirect) + §7 (`tenant` middleware → 404). |
| general | `resources/js/components/nav-main.tsx` | Renderer genérico de grupo de sidebar; consumido por `app-sidebar` para ambos roles. |
| general | `resources/js/pages/landlord/admin-panel.tsx` | Página Inertia para `/admin` (home del landlord). NO usar `dashboard.tsx` en sesión landlord. |
| general | `resources/js/pages/landlord/tenants/index.tsx` | Lista tenants con botones Edit y View por fila, más botón Create Tenant. |
| general | `resources/js/pages/landlord/tenants/create.tsx` | `useForm` que postea name/domain/database a `/admin/tenants`. |
| general | `resources/js/pages/landlord/tenants/show.tsx` | Renderiza tarjeta de detalle del tenant (id, domain, database, created_at) con botones Edit y Delete con confirmación. |
| general | `resources/js/pages/landlord/tenants/edit.tsx` | `useForm` con PUT a `/admin/tenants/{id}` para editar name/domain/database. |

---

## 21. Verificación

| Flujo | Dominio Landlord | Dominio Tenant |
|-------|------------------|----------------|
| Registro de usuario | Usa `Landlord` + BD landlord | Usa `User` + BD tenant |
| Login | Usa `Landlord` + BD landlord | Usa `User` + BD tenant |
| Reset de contraseña | Acepta `Landlord` | Acepta `User` |
| Actualización de perfil | Valida contra BD landlord | Valida contra BD tenant |

---

## 22. Tests — Ejecución y categorías

### Cómo correr los tests

| Objetivo | Comando |
|---|---|
| Feature + Unit (default) | `php artisan test` |
| Solo Feature (HTTP simulado) | `php artisan test tests\Feature` |
| Solo Browser (Playwright real) | `php artisan test tests\Browser` |
| Un archivo específico | `php artisan test tests\Browser\Tenant\TenantCrudBrowserTest.php` |
| Todo completo | `php artisan test tests\Feature tests\Browser` |

### Categorías y resumen

| Capa | Tests | Stack | Estado |
|---|---|---|---|
| **Feature** | ~61 | Pest, HTTP simulado, PostgreSQL | ✅ ~58 pass, 3 skip* |
| **Browser** | 9 | Pest + Playwright, Chromium real | ✅ 9 pass |
| **Unit** | 1 | Pest | ✅ 1 pass |
| **Total** | **~70** | | ✅ **~67 pass, 3 skip** |

\* Los 3 skipped corresponden a `SecurityTest` — dependen de `Features::twoFactorAuthentication()` que no está habilitado en `config/fortify.php`.

### Entorno de testing

Tests contra PostgreSQL, no SQLite en memoria. Las conexiones `pgsql` standard y `landlord` apuntan a una BD de testing separada. Configurado vía `.env.testing`:

```env
# .env.testing
DB_CONNECTION=pgsql
DB_DATABASE=spatie-laravel-multitenancy-testing
SESSION_DRIVER=array      # Sin sesiones persistentes en tests
CACHE_STORE=array          # Sin cache persistente
QUEUE_CONNECTION=sync      # Jobs se ejecutan inline
MAIL_MAILER=array          # Sin envio real de emails
BROADCAST_CONNECTION=null
BCRYPT_ROUNDS=4            # Hasheo rápido
```

> **Punto clave:** NO se usa SQLite en memoria. Las migraciones de tenants (`CREATE DATABASE`) requieren conexión real a PostgreSQL. `DB_CONNECTION=sqlite` haría fallar el callback `creating` de `Tenant` que necesita `pg_database` y `CREATE DATABASE` — operaciones exclusivas de PostgreSQL.

### Archivos de test — código completo

#### Base test infrastructure

Estos archivos configuran el entorno de testing para multitenancy. Veamos cada uno:

**`phpunit.xml`** (en la raíz del proyecto) es mínimo — solo define `APP_ENV=testing`. El resto de la configuración de testing vive en `.env.testing` (gitignoreado, copia local de `.env.testing.example` con credenciales reales de BD). `tests/TestCase.php` es la clase base que extienden todos los tests:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
}
```

**`tests/Pest.php`** — configura Pest para que use la conexión landlord y la extensión de la conexión tenant:

```php
<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshLandlordDatabase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/
uses(
    Tests\TestCase::class,
    RefreshLandlordDatabase::class,
)->in('Feature')->in('Unit');

uses(
    Tests\Browser\BrowserTestCase::class,
)->in('Browser');

/*
|--------------------------------------------------------------------------
| Extender la conexión tenant para tests
|--------------------------------------------------------------------------
|
| Spatie espera que la conexión 'tenant' esté definida, pero en los tests
| no hay un tenant activo (el request no pasa por el middleware de Spatie).
| Sin esta extensión de base de datos, cualquier query contra la conexión
| 'tenant' falla porque 'database' es null en config/database.php (ver §5).
|
| Esta extensión mockea la conexión 'tenant' apuntando a la misma BD que
| la conexión 'pgsql' (la BD landlord de testing), lo que permite que los
| tests de Feature que usan User::factory() (el modelo de tenant) funcionen
| sin un tenant HTTP activo. La BD tenant real se crea dinámicamente en el
| callback creating de Tenant (ver §11) -- pero en los tests no llegamos a
| ese código a menos que estemos probando el modelo Tenant directamente.
*/
DB::extend('tenant', function ($config, $name) {
    $config['database'] = config('database.connections.pgsql.database');
    return DB::connectUsing('tenant', $config);
});
```

> **Punto clave:** `DB::extend('tenant', ...)` es esencial — sin esto, la conexión `tenant` tiene `database => null` y cualquier query contra el modelo `User` (que usa `UsesTenantConnection`) falla en los tests. La extensión redirige temporalmente a la BD landlord de testing.

**`tests/Support/RefreshLandlordDatabase.php`** — trait que refresca las migraciones de la carpeta landlord antes de cada test:

```php
<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait RefreshLandlordDatabase
{
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');

            $this->artisan('migrate', [
                '--path' => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force' => true,
            ]);

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }
}
```

> **Punto clave:** `refreshTestDatabase()` corre `migrate:fresh` (migraciones default de Laravel) y luego las migraciones de `database/migrations/landlord/` (creación de la tabla `tenants`). Sin este paso, la tabla `tenants` no existe en la BD de testing y `Tenant::factory()->create()` falla porque `assertTenantsTableExists()` lanza `RuntimeException`.

**`tests/Browser/BrowserTestCase.php`** — base class para browser tests. Extiende `TestCase` pero overridea `refreshDatabase()` porque el HTTP server de browser tests corre en un proceso PHP separado y no puede ver datos no commiteados:

```php
<?php

namespace Tests\Browser;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class BrowserTestCase extends TestCase
{
    protected function refreshDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            $this->artisan('migrate', [
                '--path' => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force' => true,
            ]);

            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }
        // Intentionally skip database transactions — the browser HTTP
        // server runs in a separate PHP process and cannot see uncommitted
        // data. Per-test cleanup is handled in setUp() instead.
    }

    protected function setUp(): void
    {
        parent::setUp();

        $landlord = $this->app->make('db')->connection('landlord');
        $landlord->table('tenants')->delete();
        $landlord->table('users')->delete();
    }
}
```

> **Punto clave:** Los browser tests no pueden usar transacciones (el servidor HTTP está en otro proceso). En vez de eso, `setUp()` hace DELETE manual de las tablas que los tests escriben (`tenants`, `users`). `refreshDatabase()` solo corre las migraciones una vez vía `RefreshDatabaseState::$migrated`.

---

#### Feature tests — Auth

Adaptados para usar `Landlord` factory en vez de `User`, y assert redirect a `/admin` en vez de `/dashboard`.

**`tests/Feature/Auth/AuthenticationTest.php`** — login de landlord:

```php
<?php

use App\Models\Landlord;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));
    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = Landlord::factory()->create();
    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $this->assertAuthenticated();
    $response->assertRedirect('/admin');
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = User::factory()->withTwoFactor()->create();
    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = Landlord::factory()->create();
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->post(route('logout'));
    $response->assertRedirect(route('home'));
    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = Landlord::factory()->create();
    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);
    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);
    $response->assertTooManyRequests();
});
```

> **Cambio clave respecto al starter kit original:** `Landlord` factory en vez de `User` para los tests de login en el dominio landlord. El redirect post-login va a `/admin` (ver §16.4). El test de 2FA usa `User` factory porque el challenge de 2FA ocurre ANTES de que se resuelva el rol (el redirect a `/two-factor-challenge` no depende del modelo).

**`tests/Feature/Auth/PasswordResetTest.php`** — reset de password de landlord:

```php
<?php

use App\Models\Landlord;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));
    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();
    $user = Landlord::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();
    $user = Landlord::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));
        $response->assertOk();
        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();
    $user = Landlord::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));
        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = Landlord::factory()->create();
    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);
    $response->assertSessionHasErrors('email');
});
```

> **Cambio clave:** `Landlord` factory en todos los tests. El flujo de reset usa `Notification::fake()` y `assertSentTo` — no depende del modelo porque Fortify resuelve el usuario por email y envía la notificación al modelo que corresponda (ver §15 para el fix del `TypeError` con `Authenticatable`).

**`tests/Feature/Auth/RegistrationTest.php`** — registro de landlord:

```php
<?php

use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));
    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    $this->assertAuthenticated();
    $response->assertRedirect('/admin');
});
```

> **Cambio clave:** Assert redirect a `/admin` en vez de `/dashboard`. `CreateNewUser` (ver §14) resuelve dinámicamente `Landlord` o `User` según el dominio, y `RoleAwareAuthResponse` (ver §16.4) redirige según `instanceof Landlord`. Como estos tests corren sin subdominio tenant, el auth provider devuelve `Landlord`.

---

#### Feature tests — Smoke

**`tests/Feature/ExampleTest.php`** — verifica que la ruta home (`/`) responde 200. Test de humo para confirmar que el entorno de testing funciona:

```php
<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));
    $response->assertOk();
});
```

> **Cambio:** Se reemplazó `$response->assertStatus(200)` por el encadenamiento Pest `->assertOk()`. La ruta `route('home')` apunta a la página welcome — accesible sin autenticación desde cualquier dominio.

---

#### Feature tests — Dashboard

**`tests/Feature/DashboardTest.php`** — acceso al admin panel de landlord:

```php
<?php

use App\Models\Landlord;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('landlord admin panel can be accessed by authenticated landlord', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);
    $response = $this->get(route('landlord.admin-panel'));
    $response->assertOk();
});
```

> **Cambio clave respecto al original:** El test de "dashboard" fue renombrado conceptualmente a "admin panel". `Landlord::factory()` en vez de `User::factory()`. La ruta `landlord.admin-panel` reemplaza a `dashboard` para landlords.

---

#### Feature tests — Settings

**`tests/Feature/Settings/ProfileUpdateTest.php`** — profile update de landlord:

```php
<?php

use App\Models\Landlord;

test('profile page is displayed', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->get(route('profile.edit'));
    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
    $response->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    $user->refresh();
    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => $user->email,
    ]);
    $response->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);
    $response->assertSessionHasNoErrors()->assertRedirect(route('home'));
    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong-password']);
    $response->assertSessionHasErrors('password')->assertRedirect(route('profile.edit'));
    expect($user->fresh())->not->toBeNull();
});
```

> **Cambios clave:** `Landlord::factory()->createQuietly()` en vez de `User`. `createQuietly` evita disparar eventos Eloquent innecesarios en tests. Los asserts de perfil y borrado usan `expect()->toBe()` (sintaxis Pest en vez de `$this->assertX()`).

**`tests/Feature/Settings/SecurityTest.php`** — cambio de contraseña y 2FA de landlord:

```php
<?php

use App\Models\Landlord;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('security page is displayed', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user)->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false),
        );
});

test('security page renders without two factor when feature is disabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    config(['fortify.features' => []]);
    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user)->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled')
            ->missing('requiresConfirmation'),
        );
});

test('password can be updated', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);
    $response->assertSessionHasNoErrors()->assertRedirect(route('security.edit'));
    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = Landlord::factory()->createQuietly();
    $response = $this->actingAs($user)->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);
    $response->assertSessionHasErrors('current_password')->assertRedirect(route('security.edit'));
});
```

> **Cambios clave:** `Landlord::factory()` en vez de `User`. Usa `assertInertia` para verificar props del componente Inertia. Los 3 tests con `Features::twoFactorAuthentication()` son los que aparecen como "skipped" en el resumen — dependen de que el feature esté habilitado en `config/fortify.php`.

---

#### Feature tests — Tenant (models, config, and custom tasks)

**`tests/Feature/Tenant/TenantControllerTest.php`** — 10 tests: CRUD completo de tenants (index, create, store, show, edit, update, destroy, validación, auth, forbidden):

```php
<?php

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
});

test('index returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenants = Tenant::factory()->count(2)->createQuietly();
    $this->actingAs($admin)->get(route('landlord.tenants.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/index')
            ->has('tenants', 2)
        );
});

test('create returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $this->actingAs($admin)->get(route('landlord.tenants.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/create')
        );
});

test('store creates a tenant', function () {
    $admin = Landlord::factory()->createQuietly();
    $dispatcher = Tenant::getEventDispatcher();
    Tenant::unsetEventDispatcher();
    try {
        $this->actingAs($admin)->post(route('landlord.tenants.store'), [
            'name' => 'New Tenant', 'domain' => 'newtenant.test', 'database' => 'new_tenant_db',
        ])->assertRedirect(route('landlord.tenants.index'));
    } finally {
        Tenant::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('tenants', [
        'name' => 'New Tenant', 'domain' => 'newtenant.test', 'database' => 'new_tenant_db',
    ], 'landlord');
});

test('store validates required fields', function () {
    $admin = Landlord::factory()->createQuietly();
    $this->actingAs($admin)->post(route('landlord.tenants.store'), [])
        ->assertSessionHasErrors(['name', 'domain', 'database']);
});

test('show returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($admin)->get(route('landlord.tenants.show', $tenant))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/show')->has('tenant')
        );
});

test('edit returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($admin)->get(route('landlord.tenants.edit', $tenant))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/edit')->has('tenant')
        );
});

test('update modifies a tenant', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($admin)->put(route('landlord.tenants.update', $tenant), [
        'name' => 'Updated Name', 'domain' => $tenant->domain, 'database' => $tenant->database,
    ])->assertRedirect(route('landlord.tenants.index'));
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Updated Name'], 'landlord');
});

test('destroy processes deletion for admin', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    DB::partialMock()->shouldReceive('unprepared')->andReturn(true);
    $this->actingAs($admin)->delete(route('landlord.tenants.destroy', $tenant))
        ->assertRedirect(route('landlord.tenants.index'));
});

test('unauthenticated user is redirected to login', function () {
    $this->get(route('landlord.tenants.index'))->assertRedirect(route('login'));
});

test('non admin landlord user receives forbidden', function () {
    $user = User::factory()->createQuietly();
    $this->actingAs($user)->get(route('landlord.tenants.index'))->assertForbidden();
});
```

> **Punto clave:** `store()` desactiva los eventos del modelo Tenant (porque `createDatabase()` necesita DDL que no corre en transacciones). `destroy()` mockea `DB::unprepared` porque `DROP DATABASE` tampoco corre dentro de una transacción. El último test verifica que un `User` (tenant user) recibe 403 al intentar acceder al panel — el middleware `EnsureUserIsAdmin` (ver §16) bloquea por `instanceof Landlord`.

**`tests/Feature/Tenant/TenantTest.php`** — 5 tests: Tenant model, factory, guard de tabla faltante:

```php
<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

test('factory creates a valid tenant', function () {
    $tenant = Tenant::factory()->createQuietly();
    expect($tenant->name)->not->toBeEmpty();
    expect($tenant->domain)->not->toBeEmpty();
    expect($tenant->database)->not->toBeEmpty();
    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id, 'name' => $tenant->name,
        'domain' => $tenant->domain, 'database' => $tenant->database,
    ], 'landlord');
});

test('factory state override pins the database field', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => 'custom_db']);
    expect($tenant->database)->toBe('custom_db');
});

test('tenant has required fillable attributes', function () {
    $tenant = Tenant::withoutEvents(fn () => Tenant::create([
        'name' => 'Test Tenant', 'domain' => 'test.example.com', 'database' => 'test_tenant_db',
    ]));
    expect($tenant->name)->toBe('Test Tenant');
    expect($tenant->domain)->toBe('test.example.com');
    expect($tenant->database)->toBe('test_tenant_db');
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id], 'landlord');
});

test('tenants table guard passes silently when table exists', function () {
    $tenant = Tenant::factory()->make();
    $reflection = new ReflectionMethod($tenant, 'assertTenantsTableExists');
    $reflection->invoke($tenant);
    expect(true)->toBeTrue();
});

test('tenants table guard throws actionable message on missing table', function () {
    $tenant = Tenant::factory()->make();
    $reflection = new ReflectionMethod($tenant, 'assertTenantsTableExists');
    $originalDb = config('database.connections.landlord.database');
    config(['database.connections.landlord.database' => 'postgres']);
    DB::purge('landlord');
    try {
        $reflection->invoke($tenant);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('php artisan migrate');
    } finally {
        config(['database.connections.landlord.database' => $originalDb]);
        DB::purge('landlord');
        DB::connection('landlord')->getPdo();
    }
});
```

> **Punto clave:** El guard `assertTenantsTableExists()` (ver código completo en §11) detecta si la tabla `tenants` no existe en la BD landlord y lanza un mensaje de error con `php artisan migrate ...` para que el desarrollador sepa exactamente qué correr. El último test verifica ese mensaje — cambia temporalmente la conexión landlord a una BD sin la tabla.

**`tests/Feature/Tenant/MultitenancyConfigTest.php`** — 6 tests: verifica que el archivo `config/multitenancy.php` tenga los valores correctos:

```php
<?php

use App\Models\Tenant;
use Spatie\Multitenancy\Tasks\PrefixCacheTask;
use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;
use Spatie\Multitenancy\TenantFinder\DomainTenantFinder;

test('multitenancy config loads as an array', function () {
    expect(config('multitenancy'))->toBeArray()->not->toBeEmpty();
});

test('tenant finder uses domain resolution', function () {
    expect(config('multitenancy.tenant_finder'))->toBe(DomainTenantFinder::class);
});

test('switch tenant tasks include core spatie tasks', function () {
    $tasks = config('multitenancy.switch_tenant_tasks');
    expect($tasks)->toContain(PrefixCacheTask::class);
    expect($tasks)->toContain(SwitchTenantDatabaseTask::class);
});

test('tenant model is the project Tenant class', function () {
    expect(config('multitenancy.tenant_model'))->toBe(Tenant::class);
});

test('landlord connection name is landlord', function () {
    expect(config('multitenancy.landlord_database_connection_name'))->toBe('landlord');
});

test('tenant connection name is tenant', function () {
    expect(config('multitenancy.tenant_database_connection_name'))->toBe('tenant');
});
```

> **Punto clave:** Tests de cordura que verifican que la configuración de Spatie no haya sido alterada. Si alguien cambia `tenant_finder` a `SubdomainTenantFinder` o modifica los nombres de conexión, estos tests fallan inmediatamente.

**`tests/Feature/Tenant/SwitchFilesystemTaskTest.php`** — 10 tests sobre aislamiento de filesystem por tenant:

```php
<?php

use App\Models\Tenant;
use App\Multitenancy\Tasks\SwitchFilesystemTask;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

test('make current sets tenant prefix', function () {
    $tenant = Tenant::factory()->createQuietly(['id' => 7]);
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);
    expect(config('filesystems.disks.tenant.prefix'))->toBe('tenant_7');
});

test('forget current restores original prefix', function () {
    Config::set('filesystems.disks.tenant.prefix', 'tenant');
    $tenant = Tenant::factory()->createQuietly(['id' => 7]);
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);
    $task->forgetCurrent();
    expect(config('filesystems.disks.tenant.prefix'))->toBe('tenant');
});

test('tenant prefixes are different per tenant', function () {
    $tenant1 = Tenant::factory()->createQuietly(['id' => 1]);
    $tenant2 = Tenant::factory()->createQuietly(['id' => 2]);
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant1);
    $prefix1 = config('filesystems.disks.tenant.prefix');
    $task->makeCurrent($tenant2);
    $prefix2 = config('filesystems.disks.tenant.prefix');
    expect($prefix1)->toBe('tenant_1');
    expect($prefix2)->toBe('tenant_2');
    expect($prefix1)->not->toBe($prefix2);
});

test('make current sets media library disk', function () {
    Config::set('media-library.disk_name', 'public');
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);
    expect(config('media-library.disk_name'))->toBe('tenant');
});

test('forget current restores media library disk', function () {
    Config::set('media-library.disk_name', 'public');
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);
    $task->forgetCurrent();
    expect(config('media-library.disk_name'))->toBe('public');
});

test('filesystem manager cache flushed on make current', function () {
    Config::set('filesystems.disks.tenant.prefix', 'tenant');
    $tenant = Tenant::factory()->createQuietly();
    $pathBefore = Storage::disk('tenant')->path('test.txt');
    expect($pathBefore)->toContain('tenant'.DIRECTORY_SEPARATOR);
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);
    $pathAfter = Storage::disk('tenant')->path('test.txt');
    expect($pathAfter)->toContain("tenant_{$tenant->getKey()}".DIRECTORY_SEPARATOR);
    expect($pathAfter)->not->toBe($pathBefore);
});

test('filesystem manager cache flushed on forget current', function () {
    Config::set('filesystems.disks.tenant.prefix', 'tenant');
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchFilesystemTask;
    $task->makeCurrent($tenant);
    $pathDuring = Storage::disk('tenant')->path('test.txt');
    expect($pathDuring)->toContain("tenant_{$tenant->getKey()}".DIRECTORY_SEPARATOR);
    $task->forgetCurrent();
    $pathAfter = Storage::disk('tenant')->path('test.txt');
    expect($pathAfter)->toContain('tenant'.DIRECTORY_SEPARATOR);
    expect($pathAfter)->not->toBe($pathDuring);
});

test('task implements switch tenant task interface', function () {
    expect(new SwitchFilesystemTask)->toBeInstanceOf(SwitchTenantTask::class);
});

test('switch tenant tasks config includes filesystem task', function () {
    $tasks = config('multitenancy.switch_tenant_tasks');
    expect($tasks)->toContain(SwitchFilesystemTask::class);
});

test('tenant disk uses scoped driver', function () {
    $disk = config('filesystems.disks.tenant');
    expect($disk['driver'])->toBe('scoped');
    expect($disk['disk'])->toBe('public');
    expect($disk['prefix'])->toBe('tenant');
});
```

> **Punto clave:** Los tests de cache flush (el 6° y 7°) verifican el fix del bug reportado en [spatie/laravel-multitenancy Discussion #480](https://github.com/spatie/laravel-multitenancy/discussions/480) — sin `app()->forgetInstance('filesystem')` + `Storage::clearResolvedInstance('filesystem')`, el `FilesystemManager` retiene el prefix anterior en su instancia cacheada.

**`tests/Feature/Tenant/SwitchTenantLoggingTaskTest.php`** — 5 tests sobre contexto compartido del logger:

```php
<?php

use App\Models\Tenant;
use App\Multitenancy\Tasks\SwitchTenantLoggingTask;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

test('make current sets tenant id in log context', function () {
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchTenantLoggingTask;
    $task->makeCurrent($tenant);
    expect(Log::sharedContext())->toBe(['tenant_id' => $tenant->getKey()]);
});

test('make current updates context when switching between different tenants', function () {
    $tenant1 = Tenant::factory()->createQuietly();
    $tenant2 = Tenant::factory()->createQuietly();
    $task = new SwitchTenantLoggingTask;
    $task->makeCurrent($tenant1);
    expect(Log::sharedContext())->toBe(['tenant_id' => $tenant1->getKey()]);
    $task->makeCurrent($tenant2);
    expect(Log::sharedContext())->toBe(['tenant_id' => $tenant2->getKey()]);
});

test('forget current clears tenant log context', function () {
    $tenant = Tenant::factory()->createQuietly();
    $task = new SwitchTenantLoggingTask;
    $task->makeCurrent($tenant);
    $task->forgetCurrent();
    expect(Log::sharedContext())->toBe([]);
});

test('task implements switch tenant task interface', function () {
    expect(new SwitchTenantLoggingTask)->toBeInstanceOf(SwitchTenantTask::class);
});

test('switch tenant tasks config includes the logging task', function () {
    $tasks = config('multitenancy.switch_tenant_tasks');
    expect($tasks)->toContain(SwitchTenantLoggingTask::class);
});
```

> **Punto clave:** `Log::sharedContext()` retorna el contexto compartido actual. El test de cambio entre tenants verifica que el contexto se actualiza (no se acumula). `forgetCurrent()` debe limpiar completamente el contexto para que el próximo request (incluso fuera de tenancy) no arrastre un `tenant_id` huérfano.

---

#### Browser tests

**`tests/Browser/Tenant/TenantCrudBrowserTest.php`** — 8 tests con Playwright real para CRUD de tenants:

```php
<?php

use App\Models\Landlord;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('index page shows tenant list', function () {
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($this->admin)->visit(route('landlord.tenants.index'))
        ->assertSee('Tenants')->assertSee($tenant->name)
        ->assertNoJavaScriptErrors();
});

test('create page loads with form fields', function () {
    $this->actingAs($this->admin)->visit(route('landlord.tenants.create'))
        ->assertSee('Create Tenant')->assertSee('Name')
        ->assertSee('Domain')->assertSee('Database')
        ->assertNoJavaScriptErrors();
});

test('tenant creation flow', function () {
    $this->actingAs($this->admin)->visit(route('landlord.tenants.create'))
        ->type('@input-name', 'Browser Test Tenant')
        ->type('@input-domain', 'browser-test.example.com')
        ->type('@input-database', 'browser_test_tenant')
        ->click('@submit-tenant-btn')
        ->waitForText('Browser Test Tenant')
        ->assertNoJavaScriptErrors();
});

test('shows validation errors when required fields are empty', function () {
    $this->actingAs($this->admin)->visit(route('landlord.tenants.create'))
        ->click('@submit-tenant-btn')
        ->waitForText('required')
        ->assertNoJavaScriptErrors();
});

test('detail page shows tenant information', function () {
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($this->admin)->visit(route('landlord.tenants.show', $tenant))
        ->assertSee($tenant->name)->assertSee($tenant->domain)
        ->assertSee($tenant->database)->assertNoJavaScriptErrors();
});

test('edit page loads with tenant data', function () {
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($this->admin)->visit(route('landlord.tenants.edit', $tenant))
        ->assertSee('Edit')->assertValue('@edit-input-name', $tenant->name)
        ->assertNoJavaScriptErrors();
});

test('edit flow updates tenant name', function () {
    $tenant = Tenant::factory()->createQuietly();
    $this->actingAs($this->admin)->visit(route('landlord.tenants.edit', $tenant))
        ->type('@edit-input-name', 'Updated Browser Name')
        ->click('@edit-tenant-submit-btn')
        ->waitForText('Updated Browser Name')
        ->assertNoJavaScriptErrors();
});

test('delete flow removes tenant from list', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    DB::partialMock()->shouldReceive('unprepared')->andReturn(true);
    $this->actingAs($admin)->visit(route('landlord.tenants.show', $tenant))
        ->click('@delete-tenant-trigger')->click('@confirm-delete-btn')
        ->assertDontSee($tenant->name)->assertNoJavaScriptErrors();
});

test('unauthenticated access redirects to login', function () {
    $this->visit(route('landlord.tenants.index'))
        ->assertPathIs('/login')->assertSee('Log in');
});
```

> **Punto clave:** Los browser tests usan selectores `@data-testid` (ej: `@input-name`, `@submit-tenant-btn`) que coinciden con los `data-testid` definidos en las páginas React (ver §19.9). Usan `assertNoJavaScriptErrors()` para detectar errores JS en la consola del browser. `DB::partialMock()` se usa en el test de delete porque `DROP DATABASE` no puede ejecutarse dentro de una transacción.

---

#### Unit tests

**`tests/Unit/ExampleTest.php`** — test de humo. Solo verifica que Pest funciona:

```php
<?php

test('true is true', function () {
    expect(true)->toBeTrue();
});
```

> **Cambio:** Se agregó un newline al final del archivo (EOF fix). El test en sí no cambió — sigue siendo el `expect(true)->toBeTrue()` del starter kit.
