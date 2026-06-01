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
APP_URL=http://localhost:8000

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

---

## 2. Migración inicial del proyecto base

```bash
php artisan migrate:fresh
```

Esto crea las tablas base (`users`, `sessions`, `cache`, `jobs`) en la BD landlord. Verificar que el login funciona en `http://spatie-laravel-multitenancy.test`.

---

## 3. Instalar Spatie Multitenancy

```bash
composer require spatie/laravel-multitenancy
php artisan vendor:publish --provider="Spatie\Multitenancy\MultitenancyServiceProvider" --tag="multitenancy-config"
```

Esto crea `config/multitenancy.php`.

---

## 4. Configurar `config/multitenancy.php`

Los valores relevantes a modificar:

```php
'tenant_finder' => DomainTenantFinder::class,

'switch_tenant_tasks' => [
    SwitchTenantDatabaseTask::class,
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

// -------------------------------------------------------
// Rutas públicas — sin restricciones, cualquier dominio
// -------------------------------------------------------
Route::inertia('/', 'welcome')->name('home');

// -------------------------------------------------------
// Rutas compartidas (admin y tenants) — requieren auth
// -------------------------------------------------------
Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// -------------------------------------------------------
// Rutas del producto SaaS — requieren tenant activo
// Accesibles desde subdominios de tenant.
// Middleware: tenant + auth + verified
// -------------------------------------------------------
Route::middleware(['tenant', 'auth', 'verified'])->group(function () {
    // Aquí van las rutas del producto SaaS para cada cliente.
});

// -------------------------------------------------------
// Rutas del admin/landlord — SIN middleware tenant
// Accesibles solo desde el dominio principal.
// Protegidas por EnsureUserIsAdmin.
// (Carga el archivo routes/landlord.php — ver sección 8.1)
// -------------------------------------------------------
require __DIR__.'/landlord.php';

require __DIR__.'/settings.php';
```

> **Punto clave:** `web.php` separa las rutas en **tres grupos**: públicas (home), compartidas con auth (dashboard), y de subdominio tenant (producto SaaS). Las rutas del panel admin viven en `routes/landlord.php` y se cargan aquí con `require` — no usan el middleware `tenant` y se protegen con `EnsureUserIsAdmin` (ver sección 16).

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

    // Dashboard
    Route::get('/', [AdminPanelController::class, 'index'])->name('admin-panel');

    // Tenant management
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
});
```

> **Punto clave:** El middleware `EnsureUserIsAdmin` se aplica **dentro** de `landlord.php` (no en `web.php`) porque solo este grupo de rutas debe ser admin-only. Las rutas públicas, compartidas y de tenant no deben pasar por él — un tenant intentando ver su propio dashboard fallaría con 403.

---

## 9. Configurar el modelo `User`

`app/Models/User.php`:

```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, UsesTenantConnection;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

---

## 10. Crear el modelo `Landlord`

`app/Models/Landlord.php`:

