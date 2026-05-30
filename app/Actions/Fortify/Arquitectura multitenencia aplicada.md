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

'tenant_model' => Tenant::class,
```

> Agregar el import: `use App\Models\Tenant;` al inicio del archivo.

---

## 5. Configurar `config/database.php`

Agregar las conexiones `pgsql`, `landlord` y `tenant`:

```php
'connections' => [
    // ... conexiones default (sqlite, mysql, etc.) ...

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

    'tenant' => [
        'driver' => 'pgsql',
        'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => null, // OBLIGATORIO: Spatie inyectará el nombre de la BD aquí.
        'username' => env('DB_USERNAME', 'root'),
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

## 6. Publicar y correr la migración de la tabla `tenants`

```bash
php artisan vendor:publish --provider="Spatie\Multitenancy\MultitenancyServiceProvider" --tag="multitenancy-migrations"
php artisan migrate --path=database/migrations/landlord --database=landlord
```

Esto crea la tabla `tenants` en la BD landlord. La migración crea esta estructura:

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('domain')->unique();
    $table->string('database')->unique();
    $table->timestamps();
});
```

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

> **¿Por qué se registra el grupo `tenant` aquí?** Porque necesitamos un middleware que asegure que haya un tenant activo antes de ejecutar las rutas del producto SaaS. Si no hay tenant activo (ej: alguien accede a una ruta de tenant desde el dominio principal), Spatie lanza `NoCurrentTenant`.

---

## 8. Configurar las rutas

Las rutas se separan en tres grupos claros. Esta separación es fundamental para el aislamiento:

**Rutas públicas:** Cualquier dominio, sin restricciones.
**Rutas SaaS:** Requieren tenant activo (middleware `tenant`).
**Rutas admin:** Solo para usuarios Landlord, SIN middleware `tenant`.

`routes/web.php`:

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
// -------------------------------------------------------
Route::middleware(['tenant', 'auth', 'verified'])->group(function () {
    // Aquí van las rutas del producto SaaS para cada cliente.
});

// -------------------------------------------------------
// Rutas del admin/landlord — SIN middleware tenant
// -------------------------------------------------------
require __DIR__.'/landlord.php';

require __DIR__.'/settings.php';
```

`routes/landlord.php`:

```php
<?php

use App\Http\Controllers\Landlord\AdminPanelController;
use App\Http\Controllers\Landlord\TenantController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

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

> **¿Por qué las rutas del admin no usan middleware `tenant`?** Si el middleware `tenant` se ejecuta en una ruta del admin, Spatie intentaría resolver un tenant desde el dominio. Como el dominio del admin es el dominio principal (no un subdominio de tenant), no encontraría tenant y lanzaría error. Las rutas del admin necesitan ejecutarse sin contexto de tenant.

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

> **Punto clave:** `UsesTenantConnection` le dice a Eloquent que este modelo usa la conexión `tenant`. Cuando hay un tenant activo, Spatie configura esa conexión para apuntar a la BD del tenant.

---

## 10. Crear el modelo `Landlord` y sus dependencias

El modelo Landlord reutiliza la tabla `users` que Laravel crea por defecto. No crea una tabla separada porque sería redundante.

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
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Landlord::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
        ]);
    }
}
```

---

## 11. Crear el modelo `Tenant` con provisioning automático

**¿Por qué el provisioning está en el modelo y no en el controller?**

Sigue el patrón que Spatie documenta con lifecycle callbacks. Permite crear tenants desde cualquier lugar (controller, comando artisan, test) con la misma lógica. El controller solo valida y crea el registro; el modelo se encarga del provisioning.

