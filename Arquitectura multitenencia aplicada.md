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
// Rutas del panel landlord/admin
// Accesibles solo desde el dominio principal.
// Usan Landlord (UsesLandlordConnection).
// NO llevan middleware 'tenant'.
// -------------------------------------------------------
Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    // Aquí irá tu panel de gestión de tenants, facturación, etc.
});

// -------------------------------------------------------
// Rutas exclusivas de subdominios tenant
// Requieren que haya un tenant activo (NeedsTenant).
// Usan User (UsesTenantConnection).
// -------------------------------------------------------
Route::middleware(['tenant', 'auth', 'verified'])->group(function () {
    // Aquí van las rutas del producto SaaS para cada cliente.
});

require __DIR__.'/settings.php';
```

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

class LandlordFactory extends Factory
{
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

use Illuminate\Database\Seeder;
use Spatie\Multitenancy\Models\Tenant;

class TenantsSeeder extends Seeder
{
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

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
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

---

## 12. Migrar las BDs de los tenants

```bash
php artisan tenants:artisan "migrate --database=tenant"
```

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
use App\Models\User;
use Spatie\Multitenancy\Models\Tenant;

trait ResolvesUserModel
{
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

class MultiTenantUserProvider extends EloquentUserProvider
{
    use ResolvesUserModel;

    public function createModel()
    {
        $class = $this->resolveUserModel();

        return new $class;
    }
}
```

Registrar el driver `multi-tenant` en la aplicación:

`app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Providers\MultiTenantUserProvider;
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

> **Cambio clave:** `User $user` → `Authenticatable $user`. Ahora el método acepta tanto `Landlord` como `User`, que es lo que el auth provider realmente puede devolver.

### Resultado

El reset de contraseña funciona en ambos dominios:
- **Landlord:** Acepta `Landlord` como `$user` → resetea en BD landlord
- **Tenant:** Acepta `User` como `$user` → resetea en BD del tenant

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
```

> `ServerAlias *.spatie-laravel-multitenancy.test` captura cualquier subdominio y lo dirige al mismo proyecto Laravel. Sin esta línea, solo el dominio principal funciona.

### 17.2 Archivo hosts de Windows

Editar `C:\Windows\System32\drivers\etc\hosts` como Administrador:

```
127.0.0.1 spatie-laravel-multitenancy.test
127.0.0.1 tenant1.spatie-laravel-multitenancy.test
127.0.0.1 tenant2.spatie-laravel-multitenancy.test
```

### 17.3 Reiniciar Laragon

Detener y reiniciar Laragon (Stop → Start All).

---

## 18. Flujo completo de reset de datos para desarrollo

```bash
# Paso 0 — Eliminar las BDs de los tenants en PostgreSQL
DROP DATABASE IF EXISTS "tenant1-spatie-laravel-multitenancy";
DROP DATABASE IF EXISTS "tenant2-spatie-laravel-multitenancy";

# Paso 1 — Fresh migrate del landlord
php artisan migrate:fresh
php artisan migrate --path=database/migrations/landlord --database=landlord

# Paso 2 — Seed del landlord
php artisan db:seed

# Paso 3 — Crear las BDs de los tenants en PostgreSQL
CREATE DATABASE "tenant1-spatie-laravel-multitenancy";
CREATE DATABASE "tenant2-spatie-laravel-multitenancy";

# Paso 4 — Migrar cada tenant
php artisan tenants:artisan "migrate --database=tenant"
```

---

## 18. Archivos clave — Resumen

| Archivo | Paso | Función |
|---------|------|---------|
| `app/Models/User.php` | 9 | Modelo de usuario para tenants (`UsesTenantConnection`) |
| `app/Models/Landlord.php` | 10 | Modelo de usuario para landlord (`UsesLandlordConnection`) |
| `app/Concerns/ResolvesUserModel.php` | 13 | Lógica centralizada de resolución de modelo (fuente única de verdad) |
| `app/Providers/MultiTenantUserProvider.php` | 13 | Auth provider que resuelve el modelo según el dominio |
| `app/Concerns/ProfileValidationRules.php` | 14 | Reglas de validación que usan resolución dinámica |
| `app/Actions/Fortify/CreateNewUser.php` | 14 | Acción de registro con modelo dinámico |
| `app/Actions/Fortify/ResetUserPassword.php` | 15 | Acción de reset de contraseña con tipo `Authenticatable` |
| `config/database.php` | 5 | Conexiones `pgsql`, `landlord` y `tenant` |
| `config/multitenancy.php` | 4 | Configuración de Spatie Multitenancy |
| `config/auth.php` | 13 | Auth guard con driver `multi-tenant` |
| `bootstrap/app.php` | 7 | Middleware `tenant` registrado |

---

## 19. Verificación

| Flujo | Dominio Landlord | Dominio Tenant |
|-------|------------------|----------------|
| Registro de usuario | Usa `Landlord` + BD landlord | Usa `User` + BD tenant |
| Login | Usa `Landlord` + BD landlord | Usa `User` + BD tenant |
| Reset de contraseña | Acepta `Landlord` | Acepta `User` |
| Actualización de perfil | Valida contra BD landlord | Valida contra BD tenant |