```php
<?php

namespace App\Models;

use Database\Factories\LandlordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class Landlord extends Authenticatable
{
    /** @use HasFactory<LandlordFactory> */
    use HasFactory, Notifiable, UsesLandlordConnection;

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

> **Nota — automatización actual:** En el flujo normal de seeders y del panel de admin, este paso es automático. El callback `creating` de `app/Models/Tenant.php` (método `createDatabase()`) crea la BD física cada vez que se invoca `Tenant::create()`. Además, `createDatabase()` es **idempotente**: chequea el catálogo `pg_database` antes de crear, así que puede llamarse varias veces seguidas sin provocar errores de tipo "42P04 database already exists". Por eso, ejecutar este paso a mano sólo tiene sentido si querés crear una BD de tenant por fuera del modelo (por ejemplo, durante una recuperación manual o para experimentar en psql).

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

- `scripts/add-host.php <hostname> [ip]` — agrega una entrada. IP por defecto: `127.0.0.1`. Si el hostname ya existe, sale con código 0 sin hacer nada (idempotente).
- `scripts/remove-host.php <hostname>` — elimina la entrada para ese hostname. Si no existe, sale con código 0.

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

> **Nota sobre el callback `creating` de `Tenant`:** el callback del modelo (`app/Models/Tenant.php`) sólo automatiza la creación de la BD física y la ejecución de migraciones en esa BD. **No toca el archivo `hosts`** por la razón explicada arriba. Después de cada `Tenant::create()` (manual, seeder, o desde el panel admin) tenés que correr `add-host.php` con el dominio del nuevo tenant. La sección 18 incluye este paso en el flujo completo de reset.

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

## 19. Archivos clave — Resumen

> Esta sección lista los 33 archivos clave del proyecto en dos vistas complementarias: **19.1** los agrupa por tipo de archivo (útil para entender la arquitectura — "qué hace cada pieza de código"); **19.2** los lista por sección del doc, en orden cronológico del build (útil para entender el orden de creación — "cuándo se agregó cada pieza"). El nombre del archivo es la columna compartida entre las dos vistas — usalo como join key para cruzar de una a otra.

### 19.1 Vista por tipo de archivo

#### Modelos

| Archivo | Sección | Función |
|---|---|---|
| `app/Models/User.php` | 9 | Modelo `Authenticatable` para tenants; usa el trait `UsesTenantConnection`; las queries corren sobre la conexión `tenant`. |
| `app/Models/Landlord.php` | 10 | Modelo `Authenticatable` para landlord; usa `UsesLandlordConnection`; reusa la tabla `users` del landlord. |
| `app/Models/Tenant.php` | 11 | Modelo Spatie `Tenant`; el callback `creating` ejecuta `createDatabase()` (idempotente — chequea `pg_database`), configura la conexión `tenant` y corre migraciones. |

#### Controllers (Landlord)

| Archivo | Sección | Función |
|---|---|---|
| `app/Http/Controllers/Landlord/AdminPanelController.php` | general | Renderiza la página Inertia `landlord/admin-panel` en `/admin` (home del landlord, ver §16.4); nunca entra en contexto tenant. |
| `app/Http/Controllers/Landlord/TenantController.php` | 11 | CRUD de tenants; `store()` llama a `Tenant::create()` y dropea la BD si el callback de provisioning lanza excepción. |

#### Middleware

| Archivo | Sección | Función |
|---|---|---|
| `app/Http/Middleware/EnsureUserIsAdmin.php` | 16 | Aborta con 403 salvo que `$request->user()` sea instancia de `Landlord`. |
| `app/Http/Middleware/HandleInertiaRequests.php` | 16 | Comparte `auth.is_admin` (true si el user es `Landlord`) — consumido por `app-sidebar` para el nav role-aware. |
| `app/Http/Middleware/RedirectIfAuthenticated.php` | 16 | Extiende `Illuminate\Auth\Middleware\RedirectIfAuthenticated`; override de `redirectTo()` para que landlord vaya a `/admin` (no a `/dashboard`). Aplica al alias `guest` que Fortify usa en login/register (ver §16.5). |

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
| `config/multitenancy.php` | 4 | Usa `DomainTenantFinder`, `SwitchTenantDatabaseTask` y `tenant_model => Tenant::class`. |
| `config/database.php` | 5 | Define las conexiones `pgsql`, `landlord` y `tenant` (con `database => null`) apuntando a PostgreSQL. |
| `config/auth.php` | 13 | Provider `users` con `driver => multi-tenant` para resolución tenant-aware del modelo. |

#### Bootstrap & Routes

| Archivo | Sección | Función |
|---|---|---|
| `bootstrap/app.php` | 7, 16 | Registra el grupo `tenant` (`NeedsTenant` + `EnsureValidTenantSession`) y override del alias `guest` → `App\Http\Middleware\RedirectIfAuthenticated` (ver §16.5). |
| `routes/web.php` | 8, 16 | Cuatro grupos bien delimitados: públicas, compartidas (carga `settings.php`), SaaS tenant (`tenant` + auth + verified, contiene `dashboard`), y admin (carga `landlord.php` con `EnsureUserIsAdmin`). El `dashboard` vive en el grupo `tenant` — un landlord en el dominio principal recibe 404 al tipearlo. |
| `routes/landlord.php` | 8 | Rutas `/admin` con stack `auth` + `verified` + `EnsureUserIsAdmin`; namespace `landlord.*`. |
| `routes/settings.php` | general | Rutas stock de profile/security/appearance requeridas por web.php; no es multitenancy-specific. |

#### Migrations & Seeders

| Archivo | Sección | Función |
|---|---|---|
| `database/migrations/landlord/2026_05_29_183736_create_landlord_tenants_table.php` | 6 | Crea la tabla `tenants` en la BD landlord (columnas: name, domain unique, database unique). |
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
| `resources/js/pages/landlord/admin-panel.tsx` | general | Página Inertia para `/admin` (home del landlord). NO usar `dashboard.tsx` en sesión landlord. |
| `resources/js/pages/landlord/tenants/index.tsx` | general | Lista tenants con link a las páginas create/show. |
| `resources/js/pages/landlord/tenants/create.tsx` | general | `useForm` que postea name/domain/database a `/admin/tenants`. |
| `resources/js/pages/landlord/tenants/show.tsx` | general | Renderiza tarjeta de detalle del tenant (id, domain, database, created_at). |

### 19.2 Vista cronológica — Archivos por sección del doc

| Sección | Archivo | Función |
|---|---|---|
| 4 | `config/multitenancy.php` | Usa `DomainTenantFinder`, `SwitchTenantDatabaseTask` y `tenant_model => Tenant::class`. |
| 5 | `config/database.php` | Define las conexiones `pgsql`, `landlord` y `tenant` (con `database => null`) apuntando a PostgreSQL. |
| 6 | `database/migrations/landlord/2026_05_29_183736_create_landlord_tenants_table.php` | Crea la tabla `tenants` en la BD landlord (columnas: name, domain unique, database unique). |
| 7 | `bootstrap/app.php` | Registra el grupo `tenant` (`NeedsTenant` + `EnsureValidTenantSession`) y override del alias `guest` → `App\Http\Middleware\RedirectIfAuthenticated` (ver §16.5). |
| 8 | `routes/web.php` | Cuatro grupos bien delimitados: públicas, compartidas (carga `settings.php`), SaaS tenant (`tenant` + auth + verified, contiene `dashboard`), y admin (carga `landlord.php` con `EnsureUserIsAdmin`). El `dashboard` vive en el grupo `tenant` — un landlord en el dominio principal recibe 404 al tipearlo. |
| 8 | `routes/landlord.php` | Rutas `/admin` con stack `auth` + `verified` + `EnsureUserIsAdmin`; namespace `landlord.*`. |
| 9 | `app/Models/User.php` | Modelo `Authenticatable` para tenants; usa el trait `UsesTenantConnection`; las queries corren sobre la conexión `tenant`. |
| 10 | `app/Models/Landlord.php` | Modelo `Authenticatable` para landlord; usa `UsesLandlordConnection`; reusa la tabla `users` del landlord. |
| 10 | `database/seeders/LandlordUserSeeder.php` | Crea el Landlord `admin@example.test` vía factory. |
| 11 | `app/Models/Tenant.php` | Modelo Spatie `Tenant`; el callback `creating` ejecuta `createDatabase()` (idempotente — chequea `pg_database`), configura la conexión `tenant` y corre migraciones. |
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
| general | `app/Http/Controllers/Landlord/AdminPanelController.php` | Renderiza la página Inertia `landlord/admin-panel` en `/admin` (home del landlord, ver §16.4); nunca entra en contexto tenant. |
| general | `routes/settings.php` | Rutas stock de profile/security/appearance requeridas por web.php; no es multitenancy-specific. |
| general | `resources/js/components/app-sidebar.tsx` | Sidebar role-aware: `mainNavItems` y el link del logo cambian según `auth.is_admin` (landlord → `/admin`, tenant → `/dashboard`). Admin suma grupo "Tenants". **Landlords nunca aterrizan en `/dashboard` por flujo normal** — ver §16.4 (POST-auth redirect) + §16.5 (guest redirect) + §7 (`tenant` middleware → 404). |
| general | `resources/js/components/nav-main.tsx` | Renderer genérico de grupo de sidebar; consumido por `app-sidebar` para ambos roles. |
| general | `resources/js/pages/landlord/admin-panel.tsx` | Página Inertia para `/admin` (home del landlord). NO usar `dashboard.tsx` en sesión landlord. |
| general | `resources/js/pages/landlord/tenants/index.tsx` | Lista tenants con link a las páginas create/show. |
| general | `resources/js/pages/landlord/tenants/create.tsx` | `useForm` que postea name/domain/database a `/admin/tenants`. |
| general | `resources/js/pages/landlord/tenants/show.tsx` | Renderiza tarjeta de detalle del tenant (id, domain, database, created_at). |

---

## 20. Verificación

| Flujo | Dominio Landlord | Dominio Tenant |
|-------|------------------|----------------|
| Registro de usuario | Usa `Landlord` + BD landlord | Usa `User` + BD tenant |
| Login | Usa `Landlord` + BD landlord | Usa `User` + BD tenant |
| Reset de contraseña | Acepta `Landlord` | Acepta `User` |
| Actualización de perfil | Valida contra BD landlord | Valida contra BD tenant |