`app/Models/Tenant.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

/**
 * Tenant model with automatic provisioning.
 *
 * Extends Spatie's Tenant model and adds lifecycle callbacks that
 * automatically provision the tenant database when a new tenant is created.
 *
 * Provisioning steps (executed in the `creating` callback):
 * 1. createDatabase() - Creates the physical PostgreSQL database
 * 2. configureTenantConnection() - Points the 'tenant' connection to the new DB
 * 3. runMigrations() - Runs Laravel migrations on the new tenant database
 */
class Tenant extends SpatieTenant implements IsTenant
{
    use HasFactory;
    use ImplementsTenant;
    use UsesLandlordConnection;

    protected $fillable = [
        'name',
        'domain',
        'database',
    ];

    /**
     * Register lifecycle callbacks for automatic provisioning.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->createDatabase();
            $tenant->configureTenantConnection();
            $tenant->runMigrations();
        });
    }

    /**
     * Create the physical PostgreSQL database for this tenant.
     */
    protected function createDatabase(): void
    {
        DB::unprepared('CREATE DATABASE "'.$this->database.'"');
    }

    /**
     * Point the 'tenant' database connection to this tenant's database.
     *
     * Changes the config at runtime so that any query using the 'tenant'
     * connection targets this specific tenant's database. DB::purge()
     * forces Laravel to create a fresh connection with the updated config.
     */
    protected function configureTenantConnection(): void
    {
        config(['database.connections.tenant.database' => $this->database]);
        DB::purge('tenant');
    }

    /**
     * Run all pending Laravel migrations on this tenant's database.
     */
    protected function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--force' => true,
        ]);
    }
}
```

> **¿Por qué necesitamos `configureTenantConnection()`?** La conexión `tenant` en `config/database.php` tiene `database => null`. Sin esta línea, el comando `migrate` no sabría en qué BD ejecutarse. `DB::purge('tenant')` fuerza a Laravel a crear una conexión nueva con la configuración actualizada.

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

```bash
php artisan db:seed
```

---

## 12. Resolver el problema de login en landlord

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

`app/Concerns/ResolvesUserModel.php`:

```php
<?php

namespace App\Concerns;

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;

trait ResolvesUserModel
{
    /**
     * Resolve the appropriate user model based on current tenancy context.
     *
     * Returns Landlord when no tenant is active (landlord domain),
     * returns User when a tenant is active (tenant domain).
     */
    protected function resolveUserModel(): string
    {
        return Tenant::current() ? User::class : Landlord::class;
    }
}
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
 * Custom user provider that resolves the correct model (Landlord or User)
 * based on the current tenancy context.
 */
class MultiTenantUserProvider extends EloquentUserProvider
{
    use ResolvesUserModel;

    /**
     * Create a new instance of the model.
     */
    public function createModel()
    {
        $class = $this->resolveUserModel();

        return new $class;
    }
}
```

Registrar el driver en `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureDefaults();

        Auth::provider('multi-tenant', function ($app, array $config) {
            return new MultiTenantUserProvider($app['hash'], $config['model']);
        });
    }

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

Configurar `config/auth.php`:

```php
'providers' => [
    'users' => [
        'driver' => 'multi-tenant',
        'model' => env('AUTH_MODEL', User::class),
    ],
],
```

### Resultado

Ahora el login funciona en ambos dominios:
- **Landlord:** `MultiTenantUserProvider` resuelve `Landlord` → busca en BD landlord → sesión iniciada
- **Tenant:** `MultiTenantUserProvider` resuelve `User` → busca en BD del tenant → sesión iniciada

---

## 13. Resolver el problema de registro en landlord

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

El concern `ResolvesUserModel` ya existe (paso 12) y contiene la lógica correcta para resolver el modelo según el tenancy. Ahora lo aplicamos a los archivos que tenían el problema.

**¿Qué archivos necesitan cambios y por qué?**

1. **`ProfileValidationRules.php`** → Usa `User::class` en `Rule::unique()`. Necesita usar el modelo resuelto dinámicamente.
2. **`CreateNewUser.php`** → Usa `User::class` para crear usuarios. Necesita usar el modelo resuelto dinámicamente.

`app/Concerns/ProfileValidationRules.php`:

```php
<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    use ResolvesUserModel;

    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    protected function emailRules(?int $userId = null): array
    {
        $userModel = $this->resolveUserModel();

        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique($userModel)
                : Rule::unique($userModel)->ignore($userId),
        ];
    }
}
```

> **Cambio clave:** `Rule::unique(User::class)` → `Rule::unique($this->resolveUserModel())`. Ahora la validación consulta la BD correcta según el dominio.

`app/Actions/Fortify/CreateNewUser.php`:

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesUserModel;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules, ResolvesUserModel;

    public function create(array $input): BaseUser
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $userModel = $this->resolveUserModel();

        return $userModel::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
```

> **Nota:** Se usa `Illuminate\Foundation\Auth\User as BaseUser` porque el contrato `CreatesNewUsers` de Fortify requiere ese tipo específico. Tanto `Landlord` como `User` extienden esa clase base.

---

## 14. Resolver el problema de reset de contraseña

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

