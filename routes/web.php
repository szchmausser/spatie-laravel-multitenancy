<?php

use Illuminate\Support\Facades\Route;

// Route::inertia('/', 'welcome')->name('home');

// Route::middleware(['auth', 'verified'])->group(function () {
//     Route::inertia('dashboard', 'dashboard')->name('dashboard');
// });

// -------------------------------------------------------
// Rutas públicas — sin restricciones, cualquier dominio
// -------------------------------------------------------
Route::inertia('/', 'welcome')->name('home');

// -------------------------------------------------------
// Rutas del panel landlord/admin
// Accesibles solo desde el dominio principal.
// Usan AdminUser (UsesLandlordConnection).
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
    // Por ejemplo:
    // Route::inertia('app/dashboard', 'app/dashboard')->name('app.dashboard');
});

require __DIR__.'/settings.php';
