<?php

use App\Http\Controllers\Billing\PaymentController as BillingPaymentController;
use App\Http\Controllers\Billing\PlanChangeController;
use App\Http\Controllers\Billing\SubscriptionHistoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Premium\AnalyticsController;
use App\Http\Controllers\Resource\ResourceController;
use App\Http\Controllers\Tenant\PaymentController as TenantPaymentController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\ShopController;
use App\Http\Controllers\Tenant\UserController;
use App\Models\Tenant;
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
Route::get('/', function () {
    return inertia('welcome', [
        'canRegister' => Tenant::current() !== null,
    ]);
})->name('home');

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

    // Shop — unified page showing plans and resources.
    Route::get('shop', [ShopController::class, 'index'])->name('shop.index');

    // Aquí van las rutas del producto SaaS para cada cliente.

    // Premium zone — requires 'premium-zone' feature
    Route::middleware('feature:premium-zone')->prefix('premium')->name('premium.')->group(function () {
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');
    });

    // Resources catalog — Phase 1.5.
    //
    // The "Resources" feature is the tenant-side catalog of
    // downloadable assets (PDFs, images, audio, etc.). Each
    // resource has an `is_premium` flag that distinguishes free
    // from premium content; access is enforced *inside* the
    // controller, not by a route middleware, because the gate is
    // per-resource, not per-tenant:
    //
    //   - Free resources (is_premium = false): visible to every
    //     authenticated tenant, including free tier. The catalog
    //     filters them in for free-tier tenants and shows them
    //     alongside premium for paid tenants.
    //   - Premium resources: visible to every authenticated tenant.
    //     Access to the download endpoint is gated by `userCanAccess()`,
    //     which checks the plan_resource pivot or explicit Entitlement row.
    //
    // The sidebar still hides the "Resources" link for free-tier
    // tenants that have no free resources to show, see
    // `app-sidebar.tsx` and the `has_free_resources` flag in the
    // tenant shared prop.
    Route::prefix('resources')->name('resources.')->group(function () {
        Route::get('/', [ResourceController::class, 'index'])->name('index');
        Route::get('{slug}', [ResourceController::class, 'show'])->name('show');
        Route::post('{slug}/request', [ResourceController::class, 'request'])->name('request');
        Route::get('{slug}/download', [ResourceController::class, 'download'])->name('download');
    });

    // Settings — tenant-scoped user & role management.
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::resource('users', UserController::class);
        Route::post('users/{user}/roles', [UserController::class, 'assignRole'])->name('users.assignRole');
        Route::delete('users/{user}/roles/{role}', [UserController::class, 'removeRole'])->name('users.removeRole');
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    });

    // Billing — self-service plan change (1.5G-buy-plan).
    //
    // The tenant UI for picking a new plan. The `change-plan`
    // permission check lives INSIDE the controller (not as route
    // middleware) because the gate is a Spatie permission lookup
    // that requires the Spatie `User` model + the tenant's
    // permission tables to be live — exactly the same shape the
    // `Gate::allows(...)` path takes. See
    // `Billing\PlanChangeController` for the auth intent and
    // `App\Services\Billing\ChangePlanService` for the shared
    // mutation.
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('change-plan', [PlanChangeController::class, 'show'])->name('change-plan.show');
        Route::post('change-plan', [PlanChangeController::class, 'update'])->name('change-plan.update');
        Route::get('history', [SubscriptionHistoryController::class, 'index'])->name('history');

        // Orders & Payments — Pago Móvil payment flow
        Route::get('orders', [TenantPaymentController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [TenantPaymentController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/payments', [TenantPaymentController::class, 'store'])->name('orders.payments.store');

        // Payment initiation — creates pending order for a plan
        Route::get('payment/create/{plan}', [BillingPaymentController::class, 'create'])->name('payment.create');
    });

    // Notifications — in-app notification center.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
    Route::put('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.markAllRead');
    Route::put('notifications/{notification}', [NotificationController::class, 'update'])->name('notifications.update');
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