Pero el auth provider (paso 12) ahora puede devolver tanto `Landlord` como `User` según el dominio. Cuando el reset se ejecuta desde el dominio landlord, el auth provider devuelve una instancia de `Landlord`, pero el método esperaba estrictamente `User` → `TypeError` en PHP 8.5+.

### Solución

`app/Actions/Fortify/ResetUserPassword.php`:

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

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
```

> **Cambio clave:** `User $user` → `Authenticatable $user`. Ahora acepta tanto `Landlord` como `User`.

---

## 15. Crear el middleware `EnsureUserIsAdmin`

**¿Por qué es necesario?** Las rutas del admin no usan middleware `tenant`, pero eso no significa que puedan acceder usuarios normales de tenants. Necesitamos verificar que el usuario autenticado sea un Landlord (admin).

`app/Http/Middleware/EnsureUserIsAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the authenticated user is a Landlord (admin platform user).
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

---

## 16. Crear los controllers del admin

`app/Http/Controllers/Landlord/AdminPanelController.php`:

```php
<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Inertia\Inertia;

/**
 * Admin dashboard controller.
 * Reads data from the landlord database only.
 */
class AdminPanelController extends Controller
{
    public function index()
    {
        $totalTenants = Tenant::count();
        $tenants = Tenant::all();

        return Inertia::render('landlord/admin-panel', [
            'totalTenants' => $totalTenants,
            'tenants' => $tenants,
        ]);
    }
}
```

`app/Http/Controllers/Landlord/TenantController.php`:

```php
<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Tenant management controller for the admin panel.
 * Provisioning is handled by the Tenant model's lifecycle callback.
 */
class TenantController extends Controller
{
    public function index()
    {
        $tenants = Tenant::all();

        return Inertia::render('landlord/tenants/index', [
            'tenants' => $tenants,
        ]);
    }

    public function create()
    {
        return Inertia::render('landlord/tenants/create');
    }

    /**
     * Create a new tenant. The Tenant model's `creating` callback
     * handles database creation, connection setup, and migrations.
     */
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
}
```

---

## 17. Actualizar el middleware de Inertia

**¿Por qué?** El frontend React necesita saber si el usuario actual es un admin para mostrar u ocultar el link "Admin" en el sidebar.

`app/Http/Middleware/HandleInertiaRequests.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

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
}
```

---

## 18. Actualizar el sidebar

`resources/js/components/app-sidebar.tsx`:

```tsx
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, FolderGit2, LayoutGrid, Shield } from 'lucide-react';
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

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Admin',
        href: '/admin',
        icon: Shield,
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
    const navItems = isAdmin ? [...mainNavItems, ...adminNavItems] : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
```

---

## 19. Crear los componentes React del admin

`resources/js/pages/landlord/admin-panel.tsx`:

```tsx
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard', href: '/admin' },
];

export default function LandlordDashboard({ totalTenants, tenants }: { totalTenants: number; tenants: any[] }) {
    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-4">Admin Dashboard</h1>
            <div className="mb-6">
                <p className="text-gray-600">Total tenants: <span className="font-semibold">{totalTenants}</span></p>
            </div>
            <div>
                <h2 className="text-lg font-semibold mb-2">Tenants</h2>
                <div className="border rounded-lg divide-y">
                    {tenants.map((tenant: any) => (
                        <div key={tenant.id} className="p-4 flex justify-between items-center">
                            <div>
                                <p className="font-medium">{tenant.name}</p>
                                <p className="text-sm text-gray-500">{tenant.domain}</p>
                            </div>
                            <a href={`/admin/tenants/${tenant.id}`} className="text-blue-600 hover:underline">
                                View
                            </a>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
```

`resources/js/pages/landlord/tenants/index.tsx`:

```tsx
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
];

export default function TenantIndex({ tenants }: { tenants: any[] }) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Tenants</h1>
                <a href="/admin/tenants/create" className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Create Tenant
                </a>
            </div>
            <div className="border rounded-lg divide-y">
                {tenants.length === 0 ? (
                    <p className="p-4 text-gray-500">No tenants yet.</p>
                ) : (
                    tenants.map((tenant: any) => (
                        <div key={tenant.id} className="p-4 flex justify-between items-center">
                            <div>
                                <p className="font-medium">{tenant.name}</p>
                                <p className="text-sm text-gray-500">{tenant.domain}</p>
                                <p className="text-sm text-gray-400">DB: {tenant.database}</p>
                            </div>
                            <a href={`/admin/tenants/${tenant.id}`} className="text-blue-600 hover:underline">
                                View
                            </a>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
```

