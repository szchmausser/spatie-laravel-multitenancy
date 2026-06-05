<?php

use App\Http\Controllers\Premium\AnalyticsController;
use App\Http\Controllers\Resource\ResourceController;
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
    //   - Premium resources: visible only when the tenant's plan
    //     includes `premium-content` OR the user has an explicit
    //     Entitlement row. Enforced by `userCanAccess()` and
    //     `canSeePremium()` in App\Http\Controllers\Resource\ResourceController.
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
