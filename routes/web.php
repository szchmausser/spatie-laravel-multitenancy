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
// -------------------------------------------------------
require __DIR__.'/landlord.php';

require __DIR__.'/settings.php';