`resources/js/pages/landlord/tenants/create.tsx`:

```tsx
import { type BreadcrumbItem } from '@/types';
import { FormEventHandler } from 'react';
import { useForm } from '@inertiajs/react';

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
        post('/admin/tenants');
    };

    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-6">Create Tenant</h1>
            <form onSubmit={submit} className="max-w-lg space-y-4">
                <div>
                    <label className="block text-sm font-medium mb-1">Name</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="w-full border rounded px-3 py-2"
                    />
                    {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Domain</label>
                    <input
                        type="text"
                        value={data.domain}
                        onChange={(e) => setData('domain', e.target.value)}
                        className="w-full border rounded px-3 py-2"
                        placeholder="tenant1.example.com"
                    />
                    {errors.domain && <p className="text-red-500 text-sm mt-1">{errors.domain}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Database</label>
                    <input
                        type="text"
                        value={data.database}
                        onChange={(e) => setData('database', e.target.value)}
                        className="w-full border rounded px-3 py-2"
                        placeholder="tenant1_database"
                    />
                    {errors.database && <p className="text-red-500 text-sm mt-1">{errors.database}</p>}
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
                >
                    {processing ? 'Creating...' : 'Create Tenant'}
                </button>
            </form>
        </div>
    );
}
```

`resources/js/pages/landlord/tenants/show.tsx`:

```tsx
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Details', href: '#' },
];

export default function TenantShow({ tenant }: { tenant: any }) {
    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-6">{tenant.name}</h1>
            <div className="border rounded-lg p-4 space-y-3">
                <div>
                    <span className="text-gray-500 text-sm">ID</span>
                    <p className="font-medium">{tenant.id}</p>
                </div>
                <div>
                    <span className="text-gray-500 text-sm">Domain</span>
                    <p className="font-medium">{tenant.domain}</p>
                </div>
                <div>
                    <span className="text-gray-500 text-sm">Database</span>
                    <p className="font-medium">{tenant.database}</p>
                </div>
                <div>
                    <span className="text-gray-500 text-sm">Created</span>
                    <p className="font-medium">{tenant.created_at}</p>
                </div>
            </div>
            <div className="mt-6">
                <a href="/admin/tenants" className="text-blue-600 hover:underline">
                    &larr; Back to tenants
                </a>
            </div>
        </div>
    );
}
```

---

## 20. Scripts para gestionar el hosts file

**¿Por qué scripts separados?** En desarrollo local, cada tenant necesita una entrada en el archivo hosts de Windows/Linux. Editar el archivo manualmente cada vez que se crea un tenant es tedioso y propenso a errores. Estos scripts automatizan el proceso.

`scripts/add-host.php`:

```php
#!/usr/bin/env php
<?php

/**
 * Add a hostname entry to the system hosts file.
 *
 * Usage:
 *   php add-host.php <hostname> [ip]
 *
 * Must be run as administrator/sudo.
 */
$hostname = $argv[1] ?? null;
$ip = $argv[2] ?? '127.0.0.1';

if (! $hostname) {
    echo "Usage: php add-host.php <hostname> [ip]\n";
    echo "  hostname: The hostname to add (e.g., tenant1.example.test)\n";
    echo "  ip:       The IP address (default: 127.0.0.1)\n";
    exit(1);
}

// Determine hosts file path based on OS
$hostsPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
    ? 'C:\Windows\System32\drivers\etc\hosts'
    : '/etc/hosts';

// Check if hosts file is writable
if (! is_writable($hostsPath)) {
    echo "Error: Cannot write to {$hostsPath}\n";
    echo "Run this script as administrator (Windows) or with sudo (Linux/macOS).\n";
    exit(1);
}

// Read current hosts file
$hosts = file_get_contents($hostsPath);

// Check if hostname already exists
$lines = explode("\n", $hosts);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (preg_match('/\s+'.preg_quote($hostname, '/').'\b/', $line)) {
        echo "Entry for '{$hostname}' already exists in hosts file.\n";
        exit(0);
    }
}

// Add the new entry at the end of the file
$entry = "{$ip}\t{$hostname}";

if (substr($hosts, -1) !== "\n") {
    $hosts .= "\n";
}

$hosts .= $entry."\n";

if (file_put_contents($hostsPath, $hosts) !== false) {
    echo "Added: {$entry}\n";
    echo "Hosts file: {$hostsPath}\n";
    exit(0);
} else {
    echo "Error: Failed to write to hosts file.\n";
    exit(1);
}
```

