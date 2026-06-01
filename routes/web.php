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