`scripts/remove-host.php`:

```php
#!/usr/bin/env php
<?php

/**
 * Remove a hostname entry from the system hosts file.
 *
 * Usage:
 *   php remove-host.php <hostname>
 *
 * Must be run as administrator/sudo.
 */
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
    echo "Run this script as administrator (Windows) or with sudo (Linux/macOS).\n";
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
        continue;
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

---

## 21. Configurar Laragon para subdominios

### 21.1 Virtual Host de Apache

Renombrar el archivo quitando el prefijo `auto.`:

```
auto.spatie-laravel-multitenancy.test.conf  →  spatie-laravel-multitenancy.test.conf
```

> **Sin este cambio, Laragon sobreescribe la configuración al reiniciar.**

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
```

> `ServerAlias *.spatie-laravel-multitenancy.test` captura cualquier subdominio y lo dirige al mismo proyecto Laravel.

### 21.2 Archivo hosts de Windows

```
127.0.0.1 spatie-laravel-multitenancy.test
```

Usar `scripts/add-host.php` para agregar subdominios de tenants.

### 21.3 Reiniciar Laragon

Stop → Start All.

---

## 22. Flujo de reset de datos para desarrollo

```bash
# Paso 0 — Eliminar las BDs de los tenants en PostgreSQL
DROP DATABASE IF EXISTS "tenant1-spatie-laravel-multitenancy";
DROP DATABASE IF EXISTS "tenant2-spatie-laravel-multitenancy";

# Paso 1 — Fresh migrate del landlord
php artisan migrate:fresh
php artisan migrate --path=database/migrations/landlord --database=landlord

# Paso 2 — Seed del landlord (crea tenants con provisioning automático)
php artisan db:seed
```

> Con el provisioning automático en el modelo Tenant, el seeder crea las BDs y ejecuta las migraciones automáticamente.

---

## 23. Decisiones arquitectónicas

### ¿Por qué Database-per-Tenant?

Es la estrategia con mayor aislamiento de datos. Cada tenant tiene su propia BD, lo que garantiza que un bug en un tenant no afecte a otros. Ideal para aplicaciones SaaS donde el aislamiento es crítico.

### ¿Por qué un Auth Provider personalizado?

El auth provider estándar de Laravel siempre usa el modelo `User`. En multitenancy, necesitamos que en el dominio landlord se use `Landlord` (conexión landlord) y en subdominios de tenant se use `User` (conexión tenant). El `MultiTenantUserProvider` resuelve esto dinámicamente.

### ¿Por qué un concern `ResolvesUserModel`?

La lógica `Tenant::current() ? User::class : Landlord::class` se necesita en múltiples lugares (auth provider, registro, validación). Extraerla a un concern evita duplicación y garantiza consistencia. Es la fuente única de verdad.

### ¿Por qué las rutas del admin no usan middleware `tenant`?

Si el middleware `tenant` se ejecuta en una ruta del admin, Spatie intentaría resolver un tenant desde el dominio. Como el dominio del admin es el dominio principal (no un subdominio de tenant), no encontraría tenant → error. Las rutas del admin necesitan ejecutarse sin contexto de tenant.

### ¿Por qué el provisioning está en el modelo Tenant?

Sigue el patrón que Spatie documenta con lifecycle callbacks. Permite crear tenants desde cualquier lugar (controller, comando artisan, test) con la misma lógica. El controller solo valida y crea el registro; el modelo se encarga del provisioning.

### ¿Por qué `Authenticatable` en ResetUserPassword?

El auth provider puede devolver tanto `Landlord` como `User`. Ambos implementan `Illuminate\Contracts\Auth\Authenticatable`. Usar esta interfaz como tipo de parámetro respeta el principio de Liskov Substitution y evita `TypeError`.

### ¿Por qué `BaseUser` en CreateNewUser?

El contrato `CreatesNewUsers` de Fortify requiere que el método `create()` retorne `\Illuminate\Foundation\Auth\User`. Tanto `Landlord` como `User` extienden esa clase base, por lo que el tipo de retorno es correcto y cumple con el contrato.
